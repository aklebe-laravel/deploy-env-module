<?php

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvClearCaches extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:cc';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clearing redis, routing, config, view and livewire caches.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $result = $this->runProcessArtisanCacheClear();
        return $result->successful() ? CommandResult::SUCCESS : CommandResult::FAILURE;
    }

}
