<?php

namespace Modules\DeployEnv\app\Services;


use Modules\DeployEnv\app\Services\Base\RequireBaseService;
use Modules\SystemBase\app\Services\Base\AddonObjectService;
use Modules\SystemBase\app\Services\ThemeService;

class RequireThemeService extends RequireBaseService
{
    /**
     * Overwrite this!
     * Currently one them: 'module', 'theme'
     *
     * @var string
     */
    protected string $addonType = 'theme';

    /**
     * @var ?AddonObjectService|ThemeService
     */
    protected AddonObjectService|ThemeService|null $addonObjectService;

    /**
     *
     */
    public function __construct(ThemeService $themeService)
    {
        parent::__construct();

        $this->addonObjectService = $themeService;
    }

}