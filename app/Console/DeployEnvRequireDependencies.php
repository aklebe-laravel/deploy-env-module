<?php

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvRequireDependencies extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:require-dependencies {--dev-mode} {--debug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Require all modules and all themes already installed and additional registered in config mercy-dependencies. Also runs deploy-env:system-update after all task were successfully finished.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        // add options to exists
        $options = array_merge($this->getEnabledOptions(), ['--no-interaction']);

        // first modules
        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:require-module', $options));
        if (!$r->successful()) {
            return CommandResult::FAILURE;
        }

        // themes after
        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:require-theme', $options));
        if (!$r->successful()) {
            return CommandResult::FAILURE;
        }

        // system update on success
        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:system-update', $options));
        if (!$r->successful()) {
            return CommandResult::FAILURE;
        }

        return CommandResult::SUCCESS;
    }

}
