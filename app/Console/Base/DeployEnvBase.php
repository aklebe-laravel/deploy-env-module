<?php

namespace Modules\DeployEnv\app\Console\Base;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult as ContractsProcessResult;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command as CommandResult;

/**
 * Every DeployEnv command should extend this class
 */
class DeployEnvBase extends Command
{
    /**
     *
     * @var bool
     */
    public bool $composerDumpAutoLoadNeededForNewModules = true;

    /**
     * @var bool
     */
    protected bool $noInteractionForExternCalls = false;

    /**
     * One og them: 'echo', 'log', 'callback'
     * Otherwise, nothing will happen. If 'callback' then $processOutputCallback have to be set.
     *
     * @var string
     */
    public string $processOutput = 'echo';

    /**
     * Only needed if $processOutput is set to 'callback'.
     * call $this->processOutputCallback(string $type, string $output)
     *
     * @var callable
     */
    public $processOutputCallback = null;

    /**
     * @var string
     */
    private string $secretMaintenanceKey = '';

    /**
     * Print out and log the message.
     * If $message is array or object, the output will be formatted.
     *
     * @param  mixed   $message
     * @param  string  $logLevel
     *
     * @return void
     */
    public function message(mixed $message, string $logLevel = LogLevel::DEBUG): void
    {
        if (!is_scalar($message)) {
            $message = print_r($message, true);
        }

        $this->output->writeln($message);
        Log::log($logLevel, $message);
    }

    /**
     * @param  bool  $handleMaintenanceMode
     *
     * @return int
     */
    public function runProcessSystemUpdate(bool $handleMaintenanceMode = true): int
    {
        // if $this->noInteractionForExternCalls already set to true, we won't change it
        if (!$this->noInteractionForExternCalls) {
            $this->noInteractionForExternCalls = $this->option('no-interaction');
        }

        if ($handleMaintenanceMode && !$this->option('dev-mode')) {
            if ($this->confirm("Enable maintenance mode first?", true)) {
                if ($this->enableMaintenanceMode()) {
                    $this->showMaintenanceInfo();
                }
            }
        }

        // next step: composer update
        if ($this->confirm("Starting composer update?", true)) {
            // $currentUpdateResult = $this->runProcessArtisanClearCompiled();
            // if ($currentUpdateResult->failed()) {
            //     return Command::FAILURE;
            // }
            // $currentUpdateResult = $this->runProcessComposerDump();
            // if ($currentUpdateResult->failed()) {
            //     return Command::FAILURE;
            // }
            // $currentUpdateResult = $this->runProcessArtisanOptimize();
            // if ($currentUpdateResult->failed()) {
            //     return Command::FAILURE;
            // }
            $currentUpdateResult = $this->runProcessComposerUpdate();
            if ($currentUpdateResult->failed()) {
                return CommandResult::FAILURE;
            }
        } else {
            $this->warn("Don't forget update composer manually!");
        }

        // next step: npm update
        if ($this->confirm("Starting npm update?", true)) {
            $currentUpdateResult = $this->runProcessNpmUpdate();
            if ($currentUpdateResult->failed()) {
                return CommandResult::FAILURE;
            }
        } else {
            $this->warn("Don't forget update npm manually!");
        }

        //        // Clear Caches will start below ...
        //        if ($this->confirm("Clear caches?", true)) {
        //            $currentUpdateResult = $this->runProcessArtisanCacheClear();
        //            if ($currentUpdateResult->failed()) {
        //                return CommandResult::FAILURE;
        //            }
        //        }

        // next step: migrate
        if ($this->confirm("Starting artisan migrate?", true)) {
            $currentUpdateResult = $this->runProcessArtisanMigrate();
            if ($currentUpdateResult->failed()) {
                return CommandResult::FAILURE;
            }
        } else {
            $this->warn("Don't forget to run 'php artisan migrate' manually!");
        }

        // cache clear 1a)
        Cache::flush();
        $cmd = $this->getFinalArtisanProcessCmd('cache:clear');
        $this->runProcess($cmd);
        //$this->runProcessArtisanCacheClear();

        // next step: deploy env update
        if ($this->confirm("Starting modules deployment env update?", true)) {
            $currentUpdateResult = $this->runProcessDeployEnvUpdate();
            if ($currentUpdateResult->failed()) {
                return CommandResult::FAILURE;
            }
        } else {
            $this->warn("Don't forget to run 'php artisan deploy-env:terraform-modules' manually!");
        }

        // cache clear 1b)
        Cache::flush();
        $cmd = $this->getFinalArtisanProcessCmd('cache:clear');
        $this->runProcess($cmd);
        //$this->runProcessArtisanCacheClear();

        // Npm build ...
        if ($this->confirm("Starting rebuild frontend?", true)) {
            $currentUpdateResult = $this->runProcessArtisanBuildFrontend();
            if ($currentUpdateResult->failed()) {
                return CommandResult::FAILURE;
            }
        }

        if (!$this->option('dev-mode')) {
            $this->runProcessArtisanOptimize();
        }

        if ($handleMaintenanceMode && !$this->option('dev-mode')) {
            if ($this->confirm("Disable maintenance mode now?", true)) {
                if (!$this->disableMaintenanceMode()) {
                    $this->showMaintenanceInfo();
                }
            }
        }

        $this->printHeadLine('System update finished successfully!');
        $this->comment(number_format(app('system_base')->getExecutionTime(LARAVEL_START), 2, '.', '').' sec');

        return CommandResult::SUCCESS;
    }

