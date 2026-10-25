<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Support\Facades\File;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\DeploymentService;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvTerraformModules extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:terraform-modules
                            {--module_name= : The module name, or "'.DeploymentService::BUSINESS_IMPORTS_IDENT.'" for business imports only, or empty for everything}
                            {--module_version= : Run this deployment ident only, like 0003}
                            {--force : Run again, even if already deployed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploying relevant database data and/or files for all modules by using modules own "config/'.DeploymentService::MODULE_CONFIG_IDENT.'.php".';

    /**
     * 1) modules and app (unless business imports only)
     * 2) business imports: every file in storage/app/imports/module-deploy-env/ (sorted by name)
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->option('module_name') ?: '*';
        $moduleVersion = $this->option('module_version') ?: '';
        $force = (bool) $this->option('force');

        /** @var DeploymentService $deploymentService */
        $deploymentService = app(DeploymentService::class);

        if ($moduleName !== DeploymentService::BUSINESS_IMPORTS_IDENT) { // if not business imports only ...
            // 1) all modules and the app itself
            if ($deploymentService->runTerraformModules($moduleName, $moduleVersion, $force)) {
                $this->info("Deployment successful!");
            } else {
                $this->error("Deployment failed!");

                return CommandResult::FAILURE;
            }
        }

        // everything (*) or if explicit specified, business imports will deploy their configs ...
        if ($moduleName === '*' || $moduleName === DeploymentService::BUSINESS_IMPORTS_IDENT) {
            // 2) the business imports location for this instance
            $businessLocalConfigPath = storage_path('app/imports');
            $dir = $businessLocalConfigPath.'/'.DeploymentService::MODULE_CONFIG_IDENT;
            if (is_dir($dir)) {
                $files = collect(File::files($dir))
                    ->filter(fn($file) => $file->getExtension() === 'php')
                    ->sortBy(fn($file) => $file->getFilename())
                    ->all();
                foreach ($files as $file) {
                    $config = include $file->getPathname();
                    $configDeployments = data_get($config, 'deployments', []);

                    if (!$deploymentService->runTerraformData($configDeployments, DeploymentService::BUSINESS_IMPORTS_IDENT, $moduleVersion, $force, $businessLocalConfigPath)) {
                        $this->error(sprintf("Business import deployment failed: %s", $file->getFilename()));

                        return CommandResult::FAILURE;
                    }
                }
            } else {
                logger("Skipping business import, no folder found: $dir", [__METHOD__]);
            }
        }

        return CommandResult::SUCCESS;
    }
}
