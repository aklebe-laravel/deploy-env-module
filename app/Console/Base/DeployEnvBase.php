<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\app\Console\Base;

use AklebeLaravel\MercyPhpDeployment\DockerProcessDeployment;
use CzProject\GitPhp\GitException;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult as ContractsProcessResult;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use Modules\DeployEnv\app\Services\DeploymentService;
use Modules\DeployEnv\app\Services\RequireModuleService;
use Modules\DeployEnv\app\Services\RequireThemeService;
use Psr\Log\LogLevel;

/**
 * Every DeployEnv command should extend this class.
 *
 * Provides:
 * - the YAML setup configs (resources/setup/*.yaml) by getDeployment()
 * - process/artisan helpers like runProcessComposerUpdate() or runProcessArtisanCacheClear()
 * - maintenance mode helpers
 * - getRequires() to clone/update the modules and themes listed in 'setup.requires'
 */
class DeployEnvBase extends Command
{
    /**
     * Directory for YAML config templates
     *
     * @var string
     */
    public string $directoryConfigTemplates = 'resources/setup';

    /**
     * Directory for last used YAML configs (previous copied from {@see $directoryConfigTemplates})
     *
     * @var string
     */
    public string $directoryConfigLastSetup = 'storage/app/last-setup';

    /**
     * Run "composer dump-autoload" after a new module was created.
     *
     * @var bool
     */
    public bool $composerDumpAutoLoadNeededForNewModules = true;

    /**
     * Add --no-interaction to artisan() calls.
     *
     * @var bool
     */
    protected bool $noInteractionForExternCalls = true;

    /**
     * Output of runProcess(): one of 'echo', 'log', 'callback'.
     * Otherwise, nothing will happen. If 'callback' then $processOutputCallback has to be set.
     *
     * @var string
     */
    public string $processOutput = 'echo';

    /**
     * Only needed if $processOutput is set to 'callback'.
     * Called like ($this->processOutputCallback)(string $type, string $output), $type is 'out' or 'err'.
     *
     * @var callable|null
     */
    public $processOutputCallback = null;

    /**
     * Secret of the maintenance mode set by enableMaintenanceMode(), '' if not set by this process.
     *
     * @var string
     */
    private string $secretMaintenanceKey = '';

    /**
     * @var DockerProcessDeployment|null
     */
    protected ?DockerProcessDeployment $deployment = null;

    /**
     * Variables to display in the initial status overview.
     */
    protected array $summaryEnvVars = [
        'APP_NAME',
        'APP_ENV',
        'APP_URL',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
    ];

    /**
     * The deployment with the loaded YAML configs (created once, see $forceNewInstance).
     * Templates from resources/setup are copied to storage/app/last-setup.
     *
     * @param  array  $requiredYamlConfigs
     * @param  bool   $forceNewInstance
     *
     * @return DockerProcessDeployment
     * @throws Exception
     */
    public function getDeployment(array $requiredYamlConfigs = [], bool $forceNewInstance = false): DockerProcessDeployment
    {
        if (!$this->deployment || $forceNewInstance) {
            $this->deployment = new DockerProcessDeployment();
            $this->deployment->initSetup(base_path(), base_path($this->directoryConfigTemplates), base_path($this->directoryConfigLastSetup), $requiredYamlConfigs);
        }

        return $this->deployment;
    }

    /**
     * Value of the YAML configs like 'setup.kernel.dev_mode'.
     *
     * @param  string      $configKeyPath
     * @param  mixed|null  $default
     *
     * @return mixed
     * @throws Exception
     */
    public function getYamlConfigValue(string $configKeyPath, mixed $default = null): mixed
    {
        return $this->getDeployment()
            ->getConfigValue($configKeyPath, $default);
    }

    /**
     * Print out and log the message.
     * If $message is array or object, the output will be formatted.
     *
     * @param  mixed   $message
     * @param  string  $logLevel
     *
     * @return void
     */
    public function message(mixed $message, string $logLevel = LogLevel::DEBUG): void
    {
        if (!is_scalar($message)) {
            $message = print_r($message, true);
        }

        $this->output->writeln($message);
        Log::log($logLevel, $message);
    }

