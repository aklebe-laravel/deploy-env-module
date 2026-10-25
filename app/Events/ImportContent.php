<?php

namespace Modules\DeployEnv\app\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\SystemBase\app\Models\JsonViewResponse;

/**
 * Dispatched for every file to import (see deploy-env:auto-import).
 * Listeners check $type and import the content, see Listeners\ImportContent.
 */
class ImportContent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Import type in singular like 'product' or 'user'.
     *
     * @var string
     */
    public string $type = '';

    /**
     * pathinfo() of the file.
     *
     * @var array
     */
    public array $sourcePathInfo = [];

    /**
     * Optional user the imported items are assigned to.
     *
     * @var int|null
     */
    public ?int $forceUserId = null;

    /**
     * Results/messages of the listeners.
     *
     * @var JsonViewResponse|null
     */
    public ?JsonViewResponse $results = null;

    /**
     * @param  string                 $type
     * @param  array                  $sourcePathInfo
     * @param  int|null               $forceUserId
     * @param  JsonViewResponse|null  $resultObject  null: a new one is created
     */
    public function __construct(string $type, array $sourcePathInfo, ?int $forceUserId = null, $resultObject = null)
    {
        $this->type = $type;
        $this->sourcePathInfo = $sourcePathInfo;
        $this->forceUserId = $forceUserId;

        if ($resultObject === null) {
            $resultObject = new JsonViewResponse();
        }
        $this->results = $resultObject;
    }
}
