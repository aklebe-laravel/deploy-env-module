<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Support\Str;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\SystemBase\app\Services\ModuleService;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvModuleInfo extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:module-info {module_name?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Info about module(s).';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        /** @var ModuleService $moduleService */
        $moduleService = app(ModuleService::class);

        if ($moduleName = $this->argument('module_name')) {
            $this->message(print_r($moduleService->getItemInfo($moduleName), true));
        } else {
            $this->message("Installed modules listed by priority:");
            foreach ($moduleService->getItemInfoList(false) as $module) {
                $this->message(sprintf("[%s] %s %s %s", data_get($module, 'is_enabled') ? 'x' : ' ',
                    Str::padRight(data_get($module, 'studly_name'), 20), Str::padRight(data_get($module, 'snake_name'), 20), data_get($module, 'priority')));
            }
        }

        return CommandResult::SUCCESS;
    }

}
