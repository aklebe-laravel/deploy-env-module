<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Support\Str;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Events\ImportContent;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvAutoImport extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:auto-import {fileRegEx} {--root=} {--dir=} {--type=} {--db_name=}';

    /**
     * @var string
     */
    const string regExSeparator = '#';

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
    public function handle(): int
    {
        $fileRegEx = $this->argument('fileRegEx');
        if ($rootOption = $this->option('root')) {
            $dir = $rootOption;
        } else {
            $dir = storage_path('app/import');
        }
        if ($dirOption = $this->option('dir')) {
            $dir = realpath($dir.DIRECTORY_SEPARATOR.$dirOption);
        }

        // get all files ...
        app('system_base_file')->runDirectoryFiles($dir, function ($file, $sourcePathInfo) use ($dir) {

            // determine type:
            if (!($type = $this->option('type'))) {
                // get type automatically by filename ...
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

            // for example see:
            // \Modules\Market\app\Listeners\ImportRowProduct
            // \Modules\Market\app\Listeners\ImportContentMarket
            ImportContent::dispatch($type, $sourcePathInfo);
            return true;

        }, regexWhitelist: [$fileRegEx], addDelimiters: self::regExSeparator);

        //
        app('system_base')->logExecutionTime("Seeded ".__METHOD__);

        return CommandResult::SUCCESS;
    }

}
