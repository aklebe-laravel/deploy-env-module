<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class MercyApp extends DeployEnvBase
{
    /**
     * @var string
     */
    protected $signature = 'mercy:app {services}';

    /**
     * @var string
     */
    protected $description = 'Runs comma separated services in this order: cache, ide, view, build-frontend, update, migrate, require, files, healthy, test (respecting resources/setup/*.yaml)';

    /**
     * Runs the services in the given order, stops at the first failing one.
     *
     * @return int
     */
    public function handle(): int
    {
        $argServices = collect(explode(',', $this->argument('services')))->map(fn($item) => trim($item));

        // preparing all configs with getDeployment() ...
        $this->getDeployment();

        $services = [
            'cache'          => function () {
                return $this->runProcessArtisanCacheClear();
            },
            'ide'            => function () {
                return $this->runProcessArtisanIdeHelpers();
            },
            'view'           => function () {
                return $this->runProcessArtisanViewCache();
            },
            'build-frontend' => function () {
                return $this->runProcessArtisanBuildFrontend();
            },
            'update'         => function () {
                return $this->runProcessUpdateSystem();
            },
            'migrate'        => function () {
                return $this->runProcessMigrateAndDeployEnv();
            },
            'require'        => function () {
                return $this->getRequires();
            },
            'files'          => function () {
                return $this->refreshFilePermissions();
            },
            'healthy'        => function () {
                return $this->performHealthCheck();
            },
            'test'           => function () {
                return $this->callTest();
            },
        ];

        foreach ($argServices as $argService) {
            if (isset($services[$argService])) {
                if ($services[$argService]() === false) {
                    return CommandResult::FAILURE;
                }
            } else {
                $this->warn(sprintf("Service '%s' not found.", $argService));
            }
        }

        return CommandResult::SUCCESS;
    }

    /**
     * Prints some app config values to check the config is loaded.
     *
     * @return bool
     */
    private function callTest(): bool
    {
        // never print secrets like app.key
        $this->comment(config('app.name').'-'.config('app.version').'-'.config('app.env'));

        return true;
    }

}
