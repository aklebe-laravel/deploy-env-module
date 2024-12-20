<?php

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\AssetService;
use Symfony\Component\Console\Command\Command as CommandResult;

class DeployEnvBuildMercyAssets extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy-env:build-mercy-assets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preparing assets of enabled modules and theme(s) in storage/app/mercy-generated/';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        /** @var AssetService $assetService */
        $assetService = app(AssetService::class);
        $assetService->buildMercyAssets();

        return CommandResult::SUCCESS;
    }

}
