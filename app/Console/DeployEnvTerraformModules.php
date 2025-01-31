<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Support\Facades\Cache;
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
    protected $signature = 'deploy-env:terraform-modules {--module_name=} {--module_version=} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploying relevant database data and/or files for all modules by using modules own Config/module-deploy-env.php';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        Cache::flush();

        $moduleName = $this->option('module_name') ?: '*';
        $moduleVersion = $this->option('module_version') ?: '';
        $force = (bool)$this->option('force');

        /** @var DeploymentService $deploymentService */
        $deploymentService = app(DeploymentService::class);
        if ($deploymentService->runTerraformModules($moduleName, $moduleVersion, $force)) {
            $this->info("Deployment successful!");
        } else {
            $this->error("Deployment failed!");
            return CommandResult::FAILURE;
        }

        return CommandResult::SUCCESS;
    }

}
