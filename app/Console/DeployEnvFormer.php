<?php
declare(strict_types = 1);

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
    protected $description = 'Create form relevant classes (form, datatable, model) for a module by the event DeployEnvFormer. Without --classes all models of the module are used.';

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

        $moduleName = data_get($moduleInfo, 'studly_name', '');

        if ($classes = $this->option('classes') ?: '') {
            $classes = explode(',', $classes);
        } else {
            $classes = $this->findModels($moduleName);
        }

        $classes = array_map(fn($val) => Str::studly(trim($val)), $classes);

        // listeners of other modules (like Form, DataTable) create the files
        DeployEnvFormerEvent::dispatch($moduleName, $classes);

        return CommandResult::SUCCESS;
    }

    /**
     * Class names of all PHP files directly in the model folder of the module.
     *
     * @param  string  $moduleName
     *
     * @return array
     */
    private function findModels(string $moduleName): array
    {
        $result = [];

        $path = ModuleService::getPath('', $moduleName, 'model');

        app('system_base_file')->runDirectoryFiles($path, function ($sourceFile, $sourcePathInfo) use (&$result) {

            // @todo: check the file defines a class
            if ($sourceFile && $sourcePathInfo['filename']) {
                $result[] = $sourcePathInfo['filename'];
            }

        }, 0, regexWhitelist: ['/\\.php$/']);

        return $result;
    }

}
