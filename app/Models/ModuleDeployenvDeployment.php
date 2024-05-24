<?php

namespace Modules\DeployEnv\app\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\DeployEnv\Models\IdeHelperModuleDeployenvDeployment;


/**
 * @mixin IdeHelperModuleDeployenvDeployment
 */
class ModuleDeployenvDeployment extends Model
{
    /**
     * @var array
     */
    protected $guarded = [];

}
