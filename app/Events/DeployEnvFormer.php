<?php

namespace Modules\DeployEnv\app\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by deploy-env:former. Listeners create form/datatable/model files.
 */
class DeployEnvFormer
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Module studly name.
     *
     * @var string
     */
    public string $moduleName = '';

    /**
     * Class names in studly case.
     *
     * @var array
     */
    public array $classes = [];

    /**
     * @param  string  $moduleName
     * @param  array   $classes
     */
    public function __construct(string $moduleName, array $classes)
    {
        $this->moduleName = $moduleName;
        $this->classes = $classes;
    }
}
