<?php

namespace Modules\DeployEnv\app\Providers;

use Modules\DeployEnv\app\Console\DeployEnvAutoImport;
use Modules\DeployEnv\app\Console\DeployEnvBuildFrontend;
use Modules\DeployEnv\app\Console\DeployEnvBuildMercyAssets;
use Modules\DeployEnv\app\Console\DeployEnvDbImport;
use Modules\DeployEnv\app\Console\DeployEnvFixGitIgnore;
use Modules\DeployEnv\app\Console\DeployEnvFormer;
use Modules\DeployEnv\app\Console\DeployEnvMakeModule;
use Modules\DeployEnv\app\Console\DeployEnvModuleInfo;
use Modules\DeployEnv\app\Console\DeployEnvTerraformModules;
use Modules\DeployEnv\app\Console\MercyInfoCommand;
use Modules\DeployEnv\app\Console\MercyApp;
use Modules\DeployEnv\app\Console\MercySyncNpmCommand;
use Modules\SystemBase\app\Providers\Base\ModuleBaseServiceProvider;

class DeployEnvServiceProvider extends ModuleBaseServiceProvider
{
    /**
     * @var string $moduleName
     */
    protected string $moduleName = 'DeployEnv';

    /**
     * @var string $moduleNameLower
     */
    protected string $moduleNameLower = 'deploy-env';

    /**
     * Registers the console commands.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DeployEnvBuildFrontend::class,
                DeployEnvBuildMercyAssets::class,
                DeployEnvFixGitIgnore::class,
                DeployEnvTerraformModules::class,
                DeployEnvMakeModule::class,
                DeployEnvModuleInfo::class,
                DeployEnvDbImport::class,
                DeployEnvAutoImport::class,
                DeployEnvFormer::class,
                MercyApp::class,
                MercyInfoCommand::class,
                MercySyncNpmCommand::class,
            ]);
        }
    }

    /**
     * Registers the route and event providers.
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
    }

}
