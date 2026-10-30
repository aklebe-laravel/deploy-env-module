<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\app\Console;

use Exception;
use Illuminate\Support\Facades\File;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\DeploymentService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command as CommandResult;

class MercySyncNpmCommand extends DeployEnvBase
{
    /**
     * @var string
     */
    protected $signature = 'mercy:sync-npm
        {--remove : Delete the proxy hosts of this installation from NPM (e.g. before "./mercy destroy")}';

    /**
     * Domains this installation created or updated in NPM, so later runs remove the ones that left the config
     * (e.g. after a domain change) and "--remove" knows what belongs to it.
     */
    private const string STATE_FILE = 'app/mercy-npm-hosts.json';

    /**
     * @var string
     */
    protected $description = 'Sync your Nginx Proxy Manager: creates/updates the proxy hosts of YAML "setup.npm.hosts" (config services.npm.url/user/password), removes the ones of former domains.';

    /**
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        // preparing all configs with getDeployment() ...
        $this->getDeployment();

        if (!($npmApiUrl = config('services.npm.url'))) {
            $this->warn("Config npm api url not set. Skipping!");

            return CommandResult::FAILURE;
        }

        $token = $this->fetchApiToken();
        if (!$token) {
            $this->error('Authentication with Nginx Proxy Manager failed.');

            return CommandResult::FAILURE;
        }

        // Fetch remote mappings to find IDs dynamically (an empty NPM is fine, everything gets created)
        $remoteHostsMap = $this->fetchRemoteHostsMap($token);
        if ($remoteHostsMap === null) {
            $this->error('Could not retrieve existing proxy hosts from NPM API.');

            return CommandResult::FAILURE;
        }

        // Parse all loaded yaml configs by resolving their values.
        $this->parseConfigs();
        $hosts = (array) $this->getDeployment()
            ->getConfigValue('setup.npm.hosts', []);
        $configured = array_values(array_filter(array_map(fn($host) => (string) ($host['domain'] ?? ''), $hosts)));
        $managed = $this->managedDomains();

        if ($this->option('remove')) {
            $result = $this->deleteHosts($token, $remoteHostsMap, array_unique([...$managed, ...$configured]));
            $this->saveManagedDomains([]);

            return $result;
        }

        // domains of an earlier run that are not configured any more (e.g. renamed): remove their hosts
        $result = $this->deleteHosts($token, $remoteHostsMap, array_diff($managed, $configured));

        foreach ($hosts as $host) {
            if (!($domain = $host['domain'] ?? '')) {
                $this->error('Missing "domain" in npm host config.');
                $result = CommandResult::FAILURE;
                continue;
            }
            $payload = $this->buildPayload($host);

            if (isset($remoteHostsMap[$domain])) {
                // Update existing host
                $npmId = $remoteHostsMap[$domain];
                $this->info("Updating existing NPM host ID {$npmId} for {$domain}...");

                $response = Http::withToken($token)
                    ->acceptJson()
                    ->put("{$npmApiUrl}/nginx/proxy-hosts/{$npmId}", $payload);
            } else {
                // Create brand-new host automatically
                $this->info("Domain '{$domain}' not found in NPM. Creating new proxy host...");

                $response = Http::withToken($token)
                    ->acceptJson()
                    ->post("{$npmApiUrl}/nginx/proxy-hosts", $payload);
            }

            if ($response->failed()) {
                $this->error("Failed processing domain {$domain}: ".$response->body());
                $result = CommandResult::FAILURE;
                continue;
            }

            $this->components->info("Successfully synchronized NPM routing for {$domain}.");
        }
        $this->saveManagedDomains($configured);

        return $result;
    }

    /**
     * @param  string             $token
     * @param  array<string,int>  $remoteHostsMap
     * @param  iterable<string>   $domains
     *
     * @return int
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    private function deleteHosts(string $token, array $remoteHostsMap, iterable $domains): int
    {
        $result = CommandResult::SUCCESS;
        foreach ($domains as $domain) {
            if (!isset($remoteHostsMap[$domain])) {
                continue;
            }
            $response = Http::withToken($token)
                ->acceptJson()
                ->delete(config('services.npm.url')."/nginx/proxy-hosts/{$remoteHostsMap[$domain]}");
            if ($response->failed()) {
                $this->error("Failed to delete the NPM host of {$domain}: ".$response->body());
                $result = CommandResult::FAILURE;
                continue;
            }
            $this->components->info("Removed the NPM host of {$domain}.");
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function managedDomains(): array
    {
        $file = storage_path(self::STATE_FILE);

        return is_file($file) ? array_values(array_filter((array) json_decode((string) file_get_contents($file), true))) : [];
    }

    /**
     * @param  list<string>  $domains
     */
    private function saveManagedDomains(array $domains): void
    {
        file_put_contents(storage_path(self::STATE_FILE), json_encode(array_values($domains), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Login token from the NPM API.
     *
     * @return string|null
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    private function fetchApiToken(): ?string
    {
        $url = config('services.npm.url')."/tokens";
        if (!($user = config('services.npm.user'))) {
            $this->error("missing config npm user");

            return null;
        }
        $password = config('services.npm.password');
        $response = Http::acceptJson()
            ->post($url, [
                'identity' => $user,
                'secret'   => $password,
            ]);

        if ($response->failed()) {
            // never print the password
            $this->error(sprintf("NPM api login failed for user '%s' at %s", $user, $url));
            $this->error("response: ".$response->body());

            return null;
        }

        return $response->json('token');
    }

    /**
     * Fetches all proxy hosts from NPM and maps domain names to their internal ID.
     *
     * @param  string  $token
     *
     * @return array<string, int>|null null if NPM does not answer
     */
    private function fetchRemoteHostsMap(string $token): ?array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->get(config('services.npm.url')."/nginx/proxy-hosts");

        if ($response->failed()) {
            return null;
        }

        $map = [];
        foreach ($response->json() ?? [] as $host) {
            if (isset($host['id'], $host['domain_names']) && is_array($host['domain_names'])) {
                foreach ($host['domain_names'] as $domainName) {
                    $map[$domainName] = (int) $host['id'];
                }
            }
        }

        return $map;
    }

    /**
     * Generates a strictly schema-compliant payload for the NPM API.
     * Any additional keys will trigger a 400 validation error.
     *
     * @param  array<string, mixed>  $host  Configuration data from YAML
     *
     * @return array<string, mixed> Filtered payload matching NPM JSON-schema
     * @throws Exception
     */
    private function buildPayload(array $host): array
    {
        $deploymentService = app(DeploymentService::class);

        // get advanced block
        $advancedConfig = $this->getAdvancedConfigTemplate($host['advanced_block'] ?? '', config('app.env'));

        $useSsl = (bool) ($host['ssl_forced'] ?? false);
        $certId = 0;
        if ($useSsl) {
            // use yaml var then
            $certId = (int) ($host['certificate_id'] ?? 0);
        }

        $locations = [];
        // locations / custom paths
        foreach ($host['locations'] ?? [] as $hostLocation) {
            $l = [];
            $l['path'] = $hostLocation['path'];
            $l['forward_scheme'] = $hostLocation['forward_scheme'];
            $l['forward_host'] = $hostLocation['forward_host'];
            $l['forward_port'] = (int) ($hostLocation['forward_port'] ?? 80);
            $l['advanced_config'] = $this->getAdvancedConfigTemplate($hostLocation['advanced_block'] ?? '', config('app.env'));
            $locations[] = $l;
        }

        // Only keys of the NPM schema, unknown keys are rejected with 400.
        return [
            'domain_names'            => [$host['domain']],
            'forward_scheme'          => $host['forward_scheme'] ?? 'http',
            'forward_host'            => (string) ($host['forward_host'] ?? $deploymentService->resolveCurrentContainerName()),
            'forward_port'            => (int) ($host['forward_port'] ?? 80),
            'advanced_config'         => trim($advancedConfig),
            'ssl_forced'              => $useSsl,
            'certificate_id'          => $certId,
            'allow_websocket_upgrade' => (bool) ($host['allow_websocket_upgrade'] ?? false),
            'block_exploits'          => (bool) ($host['block_exploits'] ?? true),
            'caching_enabled'         => (bool) ($host['caching_enabled'] ?? false),
            'http2_support'           => (bool) ($host['http2_support'] ?? false),
            'hsts_enabled'            => (bool) ($host['hsts_enabled'] ?? false),
            'hsts_subdomains'         => (bool) ($host['hsts_subdomains'] ?? false),
            'meta'                    => new \stdClass(), // Must be an empty object, not an array
            'locations'               => $locations,
        ];
    }

    /**
     * Content of resources/setup/nginx/{env}/advanced/{name}.conf with parsed vars, '' if missing.
     *
     * @param  string  $advancedBlockName
     * @param  string  $envName
     *
     * @return string
     * @throws Exception
     */
    private function getAdvancedConfigTemplate(string $advancedBlockName, string $envName): string
    {
        if (!$advancedBlockName) {
            return '';
        }

        $advancedConfigTemplate = resource_path(sprintf("setup/nginx/%s/advanced/%s.conf", $envName, $advancedBlockName));
        if (!File::exists($advancedConfigTemplate) || !($advancedConfig = file_get_contents($advancedConfigTemplate))) {
            return '';
        }

        return app(DeploymentService::class)->parseEverything($advancedConfig);
    }
}

