<?php

namespace Modules\DeployEnv\app\Services;

use Modules\DeployEnv\app\Services\Base\RequireBaseService;
use Modules\SystemBase\app\Services\Base\AddonObjectService;
use Modules\SystemBase\app\Services\ModuleService;

/**
 * Clones/updates module repositories, see RequireBaseService.
 */
class RequireModuleService extends RequireBaseService
{
    /**
     * @var string
     */
    protected string $addonType = 'module';

    /**
     * @var ?AddonObjectService|ModuleService
     */
    protected AddonObjectService|ModuleService|null $addonObjectService;

    /**
     * @param  ModuleService  $moduleService
     */
    public function __construct(ModuleService $moduleService)
    {
        parent::__construct();

        $this->addonObjectService = $moduleService;
    }

}