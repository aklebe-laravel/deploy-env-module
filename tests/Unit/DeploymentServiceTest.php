<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\tests\Unit;

use Exception;
use Modules\DeployEnv\app\Services\DeploymentService;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;
use ReflectionMethod;

/**
 * DeploymentService parts which work without database and modules.
 */
class DeploymentServiceTest extends IsolatedTestCase
{
    /**
     * @var DeploymentService
     */
    protected DeploymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfig('app.ip', '127.0.0.1');
        $this->setConfig('app.compose_project_name', 'proxtop');
        $this->service = new DeploymentService();
    }

    /**
     * Calls a protected method.
     */
    protected function callProtected(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($this->service, $method))->invoke($this->service, ...$args);
    }

    public function test_hash_placeholder_is_stable_for_same_content(): void
    {
        $first = $this->service->parseEverything("\${{hash content='abc'}}");

        $this->assertSame(12, strlen($first));
        $this->assertSame($first, $this->service->parseEverything("\${{hash content='abc'}}"));
    }

    public function test_conditions(): void
    {
        $this->assertTrue($this->service->checkCondition('function', fn() => true));
        $this->assertFalse($this->service->checkCondition('function', fn() => false));
        $this->assertFalse($this->service->checkCondition('function', 'not callable'));
        $this->assertFalse($this->service->checkCondition('unknown_code', 'x'));
        $this->assertFalse($this->service->checkCondition('db_table_column_exists', 'missing_dot'));

        $this->assertTrue($this->service->checkAllConditions([['function' => fn() => true], ['function' => fn() => true]]));
        $this->assertFalse($this->service->checkAllConditions([['function' => fn() => true, 'unknown' => 1]]));
        $this->assertTrue($this->service->checkAllConditions([]));
    }

    public function test_function_condition_resolves_non_static_methods_by_container(): void
    {
        $this->assertTrue($this->service->checkCondition('function', [DeploymentServiceTestCondition::class, 'check']));
    }

    public function test_runConfigCmd_unknown_cmd_fails(): void
    {
        $this->assertFalse($this->service->runConfigCmd('does_not_exist', []));
        $errors = $this->service->getErrors();
        $this->assertStringContainsString('runConfigCmdDoesNotExist', (string) end($errors));
    }

    public function test_runConfigCmdFunctions_calls_every_function(): void
    {
        $calls = [];
        $result = $this->service->runConfigCmd('functions', [
            'sources' => [
                'first'  => function ($cmd, $key) use (&$calls) {
                    $calls[] = "$cmd:$key";
                },
                'second' => function ($cmd, $key) use (&$calls) {
                    $calls[] = "$cmd:$key";
                },
            ],
        ]);

        $this->assertTrue($result);
        $this->assertSame(['functions:first', 'functions:second'], $calls);
    }

    public function test_runConfigCmdFunctions_validates_before_calling(): void
    {
        $called = false;
        $result = $this->service->runConfigCmd('functions', [
            'sources' => [
                'ok'  => function () use (&$called) {
                    $called = true;
                },
                'bad' => 'not a function',
            ],
        ]);

        $this->assertFalse($result);
        $this->assertFalse($called);
    }

    public function test_runTerraformData_fails_without_cmd(): void
    {
        $this->assertFalse($this->service->runTerraformData(['0001' => [['sources' => []]]], 'test', '', true));
        // the indent is reset after the failure
        $this->assertSame(0, $this->service->getIndentValue());
    }

    public function test_model_data_helpers(): void
    {
        $data = ['code' => 'admin', 'name' => 'Admin', '#sync_relations' => ['x' => []], 0 => 'numeric key'];

        $this->assertSame(['code' => 'admin', 'name' => 'Admin', 0 => 'numeric key'], $this->callProtected('cleanedModelData', $data));
        $this->assertSame(['code' => 'admin'], $this->callProtected('getPreparedUniqueData', $data, ['code', 'missing']));
    }

    public function test_deploy_script_path_for_custom_location(): void
    {
        $this->service->runTerraformData([], null, '', false, '/custom/root');
        (new \ReflectionProperty($this->service, 'currentDeployIdent'))->setValue($this->service, '0001');

        $this->assertSame('/custom/root/module-deploy-env/0001/models/x.php', $this->callProtected('getDeployScriptPath', 'models', 'x.php'));
    }
}

/**
 * Condition class with a non-static method for test_function_condition_resolves_non_static_methods_by_container().
 */
class DeploymentServiceTestCondition
{
    public function check(string $conditionCode): bool
    {
        return $conditionCode === 'function';
    }
}
