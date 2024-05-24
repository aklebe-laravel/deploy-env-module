<?php

namespace Modules\DeployEnv\app\Services;

use Modules\DeployEnv\app\Services\Base\RequireBaseService;
use Modules\SystemBase\app\Services\Base\AddonObjectService;
use Modules\SystemBase\app\Services\ModuleService;

class RequireModuleService extends RequireBaseService
{
    /**
     * Overwrite this!
     * Currently one them: 'module', 'theme'
     *
     * @var string
     */
    protected string $addonType = 'module';

    /**
     * @var ?AddonObjectService|ModuleService
     */
    protected AddonObjectService|ModuleService|null $addonObjectService;

    /**
     *
     */
    public function __construct(ModuleService $moduleService)
    {
        parent::__construct();

        $this->addonObjectService = $moduleService;
    }

}