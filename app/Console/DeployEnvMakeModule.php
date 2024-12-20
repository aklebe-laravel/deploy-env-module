<?php

namespace Modules\DeployEnv\app\Console;

use Exception;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\MakeModuleService;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvMakeModule extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:make-module {module_name} {--update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a module and will prepare all stuff needed.';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        if (!($moduleName = $this->argument('module_name'))) {
            return CommandResult::FAILURE;
        }

        $canUpdate = (bool) $this->option('update');

        /** @var MakeModuleService $makeModuleService */
        $makeModuleService = app(MakeModuleService::class);
        if ($makeModuleService->makeModule($moduleName, $canUpdate)) {
            $this->info("Make Module successful!");
        } else {
            $this->error("Make Module failed!");

            return CommandResult::FAILURE;
        }

        // since v11 composer dump-autoload is needed for new modules
        if ($this->composerDumpAutoLoadNeededForNewModules) {
            $r = $this->runProcessComposerDump();
            if ($r->failed()) {
                return CommandResult::FAILURE;
            }
        }

        return CommandResult::SUCCESS;
    }

}
