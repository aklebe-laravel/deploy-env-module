<?php

namespace Modules\DeployEnv\app\Console;

use CzProject\GitPhp\GitException;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\RequireModuleService;
use Symfony\Component\Console\Command\Command as CommandResult;

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
    protected $description = 'Require comma seperated modules or update all modules already installed and additional registered in config mercy-dependencies';

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
        $moduleNames = $this->argument('module_name') ?? [null];
        if (!is_array($moduleNames)) {
            $moduleNames = array_map('trim', explode(",", $moduleNames));
        }

        // debug enable/disable (BEFORE services allocated!)
        app('system_base')->switchEnvDebug($this->option('debug'));

        /** @var RequireModuleService $requireModuleService */
        $requireModuleService = app(RequireModuleService::class);

        // Allow git pull?
        if ($this->option('no-git-pull')) {
            $requireModuleService->allowProcess('git_pull', false);
        }

        $requireModuleService->allowProcess('dev_mode', $this->option('dev-mode'));

        $changesMade = false;
        foreach ($moduleNames as $moduleName) {
            $this->printHeadLine("Creating/updating Module".($moduleName ? " $moduleName" : 's'));

            if ($requireModuleService->requireItemByName($moduleName)) {

                if ($updatedModulesCount = count($requireModuleService->changedRepositories)) {
                    $changesMade = true;
                    $this->info(sprintf("%s modules were updated.", $updatedModulesCount));
                    $this->line(print_r($requireModuleService->changedRepositories, true));
                } else {
                    $this->info("Everything was already up-to-date.");
                }

            } else {
                $this->error("Module requirement failed!");

                return CommandResult::FAILURE;
            }
        }

        // since v11 composer dump-autoload is needed for new modules
        if ($automaticProcesses && $changesMade) {
            if ($this->composerDumpAutoLoadNeededForNewModules) {
                $r = $this->runProcessComposerDump();
                if ($r->failed()) {
                    return CommandResult::FAILURE;
                }
            }
        }

        return CommandResult::SUCCESS;
    }

}
