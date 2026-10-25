<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\tests\Unit;

use Modules\DeployEnv\app\Listeners\ImportRow;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;

class ImportRowListenerTest extends IsolatedTestCase
{
    /**
     * Listener exposing the protected helpers.
     */
    protected function makeListener(): object
    {
        return new class extends ImportRow {
            protected array $columnTypeMap = [
                'active' => 'bool',
                'price'  => 'double',
                'amount' => 'int',
            ];

            public function cast(string $column, mixed $value): mixed
            {
                return $this->typeCast($column, $value);
            }

            public function basic(array $source, string $key, ?string $destKey = null): array
            {
                $dest = [];
                $this->addBasicColumnIfPresent($source, $dest, $key, $destKey);

                return $dest;
            }

            public function custom(array $source, string $key, ?callable $callback = null): array
            {
                $dest = [];
                $this->addCustomColumnIfPresent($source, $dest, $key, null, $callback);

                return $dest;
            }
        };
    }

    public function test_typeCast(): void
    {
        $listener = $this->makeListener();

        $this->assertTrue($listener->cast('active', 'TRUE'));
        $this->assertTrue($listener->cast('active', ' on '));
        $this->assertTrue($listener->cast('active', '1'));
        $this->assertFalse($listener->cast('active', 'no'));
        $this->assertFalse($listener->cast('active', ''));
        $this->assertSame(12.5, $listener->cast('price', '12,5'));
        $this->assertSame(0.0, $listener->cast('price', ''));
        $this->assertSame(3, $listener->cast('amount', '3,7'));
        $this->assertSame('x', $listener->cast('other', 'x'));
    }

    public function test_column_helpers(): void
    {
        $listener = $this->makeListener();

        $this->assertSame(['price' => 1.5], $listener->basic(['price' => '1,5'], 'price'));
        $this->assertSame(['cost' => 1.5], $listener->basic(['price' => '1,5'], 'price', 'cost'));
        $this->assertSame([], $listener->basic([], 'price'));

        $this->assertSame(['name' => 'X'], $listener->custom(['name' => 'x'], 'name', fn() => 'X'));
        // without callback the source value is used
        $this->assertSame(['name' => 'x'], $listener->custom(['name' => 'x'], 'name'));
    }
}
