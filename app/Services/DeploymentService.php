<?php

namespace Modules\DeployEnv\app\Services;

use Exception;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\DeployEnv\app\Models\ModuleDeployenvDeployment;
use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\DatabaseService;
use Modules\SystemBase\app\Services\ModuleService;
use Modules\SystemBase\app\Services\ParserService;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Nwidart\Modules\Module;
use Symfony\Component\Console\Command\Command;

/**
 * Runs the "terraform" deployments of modules, the app and business imports.
 *
 * A deployment config (module-deploy-env.php) contains 'deployments' => [ident => [steps]].
 * Each ident runs once and is stored in table module_deployenv_deployments (like migrations).
 * A step has a 'cmd' (models, raw_sql, artisan, process, functions), 'sources' and optional 'conditions'.
 * See README.md for the format.
 *
 * Also parses env content with ${ENV} vars and ${{placeholder}}, see parseEverything().
 */
class DeploymentService extends BaseService
{
    /**
     * Name of the deployment config file (without .php) and folder name for deployment scripts.
     */
    const string MODULE_CONFIG_IDENT = 'module-deploy-env';

    /**
     * Module name used for deployments from storage/app/imports (business imports of this instance).
     */
    const string BUSINESS_IMPORTS_IDENT = 'local:business-imports';

    /**
     * Batch number of the current run, stored with every deployment (like migrations).
     *
     * @var int
     */
    protected int $currentBatch = 1;

    /**
     * The current module in {@see runTerraformModules}
     *
     * @var Module|null
     */
    protected ?Module $currentModule = null;

    /**
     * The current deployment ident (like '0001'), part of the script paths.
     *
     * @var string
     */
    protected string $currentDeployIdent = '';

    /**
     * @var DatabaseService
     */
    protected DatabaseService $dbService;

    /**
     * If set, deployment scripts are loaded from this root instead of the module/app resources.
     *
     * @var string|null
     */
    protected ?string $customSourceLocation = null;

    /**
     * @var ParserService
     */
    protected ParserService $parser;

    /**
     * Placeholders for parseEverything(): hash, app_ip, container_id, host_depend_env, port_depend_env.
     *
     * @var array|array[]
     */
    protected array $placeHolders;

    /**
     * Prepares the placeholders for parseEverything().
     */
    public function __construct()
    {
        parent::__construct();
        $this->dbService = app(DatabaseService::class);
        $this->parser = app(ParserService::class);

        $isProduction = (config('app.env', 'production') === 'production');
        // How the proxy reaches the app (setup.npm.forward). Not the stage decides, but where the proxy runs:
        // "network": proxy container in the shared docker network -> container alias, internal port 80
        // "host": proxy somewhere else (other machine) -> host ip and APP_PORT
        $forwardToHost = match (config('services.npm.forward', '')) {
            'host' => true,
            'network' => false,
            default => $isProduction,
        };

        $this->placeHolders = [
            'hash'            => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return app('system_base')->getShortSecHash($placeholderParameters['content'] ?? uniqid());
                },
            ],
            'app_ip'          => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    if ($ip = config('app.ip')) {
                        return $ip;
                    }