    /**
     * Converts values like 'true', 'on', '1', 'yes' to true.
     *
     * @param  mixed  $value
     *
     * @return bool
     */
    protected function getBoolValue(mixed $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Runs (depending on YAML 'setup.kernel.dev_mode'):
     * - composer (update in dev mode, otherwise install --no-dev)
     * - npm update
     * - clearing caches
     * - build frontend
     * - optimize (caching warmup, not in dev mode)
     *
     * @return bool
     */
    public function runProcessUpdateSystem(): bool
    {
        $devMode = $this->getYamlConfigValue('setup.kernel.dev_mode', false);

        // composer update
        if (!$this->runProcessComposerUpdate()) {
            return false;
        }

        // npm update
        if (!$this->runProcessNpmUpdate()) {
            return false;
        }

        // Clear all caches
        if (!$this->runProcessArtisanCacheClear()) {
            return false;
        }

        // Npm build ...
        if (!$this->runProcessArtisanBuildFrontend()) {
            return false;
        }

        if (!$devMode) {
            $this->runProcessArtisanOptimize();
        }

        $this->info('System update finished successfully!');
        $this->comment(number_format(app('system_base')->getExecutionTime(), 2, '.', '').' sec');

        return true;
    }

    /**
     * Runs:
     * - migrate --force
     * - DeployEnv deployments (deploy-env:terraform-modules)
     *
     * @return bool
     */
    public function runProcessMigrateAndDeployEnv(): bool
    {
        // Migrate
        if (!$this->runProcessArtisanMigrate()) {
            return false;
        }

        // Deploy env update (Seeding)
        if (!$this->runProcessDeployEnvUpdate()) {
            return false;
        }

        return true;
    }

    /**
     * Runs a shell command without timeout, the output goes to printProcessOutput().
     *
     * @param  string  $cmd
     * @param  array   $options  see addCommandOptions()
     * @param  string  $workingDir
     *
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcess(string $cmd, array $options = [], string $workingDir = '.'): ProcessResult|ContractsProcessResult
    {
        $cmd = self::addCommandOptions($cmd, $options);

        $this->info("Process: '$cmd'");

        $result = Process::forever()
            ->path($workingDir)
            ->run($cmd, function (string $type, string $output) {
                $this->printProcessOutput($type, $output);
            });

        if ($result->failed()) {
            $this->comment("Process: Failed: \"$cmd\"");
        }

        $this->comment("");

        return $result;
    }

    /**
     * Handles process output depending on $processOutput. Overwrite this to send output to your favorite.
     *
     * @param  string  $type  'out' or 'err'
     * @param  string  $output
     *
     * @return void
     */
    protected function printProcessOutput(string $type, string $output): void
    {
        switch ($this->processOutput) {
            case 'echo':
                echo $output;
                break;
            case 'log':
                // $type is no log level
                Log::log(($type === 'err') ? LogLevel::ERROR : LogLevel::INFO, $output);
                break;
            case 'callback':
                if ($this->processOutputCallback && app('system_base')->isCallableClosure($this->processOutputCallback)) {
                    ($this->processOutputCallback)($type, $output);
                }
                break;
        }
    }

    /**
     * composer update on dev (YAML 'setup.kernel.dev_mode'), otherwise composer install --no-dev --optimize-autoloader.
     *
     * @return bool
     */
    public function runProcessComposerUpdate(): bool
    {
        $devMode = $this->getBoolValue($this->getYamlConfigValue('setup.kernel.dev_mode', false));

        // "--no-dev" in a dev environment removes the dev packages (sail, ignition, ...) while this process
        // still uses them: it crashes halfway and leaves the environment without "vendor/bin/sail".
        if (!$devMode && app()->environment('local')) {
            $this->error("APP_ENV is 'local', but 'setup.kernel.dev_mode' is not true: refusing 'composer install --no-dev'. "."Set 'kernel.dev_mode: true' in the dev stage of your project (project/config/dev.yaml).");

            return false;
        }

        if ($devMode) {
            return $this->runProcess('composer update')
                ->successful();
        }

        return $this->runProcess('composer install', ['--no-dev', '--optimize-autoloader'])
            ->successful();
    }

    /**
     * @return bool
     */
    public function runProcessArtisanClearCompiled(): bool
    {
        return $this->artisan('clear-compiled') === 0;
    }

    /**
     * @return bool
     */
    public function runProcessArtisanWebsiteBaseChangelog(): bool
    {
        return $this->artisan('website-base:changelog') === 0;
    }

    /**
     * @return bool
     */
    public function runProcessArtisanOptimize(): bool
    {
        return $this->artisan('optimize') === 0;
    }

    /**
     * @return bool
     */
    public function runProcessComposerDump(): bool
    {
        return $this->runProcess('composer dump-autoload', [])
            ->successful();
    }

    /**
     * @return bool
     */
    public function runProcessNpmUpdate(): bool
    {
        return $this->runProcess('npm update')
            ->successful();
    }

    /**
     * npm run dev or npm run build.
     *
     * @param  bool  $dev
     *
     * @return bool
     */
    public function runProcessNpmBuild(bool $dev = false): bool
    {
        return $this->runProcess($dev ? 'npm run dev' : 'npm run build')
            ->successful();
    }

    /**
     * Appends options to a command line:
     * - ['--force'] => ' --force'
     * - ['--env' => 'prod'] => ' --env=prod'
     * Values are not escaped.
     *
     * @param  string  $cmd
     * @param  array   $options
     *
     * @return string
     */
    public static function addCommandOptions(string $cmd, array $options = []): string
    {
        foreach ($options as $k => $option) {
            // if option key is string we build an assign --my_option=value
            if (is_string($k)) {
                $cmd .= ' '.$k.'='.$option;
            } else { // otherwise, just build the option/switch
                $cmd .= ' '.$option;
            }
        }

        return $cmd;
    }

    /**
     * Calls an artisan command (with --no-interaction, see $noInteractionForExternCalls).
     *
     * @param  string  $command
     * @param  array   $options  like ['--force' => true]
     *
     * @return int  exit code, 0 = success
     */
    public function artisan(string $command, array $options = []): int
    {
        if ($this->noInteractionForExternCalls) {
            $options['--no-interaction'] = true;
        }

        $this->message(sprintf("Artisan: %s; options: %s", $command, json_encode($options)));

        return Artisan::call($command, $options);
    }

    /**
     * - deploy-env:build-frontend
     *
     * @return bool
     */
    public function runProcessArtisanBuildFrontend(): bool
    {
        return $this->artisan('deploy-env:build-frontend') === 0;
    }

    /**
     * @return bool
     */
    public function runProcessArtisanMigrate(): bool
    {
        return $this->artisan('migrate', ['--force' => true]) === 0;
    }

    /**
     * @return bool
     */
    public function runProcessDeployEnvUpdate(): bool
    {
        return $this->artisan('deploy-env:terraform-modules') === 0;
    }

    /**
     * Clears all caches: clear-compiled, optimize:clear, compiled views, route/config/view/cache:clear.
     * Errors are ignored, always true.
     *
     * @return bool
     */
    public function runProcessArtisanCacheClear(): bool
    {
        $this->artisan('clear-compiled');
        $this->artisan('optimize:clear');
        $this->runProcess('rm -rf storage/framework/views/*');
        $this->artisan('route:clear');
        $this->artisan('config:clear');
        $this->artisan('view:clear');
        $this->artisan('cache:clear');

        return true;
    }

    /**
     * sail artisan clear-compiled
     * sail artisan ide-helper:generate
     * sail artisan ide-helper:models -M
     * sail artisan ide-helper:meta
     *
     * @return bool
     */
    public function runProcessArtisanIdeHelpers(): bool
    {
        $this->artisan('clear-compiled');
        $this->artisan('ide-helper:generate');
        $this->artisan('ide-helper:models', ['-M' => true]);
        $this->artisan('ide-helper:meta');

        return true;
    }

    /**
     * warm up the view
     * - view:cache
     *
     * @return bool
     */
    public function runProcessArtisanViewCache(): bool
    {
        return $this->artisan('view:cache') === 0;
    }

    /**
     * Enables the maintenance mode with a secret (if not already in maintenance).
     *
     * @return bool
     */
    public function enableMaintenanceMode(): bool
    {
        if (!app()->isDownForMaintenance()) {
            $this->secretMaintenanceKey = uniqid('maintenance-');

            return $this->artisan('down', ['--secret' => $this->secretMaintenanceKey]) === 0;
        }

        return true;
    }

    /**
     * Disables the maintenance mode.
     *
     * @param  bool  $onlyWhenOwnShutdown  true: only if it was enabled by enableMaintenanceMode() of this process
     *
     * @return bool  true if the app is up now
     */
    public function disableMaintenanceMode(bool $onlyWhenOwnShutdown = true): bool
    {
        if (!app()->isDownForMaintenance()) {
            $this->secretMaintenanceKey = '';

            return true;
        }

        if (!$onlyWhenOwnShutdown || $this->secretMaintenanceKey) {
            $result = $this->artisan('up');
            $this->secretMaintenanceKey = '';

            return $result === 0;
        }

        return false;
    }

    /**
     * Prints how to access the site in maintenance mode.
     *
     * @return void
     */
    protected function showMaintenanceInfo(): void
    {
        if (app()->isDownForMaintenance()) {
            if ($this->secretMaintenanceKey) {
                $this->message("Your site is in maintenance currently. Secret key: $this->secretMaintenanceKey");
                $this->message("Type 'php artisan up' or check the site in maintenance mode by typing the following line in your browser:");
                $this->message(URL::to("/".$this->secretMaintenanceKey));
            } else {
                $this->message('The application is already in maintenance mode by a previous process.');
            }
        }
    }

    /**
     * All options of this command with a truthy value as list like ['--force'].
     *
     * @return array
     */
    protected function getEnabledOptions(): array
    {
        $result = [];
        foreach ($this->options() as $k => $v) {
            if ($v) {
                $result[] = '--'.$k;
            }
        }

        return $result;
    }

    /**
     * Display a clean table of current important .env values.
     */
    protected function showEnvVarsTable(array $envVars, string $title = 'Env Vars'): void
    {
        $this->newLine();
        $this->info($title);

        $data = [];
        foreach ($envVars as $var) {
            $data[] = [$var, env($var, '(NOT SET)')];
        }

        $this->table(['Variable', 'Current Value'], $data);
    }

    /**
     * Display a table of key/value pairs.
     */
    protected function showKeyValueTable(array $keyValues, string $title = 'Key/Values'): void
    {
        $this->newLine();
        $this->info($title);

        $data = [];
        foreach ($keyValues as $key => $value) {
            $data[] = [$key, is_scalar($value) || ($value === null) ? $value : json_encode($value)];
        }

        $this->table(['Key', 'Value'], $data);
    }

    /**
     * Clones/updates the modules and themes of YAML 'setup.requires' (the project's own), then 'setup.base_requires'
     * (the Mercy base). A name processed once is skipped later, so the project wins.
     * Each entry: type (module|theme), repository, vendor, version (tags, e.g. "^2.0") or branch_prefix/branch,
     * names[], dev_mode.
     *
     * @param  string  $destinationRootPath  if given, use this as project root (to deploy to external projects), also "git archive" will be used
     *
     * @return bool
     * @throws GitException
     */
    public function getRequires(string $destinationRootPath = ''): bool
    {
        $requires = [
            ...(array) ($this->getYamlConfigValue('setup.requires', []) ?? []),
            ...(array) ($this->getYamlConfigValue('setup.base_requires', []) ?? []),
        ];
        // if no requires exit, but no error ...
        if (!$requires) {
            return true;
        }

        /** @var RequireModuleService $requireModuleService */
        $requireModuleService = app(RequireModuleService::class);
        /** @var RequireThemeService $requireThemeService */
        $requireThemeService = app(RequireThemeService::class);

        // each every require block (vendor,type,branch,...)
        foreach ($requires as $require) {

            /**
             * $currentAddonService depends on type 'module' or 'theme'
             *
             * @var RequireModuleService|RequireThemeService $currentAddonService
             */
            $type = data_get($require, 'type');
            if (!($currentAddonService = ($type == 'module') ? $requireModuleService : (($type == 'theme') ? $requireThemeService : null))) {
                $this->error("Missing or invalid type (module or theme).");

                return false;
            }

            // repository has to exist
            $currentAddonService->allowProcess('git_pull', true);
            if (!($currentAddonService->repositorySource = data_get($require, 'repository'))) {
                $this->error("Missing repository.");

                return false;
            }

            // vendor has to exist
            if (!$currentAddonService->setVendor((string) data_get($require, 'vendor', ''))) {
                $this->error("Missing vendor name.");

                return false;
            }

            // tags by "version" (e.g. "^2.0"), otherwise the branch
            if ($constraint = (string) data_get($require, 'version', '') ?: data_get($require, 'branch_prefix', '').data_get($require, 'branch', '')) {
                $currentAddonService->currentRequiredConstraint = $constraint;
            }
            $this->comment(sprintf("Start requirement '%s' for vendor '%s' and version/branch '%s'", $type, $currentAddonService->getVendor(), $currentAddonService->currentRequiredConstraint));

            // test branches (git.branch / git.branches, usually in project/config/local.yaml)
            $testBranch = (string) $this->getYamlConfigValue('setup.git.branch', '');
            $testBranches = (array) ($this->getYamlConfigValue('setup.git.branches', []) ?? []);

            // loop required names (modules/themes) ...
            foreach (data_get($require, 'names', []) as $requireName) {

                // a branch set for this repo must exist, the common one is used where it exists
                $currentAddonService->preferredBranches = array_values(array_filter([
                    isset($testBranches[$requireName]) ? [(string) $testBranches[$requireName], true] : null,
                    $testBranch !== '' ? [$testBranch, false] : null,
                ]));

                $currentAddonService->allowProcess('dev_mode', $this->getBoolValue(data_get($require, 'dev_mode')));
                // dev environment (not the build): branches of existing clones belong to the developer
                $currentAddonService->allowProcess('keep_branches', $this->getBoolValue($this->getYamlConfigValue('setup.kernel.dev_mode', false)));
                $currentAddonService->allowProcess('is_bare_deployment', $destinationRootPath !== '');

                if ($currentAddonService->requireItemByName($requireName, $destinationRootPath)) {

                    if ($updatedModulesCount = count($currentAddonService->changedRepositories)) {
                        $this->comment(sprintf("%s '%s': %s were updated.", $type, $requireName, $updatedModulesCount));
                    } else {
                        $this->comment(sprintf("%s '%s': (no changes)", $type, $requireName));
                    }

                } else {
                    $this->error(sprintf("%s '%s': requirement failed!", $type, $requireName));

                    return false;
                }
            }
            $this->comment("");
        }

        return true;
    }

    /**
     * chmod 775 and chown -R WWWUSER:WWWGROUP for storage and bootstrap/cache.
     *
     * @return bool
     */
    protected function refreshFilePermissions(): bool
    {
        try {
            $this->comment('Fixing file permissions for storage and cache...');
            // chown expects user:group
            $owner = escapeshellarg(env('WWWUSER', 1000).':'.env('WWWGROUP', 1000));
            foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
                if (is_dir($path)) {
                    // 775, so the group (www-data/sail) is allowed to write
                    chmod($path, 0775);
                    shell_exec('chown -R '.$owner.' '.escapeshellarg($path));
                }
            }
            $this->info('Permissions updated.');
        } catch (Exception $e) {
            $this->warn('Could not auto-fix file permissions. Please check manually.');

            return false;
        }

        return true;
    }

