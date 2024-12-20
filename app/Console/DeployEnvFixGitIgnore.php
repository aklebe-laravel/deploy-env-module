<?php

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvFixGitIgnore extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:fix-gitignore';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs git commands to fix gitignore tracks';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $r = $this->runProcess('git rm -r --cached .');
        if (!$r->successful()) {
            return CommandResult::FAILURE;
        }

        $r = $this->runProcess('git add .');
        if (!$r->successful()) {
            return CommandResult::FAILURE;
        }

        $r = $this->runProcess('git checkout -m "gitignore fixed by deploy-env process"');

        return $r->successful() ? CommandResult::SUCCESS : CommandResult::FAILURE;
    }

}
