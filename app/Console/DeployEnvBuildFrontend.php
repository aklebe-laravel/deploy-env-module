<?php

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvBuildFrontend extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:build-frontend {--dev}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '1) Cache clear process 2) build mercy assets 3) build frontend by npm build 4) warm up view cache';

    /**
     * @return int
     */
    public function handle(): int
    {
        if (!$this->runProcessArtisanCacheClear()) {
            return CommandResult::FAILURE;
        }

        if ($this->artisan('deploy-env:build-mercy-assets') !== 0) {
            return CommandResult::FAILURE;
        }

        if (!$this->runProcessNpmBuild((bool) $this->option('dev'))) {
            return CommandResult::FAILURE;
        }

        if (!$this->runProcessArtisanViewCache()) {
            return CommandResult::FAILURE;
        }

        return CommandResult::SUCCESS;
    }

}
