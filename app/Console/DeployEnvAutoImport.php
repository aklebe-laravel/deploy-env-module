<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Events\ImportRow;
use Modules\SystemBase\app\Services\Csv;

class DeployEnvAutoImport extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:auto-import {fileRegEx} {--dir=} {--type=} {--db_name=}';

    /**
     * @var string
     */
    const regExSeparator = '#';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importing file(s) by detecting their content. Valid file type: csv. RegEx "'.self::regExSeparator.'" separator will be used.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $fileRegEx = $this->argument('fileRegEx');
        $dir = storage_path('app/import');
        if ($dirOption = $this->option('dir')) {
            $dir = realpath($dir.DIRECTORY_SEPARATOR.$dirOption);
        }

        app('system_base_file')->runDirectoryFiles($dir, function ($file, $sourcePathInfo) use ($dir) {

            // determine type:
            if (!($type = $this->option('type'))) {
                if (!preg_match(self::regExSeparator.'(.*?)[-]'.self::regExSeparator, $sourcePathInfo['basename'],
                    $out)) {
                    $this->getOutput()->error("Unable to determine import type");
                    return false;
                }
                $type = $out[1];
            }

            // validate type to lower and singular
            $type = strtolower($type);
            $type = Str::singular($type);

            // prepare to import
            // Log::debug(sprintf("File: %s", $sourcePathInfo['basename']));
            $csv = app(Csv::class);
            $csv->init($sourcePathInfo['dirname'], $sourcePathInfo['basename']);
            $rowsImported = 0;
            if (!$csv->load(function ($row) use ($type, $sourcePathInfo, &$rowsImported) {

                // do the work ...
                // for products see: \Modules\Market\app\Listeners\ImportRow
                if (ImportRow::dispatch($type, $sourcePathInfo, $row)) {
                    $rowsImported++;
                }

            })) {
                $this->getOutput()->error(sprintf("Unable to load file: %s", $file));
                return false;
            }

            $this->getOutput()->text(sprintf("Imported: %s rows of '%s' from file '%s'", $rowsImported, $type,
                $sourcePathInfo['basename']));

            return true;

        }, regexWhitelist: [$fileRegEx], addDelimiters: self::regExSeparator);

        return Command::SUCCESS;
    }

}
