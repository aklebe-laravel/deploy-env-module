<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\tests\Unit;

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Modules\DeployEnv\app\Services\DeploymentService;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use ReflectionMethod;
use ReflectionProperty;

/**
 * cmd 'models' with 'relation.column' values and 'after_save', on an SQLite database in memory.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class DeploymentServiceModelsTest extends IsolatedTestCase
{
    /**
     * @var DeploymentService
     */
    protected DeploymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $resolver = new ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);

        $schema = $connection->getSchemaBuilder();
        $schema->create('test_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('test_tags', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('kind')
                ->default('a');
        });
        $schema->create('test_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id')
                ->nullable();
            $table->unsignedBigInteger('tag_id')
                ->nullable();
            $table->string('name');
            $table->unique(['owner_id', 'name']);
        });

        TestOwner::create(['name' => 'andi']);
        TestTag::create(['label' => 'red', 'kind' => 'a']);
        TestTag::create(['label' => 'red', 'kind' => 'b']);

        $this->setConfig('app.ip', '127.0.0.1');
        $this->setConfig('app.compose_project_name', 'proxtop');
        $this->service = new DeploymentService();
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();

        parent::tearDown();
    }

    /**
     * Calls a protected method.
     */
    protected function callProtected(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($this->service, $method))->invoke($this->service, ...$args);
    }

    /**
     * Writes a model definition to a temp business imports folder and runs it by cmd 'models'.
     *
     * @param  string  $definitionPhp  PHP code returning the definition (without '<?php')
     *
     * @return bool
     */
    protected function runModelsScript(string $definitionPhp): bool
    {
        $root = $this->makeTempDir();
        $this->makeFile($root.'/module-deploy-env/0001/models/test.php', "<?php\n".$definitionPhp);

        $this->service->runTerraformData([], null, '', false, $root);
        (new ReflectionProperty($this->service, 'currentDeployIdent'))->setValue($this->service, '0001');

        return $this->service->runConfigCmd('models', ['sources' => ['test.php']]);
    }

    public function test_resolveBelongsToValues(): void
    {
        $item = new TestItem();

        $this->assertSame(['name' => 'a', 'owner_id' => 1],
            $this->callProtected('resolveBelongsToValues', $item, ['name' => 'a', 'owner.name' => 'andi']));
        $this->assertSame(['name' => 'a', 'owner_id' => null],
            $this->callProtected('resolveBelongsToValues', $item, ['name' => 'a', 'owner.name' => null]));

        // several columns of the same relation search together
        $this->assertSame(['tag_id' => 2],
            $this->callProtected('resolveBelongsToValues', $item, ['tag.label' => 'red', 'tag.kind' => 'b']));

        // missing related model or no BelongsTo relation
        $this->assertNull($this->callProtected('resolveBelongsToValues', $item, ['owner.name' => 'nobody']));
        $this->assertNull($this->callProtected('resolveBelongsToValues', $item, ['missing.name' => 'x']));

        // not strict: the key is left out
        $this->assertSame(['name' => 'a'],
            $this->callProtected('resolveBelongsToValues', $item, ['name' => 'a', 'owner.name' => 'nobody'], false));
    }

    public function test_models_resolves_belongs_to_and_calls_after_save(): void
    {
        $GLOBALS['deployEnvTestAfterSave'] = [];

        $result = $this->runModelsScript(<<<'PHP'
return [
    'model'      => \Modules\DeployEnv\tests\Unit\TestItem::class,
    'uniques'    => ['owner.name', 'name'],
    'can_update' => true,
    'after_save' => function (\Illuminate\Database\Eloquent\Model $model, array $row) {
        $GLOBALS['deployEnvTestAfterSave'][] = $model->name.':'.$row['#file'];
    },
    'data'       => [
        ['owner.name' => 'andi', 'name' => 'gitea', '#file' => 'gitea.png'],
        // unknown user: skipped, after_save not called
        ['owner.name' => 'nobody', 'name' => 'lost', '#file' => 'lost.png'],
    ],
];
PHP
        );

        $this->assertTrue($result);
        $this->assertSame(['gitea:gitea.png'], $GLOBALS['deployEnvTestAfterSave']);
        $this->assertSame(1,
            TestItem::query()
                ->count());
        $this->assertSame(1,
            (int) TestItem::query()
                ->first()->owner_id);

        // second run finds the item by 'owner.name' + 'name' (update, no new item)
        $this->assertTrue($this->runModelsScript(<<<'PHP'
return [
    'model'      => \Modules\DeployEnv\tests\Unit\TestItem::class,
    'uniques'    => ['owner.name', 'name'],
    'can_update' => true,
    'data'       => [['owner.name' => 'andi', 'name' => 'gitea', 'tag.label' => 'red']],
];
PHP
        ));
        $this->assertSame(1,
            TestItem::query()
                ->count());
        $this->assertSame(1,
            (int) TestItem::query()
                ->first()->tag_id);

        unset($GLOBALS['deployEnvTestAfterSave']);
    }

    public function test_after_save_errors_are_logged_and_import_continues(): void
    {
        $result = $this->runModelsScript(<<<'PHP'
return [
    'model'      => \Modules\DeployEnv\tests\Unit\TestItem::class,
    'uniques'    => ['name'],
    'after_save' => fn() => throw new \RuntimeException('file missing'),
    'data'       => [['name' => 'a'], ['name' => 'b']],
];
PHP
        );

        $this->assertTrue($result);
        $this->assertSame(2,
            TestItem::query()
                ->count());
        $this->assertStringContainsString('file missing', implode("\n", $this->service->getErrors()));
    }

    public function test_after_save_has_to_be_callable(): void
    {
        $this->assertFalse($this->runModelsScript(<<<'PHP'
return [
    'model'      => \Modules\DeployEnv\tests\Unit\TestItem::class,
    'after_save' => 'not a function',
    'data'       => [['name' => 'a']],
];
PHP
        ));
        $this->assertSame(0,
            TestItem::query()
                ->count());
    }

    public function test_has_many_resolves_other_relation_values(): void
    {
        $result = $this->runModelsScript(<<<'PHP'
return [
    'model'      => \Modules\DeployEnv\tests\Unit\TestOwner::class,
    'uniques'    => ['name'],
    'can_update' => true,
    'relations'  => [
        'items' => ['method' => 'items', 'columns' => ['name']],
    ],
    'data'       => [
        [
            'name'            => 'andi',
            '#sync_relations' => [
                'items' => [
                    ['name' => 'with-tag', 'tag.label' => 'red'],
                    // unknown tag: the item is saved without tag
                    ['name' => 'unknown-tag', 'tag.label' => 'blue'],
                ],
            ],
        ],
    ],
];
PHP
        );

        $this->assertTrue($result);
        $this->assertSame(1,
            (int) TestItem::query()
                ->where('name', 'with-tag')
                ->value('tag_id'));
        $this->assertTrue(TestItem::query()
            ->where('name', 'unknown-tag')
            ->exists());
        $this->assertNull(TestItem::query()
            ->where('name', 'unknown-tag')
            ->value('tag_id'));
        $this->assertStringContainsString('{"label":"blue"} not found', implode("\n", $this->service->getErrors()));
    }
}

/**
 * Test models (SQLite tables created in setUp()).
 */
class TestOwner extends Model
{
    protected $table = 'test_owners';

    public $timestamps = false;

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(TestItem::class, 'owner_id');
    }
}

class TestTag extends Model
{
    protected $table = 'test_tags';

    public $timestamps = false;

    protected $guarded = [];
}

class TestItem extends Model
{
    protected $table = 'test_items';

    public $timestamps = false;

    protected $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(TestOwner::class, 'owner_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(TestTag::class, 'tag_id');
    }
}
