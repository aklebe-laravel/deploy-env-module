<?php

namespace Modules\DeployEnv\app\Services;

use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\ModuleService;

class BashScriptsService extends BaseService
{
    /**
     * @return bool
     * @throws \Exception
     */
    public function updateBashScripts(): bool
    {
        $moduleName = 'DeployEnv';

        // get stubs path
        /** @var MakeModuleService $makeModuleService */
        $makeModuleService = app(MakeModuleService::class);
        $pathRootTemplate = ModuleService::getPath('module-stubs/UpdateBashScripts', $moduleName, 'resources');

        // generate the files
        if ($makeModuleService->generateModuleFiles($moduleName, true, $pathRootTemplate, base_path(), true)) {
            // $this->info("DataTable files successful generated!");
        } else {
            $this->error("Failed to generate bash-scripts files!");
            return false;
        }

        return true;
    }
}