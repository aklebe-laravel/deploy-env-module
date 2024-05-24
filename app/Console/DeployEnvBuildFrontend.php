<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Console\Command;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;

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
    public function handle()
    {
        $result = $this->runProcessArtisanCacheClear();
        if (!$result->successful()) {
            return Command::FAILURE;
        }

        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:build-mercy-assets'));
        if (!$r->successful()) {
            return Command::FAILURE;
        }

        $result = $this->runProcessNpmBuild();
        if (!$result->successful()) {
            return Command::FAILURE;
        }

        $currentUpdateResult = $this->runProcessArtisanViewCache();
        if ($currentUpdateResult->failed()) {
            return Command::FAILURE;
        }

        return $result->successful() ? Command::SUCCESS : Command::FAILURE;
    }

}