                    return $this->getHostExternalIp();
                },
            ],
            'container_id'    => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return $this->resolveCurrentContainerName();
                },
            ],
            'host_depend_env' => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) use ($forwardToHost) {
                    // "network": the container alias ("app.ip" must not win here, it can be 127.0.0.1,
                    // which is unreachable from the proxy container)
                    return $forwardToHost ? $this->getHostExternalIp() : $this->resolveCurrentContainerName();
                },
            ],
            'port_depend_env' => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) use ($forwardToHost) {
                    // "host": the published APP_PORT, "network": the port inside the container
                    return $forwardToHost ? config('app.port', 80) : 80;
                },
            ],
        ];
    }

    /**
     * Sets the current batch to the highest stored batch + 1.
     *
     * @return int
     */
    protected function createNextBatch(): int
    {
        if ($m = ModuleDeployenvDeployment::with([])
            ->orderBy('batch', 'desc')
            ->first()
        ) {
            $this->currentBatch = (int) $m->batch + 1;
        }

        return $this->currentBatch;
    }

    /**
     * Runs the deployments of all enabled modules (ordered by priority) and finally of the app.
     * The app (config/module-deploy-env.php) only runs if all modules were successful.
     *
     * @param  string  $moduleName     module snake or studly name, '*' for all modules
     * @param  string  $moduleVersion  run this deployment ident only (like '0003')
     * @param  bool    $force          run again, even if already done
     *
     * @return bool
     */
    public function runTerraformModules(string $moduleName = '*', string $moduleVersion = '', bool $force = false): bool
    {
        // force snake notation
        $moduleSnakeName = Str::snake($moduleName, '-');
        $this->info(sprintf("DeployEnv: Starting modules config deployments for %s, version: %s, force: %d",
            $moduleSnakeName,
            $moduleVersion,
            $force));

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

            // if $moduleSnakeName is specified, but it's not this one, skip it
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
     * Runs config('{$configName}.deployments').
     *
     * @param  string       $configName  like 'my-module.module-deploy-env'
     * @param  string|null  $moduleName  null for the app
     * @param  string       $version
     * @param  bool         $force
     *
     * @return bool
     */
    public function runTerraformModule(string $configName, ?string $moduleName = null, string $version = '', bool $force = false): bool
    {
        $configDeployments = config($configName.'.deployments', []);

        if (!$configDeployments) {
            return true;
        }

        return $this->runTerraformData($configDeployments, $moduleName, $version, $force);
    }

    /**
     * Runs a list of deployments like [ident => [steps]].
     * Stops at the first failing step (the deployment is not stored then).
     *
     * @param  array        $configDeployments
     * @param  string|null  $moduleNameOrIdent              used to save for {@see ModuleDeployenvDeployment}
     * @param  string       $version
     * @param  bool         $force
     * @param  string|null  $businessImportsSourceLocation  if not empty use this custom location for deployment configs
     *
     * @return bool
     */
    public function runTerraformData(array $configDeployments, ?string $moduleNameOrIdent = null, string $version = '', bool $force = false, ?string $businessImportsSourceLocation = null): bool
    {
        $this->customSourceLocation = $businessImportsSourceLocation;
        $this->incrementIndent();
        foreach ($configDeployments as $deployIdent => $identContainer) {

            $this->currentDeployIdent = $deployIdent;

            // only this version wanted?
            if ($version && ($version != $deployIdent)) {
                continue;
            }

            if (!$force) {
                // check deployment is already done ...
                if (ModuleDeployenvDeployment::with([])
                    ->where('module', $moduleNameOrIdent)
                    ->where('version', $deployIdent)
                    ->first()
                ) {
                    $this->debug(sprintf("OK. Deployment already done %s-%s", $moduleNameOrIdent ?? 'APP', $deployIdent));
                    continue;
                }
            }

            $this->incrementIndent();
            foreach ($identContainer as $identContainerItem) {

                $cmd = data_get($identContainerItem, 'cmd');
                if (!$cmd) {
                    $this->error("Missing cmd in terraform data!", [__METHOD__]);
                    $this->decrementIndent(2);

                    return false;
                }

                $conditionResult = true;
                if ($conditions = data_get($identContainerItem, 'conditions', [])) {
                    $conditionResult = $this->checkAllConditions($conditions);
                }

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
            $this->createDeployenvDeployment($moduleNameOrIdent, $deployIdent);

            $this->decrementIndent();
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * Checks a list of condition arrays like [['db_table_exists' => 'users', 'module_enabled' => 'Acl']].
     * All conditions have to be true (implicit AND).
     *
     * @param  array  $conditions
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
     * Checks a single condition:
     * - db_table_exists / db_table_not_exists: table name
     * - db_table_column_exists / db_table_not_column_exists: 'table.column'
     * - module_enabled / module_not_enabled: module name
     * - function: Closure or [class, method], called with the condition code, has to return bool
     * Unknown codes are false.
     *
     * @param  string  $conditionCode
     * @param  mixed   $conditionValue
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
                $dotParts = explode('.', (string) $conditionValue);
                if (count($dotParts) !== 2) {
                    $this->error(sprintf("Expect dot separated 'table.column'. Condition code: %s", $conditionCode));
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
                if ($callable = app('system_base')->getCallable($conditionValue)) {
                    $result = (bool) $callable($conditionCode);
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
     * Stores a deployment as done (if not stored yet).
     *
     * @param  string|null  $moduleName      null for the app
     * @param  string|null  $deployIdent
     * @param  int|null     $batchOrCurrent  leave null to use the batch of the current process
     *
     * @return bool
     */
    public function createDeployenvDeployment(?string $moduleName, ?string $deployIdent, ?int $batchOrCurrent = null): bool
    {
        if (!ModuleDeployenvDeployment::where('module', $moduleName)
            ->where('version', $deployIdent)
            ->first()
        ) {
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
     * Runs a step by calling runConfigCmd{StudlyCmd}($cmd, $data) like runConfigCmdRawSql().
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
     * Path of a deployment script like ".../resources/module-deploy-env/0001/models/my_model.php".
     * Root: $customSourceLocation, the current module resources or the app resources.
     *
     * @param  string  $cmd     like 'models', 'artisan' or 'raw_sql'
     * @param  string  $script  script filename like 'my_model.php' or 'more/script_07.sql'
     *
     * @return string
     */
    protected function getDeployScriptPath(string $cmd, string $script): string
    {
        $pathInResource = DeploymentService::MODULE_CONFIG_IDENT.'/'.$this->currentDeployIdent.'/'.$cmd.'/'.$script;

        // custom (local) path?
        if ($this->customSourceLocation) {
            return $this->customSourceLocation.'/'.$pathInResource;
        }

        // get from module?
        if ($this->currentModule) {
            return ModuleService::getPath($pathInResource, ModuleService::getStudlyName($this->currentModule), 'resources');
        }

        // get from app
        return resource_path($pathInResource);
    }

    /**
     * cmd 'artisan': runs every source as artisan command line like 'cache:clear'.
     *
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdArtisan(string $cmd, array $data): bool
    {
        $this->incrementIndent();
        $artisanCommands = data_get($data, 'sources', []);
        foreach ($artisanCommands as $artisanCommand) {
            if (Artisan::call($artisanCommand) !== Command::SUCCESS) {
                $this->error(sprintf("Artisan failed: %s", $artisanCommand));
                $this->decrementIndent();

                return false;
            }
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * cmd 'process': runs every source as shell command (without timeout).
     *
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdProcess(string $cmd, array $data): bool
    {
        $this->incrementIndent();
        $processSources = data_get($data, 'sources', []);
        foreach ($processSources as $processSource) {
            $result = Process::forever()
                ->run($processSource);

            if ($result->failed()) {
                $this->error(sprintf("Process failed: %s", $processSource), [$result->errorOutput()]);
                $this->decrementIndent();

                return false;
            }
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * cmd 'functions': calls every source like $function($cmd, $key). Return values are ignored.
     * All sources are validated first, so nothing runs if one of them is not callable.
     *
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdFunctions(string $cmd, array $data): bool
    {
        $this->incrementIndent();
        $functionCommands = data_get($data, 'sources', []);

        foreach ($functionCommands as $functionKey => $functionCommand) {
            if (!app('system_base')->isCallableClosure($functionCommand)) {
                $this->error(sprintf("Function expected for cmd '%s', key '%s'", $cmd, $functionKey));
                $this->decrementIndent();

                return false;
            }
        }

        foreach ($functionCommands as $functionKey => $functionCommand) {
            $functionCommand($cmd, $functionKey);
        }

        $this->decrementIndent();

        return true;
    }

    /**
     * cmd 'raw_sql': executes every source file (see getDeployScriptPath()) by DB::unprepared().
     *
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdRawSql(string $cmd, array $data): bool
    {
        $scripts = data_get($data, 'sources', []);
        $this->incrementIndent();
        foreach ($scripts as $script) {
            $script = $this->getDeployScriptPath($cmd, $script);
            if (($scriptContent = app('system_base_file')->loadFile($script)) === false) {
                $this->error(sprintf("Failed to load script: %s", $script));
                $this->decrementIndent();

                return false;
            }

            // execute sql script (the script may switch the database, so it's reset afterward)
            $this->dbService->rememberCurrentDatabase();
            try {
                DB::unprepared($scriptContent);
            } catch (Exception $ex) {
                $this->dbService->resetDatabase();
                $this->error(sprintf("Script failed: %s", $script));
                $this->error($ex->getMessage());
                $this->error($ex->getTraceAsString());
                $this->decrementIndent();

                return false;
            }
            $this->dbService->resetDatabase();
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * cmd 'models': every source is a PHP file returning a model definition:
     * - model: model class (required)
     * - data: list of attribute arrays (or a Closure returning it), '#sync_relations' inside a row for relations
     * - uniques: columns to find an existing model (also 'relation.column')
     * - can_create (default true), can_update (default false), ignore_update_fields (not overwritten on update)
     * - unique_separator (default ','): a unique column value like 'david,uwe' (or an array) creates/updates
     *   one model per value with the same data and relations; false disables splitting strings
     * - relations: see createOrUpdateRelations()
     * - auto_delete: list of values of the unique column(s) to delete
     *
     * @param  string  $cmd
     * @param  array   $data
     *
     * @return bool
     */
    public function runConfigCmdModels(string $cmd, array $data): bool
    {
        $scripts = data_get($data, 'sources', []);
        $this->incrementIndent();
        foreach ($scripts as $script) {

            $script = $this->getDeployScriptPath($cmd, $script);
            $this->info(sprintf("Deploy Script: '%s'", $script));
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
            // rows with multiple unique values (like 'name' => 'david,uwe') become one row per value
            $modelList = $this->expandMultiUniqueRows($modelList, $uniqueColumns, data_get($scriptResult, 'unique_separator', ','));
            $relationsDefinitions = data_get($scriptResult, 'relations', []);
            $updateCount = 0;
            $createdCount = 0;

            $this->incrementIndent();
            // run all data rows ...
            foreach ($modelList as $modelData) {
                $preparedUniqueData = $this->getPreparedUniqueData($modelData, $uniqueColumns);
                $cleanedModelData = $this->cleanedModelData($modelData);
                // decide update or create ...
                if ($modelFound = $this->loadModelByData($modelName, $modelData, $preparedUniqueData)) {
                    if (data_get($scriptResult, 'can_update', false)) {

                        // if update but 'ignore_update_fields' to not overwrite with defaults
                        if ($ignoreFields = data_get($scriptResult, 'ignore_update_fields', [])) {
                            foreach ($ignoreFields as $ignoreField) {
                                if (isset($cleanedModelData[$ignoreField])) {
                                    unset($cleanedModelData[$ignoreField]);
                                }
                            }
                        }

                        $modelFound->update($cleanedModelData);
                        $this->createOrUpdateRelations($modelFound, $modelData, $relationsDefinitions);
                        $updateCount++;
                    } // else no update wanted when already exist
                } else {
                    if (data_get($scriptResult, 'can_create', true)) {
                        $newModel = app($modelName)->create($cleanedModelData);
                        $this->createOrUpdateRelations($newModel, $modelData, $relationsDefinitions);
                        $createdCount++;
                    }
                }
            }
            $this->debug(sprintf("Models %s: %d created, %d updated", $modelName, $createdCount, $updateCount));

            // run deletions (only with unique columns, otherwise everything would be deleted)
            if (($modelDeleteList = data_get($scriptResult, 'auto_delete', false)) && $uniqueColumns) {
                /** @var Builder $builder */
                $builder = app($modelName)->query();
                foreach ($uniqueColumns as $uniqueColumn) {
                    $builder->whereIn($uniqueColumn, (array) $modelDeleteList);
                }
                $builder->delete();
            }

            $this->decrementIndent();
        }
        $this->decrementIndent();

        return true;
    }

    /**
     * Creates/updates relations of $model by $modelData['#sync_relations'][relationKey].
     *
     * Relation definition: ['method' => 'relationMethod', 'columns' => [...], 'auto_delete' => bool]
     * - HasMany (has upsert()): every item is a row of the related model, 'columns' are the unique keys
     *   (with the parent foreign key). 'relation.column' (like 'vm.extern_vm_id') is resolved to the foreign key id.
     *   auto_delete removes related rows not listed.
     * - BelongsToMany (sync()): every item is a value (or list of values) for 'columns' to find related models.
     *   auto_delete uses sync(), otherwise syncWithoutDetaching().
     * - BelongsTo (associate()): like above, the found model is associated and $model is saved.
     *
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

        foreach ($relationsDefinitions as $relationKey => $relationsDefinition) {
            $relationProperty = '#sync_relations.'.$relationKey;
            $relationMethod = data_get($relationsDefinition, 'method', '');
            $relationColumns = data_get($relationsDefinition, 'columns', []);
            if (!is_array($relationColumns)) {
                $relationColumns = [$relationColumns];
            }
            $relationDelete = data_get($relationsDefinition, 'auto_delete', false);

            $modelRelationList = data_get($modelData, $relationProperty, []);
            if (empty($modelRelationList)) {
                continue;
            }

            /** @var Relation $relationInstance */
            $relationInstance = $model->$relationMethod();

            if (method_exists($relationInstance, 'upsert')) {
                $this->debug(sprintf("upsert for relation '%s'", $relationMethod));

                /** @var HasMany $relationInstance */
                $foreignKeyName = $relationInstance->getForeignKeyName();
                $relatedModelInstance = $relationInstance->getRelated();

                $preparedUpsertRows = [];
                $upsertKeys = [$foreignKeyName];

                foreach ($modelRelationList as $modelRelationItem) {
                    $rowValues = $modelRelationItem;
                    $rowValues[$foreignKeyName] = $model->getKey(); // Inject parent ID (e.g., user_id)

                    foreach ($relationColumns as $column) {
                        if (str_contains($column, '.')) {
                            // Resolve dot-notation strings like 'vm.extern_vm_id' into a real database ID
                            $resolved = $this->resolveNestedRelationKey($relatedModelInstance, $column, $modelRelationItem);

                            if (!$resolved) {
                                return false; // Error already logged inside the helper method
                            }

                            $rowValues[$resolved['fk_name']] = $resolved['id'];
                            unset($rowValues[$column]); // Remove temporary 'vm.extern_vm_id' key

                            if (!in_array($resolved['fk_name'], $upsertKeys)) {
                                $upsertKeys[] = $resolved['fk_name'];
                            }
                        } else {
                            // Standard flat columns (e.g., command_code)
                            if (!in_array($column, $upsertKeys)) {
                                $upsertKeys[] = $column;
                            }
                        }
                    }

                    $preparedUpsertRows[] = $rowValues;
                }

                if (!empty($preparedUpsertRows)) {
                    // One upsert per row, because the columns of the config rows can differ
                    foreach ($preparedUpsertRows as $preparedUpsertRow) {
                        // update all columns except the unique keys
                        $updateColumns = array_values(array_diff(array_keys($preparedUpsertRow), $upsertKeys));

                        $relationInstance->upsert($preparedUpsertRow, $upsertKeys, $updateColumns ?: null);
                    }

                    // remove related rows not listed (by the first unique key after the parent foreign key, like vm_id)
                    if ($relationDelete && isset($upsertKeys[1])) {
                        $keepIds = collect($preparedUpsertRows)
                            ->pluck($upsertKeys[1])
                            ->toArray();
                        $relationInstance->whereNotIn($upsertKeys[1], $keepIds)
                            ->delete();
                    }
                }

            } elseif (($hasSync = method_exists($relationInstance, 'sync'))
                      || ($hasAssociate = method_exists($relationInstance, 'associate'))
            ) {

                // related id list have to be synced with ...
                $syncKeys = [];
                $syncModels = [];

                // preparing relation ids
                foreach ($modelRelationList as $modelRelationItem) {

                    /** @var Model $relatedClass */
                    $relatedClass = get_class($relationInstance->getRelated());

                    // force relationItems to be an array like $relationColumns
                    if (!is_array($modelRelationItem)) {
                        $modelRelationItem = [$modelRelationItem];
                    }
                    $newRelatedBuilder = $relatedClass::query();
                    foreach ($relationColumns as $_k => $_c) {
                        $newRelatedBuilder->where($_c, $modelRelationItem[$_k] ?? null);
                    }

                    foreach ($newRelatedBuilder->get() as $newRelated) {
                        $syncKeys[] = $newRelated->getKey();
                        $syncModels[] = $newRelated;
                    }

                }

                if ($hasSync && $syncKeys) {
                    // ---------------------------------------------------------
                    // sync relation
                    // ---------------------------------------------------------
                    /** @var BelongsToMany $relationInstance */
                    if ($relationDelete) {
                        // will remove all relations not in $sync
                        $relationInstance->sync($syncKeys);
                    } else {
                        // will not delete existing items
                        $relationInstance->syncWithoutDetaching($syncKeys);
                    }
                    $this->debug(sprintf("Synced %s '%s' relations", count($syncKeys), $relationMethod));
                }
                if ($hasAssociate ?? false) {
                    // ---------------------------------------------------------
                    // save with and without Events relations
                    // ---------------------------------------------------------
                    $associateCount = 0;
                    foreach ($syncModels as $syncModel) { // should be only one
                        if (method_exists($relationInstance, 'saveWithoutEvents')) {
                            $relationInstance->associate($syncModel)
                                ->saveWithoutEvents();
                        } else {
                            $relationInstance->associate($syncModel)
                                ->save();
                        }
                        $associateCount++;
                    }
                    $this->debug(sprintf("Associated %s '%s' relations", $associateCount, $relationMethod));
                }
            }
        }

        return true;
    }

    /**
     * Resolves dot-notation relation keys (e.g., 'vm.extern_vm_id') to their actual database foreign key and ID.
     *
     * @param  Model   $relatedModelInstance  The child model instance (e.g., ObserveItem)
     * @param  string  $column                The configuration column string (e.g., 'vm.extern_vm_id')
     * @param  array   $modelRelationItem     The raw input data row
     *
     * @return array|null                    Returns ['fk_name' => string, 'id' => mixed] or null on failure
     */
    private function resolveNestedRelationKey(Model $relatedModelInstance, string $column, array $modelRelationItem): ?array
    {
        static $resolvedCache = []; // Prevents duplicate DB queries for the same entity during one runtime cycle

        $nestedValue = $modelRelationItem[$column] ?? null;
        if ($nestedValue === null) {
            return null;
        }

        [$nestedRelationName, $nestedColumnName] = explode('.', $column);

        // Build a unique cache key based on the target class, column name, and value
        $nestedRelationInstance = $relatedModelInstance->$nestedRelationName();
        $targetClass = get_class($nestedRelationInstance->getRelated());
        $cacheKey = "{$targetClass}:{$nestedColumnName}:{$nestedValue}";

        if (!isset($resolvedCache[$cacheKey])) {
            $linkedModel = $targetClass::where($nestedColumnName, $nestedValue)
                ->first();

            if (!$linkedModel) {
                $this->error(sprintf("Related model %s with %s = '%s' not found!", $targetClass, $nestedColumnName, $nestedValue));

                return null;
            }

            $resolvedCache[$cacheKey] = [
                'fk_name' => $nestedRelationInstance->getForeignKeyName(), // e.g., 'vm_id'
                'id'      => $linkedModel->getKey(),
            ];
        }

        return $resolvedCache[$cacheKey];
    }

    /**
     * Expands rows with multiple values in unique columns into one row per value.
     * A value is multiple if it's an array or a string containing $separator.
     * Several multi-value unique columns produce all combinations.
     * Example: ['name' => 'david,uwe', 'is_enabled' => 1]
     *       => [['name' => 'david', 'is_enabled' => 1], ['name' => 'uwe', 'is_enabled' => 1]]
     *
     * @param  iterable          $modelList
     * @param  array             $uniqueColumns
     * @param  string|bool|null  $separator  false/null/'' to only expand arrays
     *
     * @return array
     */
    protected function expandMultiUniqueRows(iterable $modelList, array $uniqueColumns, string|bool|null $separator = ','): array
    {
        $result = [];
        foreach ($modelList as $modelData) {
            $rows = [$modelData];

            foreach ($uniqueColumns as $uniqueColumn) {
                $expandedRows = [];
                foreach ($rows as $row) {
                    $values = $this->splitMultiUniqueValue($row[$uniqueColumn] ?? null, $separator);
                    if ($values === null) {
                        // single value (or column missing), keep row unchanged
                        $expandedRows[] = $row;
                        continue;
                    }
                    foreach ($values as $value) {
                        $row[$uniqueColumn] = $value;
                        $expandedRows[] = $row;
                    }
                }
                $rows = $expandedRows;
            }

            if (count($rows) > 1) {
                $this->debug(sprintf("Expanded row to %d rows by unique columns", count($rows)));
            }
            array_push($result, ...$rows);
        }

        return $result;
    }

    /**
     * @param  mixed             $value
     * @param  string|bool|null  $separator
     *
     * @return array|null  list of trimmed, non-empty, unique values or null if not a multi value
     */
    protected function splitMultiUniqueValue(mixed $value, string|bool|null $separator): ?array
    {
        if (is_array($value)) {
            $values = $value;
        } elseif (is_string($value) && is_string($separator) && ($separator !== '') && str_contains($value, $separator)) {
            $values = explode($separator, $value);
        } else {
            return null;
        }

        $values = array_map(fn($v) => is_string($v) ? trim($v) : $v, $values);
        $values = array_filter($values, fn($v) => $v !== null && $v !== '');

        return array_values(array_unique($values, SORT_REGULAR));
    }

    /**
     * Model data without special keys starting with '#' like '#sync_relations'.
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
     * Finds an existing model by the unique data. A key like 'relation.column' uses whereHas().
     *
     * @param  string  $modelName
     * @param  array   $modelData
     * @param  array   $preparedUniqueData
     *
     * @return Model|null  null if not found or no unique data
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

    /**
     * parseEverything() for every string value of a nested array.
     *
     * @param  array  $data
     * @param  bool   $strict  throw an exception on unknown ${{placeholders}}
     *
     * @return array
     * @throws Exception
     */
    public function parseEverythingFromArray(array $data, bool $strict = false): array
    {
        array_walk_recursive($data, function (mixed &$value) use ($strict): void {
            if (is_string($value)) {
                $value = $this->parseEverything($value, $strict);
            }
        });

        return $data;
    }

    /**
     * 1) all env vars like ${APP_DOMAIN} will be parsed
     * 2) all {@see ParserService} vars will be parsed
     *
     * Can be used for something like this: APP_INSTANCE_SHORT_KEY="\${{hash}}"
     * Unknown ${{placeholders}} stay unchanged (or throw with $strict).
     *
     * @param  string  $envContent
     * @param  bool    $strict  throw an exception on unknown ${{placeholders}}
     *
     * @return string
     * @throws Exception
     */
    public function parseEverything(string $envContent, bool $strict = false): string
    {
        $this->parser->allowThrowExceptions = $strict;
        $this->parser->useInlineErrors = false;
        $this->parser->setPlaceholders($this->placeHolders);

        // parse env vars
        $envContent = app('system_base')->parseEnv($envContent);

        // parse by own parser
        return $this->parser->parse($envContent);
    }

    /**
     * Internal DNS hostname of the app container like 'app-myproject.internal'.
     * Same name as in compose.yaml (networks aliases), built by config 'app.compose_project_name'.
     * (gethostname() would return the volatile container id.)
     *
     * @return string
     */
    public function resolveCurrentContainerName(): string
    {
        return 'app-'.config('app.compose_project_name').'.internal';
    }

    /**
     * IP of the host machine from config 'app.ip' (default '127.0.0.1').
     *
     * @return string
     */
    public function getHostExternalIp(): string
    {
        return (string) config('app.ip', '127.0.0.1');
    }

}