    /**
     * @param $title
     *
     * @return void
     */
    protected function printHeadLine($title): void
    {
        $padChar = '+';
        $title = $padChar.'  '.$title.'  '.$padChar;
        $lineMark = str_pad('', strlen($title), $padChar);
        $this->line("");
        $this->line($lineMark);
        $this->line($title);
        $this->line($lineMark);
    }

    /**
     * @param  string  $cmd
     * @param  array   $options
     *
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcess(string $cmd, array $options = []): ProcessResult|ContractsProcessResult
    {
        $cmd = self::addCommandOptions($cmd, $options);

        $this->printHeadLine("Process: '$cmd'");

        $result = Process::forever()->run($cmd, function (string $type, string $output) {
            $this->printProcessOutput($type, $output);
        });

        if ($result->successful()) {
            $this->line("Process: OK! ($cmd)");
        }
        if ($result->failed()) {
            $this->line("Process: Failed! ($cmd)");
        }

        $this->line("");

        return $result;
    }

    /**
     * Overwrite this to send output to your favorite.
     *
     * @param  string  $type
     * @param  string  $output
     *
     * @return void
     */
    protected function printProcessOutput(string $type, string $output): void
    {
        switch ($this->processOutput) {
            case 'echo':
                echo $output;
                break;
            case 'log':
                Log::log($type, $output);
                break;
            case 'callback':
                if (($this->processOutputCallback) && (app('system_base')->isCallableClosure($this->processOutputCallback))) {
                    $this->processOutputCallback($type, $output);
                }
                break;
        }
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessComposerUpdate(): ProcessResult|ContractsProcessResult
    {
        $options = [];
        if (!$this->option('dev-mode')) {
            $options[] = '--no-dev';
        }

        return $this->runProcess('composer update', $options);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessArtisanClearCompiled(): ProcessResult|ContractsProcessResult
    {
        $cmd = $this->getFinalArtisanProcessCmd('clear-compiled');

        return $this->runProcess($cmd);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessArtisanOptimize(): ProcessResult|ContractsProcessResult
    {
        $cmd = $this->getFinalArtisanProcessCmd('optimize');

        return $this->runProcess($cmd);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessComposerDump(): ProcessResult|ContractsProcessResult
    {
        $options = [];

        return $this->runProcess('composer dump-autoload', $options);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessNpmUpdate(): ProcessResult|ContractsProcessResult
    {
        return $this->runProcess('npm update');
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessNpmBuild(): ProcessResult|ContractsProcessResult
    {
        return $this->runProcess('npm run build');
    }

    /**
     * @param  string  $cmd
     * @param  array   $options
     *
     * @return string
     */
    public static function addCommandOptions(string $cmd, array $options = []): string
    {
        foreach ($options as $k => $option) {
            // if option key is string we build an assign --my_option=value
            if (is_string($k)) {
                $cmd .= ' '.$k.'='.$option;
            } else { // otherwise, just build the option/switch
                $cmd .= ' '.$option;
            }
        }

        return $cmd;
    }

    /**
     * @param  string  $cmd
     * @param  array   $options  can be assoc indexed or just have values or mixed
     *
     * @return string
     */
    protected function getFinalArtisanProcessCmd(string $cmd, array $options = []): string
    {
        $cmd = 'php artisan '.$cmd;
        if ($this->noInteractionForExternCalls) {
            $cmd .= ' --no-interaction';
        }

        return self::addCommandOptions($cmd, $options);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessArtisanBuildFrontend(): ProcessResult|ContractsProcessResult
    {
        $cmd = $this->getFinalArtisanProcessCmd('deploy-env:build-frontend');

        return $this->runProcess($cmd);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessArtisanMigrate(): ProcessResult|ContractsProcessResult
    {
        $cmd = $this->getFinalArtisanProcessCmd('migrate');

        return $this->runProcess($cmd);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessDeployEnvUpdate(): ProcessResult|ContractsProcessResult
    {
        $cmd = $this->getFinalArtisanProcessCmd('deploy-env:terraform-modules');

        return $this->runProcess($cmd);
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessArtisanCacheClear(): ProcessResult|ContractsProcessResult
    {
        $cmd = $this->getFinalArtisanProcessCmd('cache:clear');
        $result1 = $this->runProcess($cmd);

        //// This should clear "bootstrap/cache", but it didn't
        //$cmd = $this->getFinalArtisanProcessCmd('optimize:clear');
        //$this->runProcess($cmd);

        // remove php files in "bootstrap/cache" folder because "cache:clear" will not remove everything
        app('system_base_file')->runDirectoryFiles(base_path("bootstrap/cache"), function (string $file, array $sourcePathInfo) {
            if ($sourcePathInfo['extension'] === 'php') {
                unlink($file);
            }
        });

        $cmd = $this->getFinalArtisanProcessCmd('route:clear');
        $this->runProcess($cmd);
        $cmd = $this->getFinalArtisanProcessCmd('config:clear');
        $this->runProcess($cmd);
        $cmd = $this->getFinalArtisanProcessCmd('view:clear');
        $this->runProcess($cmd);

        return $result1;
    }

    /**
     * @return ProcessResult|ContractsProcessResult
     */
    public function runProcessArtisanViewCache(): ProcessResult|ContractsProcessResult
    {
        $cmd = $this->getFinalArtisanProcessCmd('view:cache');

        return $this->runProcess($cmd);
    }

    /**
     * @return bool
     */
    public function enableMaintenanceMode(): bool
    {
        // Setup maintenance if not already done ...
        if (!app()->isDownForMaintenance()) {
            $this->secretMaintenanceKey = uniqid('maintenance-');
            $cmd = $this->getFinalArtisanProcessCmd('down', ['--secret' => $this->secretMaintenanceKey]);
            $result = $this->runProcess($cmd);

            return $result->successful();
        }

        return true;
    }

    /**
     * @param  bool  $onlyWhenOwnShutdown
     *
     * @return bool
     */
    public function disableMaintenanceMode(bool $onlyWhenOwnShutdown = true): bool
    {
        if (!app()->isDownForMaintenance()) {
            $this->secretMaintenanceKey = '';

            return true;
        }

        if (!$onlyWhenOwnShutdown || $this->secretMaintenanceKey) {
            $cmd = $this->getFinalArtisanProcessCmd('up');
            $result = $this->runProcess($cmd);
            $this->secretMaintenanceKey = '';

            return $result->successful();
        }

        return false;
    }

    /**
     * @return void
     */
    protected function showMaintenanceInfo(): void
    {
        if (app()->isDownForMaintenance()) {
            if ($this->secretMaintenanceKey) {
                $this->message("Your site is in maintenance currently. Secret key: $this->secretMaintenanceKey");
                $this->message("Type 'php artisan up' or check the site in maintenance mode by typing the following line in your browser:");
                $this->message(URL::to("/".$this->secretMaintenanceKey));
            } else {
                $this->message('The application is already in maintenance mode by a previous process.');
            }
        }
    }

    /**
     * @return array
     */
    protected function getEnabledOptions(): array
    {
        $result = [];
        foreach ($this->options() as $k => $v) {
            if ($v) {
                $result[] = '--'.$k;
            }
        }

        return $result;
    }

}
