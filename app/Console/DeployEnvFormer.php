<?php

namespace Modules\DeployEnv\app\Console;

use Exception;
use Illuminate\Support\Str;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Events\DeployEnvFormer as DeployEnvFormerEvent;
use Modules\SystemBase\app\Services\ModuleService;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvFormer extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:former {module_name} {--classes=} {--no-dt} {--no-model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create form relevant classes for module inclusive datatable';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        if (!($moduleName = $this->argument('module_name'))) {
            $this->error("Missing module_name!");

            return CommandResult::FAILURE;
        }

        $moduleService = app('system_base_module');
        $moduleInfo = $moduleService->getItemInfo($moduleName);
        if (!data_get($moduleInfo, 'is_installed', false)) {
            $this->error(sprintf("Unknown module %s!", $moduleName));

            return CommandResult::FAILURE;
        }

        // $this->info(print_r($moduleInfo, true));

        $moduleName = data_get($moduleInfo, 'studly_name', '');

        if ($classes = $this->option('classes') ?: '') {
            $classes = explode(',', $classes);
        } else {
            $classes = $this->findModels($moduleName);
        }

        $classes = array_map(function ($val) {
            return Str::studly($val);
        }, $classes);

        // $this->info(print_r($classes, true));

        // Call listeners for events
        DeployEnvFormerEvent::dispatch($moduleName, $classes);

        return CommandResult::SUCCESS;
    }

    /**
     * @param  string  $moduleName
     *
     * @return array
     */
    private function findModels(string $moduleName): array
    {
        $result = [];

        $path = ModuleService::getPath('', $moduleName, 'model');
        $this->info($path);

        app('system_base_file')->runDirectoryFiles($path, function ($sourceFile, $sourcePathInfo) use (&$result) {
            $result[] = $sourcePathInfo['filename'];
        }, 0);

        return $result;
    }

}
