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
    protected $signature = 'deploy-env:build-frontend';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '1) Cache clear process 2) build mercy assets 3) build frontend by npm build 4) warm up view cache';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $result = $this->runProcessArtisanCacheClear();
        if (!$result->successful()) {
            return CommandResult::FAILURE;
        }

        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:build-mercy-assets'));
        if (!$r->successful()) {
            return CommandResult::FAILURE;
        }

        $result = $this->runProcessNpmBuild();
        if (!$result->successful()) {
            return CommandResult::FAILURE;
        }

        $currentUpdateResult = $this->runProcessArtisanViewCache();
        if ($currentUpdateResult->failed()) {
            return CommandResult::FAILURE;
        }

        return $result->successful() ? CommandResult::SUCCESS : CommandResult::FAILURE;
    }

}
