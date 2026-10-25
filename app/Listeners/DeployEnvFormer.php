<?php

namespace Modules\DeployEnv\app\Listeners;

/**
 * Default listener, does nothing. Modules like Form or DataTable register their own listeners.
 */
class DeployEnvFormer
{
    /**
     * Handle the event.
     *
     * @param  \Modules\DeployEnv\app\Events\DeployEnvFormer  $event
     *
     * @return void
     */
    public function handle(\Modules\DeployEnv\app\Events\DeployEnvFormer $event): void
    {
        //
    }
}
