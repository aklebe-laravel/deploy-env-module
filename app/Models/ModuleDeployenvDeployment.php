<?php

namespace Modules\DeployEnv\app\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A deployment already done: module (null for the app), version (deployment ident) and batch.
 *
 * @mixin IdeHelperModuleDeployenvDeployment
 */
class ModuleDeployenvDeployment extends Model
{
    /**
     * @var array
     */
    protected $guarded = [];

}
