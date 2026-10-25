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
    protected $signature = 'mercy:sync-npm';

    /**
     * @var string
     */
    protected $description = 'Sync your Nginx Proxy Manager: creates/updates the proxy hosts of YAML "setup.npm.hosts" (config services.npm.url/user/password).';

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

        // Fetch remote mappings to find IDs dynamically
        $remoteHostsMap = $this->fetchRemoteHostsMap($token);
        if (empty($remoteHostsMap)) {
            $this->error('Could not retrieve existing proxy hosts from NPM API.');

            return CommandResult::FAILURE;
        }

        // Parse all loaded yaml configs by resolving their values.
        $this->parseConfigs();

        $result = CommandResult::SUCCESS;
        foreach ($this->getDeployment()->getConfigValue('setup.npm.hosts', []) as $host) {
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

        return $result;
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
     * @return array<string, int>
     */
    private function fetchRemoteHostsMap(string $token): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->get(config('services.npm.url')."/nginx/proxy-hosts");

        if ($response->failed()) {
            return [];
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

