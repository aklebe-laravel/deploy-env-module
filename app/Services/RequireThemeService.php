<?php

namespace Modules\DeployEnv\app\Services;

use Modules\DeployEnv\app\Services\Base\RequireBaseService;
use Modules\SystemBase\app\Services\Base\AddonObjectService;
use Modules\SystemBase\app\Services\ThemeService;

/**
 * Clones/updates theme repositories, see RequireBaseService.
 */
class RequireThemeService extends RequireBaseService
{
    /**
     * @var string
     */
    protected string $addonType = 'theme';

    /**
     * @var ?AddonObjectService|ThemeService
     */
    protected AddonObjectService|ThemeService|null $addonObjectService;

    /**
     * @param  ThemeService  $themeService
     */
    public function __construct(ThemeService $themeService)
    {
        parent::__construct();

        $this->addonObjectService = $themeService;
    }

}