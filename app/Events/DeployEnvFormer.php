<?php

namespace Modules\DeployEnv\app\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeployEnvFormer
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $moduleName = '';
    public array $classes = [];

    /**
     * Create a new event instance.
     */
    public function __construct(string $moduleName, array $classes)
    {
        $this->moduleName = $moduleName;
        $this->classes = $classes;
    }

    // /**
    //  * Get the channels the event should be broadcast on.
    //  */
    // public function broadcastOn(): array
    // {
    //     return [
    //         new PrivateChannel('channel-name'),
    //     ];
    // }
}
