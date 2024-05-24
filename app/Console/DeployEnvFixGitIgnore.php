<?php

namespace Modules\DeployEnv\app\Console;

use Illuminate\Console\Command;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;

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
    public function handle()
    {
        $r = $this->runProcess('git rm -r --cached .');
        if (!$r->successful()) {
            return Command::FAILURE;
        }

        $r = $this->runProcess('git add .');
        if (!$r->successful()) {
            return Command::FAILURE;
        }

        $r = $this->runProcess('git checkout -m "gitignore fixed by deploy-env process"');
        return $r->successful() ? Command::SUCCESS : Command::FAILURE;
    }

}
