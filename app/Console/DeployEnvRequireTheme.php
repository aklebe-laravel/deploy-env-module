<?php

namespace Modules\DeployEnv\app\Console;

use CzProject\GitPhp\GitException;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\RequireThemeService;
use Symfony\Component\Console\Command\Command as CommandResult;

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
    protected $description = 'Require comma seperated themes or update all themes already installed and additional registered in config mercy-dependencies';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws GitException
     */
    public function handle(): int
    {
        $automaticProcesses = !$this->option('no-auto');

        // default one item with null for all modules and themes at once
        $themeNames = $this->argument('theme_name') ?? [null];
        if (!is_array($themeNames)) {
            $themeNames = array_map('trim', explode(",", $themeNames));
        }

        // debug enable/disable (BEFORE services allocated!)
        app('system_base')->switchEnvDebug($this->option('debug'));

        /** @var RequireThemeService $requireThemeService */
        $requireThemeService = app(RequireThemeService::class);

        // Allow git pull?
        if ($this->option('no-git-pull')) {
            $requireThemeService->allowProcess('git_pull', false);
        }

        $requireThemeService->allowProcess('dev_mode', $this->option('dev-mode'));

        foreach ($themeNames as $themeName) {
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

                return CommandResult::FAILURE;
            }
        }

        return CommandResult::SUCCESS;
    }

}
