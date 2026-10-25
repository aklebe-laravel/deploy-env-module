<?php

namespace Modules\DeployEnv\app\Listeners;

use Modules\DeployEnv\app\Events\ImportRow as ImportRowEvent;

/**
 * Base listener for ImportRow with helpers to map and type cast columns.
 * Extend it, set $requiredTypesSingular and $columnTypeMap and overwrite handle().
 */
class ImportRow
{
    /**
     * Column name => type for typeCast(): bool/boolean, double, int/integer.
     *
     * @var array
     */
    protected array $columnTypeMap = [];

    /**
     * Import types (singular) handled by this listener.
     *
     * @var array
     */
    protected array $requiredTypesSingular = [];

    /**
     * The current event (set by handle()).
     *
     * @var ImportRowEvent
     */
    public ImportRowEvent $event;

    /**
     * @param  string  $t
     *
     * @return bool
     */
    protected function isRequiredType($t): bool
    {
        return in_array($t, $this->requiredTypesSingular);
    }

    /**
     * Handle the event.
     *
     * @param  ImportRowEvent  $event
     *
     * @return bool  false to stop all following listeners
     */
    public function handle(ImportRowEvent $event): bool
    {
        $this->event = $event;

        return true;
    }

    /**
     * If $source[$sourceKey] exists, $dest[$destKey] is set to the result of $callback()
     * (without callback: the source value).
     *
     * @param  array          $source
     * @param  array          $dest
     * @param  string         $sourceKey
     * @param  string|null    $destKey  null: same as $sourceKey
     * @param  callable|null  $callback
     *
     * @return bool  false if the source column does not exist
     */
    protected function addCustomColumnIfPresent(array &$source, array &$dest, string $sourceKey, ?string $destKey = null, ?callable $callback = null): bool
    {
        if (!isset($source[$sourceKey])) {
            return false;
        }

        $dest[$destKey ?? $sourceKey] = $callback ? $callback() : $source[$sourceKey];

        return true;
    }

    /**
     * If source exists, dest is set to the source value, type cast by $columnTypeMap
     *
     * @param  array        $source
     * @param  array        $dest
     * @param  string       $sourceKey
     * @param  string|null  $destKey
     * @param  mixed|null   $default
     *
     * @return bool
     */
    protected function addBasicColumnIfPresent(array &$source, array &$dest, string $sourceKey, ?string $destKey = null, mixed $default = null): bool
    {
        return $this->addCustomColumnIfPresent($source, $dest, $sourceKey, $destKey,
            function () use (&$source, $sourceKey, $default) {
                $v = data_get($source, $sourceKey, $default);

                return $this->typeCast($sourceKey, $v);
            });
    }

    /**
     * Casts a value by $columnTypeMap:
     * - bool/boolean: 'true', '1', 'on' (case-insensitive) => true, everything else false
     * - double: ',' as decimal separator allowed
     * - int/integer: ',' as decimal separator allowed, decimals are cut
     *
     * @param  string  $column
     * @param  mixed   $value
     *
     * @return mixed
     */
    protected function typeCast($column, $value): mixed
    {
        if (!($t = data_get($this->columnTypeMap, $column))) {
            return $value;
        }

        switch ($t) {
            case 'bool':
            case 'boolean':
                if ($value) {
                    $value = strtolower(trim((string) $value));
                    $value = ($value === 'true' || $value === '1' || $value === 'on');
                } else {
                    $value = false;
                }
                break;

            case 'double':
                if ($value) {
                    $value = str_replace(',', '.', (string) $value);
                }
                $value = (float) $value;
                break;

            case 'int':
            case 'integer':
                if ($value) {
                    $value = str_replace(',', '.', (string) $value);
                }
                $value = (int) $value;
                break;
        }

        return $value;
    }

}
