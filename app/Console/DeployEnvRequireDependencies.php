<?php

namespace Modules\DeployEnv\app\Console;

use CzProject\GitPhp\GitException;
use Illuminate\Console\Command;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;

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
     * @throws GitException
     */
    public function handle()
    {
        // add options to exists
        $options = array_merge($this->getEnabledOptions(), ['--no-interaction']);

        // first modules
        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:require-module', $options));
        if (!$r->successful()) {
            return Command::FAILURE;
        }

        // themes after
        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:require-theme', $options));
        if (!$r->successful()) {
            return Command::FAILURE;
        }

        // system update on success
        $r = $this->runProcess($this->getFinalArtisanProcessCmd('deploy-env:system-update', $options));
        if (!$r->successful()) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

}
