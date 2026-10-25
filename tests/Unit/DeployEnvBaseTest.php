<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\tests\Unit;

use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;
use ReflectionMethod;

class DeployEnvBaseTest extends IsolatedTestCase
{
    /**
     * A minimal command extending DeployEnvBase.
     */
    protected function makeCommand(): DeployEnvBase
    {
        return new class extends DeployEnvBase {
            protected $signature = 'deploy-env:unit-test';
        };
    }

    /**
     * Calls a protected method.
     */
    protected function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    public function test_addCommandOptions(): void
    {
        $this->assertSame('composer install', DeployEnvBase::addCommandOptions('composer install'));
        $this->assertSame('composer install --no-dev --optimize-autoloader',
            DeployEnvBase::addCommandOptions('composer install', ['--no-dev', '--optimize-autoloader']));
        $this->assertSame('cmd --env=prod --force', DeployEnvBase::addCommandOptions('cmd', ['--env' => 'prod', '--force']));
    }

    public function test_getBoolValue(): void
    {
        $command = $this->makeCommand();

        foreach (['true', 'on', '1', 'yes', true, 1] as $value) {
            $this->assertTrue($this->callProtected($command, 'getBoolValue', $value), var_export($value, true));
        }
        foreach (['false', 'off', '0', '', null, 'something'] as $value) {
            $this->assertFalse($this->callProtected($command, 'getBoolValue', $value), var_export($value, true));
        }
    }

    public function test_process_output_callback(): void
    {
        $command = $this->makeCommand();
        $received = [];
        $command->processOutput = 'callback';
        $command->processOutputCallback = function (string $type, string $output) use (&$received) {
            $received[] = [$type, $output];
        };

        $this->callProtected($command, 'printProcessOutput', 'out', 'hello');

        $this->assertSame([['out', 'hello']], $received);
    }

    public function test_process_output_log_uses_valid_levels(): void
    {
        $command = $this->makeCommand();
        $command->processOutput = 'log';

        $this->callProtected($command, 'printProcessOutput', 'out', 'normal');
        $this->callProtected($command, 'printProcessOutput', 'err', 'problem');

        $this->assertSame(['normal'], $this->getLoggedMessages('info'));
        $this->assertSame(['problem'], $this->getLoggedMessages('error'));
    }
}
