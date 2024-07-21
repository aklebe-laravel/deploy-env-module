<?php

namespace Modules\DeployEnv\app\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportContent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var string
     */
    public string $type = '';

    /**
     * @var array
     */
    public array $sourcePathInfo = [];

    /**
     * @var int
     */
    public int $currentRowNumber = 0;

    /**
     * Create a new event instance.
     */
    public function __construct(string $type, array $sourcePathInfo)
    {
        $this->type = $type;
        $this->sourcePathInfo = $sourcePathInfo;
    }
}
