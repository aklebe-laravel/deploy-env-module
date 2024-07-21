<?php

namespace Modules\DeployEnv\app\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\DeployEnv\app\Events\ImportContent as ImportContentEvent;
use Modules\DeployEnv\app\Events\ImportRow;
use Modules\SystemBase\app\Services\Csv;
use Modules\WebsiteBase\app\Models\Base\UserTrait;

class ImportContent
{
    /**
     * @var array
     */
    protected array $columnTypeMap = [];

    /**
     * @var array
     */
    protected array $columnAclResources = [
        'user'  => [
            'admin',
        ],
        'store' => [
            'admin',
        ],
    ];

    /**
     * @var array
     */
    protected array $requiredTypesSingular = [];

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * @param $t
     * @return bool
     */
    protected function isRequiredType($t): bool
    {
        return in_array($t, $this->requiredTypesSingular);
    }

    /**
     * Handle the event.
     *
     * @param  ImportContentEvent  $event
     * @return bool  false to stop all following listeners
     */
    public function handle(ImportContentEvent $event): bool
    {
        if (!$this->isRequiredType($event->type)) {
            return true;
        }

        Log::info(sprintf("Listening import content from '%s' by: %s", $event->sourcePathInfo['basename'],
            get_class($this)));

        switch ($event->sourcePathInfo['extension']) {
            case 'csv':
                $this->importCsv($event);
                break;

            // case 'json':
            //     $this->importJson($event);
            //     break;

            default:
                Log::error(sprintf("Import not supported for file extension: %s", $event->sourcePathInfo['extension']));
                break;
        }

        return true;
    }

    /**
     * @param  ImportContentEvent  $event
     * @return bool
     */
    protected function importCsv(ImportContentEvent $event): bool
    {
        // prepare to import
        // Log::debug(sprintf("File: %s", $sourcePathInfo['basename']));
        $csv = app(Csv::class);
        $csv->init($event->sourcePathInfo['dirname'], $event->sourcePathInfo['basename']);
        if (!$csv->load(function ($row) use ($event, $csv) {

            // check header before the first row
            if (!$this->checkBeforeFirstRow($event, $csv)) {
                Log::error("Permission failed", [__METHOD__]);
                return false;
            }

            // Import row event  ...
            ImportRow::dispatch($event->type, $event->sourcePathInfo, $row);
            $event->currentRowNumber++;
            return true;

        })) {
            Log::error(sprintf("Failed to import file: '%s'", $event->sourcePathInfo['basename']));
            return false;
        }

        Log::info(sprintf("Imported: %s rows of '%s' from file '%s'", $event->currentRowNumber, $event->type,
            $event->sourcePathInfo['basename']));

        return true;
    }

    /**
     * @param  ImportContentEvent  $event
     * @param  Csv                 $csv
     * @return bool
     */
    protected function checkBeforeFirstRow(ImportContentEvent $event, Csv $csv): bool
    {
        // check header before the first row
        if ($event->currentRowNumber === 0) {
            if (!$this->checkAllColumnsPermissions($event, $csv)) {
                return false;
            }
        }

        // ...

        return true;
    }

    /**
     * Check all header permissions
     *
     * @param  ImportContentEvent  $event
     * @param  Csv                 $csv
     * @return bool
     */
    protected function checkAllColumnsPermissions(ImportContentEvent $event, Csv $csv): bool
    {
        // Log::debug("Check header permissions.", [__METHOD__]);

        if (app()->runningInConsole()) {
            // console allowed everything
            return true;
        }

        /** @var User|UserTrait $user */
        if (!($user = Auth::user())) {
            return false;
        }

        foreach ($csv->header as $k) {
            if ($res = data_get($this->columnAclResources, $k)) {
                if (!$user->hasAclResource($res)) {
                    Log::error(sprintf("Import column '%s' not allowed for user %s", $k, $user->name));
                    return false;
                }
            }
        }

        return true;
    }
}
