<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\app\Console;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\DeployEnv\app\Services\DeploymentService;
use Symfony\Component\Console\Command\Command as CommandResult;

class MercyInfoCommand extends DeployEnvBase
{
    /**
     * @var string
     */
    protected $signature = 'mercy:info';

    /**
     * @var string
     */
    protected $description = 'Shows important env vars, docker hosts/ports and URLs of this instance.';

    /**
     * @var array|string[]
     */
    protected array $portsEnvVars = ['APP_PORT', 'FORWARD_DB_PORT', 'FORWARD_REDIS_PORT', 'FORWARD_MAILPIT_PORT', 'FORWARD_MAILPIT_DASHBOARD_PORT', 'VITE_PORT', 'REVERB_SERVER_PORT'];

    /**
     * @return int
     */
    public function handle(): int
    {
        $this->showEnvVarsTable($this->summaryEnvVars, "Important Vars");
        $this->showEnvVarsTable($this->portsEnvVars, "ENV Ports");

        $this->showKeyValueTable((array) config('docker.hosts', []), "Docker Hosts");
        $this->showKeyValueTable((array) config('docker.ports', []), "Docker Ports");

        $deploymentService = app(DeploymentService::class);
        $host = $deploymentService->resolveCurrentContainerName();
        $ip = $deploymentService->getHostExternalIp();
        $port = config('app.port', '');
        $urlPortPart = ($port ? (':'.$port) : '');
        $rawUrl1 = "http://".$ip.$urlPortPart;
        $rawUrl2 = "http://".$host.$urlPortPart;
        $pingPath = '/ping';
        $keyValues = [
            'App'               => config('app.name', ''),
            'Version'           => config('app.version', ''),
            'Host'              => $host,
            'IP'                => $ip,
            'Port'              => config('app.port', ''),
            'Url'               => config('app.url', ''),
            'Ping Url'          => config('app.url', '').$pingPath,
            'Ping Url (raw)'    => $rawUrl1.$pingPath,
            'Ping Url (docker)' => $rawUrl2.$pingPath,
            'Timezone'          => config('app.timezone', 'UTC'),
            'Locale'            => config('app.locale', ''),
            'Debug'             => config('app.debug', false) ? 'true' : 'false',
        ];
        $this->showKeyValueTable($keyValues, "Quick-view");

        return CommandResult::SUCCESS;
    }
}
