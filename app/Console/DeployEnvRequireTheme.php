<?php

namespace Modules\DeployEnv\app\Console;

use CzProject\GitPhp\GitException;
use Illuminate\Console\Command;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\RequireThemeService;

class DeployEnvRequireTheme extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:require-theme {theme_name?} {--no-git-pull} {--dev-mode} {--debug} {--no-auto}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Require a Theme or update all themes already installed and additional registered in config mercy-dependencies';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws GitException
     */
    public function handle()
    {
        $automaticProcesses = !$this->option('no-auto');

        $themeName = $this->argument('theme_name');

        // debug enable/disable (BEFORE services allocated!)
        app('system_base')->switchEnvDebug($this->option('debug'));

        /** @var RequireThemeService $requireThemeService */
        $requireThemeService = app(RequireThemeService::class);

        // Allow git pull?
        if ($this->option('no-git-pull')) {
            $requireThemeService->allowProcess('git_pull', false);
        }

        $requireThemeService->allowProcess('dev_mode', $this->option('dev-mode'));

        $this->printHeadLine("Creating/updating Theme".($themeName ? " $themeName" : 's'));

        if ($requireThemeService->requireItemByName($themeName)) {

            if ($updatedThemesCount = count($requireThemeService->changedRepositories)) {
                $this->info(sprintf("%s themes were updated.", $updatedThemesCount));
                $this->line(print_r($requireThemeService->changedRepositories, true));
            } else {
                $this->info("Everything was already up-to-date.");
            }

        } else {
            $this->error("Theme requirement failed!");
        }

        return Command::SUCCESS;
    }

}
