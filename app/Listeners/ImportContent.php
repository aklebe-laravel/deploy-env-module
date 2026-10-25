<?php

namespace Modules\DeployEnv\app\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Acl\app\Models\AclResource;
use Modules\Acl\app\Services\UserService;
use Modules\DeployEnv\app\Events\ImportContent as ImportContentEvent;
use Modules\DeployEnv\app\Events\ImportRow;
use Modules\SystemBase\app\Services\Csv;
use Modules\Acl\app\Models\Base\UserTrait;

/**
 * Base listener for ImportContent. Extend it and set $requiredTypesSingular (like ['product']).
 * CSV files are read row by row and ImportRow is dispatched for every row.
 * Outside the console, the current user needs the acl resources of $columnAclResources
 * for every listed column found in the header.
 */
class ImportContent
{
    /**
     * Column name => type, used by listeners extending this class.
     *
     * @var array
     */
    protected array $columnTypeMap = [];

    /**
     * Column name => acl resources needed to import this column.
     *
     * @var array
     */
    protected array $columnAclResources = [
        'user'  => [
            AclResource::RES_ADMIN,
        ],
        'store' => [
            AclResource::RES_ADMIN,
        ],
    ];

    /**
     * Import types (singular) handled by this listener.
     *
     * @var array
     */
    protected array $requiredTypesSingular = [];

    /**
     * @param  string  $t
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
        // not the proper type (like user, product, ...)
        if (!$this->isRequiredType($event->type)) {
            return true;
        }

        $extension = $event->sourcePathInfo['extension'] ?? '';
        switch ($extension) {
            case 'csv':
                $this->importCsv($event);
                break;

            default:
                Log::error(sprintf("Import not supported for file extension: %s", $extension));
                break;
        }

        return true;
    }

    /**
     * Loads the CSV and dispatches ImportRow for every row.
     * Stops if the column permissions fail (checked before the first row).
     *
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

            // for example see: \Modules\Market\app\Listeners\ImportRowProduct
            if (ImportRow::dispatch($event, $row)) {
                $importCount++;
            }

            return true;

        })
        ) {
            Log::error(sprintf("Failed to import file: '%s'", $event->sourcePathInfo['basename']));

            return false;
        }

        $event->results->setData('import_rows_requested', $csv->currentRowNumber - 1);
        $event->results->setData('import_rows_success', $importCount);
        Log::info(sprintf("Import processed %s/%s rows of '%s' from file '%s'", $importCount, $csv->currentRowNumber - 1, $event->type, $event->sourcePathInfo['basename']));

        return true;
    }

    /**
     * Checks done once before the first row (column permissions).
     *
     * @param  ImportContentEvent  $event
     * @param  Csv                 $csv
     *
     * @return bool
     */
    protected function checkBeforeFirstRow(ImportContentEvent $event, Csv $csv): bool
    {
        if (($csv->currentRowNumber === 1) && !$this->checkAllColumnsPermissions($event, $csv)) {
            return false;
        }

        return true;
    }

    /**
     * Checks the permissions of all header columns. Everything is allowed in console,
     * without a logged-in user nothing is allowed.
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
        /** @var UserService $userService */
        $userService = app(UserService::class);

        /** @var User|UserTrait $user */
        if (!($user = $userService->currentUser())) {
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
