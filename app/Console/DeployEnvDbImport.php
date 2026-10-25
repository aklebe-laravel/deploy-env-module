<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Support\Facades\DB;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\SystemBase\app\Services\DatabaseService;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvDbImport extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:db-import {sql_file} {--db_name=} {--no_db_check} {--drop}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importing a dump or just sql statements. With --db_name into this database (created if missing, MySQL/MariaDB). --drop drops it first, --no_db_check skips the existence check.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $dbService = app(DatabaseService::class);
        $sqlFilename = $this->argument('sql_file');

        if (($scriptContent = app('system_base_file')->loadFile($sqlFilename)) === false) {
            $this->getOutput()->error(sprintf("Unable to read file: %s", $sqlFilename));

            return CommandResult::FAILURE;
        }

        $dbName = $this->option('db_name') ?: '';

        // the name is used in raw sql, so validate it first
        if ($dbName && !preg_match('#^[a-zA-Z0-9_]+$#', $dbName)) {
            $this->getOutput()->error(sprintf("Invalid DB Name: %s", $dbName));

            return CommandResult::FAILURE;
        }

        if ($dbName && $this->option('drop')) {
            DB::unprepared('DROP DATABASE IF EXISTS `'.$dbName.'`;');
        }

        // create db if not exists
        if ($dbName && !$this->option('no_db_check')) {
            $query = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?";
            if (!DB::select($query, [$dbName])) {
                DB::unprepared('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;');
            }
        }

        $dbService->rememberCurrentDatabase();

        if ($dbName) {
            $dbService->setDatabaseName($dbName);
        }

        try {
            $success = DB::unprepared($scriptContent);
        } finally {
            $dbService->resetDatabase();
        }

        return $success ? CommandResult::SUCCESS : CommandResult::FAILURE;
    }

}
