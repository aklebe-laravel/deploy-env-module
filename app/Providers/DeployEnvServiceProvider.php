<?php

namespace Modules\DeployEnv\app\Providers;

use Modules\DeployEnv\app\Console\DeployEnvBuildFrontend;
use Modules\DeployEnv\app\Console\DeployEnvBuildMercyAssets;
use Modules\DeployEnv\app\Console\DeployEnvClearCaches;
use Modules\DeployEnv\app\Console\DeployEnvDbImport;
use Modules\DeployEnv\app\Console\DeployEnvFixGitIgnore;
use Modules\DeployEnv\app\Console\DeployEnvFormer;
use Modules\DeployEnv\app\Console\DeployEnvMakeModule;
use Modules\DeployEnv\app\Console\DeployEnvModuleInfo;
use Modules\DeployEnv\app\Console\DeployEnvRequireDependencies;
use Modules\DeployEnv\app\Console\DeployEnvRequireModule;
use Modules\DeployEnv\app\Console\DeployEnvRequireTheme;
use Modules\DeployEnv\app\Console\DeployEnvSystemUpdate;
use Modules\DeployEnv\app\Console\DeployEnvTerraformModules;
use Modules\DeployEnv\app\Console\DeployEnvUpdateBashScriptEnv;
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
     * Boot the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        $this->commands([
            DeployEnvBuildFrontend::class,
            DeployEnvClearCaches::class,
            DeployEnvBuildMercyAssets::class,
            DeployEnvSystemUpdate::class,
            DeployEnvFixGitIgnore::class,
            DeployEnvTerraformModules::class,
            DeployEnvMakeModule::class,
            DeployEnvRequireModule::class,
            DeployEnvRequireTheme::class,
            DeployEnvRequireDependencies::class,
            DeployEnvModuleInfo::class,
            DeployEnvDbImport::class,
            DeployEnvFormer::class,
            DeployEnvUpdateBashScriptEnv::class,
        ]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();

        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
    }

}
