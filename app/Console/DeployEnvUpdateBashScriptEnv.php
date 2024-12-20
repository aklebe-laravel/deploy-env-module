<?php

namespace Modules\DeployEnv\app\Console;

use Exception;
use Illuminate\Console\Command;
use Modules\DeployEnv\app\Services\BashScriptsService;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvUpdateBashScriptEnv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:update-bash';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update env file in bash-scripts';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        $bashScriptsService = app(BashScriptsService::class);

        return $bashScriptsService->updateBashScripts() ? CommandResult::SUCCESS : CommandResult::FAILURE;
    }

}
