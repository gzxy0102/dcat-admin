<?php

namespace Dcat\Admin\Scaffold;

use Dcat\Admin\Exception\AdminException;
use Illuminate\Database\Migrations\MigrationCreator as BaseMigrationCreator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;

class MigrationCreator extends BaseMigrationCreator
{
    /**
     * 允许的字段类型(与页面脚手架可选类型一致).
     *
     * @var array
     */
    protected static $allowedColumnTypes = [
        'string', 'integer', 'text', 'float', 'double', 'decimal', 'boolean', 'date', 'time',
        'dateTime', 'timestamp', 'char', 'mediumText', 'longText', 'tinyInteger', 'smallInteger',
        'mediumInteger', 'bigInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger',
        'unsignedInteger', 'unsignedBigInteger', 'enum', 'json', 'jsonb', 'dateTimeTz', 'timeTz',
        'timestampTz', 'nullableTimestamps', 'binary', 'ipAddress', 'macAddress',
    ];

    /**
     * 允许的字段索引类型.
     *
     * @var array
     */
    protected static $allowedKeyTypes = ['index', 'unique', 'primary'];

    /**
     * @var string
     */
    protected $bluePrint = '';

    /**
     * Create a new migration creator instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    /**
     * Create a new model.
     *
     * @param  string  $name
     * @param  string  $path
     * @param  null  $table
     * @param  bool|true  $create
     * @return string
     */
    public function create($name, $path, $table = null, $create = true)
    {
        $this->ensureMigrationDoesntAlreadyExist($name);

        $path = $this->getPath($name, $path);

        $stub = $this->files->get(__DIR__.'/stubs/create.stub');

        $this->files->put($path, $this->populateAdminStub($name, $stub, $table));
        $this->files->chmod($path, 0664);

        $this->firePostCreateHooks($table, $path);

        return $path;
    }

    /**
     * Populate stub.
     *
     * @param  string  $name
     * @param  string  $stub
     * @param  string  $table
     * @return mixed
     */
    protected function populateAdminStub($name, $stub, $table)
    {
        return str_replace(
            ['DummyClass', 'DummyTable', 'DummyStructure'],
            [$this->getClassName($name), var_export((string) $table, true), $this->bluePrint],
            $stub
        );
    }

    /**
     * Build the table blueprint.
     *
     * @param  array  $fields
     * @param  string  $keyName
     * @param  bool|true  $useTimestamps
     * @param  bool|false  $softDeletes
     * @return $this
     *
     * @throws \Exception
     */
    public function buildBluePrint($fields = [], $keyName = 'id', $useTimestamps = true, $softDeletes = false)
    {
        $fields = array_filter($fields, function ($field) {
            return isset($field['name']) && ! empty($field['name']);
        });

        if (empty($fields)) {
            throw new AdminException('Table fields can\'t be empty');
        }

        $keyName = (string) $keyName;

        if (! $this->isValidIdentifier($keyName)) {
            throw new AdminException("Invalid primary key [$keyName]");
        }

        $rows[] = "\$table->bigIncrements('$keyName');\n";

        foreach ($fields as $field) {
            $name = (string) $field['name'];

            if (! $this->isValidIdentifier($name)) {
                throw new AdminException("Invalid field name [$name]");
            }

            $type = (string) Arr::get($field, 'type');

            if (! in_array($type, static::$allowedColumnTypes, true)) {
                throw new AdminException("Invalid field type [$type]");
            }

            $column = "\$table->{$type}('{$name}')";

            $key = (string) Arr::get($field, 'key');

            if ($key) {
                if (! in_array($key, static::$allowedKeyTypes, true)) {
                    throw new AdminException("Invalid field key [$key]");
                }

                $column .= "->{$key}()";
            }

            $hasDefault = isset($field['default'])
                && ! is_null($field['default'])
                && $field['default'] !== '';
            if ($hasDefault) {
                $column .= '->default('.var_export((string) $field['default'], true).')';
            }

            if (Arr::get($field, 'nullable') == 'on') {
                $column .= '->nullable()';
            } elseif (! $hasDefault && $type === 'string') {
                $column .= "->default('')";
            }

            if (isset($field['comment']) && $field['comment']) {
                $column .= '->comment('.var_export((string) $field['comment'], true).')';
            }

            $rows[] = $column.";\n";
        }

        if ($useTimestamps) {
            $rows[] = "\$table->timestamps();\n";
        }

        if ($softDeletes) {
            $rows[] = "\$table->softDeletes();\n";
        }

        $this->bluePrint = trim(implode(str_repeat(' ', 12), $rows), "\n");

        return $this;
    }

    /**
     * 校验表字段名/主键等标识符,防止注入 PHP 代码.
     *
     * @param  string  $name
     * @return bool
     */
    protected function isValidIdentifier($name)
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }
}
