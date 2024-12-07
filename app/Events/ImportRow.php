<?php

namespace Modules\DeployEnv\app\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\DeployEnv\app\Events\ImportContent as ImportContentEvent;

class ImportRow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Contains columns of the row
     *
     * @var array
     */
    public array $row = [];

    /**
     * @var ImportContent
     */
    public ImportContentEvent $importContentEvent;

    /**
     * Create a new event instance.
     */
    public function __construct(ImportContent $importContentEvent, array $row)
    {
        $this->importContentEvent = $importContentEvent;
        $this->row = $row;
    }
}
