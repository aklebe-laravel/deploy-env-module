<?php

namespace Modules\DeployEnv\app\Services;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\DeployEnv\app\Models\ModuleDeployenvDeployment;
use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\DatabaseService;
use Modules\SystemBase\app\Services\ModuleService;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Nwidart\Modules\Module;
use Symfony\Component\Console\Command\Command;

class DeploymentService extends BaseService
{
    /**
     *
     */
    const string MODULE_CONFIG_IDENT = 'module-deploy-env';

    /**
     * @var int
     */
    protected int $currentBatch = 1;

    /**
     * @var Module|null
     */
    protected ?Module $currentModule = null;

    /**
     * @var string
     */
    protected string $currentDeployIdent = '';

    /**
     * @var DatabaseService
     */
    protected DatabaseService $dbService;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->dbService = app(DatabaseService::class);
    }

    /**
     * @return int
     */
    protected function createNextBatch(): int
    {
        if ($m = ModuleDeployenvDeployment::with([])->orderBy('batch', 'desc')->first()) {
            $this->currentBatch = (int) $m->batch + 1;
        }

        return $this->currentBatch;
    }

    /**
     * @param  string  $moduleName  module snake or studly name, '*' for all modules
     * @param  string  $moduleVersion
     * @param  bool    $force
     *
     * @return bool
     */
    public function runTerraformModules(string $moduleName = '*', string $moduleVersion = '', bool $force = false): bool
    {
        // force snake notation
        $moduleSnakeName = Str::snake($moduleName, '-');
        $this->info(sprintf("DeployEnv: Starting modules config deployments for %s, version: %s, force: %b",
            $moduleSnakeName, $moduleVersion, $force));

        // Create next batch counter
        $this->createNextBatch();
        $this->incrementIndent();

        /** @var ModuleService $moduleService */
        $moduleService = app(ModuleService::class);
        if ($result = $moduleService->runOrderedEnabledModules(function (?Module $module) use (
            $moduleSnakeName,
            $moduleService,
            $moduleVersion,
            $force
        ) {
            $this->currentModule = $module;
            $currentModuleSnakeName = $moduleService->getSnakeName($module);

            // if $moduleLowerName is specified, but it's not this one, skip it
            if (($moduleSnakeName !== '*') && ($currentModuleSnakeName !== $moduleSnakeName)) {
                return true;
            }

            $this->info("Run terraform module \"$currentModuleSnakeName\"");
            $configName = $currentModuleSnakeName.'.'.self::MODULE_CONFIG_IDENT;

            return $this->runTerraformModule($configName, $currentModuleSnakeName, $moduleVersion, $force);
        })
        ) {

            // After all modules successfully done: run once for the app itself
            // @todo: place it in main loop with runOrderedEnabledModules(..., inclusiveApp: true)
            $this->currentModule = null;
            $result = $this->runTerraformModule(self::MODULE_CONFIG_IDENT, null, $moduleVersion, $force);

        }

        $this->decrementIndent();

        return $result;
    }

    /**
     * @param  string       $configName
     * @param  string|null  $moduleName
     * @param  string       $version
     * @param  bool         $force
     *
     * @return bool
     */
    public function runTerraformModule(string $configName, ?string $moduleName = null, string $version = '', bool $force = false): bool
    {
        $configDeployments = config($configName.'.deployments', []);
        if (!count($configDeployments)) {
            return true;
        }

        $this->incrementIndent();
        foreach ($configDeployments as $deployIdent => $identContainer) {

            $this->currentDeployIdent = $deployIdent;

            // force this version?
            if ($version) {
                // ... then use this version only.
                if ($version != $deployIdent) {
                    continue;
                }
            }

            if (!$force) {
                // check deployment is already done ...
                if (ModuleDeployenvDeployment::with([])
                                             ->where('module', $moduleName)
                                             ->where('version', $deployIdent)
                                             ->first()
                ) {
                    // $this->debug(sprintf("OK. Deployment already done %s-%s", $moduleName ?? 'APP', $deployIdent));
                    continue;
                }
            }

            // $this->debug(sprintf("Checking deployment: %s-%s", $moduleName ?? 'APP', $deployIdent));

            $this->incrementIndent();
            foreach ($identContainer as $identContainerItem) {

                $cmd = data_get($identContainerItem, 'cmd');
                // if ($fileFilter) {
                //     if ($sources = data_get($identContainerItem, 'sources', [])) {
                //         $newList = [];
                //         foreach ($sources as $v) {
                //             // 1) Don't include functions, because there is no sources (maybe).
                //             // 2) Don't include process to avoid recursion!
                //             if (($cmd !== 'functions') && ($cmd !== 'process') && str_contains($v, $fileFilter)) {
                //                 $newList[] = $v;
                //             }
                //         }
                //         if (!$newList) {
                //             continue;
                //         }
                //         $identContainerItem['sources'] = $newList;
                //     } else {
                //         continue;
                //     }
                // }

                if (!$cmd) {
                    $this->error(sprintf("Missing cmd in config: %s", $configName), [__METHOD__]);
                    $this->decrementIndent(2);

                    return false;
                }

                $conditionResult = true;
                if ($conditions = data_get($identContainerItem, 'conditions', [])) {
                    $conditionResult = $this->checkAllConditions($conditions);
                }

                //
                if ($conditionResult) {
                    if (!$this->runConfigCmd($cmd, $identContainerItem)) {
                        $this->error("Run Config Command returns false. Skipping process.", [__METHOD__]);
                        $this->decrementIndent(2);

                        return false;
                    }
                } else {
                    $this->debug("Conditions failed. Command skipped.", [$cmd, $conditions]);
                }
            }

            // create the db entry
            $this->createDeployenvDeployment($moduleName, $deployIdent);

            $this->decrementIndent();
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * Check condition in data array.
     * Currently supported: implicit AND.
     *
     * @param $conditions
     *
     * @return bool
     */
    public function checkAllConditions($conditions): bool
    {
        foreach ($conditions as $conditionData) {
            foreach ($conditionData as $conditionCode => $conditionValue) {
                if (!$this->checkCondition($conditionCode, $conditionValue)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param $conditionCode
     * @param $conditionValue
     *
     * @return bool
     */
    public function checkCondition($conditionCode, $conditionValue): bool
    {
        $result = true;
        switch ($conditionCode) {

            case 'db_table_exists':
            case 'db_table_not_exists':
                $result = Schema::hasTable($conditionValue);
                $result = ($conditionCode === 'db_table_exists') ? $result : !$result;
                break;

            case 'db_table_column_exists':
            case 'db_table_not_column_exists':
                $dotParts = explode('.', $conditionValue);
                if (count($dotParts) != 2) {
                    $this->error(sprintf("Expect dot separated 'table.colum'. Condition code: %s", $conditionCode));
                    $result = false;
                } else {
                    $result = Schema::hasColumn($dotParts[0], $dotParts[1]);
                    $result = ($conditionCode === 'db_table_column_exists') ? $result : !$result;
                }
                break;

            case 'module_enabled':
            case 'module_not_enabled':
                $result = (ModuleFacade::has($conditionValue) && ModuleFacade::isEnabled($conditionValue));
                $result = ($conditionCode === 'module_enabled') ? $result : !$result;
                break;

            case 'function':
                if (app('system_base')->isCallableClosure($conditionValue)) {
                    $result = $conditionValue($conditionCode);
                } else {
                    $this->error(sprintf("Closure expected in condition code: %s", $conditionCode));
                    $result = false;
                }
                break;

            default:
                $this->error(sprintf("Unknown condition code: %s", $conditionCode));
                $result = false;
                break;

        }

        return $result;
    }

    /**
     * @param  string|null  $moduleName
     * @param  string|null  $deployIdent
     * @param  int|null     $batchOrCurrent  leave null to set automatically by current process
     *
     * @return bool
     */
    public function createDeployenvDeployment(?string $moduleName, ?string $deployIdent, ?int $batchOrCurrent = null): bool
    {
        if (!ModuleDeployenvDeployment::where('module', $moduleName)->where('version', $deployIdent)->first()) {
            $this->debug(sprintf("Creating deployment entry: %s-%s", $moduleName ?? 'APP', $deployIdent), [__METHOD__]);
            ModuleDeployenvDeployment::create([
                'module'  => $moduleName,
                'version' => $deployIdent,
                'batch'   => $batchOrCurrent ?? $this->currentBatch,
            ]);
        }

        return true;
    }

    /**
     * The main process for commands which will run the specific subroutine.
     *
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool false when failed
     */
    public function runConfigCmd(string $cmd, array $data): bool
    {
        $methodName = 'runConfigCmd'.Str::studly($cmd);
        if (!method_exists($this, $methodName)) {
            $this->error(sprintf("Missing method: %s", $methodName));

            return false;
        }

        return $this->$methodName($cmd, $data);
    }

    /**
     * @param  string  $cmd     like 'models', 'artisan' or 'raw_sql'
     * @param  string  $script  script filename like 'my_model.php' or 'more/script_07.sql'
     *
     * @return string
     */
    protected function getDeployScriptPath(string $cmd, string $script): string
    {
        $pathInResource = DeploymentService::MODULE_CONFIG_IDENT.'/'.$this->currentDeployIdent.'/'.$cmd.'/'.$script;

        // get from module?
        if ($this->currentModule) {
            return ModuleService::getPath($pathInResource, $this->currentModule->getStudlyName(), 'resources');
            // return $this->currentModule->getExtraPath('Resources/'.$pathInResource);
        }

        // get from app
        return resource_path($pathInResource);
    }

    /**
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdArtisan(string $cmd, array $data): bool
    {
        // $this->debug('Run Artisan Commands: ');
        $this->incrementIndent();
        $artisanCommands = data_get($data, 'sources', []);
        foreach ($artisanCommands as $artisanCommand) {
            // $this->debug(sprintf("Artisan: %s", $artisanCommand));
            if (Artisan::call($artisanCommand) !== Command::SUCCESS) {
                $this->error("Artisan failed!");
                $this->decrementIndent();

                return false;
            }
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdProcess(string $cmd, array $data): bool
    {
        // $this->debug('Run Processes: ');
        $this->incrementIndent();
        $processSources = data_get($data, 'sources', []);
        foreach ($processSources as $processSource) {
            // $this->debug(sprintf("Process: %s", $processSource));

            $result = Process::forever()->run($processSource, function (string $type, string $output) {
                // $this->debug('(from process) '.$output);
            });

            if ($result->failed()) {
                $this->error("Process failed!");
                $this->decrementIndent();

                return false;
            }
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdFunctions(string $cmd, array $data): bool
    {
        // $this->debug('Run Function Commands: ');
        $this->incrementIndent();
        $functionCommands = data_get($data, 'sources', []);
        /**
         * @var string   $functionKey
         * @var callable $functionCommand
         */
        foreach ($functionCommands as $functionKey => $functionCommand) {
            // $this->debug(sprintf("Function: %s", $functionKey));
            if (!app('system_base')->isCallableClosure($functionCommand)) {
                $this->error("Function expected!");
                $this->decrementIndent();

                return false;
            }
        }
        $functionCommand($cmd, $functionKey);
        $this->decrementIndent();

        return true;
    }

    /**
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdRawSql(string $cmd, array $data): bool
    {
        // $this->debug('Run Raw Scripts: ');
        $scripts = data_get($data, 'sources', []);
        $this->incrementIndent();
        foreach ($scripts as $script) {
            $script = $this->getDeployScriptPath($cmd, $script);
            if (($scriptContent = app('system_base_file')->loadFile($script)) === false) {
                $this->error(sprintf("Failed to load script: %s", $script));
                $this->decrementIndent();

                return false;
            }

            // execute sql script
            try {
                $this->dbService->rememberCurrentDatabase();
                DB::unprepared($scriptContent);
                $this->dbService->resetDatabase();

                // $this->debug(sprintf("Script executed successfully: %s", $script));
            } catch (\Exception $ex) {
                $this->error(sprintf("Script failed: %s", $script));
                $this->error($ex->getMessage());
                $this->error($ex->getTraceAsString());
                $this->decrementIndent();

                return false;
            }
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdModels(string $cmd, array $data): bool
    {
        // $this->debug('Run Models: ');

        $scripts = data_get($data, 'sources', []);
        $this->incrementIndent();
        foreach ($scripts as $script) {

            $script = $this->getDeployScriptPath($cmd, $script);
            if (!file_exists($script)) {
                $this->error('Missing model script {script}', ['script' => $script]);
                $this->decrementIndent();

                return false;
            }

            $scriptResult = include($script);
            if (!($modelName = data_get($scriptResult, 'model', ''))) {
                $this->error('Missing parameter model!', ['script' => $script]);
                $this->decrementIndent();

                return false;
            }

            if (!(class_exists($modelName))) {
                $this->error(sprintf("Missing class %s", $modelName), ['script' => $script]);
                $this->decrementIndent();

                return false;
            }

            $uniqueColumns = data_get($scriptResult, 'uniques', []);
            $modelList = data_get($scriptResult, 'data', []);
            if (app('system_base')->isCallableClosure($modelList)) {
                $modelList = $modelList();
            }
            $relationsDefinitions = data_get($scriptResult, 'relations', []);
            $processedCount = 0;
            $updateCount = 0;
            $createdCount = 0;

            $this->incrementIndent();
            // run all data rows ...
            foreach ($modelList as $modelData) {
                $preparedUniqueData = $this->getPreparedUniqueData($modelData, $uniqueColumns);
                $cleanedModelData = $this->cleanedModelData($modelData);
                // decide update or create ...
                if ($modelFound = $this->loadModelByData($modelName, $modelData, $preparedUniqueData)) {
                    if (data_get($scriptResult, 'update', false)) {
                        // $this->debug("Model data found. Updating ...", $preparedUniqueData);

                        // updating model ...
                        $modelFound->update($cleanedModelData, $preparedUniqueData);
                        $this->createOrUpdateRelations($modelFound, $modelData, $relationsDefinitions);
                        $updateCount++;
                    }
                } else {
                    // $this->debug("Model data not found. Creating ...", $preparedUniqueData);

                    // creating model ...
                    $newModel = app($modelName)->create($cleanedModelData);
                    $this->createOrUpdateRelations($newModel, $modelData, $relationsDefinitions);
                    $createdCount++;
                }
                $processedCount++;
            }

            // run deletions ...
            if ($modelDeleteList = data_get($scriptResult, 'delete', [])) {
                /** @var Builder $builder */
                $builder = app($modelName);
                foreach ($uniqueColumns as $uniqueColumn) {
                    $builder = $builder->whereIn($uniqueColumn, $modelDeleteList);
                }
                $builder->delete();
            }


            $this->decrementIndent();

            // $this->debug(sprintf("Finished '%s', Processed: %s. Created: %s. Updated: %s.", $modelName, $processedCount,
            //     $createdCount, $updateCount));
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * @param  Model  $model
     * @param  array  $modelData
     * @param  array  $relationsDefinitions
     *
     * @return bool
     */
    protected function createOrUpdateRelations(Model $model, array $modelData, array $relationsDefinitions): bool
    {
        if (!$relationsDefinitions) {
            return true;
        }

        // get all relations (commonly only one) ...
        foreach ($relationsDefinitions as $relationKey => $relationsDefinition) {

            $relationProperty = '#sync_relations.'.$relationKey;
            $relationMethod = data_get($relationsDefinition, 'method', '');
            $relationColumn = data_get($relationsDefinition, 'columns', '');
            $relationDelete = data_get($relationsDefinition, 'delete', false);

            // relations in model data present?
            if ($modelRelationList = data_get($modelData, $relationProperty, [])) {

                // related id list have to be synced with ...
                $syncKeys = [];
                $syncModels = [];

                //
                foreach ($modelRelationList as $modelRelationItem) {

                    $relatedClass = get_class($model->$relationMethod()->getRelated());

                    // relation "column" can be an array, but then relation in model also have toi be an array
                    if (is_array($relationColumn)) {
                        if (!is_array($modelRelationItem)) {
                            $this->error("If relation colum is an array, model relation item also have to be an array.");

                            return false;
                        }
                        $newRelatedBuilder = $relatedClass::with([]);
                        foreach ($relationColumn as $_k => $_c) {
                            $newRelatedBuilder = $newRelatedBuilder->where($_c, $modelRelationItem[$_k]);
                        }
                    } else {
                        $newRelatedBuilder = $relatedClass::with([])->where($relationColumn, $modelRelationItem);
                    }

                    foreach ($newRelatedBuilder->get() as $newRelated) {
                        $syncKeys[] = $newRelated->getKey();
                        $syncModels[] = $newRelated;
                    }

                }

                if ($syncKeys) {

                    $modelRelationMethodInstance = $model->$relationMethod();
                    // $this->debug(sprintf("Relation method '%s' for class '%s'", $relationMethod,
                    //     $modelRelationMethodInstance::class));

                    // checking type of relation ...
                    if (method_exists($modelRelationMethodInstance, 'sync')) {

                        if ($relationDelete) {
                            // will remove all relations not in $sync
                            $modelRelationMethodInstance->sync($syncKeys);
                        } else {
                            // will not delete existing items
                            $modelRelationMethodInstance->syncWithoutDetaching($syncKeys);
                        }

                        // $this->debug(sprintf("%s relations synced if not already done.", count($syncKeys)));

                    } elseif (method_exists($modelRelationMethodInstance, 'saveMany')) {

                        // use upsert() here

                        $this->error("NOT IMPLEMENTED: saveMany()", [__METHOD__]);

                        return false;

                    } elseif (method_exists($modelRelationMethodInstance, 'associate')) {

                        foreach ($syncModels as $syncModel) { // should be only one
                            if (method_exists($modelRelationMethodInstance, 'saveWithoutEvents')) {
                                $modelRelationMethodInstance->associate($syncModel)->saveWithoutEvents();
                            } else {
                                $modelRelationMethodInstance->associate($syncModel)->save();
                            }
                        }

                    } else {

                        $this->error("NOT IMPLEMENTED: no update routine!", [__METHOD__]);

                        return false;

                    }
                }

            }

        }

        return true;
    }

    /**
     * Get cleaned model data without specials like '#sync_relation.res' etc ...
     *
     * @param  array  $modelData
     *
     * @return array
     */
    protected function cleanedModelData(array $modelData): array
    {
        $cleaned = [];
        foreach ($modelData as $k => $v) {
            // filter out special like '#sync_relation.res'
            if (is_string($k) && (strlen($k) > 0) && $k[0] === '#') {
                continue;
            }
            $cleaned[$k] = $v;
        }

        return $cleaned;
    }

    /**
     * Get prepared array like ['code'=>'admin'] by unique list like ['code']
     *
     * @param  array  $modelData
     * @param  array  $uniqueColumns
     *
     * @return array
     */
    protected function getPreparedUniqueData(array $modelData, array $uniqueColumns): array
    {
        $result = [];
        foreach ($uniqueColumns as $unique) {
            if (isset($modelData[$unique])) {
                $result[$unique] = $modelData[$unique];
            }
        }

        return $result;
    }

    /**
     * @param  string  $modelName
     * @param  array   $modelData
     * @param  array   $preparedUniqueData
     *
     * @return Model|null
     */
    protected function loadModelByData(string $modelName, array $modelData, array $preparedUniqueData): ?Model
    {
        if (!$preparedUniqueData) {
            return null;
        }
        /** @var Builder $builder */
        $builder = app($modelName)->query();
        foreach ($preparedUniqueData as $k => $v) {
            if (isset($modelData[$k])) {
                // check if defined a relation notation like "notificationTemplate.code"
                if (($relationNotation = explode('.', $k)) && (count($relationNotation) === 2)) {
                    $builder->whereHas($relationNotation[0], function (Builder $b2) use ($v, $relationNotation) {
                        $b2->where($relationNotation[1], $v);
                    });
                } else {
                    $builder->where($k, '=', $v);
                }
            }
        }

        return $builder->first();
    }


}