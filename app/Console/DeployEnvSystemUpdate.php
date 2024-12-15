<?php

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;

class DeployEnvSystemUpdate extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:system-update {--dev-mode} {--debug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs composer update, npm update, npm build, migrate, clearing caches, build frontend. Inclusive maintenance mode. Run it with --no-interaction to chill.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        return $this->runProcessSystemUpdate();
    }

}
