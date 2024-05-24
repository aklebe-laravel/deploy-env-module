<?php

namespace Modules\DeployEnv\app\Console;

use CzProject\GitPhp\GitException;
use Illuminate\Console\Command;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\RequireModuleService;

class DeployEnvRequireModule extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:require-module {module_name?} {--no-git-pull} {--dev-mode} {--debug} {--no-auto}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Require a module or update all modules already installed and additional registered in config mercy-dependencies';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws GitException
     */
    public function handle()
    {
        $automaticProcesses = !$this->option('no-auto');

        $moduleName = $this->argument('module_name');

        // debug enable/disable (BEFORE services allocated!)
        app('system_base')->switchEnvDebug($this->option('debug'));

        /** @var RequireModuleService $requireModuleService */
        $requireModuleService = app(RequireModuleService::class);

        // Allow git pull?
        if ($this->option('no-git-pull')) {
            $requireModuleService->allowProcess('git_pull', false);
        }

        $requireModuleService->allowProcess('dev_mode', $this->option('dev-mode'));

        $this->printHeadLine("Creating/updating Module".($moduleName ? " $moduleName" : 's'));

        if ($requireModuleService->requireItemByName($moduleName)) {

            if ($updatedModulesCount = count($requireModuleService->changedRepositories)) {
                $this->info(sprintf("%s modules were updated.", $updatedModulesCount));
                $this->line(print_r($requireModuleService->changedRepositories, true));
            } else {
                $this->info("Everything was already up-to-date.");
            }

        } else {
            $this->error("Module requirement failed!");
            return Command::FAILURE;
        }

        // since v11 composer dump-autoload is needed for new modules
        if ($this->composerDumpAutoLoadNeededForNewModules) {
            $r = $this->runProcessComposerDump();
            if ($r->failed()) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

}
