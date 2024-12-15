<?php

namespace Modules\DeployEnv\app\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\DeployEnv\app\Events\ImportContent as ImportContentEvent;
use Modules\DeployEnv\app\Events\ImportRow;
use Modules\SystemBase\app\Services\Csv;
use Modules\Acl\app\Models\Base\UserTrait;

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
     *
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
     *
     * @return bool  false to stop all following listeners
     */
    public function handle(ImportContentEvent $event): bool
    {
        // Go out if not the proper type (like user,product,...)
        if (!$this->isRequiredType($event->type)) {
            //Log::debug("Skipping import content", [$event->type, get_class($this), $event->sourcePathInfo['basename']]);

            return true;
        }

        //Log::info("Listening import content", [$event->type, get_class($this), $event->sourcePathInfo['basename']]);

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
     *
     * @return bool
     */
    protected function importCsv(ImportContentEvent $event): bool
    {
        $importCount = 0;
        // prepare to import
        $csv = app(Csv::class);
        $csv->init($event->sourcePathInfo['dirname'], $event->sourcePathInfo['basename']);
        if (!$csv->load(function ($row) use ($event, $csv, &$importCount) {

            // check header before the first row
            if (!$this->checkBeforeFirstRow($event, $csv)) {
                Log::error("Permission failed", [__METHOD__]);

                return false;
            }

            // Import row event  ...
            // for example see: \Modules\Market\app\Listeners\ImportRowProduct
            if ($r = ImportRow::dispatch($event, $row)) {
                $importCount++;
            }

            return true;

        })
        ) {
            Log::error(sprintf("Failed to import file: '%s'", $event->sourcePathInfo['basename']));

            return false;
        }

        Log::info(sprintf("Import processed %s/%s rows of '%s' from file '%s'", $importCount, $csv->currentRowNumber - 1, $event->type, $event->sourcePathInfo['basename']));

        return true;
    }

    /**
     * @param  ImportContentEvent  $event
     * @param  Csv                 $csv
     *
     * @return bool
     */
    protected function checkBeforeFirstRow(ImportContentEvent $event, Csv $csv): bool
    {
        // check header before the first row
        if ($csv->currentRowNumber === 1) {
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
     *
     * @return bool
     */
    protected function checkAllColumnsPermissions(ImportContentEvent $event, Csv $csv): bool
    {
        if (app()->runningInConsole()) {
            // console allowed everything
            return true;
        }

        // Otherwise, if no user logged in, nothing is allowed.
        /** @var User|UserTrait $user */
        if (!($user = Auth::user())) {
            return false;
        }

        // Otherwise, check column was configured for acl resource.
        foreach ($csv->header as $k) {
            if ($res = data_get($this->columnAclResources, $k)) { // resource needed ...
                if (!$user->hasAclResource($res)) { // but user dont have this resource ...
                    Log::error(sprintf("Import column '%s' not allowed for user %s", $k, $user->name));

                    return false;
                }
            }
        }

        return true;
    }
}