    /**
     * Parse all loaded yaml configs by resolving their vars.
     *
     * @return void
     * @throws Exception
     */
    public function parseConfigs(): void
    {
        $this->deployment->yamlConfigs = app(DeploymentService::class)->parseEverythingFromArray($this->deployment->yamlConfigs);
    }

    /**
     * Final health check: fixes permissions, checks the webserver on 127.0.0.1:80 and clears config/view cache.
     *
     * @return bool  false if the webserver does not respond
     */
    public function performHealthCheck(): bool
    {
        $result = true;
        $this->newLine();
        $this->info('--- Health Check ---');

        // Permissions for Storage and Cache
        $this->refreshFilePermissions();

        // port validation (loopback)
        $this->comment('Checking if webserver is responding ...');
        $host = '127.0.0.1';
        $port = 80;
        $connection = @fsockopen($host, $port, $errno, $errorString, 2);

        if ($connection) {
            $this->info("Webserver is alive on internal port $port.");
            fclose($connection);
        } else {
            $this->error("Webserver not responding on port $port! (502/503 risk)");
            $result = false;
        }

        $this->call('config:clear');
        $this->call('view:clear');

        $this->newLine();
        if ($result) {
            $this->info('Server is healthy.');
        } else {
            $this->error('Server is not healthy.');
        }

        return $result;
    }

    /**
     * Detect if the current environment is a Proxmox/QEMU Virtual Machine.
     *
     * @return bool
     */
    private function isProxmoxEnvironment(): bool
    {
        // Check CPU info for QEMU signature
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuInfo && str_contains($cpuInfo, 'QEMU Virtual CPU')) {
            return true;
        }

        // Check DMI vendor information if accessible
        $vendor = @file_get_contents('/sys/class/dmi/id/board_vendor');
        if ($vendor && (str_contains($vendor, 'Proxmox') || str_contains($vendor, 'QEMU'))) {
            return true;
        }

        return false;
    }

}
