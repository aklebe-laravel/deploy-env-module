<?php

namespace Modules\DeployEnv\app\Listeners;

use Modules\DeployEnv\app\Events\ImportRow as ImportRowEvent;

class ImportRow
{
    /**
     * @var array
     */
    protected array $columnTypeMap = [];

    /**
     * @var array
     */
    protected array $requiredTypesSingular = [];

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * @param $t
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
     * @return bool  false to stop all following listeners
     */
    public function handle(ImportRowEvent $event): bool
    {
        return true;
    }

    /**
     * If source exists, dest will set with return from callback()
     *
     * @param  array          $source
     * @param  array          $dest
     * @param  string         $sourceKey
     * @param  string|null    $destKey
     * @param  callable|null  $callback
     * @return bool
     */
    protected function addCustomColumnIfPresent(array &$source, array &$dest, string $sourceKey,
        ?string $destKey = null, callable $callback = null): bool
    {
        if (!isset($source[$sourceKey])) {
            return false;
        }

        if ($destKey === null) {
            $destKey = $sourceKey;
        }

        $dest[$destKey] = $callback();

        return true;
    }

    /**
     * If source exists, dest will simply set with source value
     *
     * @param  array        $source
     * @param  array        $dest
     * @param  string       $sourceKey
     * @param  string|null  $destKey
     * @param  mixed|null   $default
     * @return bool
     */
    protected function addBasicColumnIfPresent(array &$source, array &$dest, string $sourceKey, ?string $destKey = null,
        mixed $default = null): bool
    {
        return $this->addCustomColumnIfPresent($source, $dest, $sourceKey, $destKey,
            function () use (&$source, $sourceKey, $default) {
                $v = data_get($source, $sourceKey, $default);
                return $this->typeCast($sourceKey, $v);
            });
    }

    /**
     * @param $column
     * @param $value
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
                    $value = strtolower($value);
                    $value = ($value === 'true' || $value === '1' || $value === 'on');
                } else {
                    $value = false;
                }
                break;

            case 'double':
                if ($value) {
                    $value = str_replace(',', '.', $value);
                }
                $value = (double) $value;
                break;

            case 'int':
            case 'integer':
                if ($value) {
                    $value = str_replace(',', '.', $value);
                }
                $value = (int) (double) $value;
                break;
        }

        return $value;
    }

}
