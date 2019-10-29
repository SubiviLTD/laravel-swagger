<?php

namespace Mtrajano\LaravelSwagger;

use App\User;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;
use Barryvdh\Reflection\DocBlock\Tag;
use Composer\Autoload\ClassMapGenerator;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionClass;
use Illuminate\Support\Str;
use Illuminate\Routing\Route;
use Illuminate\Foundation\Http\FormRequest;
use phpDocumentor\Reflection\DocBlockFactory;
use Symfony\Component\Console\Output\OutputInterface;

class ModelsGenerator
{
    protected $dirs = ['app'];
    protected $properties = array();
    protected $methods = array();
    protected $write_model_magic_where = true;

    public function generate()
    {
        $output = [];

        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

        foreach ($this->getAppModels() as $name) {

            $this->properties = array();
            $this->methods = array();

            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new ReflectionClass($name);

                    if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        continue;
                    }

                    if (!$reflectionClass->IsInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }

                    if (in_array($reflectionClass->getShortName(), ['Pivot', 'MorphPivot', 'Model'])) {
                        // ignore abstract class or interface
                        continue;
                    }

                    $model = app()->make($name);

                    if ($hasDoctrine) {
                        $this->getPropertiesFromTable($model);
                    }

                    if (method_exists($model, 'getCasts')) {
                        $this->castPropertiesType($model);
                    }

                    $this->getPropertiesFromMethods($model);
                    $this->getSoftDeleteMethods($model);
                    $output[$reflectionClass->getShortName()]= $this->createModelDoc($name);
                    $ignore[] = $name;
                    $this->nullableColumns = [];
                } catch (\Throwable $e) {
                    //Just skip
//                    $this->error("Exception: " . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }
        }

        return $output;
    }

    protected function getAppModels()
    {
        $models = array();
        foreach ($this->dirs as $dir) {
            $dir = base_path() . '/' . $dir;
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }
        return $models;
    }


    /**
     * cast the properties's type from $casts.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function castPropertiesType($model)
    {
        $casts = $model->getCasts();
        foreach ($casts as $name => $type) {
            switch ($type) {
                case 'boolean':
                case 'bool':
                    $realType = 'boolean';
                    break;
                case 'string':
                    $realType = 'string';
                    break;
                case 'array':
                case 'json':
                    $realType = 'array';
                    break;
                case 'object':
                    $realType = 'object';
                    break;
                case 'int':
                case 'integer':
                case 'timestamp':
                    $realType = 'integer';
                    break;
                case 'real':
                case 'double':
                case 'float':
                    $realType = 'number';
                    break;
                case 'date':
                case 'datetime':
                    $realType = '\Illuminate\Support\Carbon';
                    break;
                case 'collection':
                    $realType = '\Illuminate\Support\Collection';
                    break;
                default:
                        $realType = class_exists($type) ? ('\\' . $type) : 'object';
                    break;
            }

            if (!isset($this->properties[$name])) {
                continue;
            } else {
                $this->properties[$name]['type'] = $this->getTypeOverride($realType);

                if (isset($this->nullableColumns[$name])) {
//                    $this->properties[$name]['type'] .= '|null';
                }
            }
        }
    }

    /**
     * Returns the overide type for the give type.
     *
     * @param string $type
     * @return string
     */
    protected function getTypeOverride($type)
    {
        return $type;
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable($model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

        $platformName = $databasePlatform->getName();

        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = '\Illuminate\Support\Carbon';
                } else {
                    $type = $column->getType()->getName();
                    switch ($type) {
                        case 'string':
                        case 'text':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                            $type = 'string';
                            break;
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                            $type = 'integer';
                            break;
                        case 'boolean':
                            switch (config('database.default')) {
                                case 'sqlite':
                                case 'mysql':
                                    $type = 'integer';
                                    break;
                                default:
                                    $type = 'boolean';
                                    break;
                            }
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'number';
                            break;
                        default:
                            $type = 'object';
                            break;
                    }
                }

                $comment = $column->getComment();
                if (!$column->getNotnull()) {
                    $this->nullableColumns[$name] = true;
                }
                $this->setProperty($name, $type, true, true, $comment, !$column->getNotnull());
                if ($this->write_model_magic_where) {
                    $this->setMethod(
                        Str::camel("where_" . $name),
                        '\Illuminate\Database\Eloquent\Builder|\\' . get_class($model),
                        array('$value')
                    );
                }
            }
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model)
    {
        $methods = get_class_methods($model);
        if ($methods) {
            sort($methods);
            foreach ($methods as $method) {
                if (Str::startsWith($method, 'get') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'getAttribute'
                ) {
                    //Magic get<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $type = $this->getReturnTypeFromDocBlock($reflection);
                        $this->setProperty($name, $type, true, null);
                    }
                } elseif (Str::startsWith($method, 'set') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'setAttribute'
                ) {
                    //Magic set<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $this->setProperty($name, null, null, true);
                    }
                } elseif (Str::startsWith($method, 'scope') && $method !== 'scopeQuery') {
                    //Magic set<name>Attribute
                    $name = Str::camel(substr($method, 5));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $args = $this->getParameters($reflection);
                        //Remove the first ($query) argument
                        array_shift($args);
                        $this->setMethod($name, '\Illuminate\Database\Eloquent\Builder|\\' . $reflection->class, $args);
                    }
                } elseif (in_array($method, ['query', 'newQuery', 'newModelQuery'])) {
                    $reflection = new \ReflectionClass($model);

                    $builder = get_class($model->newModelQuery());

                    $this->setMethod($method, "\\{$builder}|\\" . $reflection->getName());
                } elseif (!method_exists('Illuminate\Database\Eloquent\Model', $method)
                    && !Str::startsWith($method, 'get')
                ) {
                    //Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                    $reflection = new \ReflectionMethod($model, $method);
                    // php 7.x type or fallback to docblock
                    $type = (string) ($reflection->getReturnType() ?: $this->getReturnTypeFromDocBlock($reflection));

                    $file = new \SplFileObject($reflection->getFileName());
                    $file->seek($reflection->getStartLine() - 1);

                    $code = '';
                    while ($file->key() < $reflection->getEndLine()) {
                        $code .= $file->current();
                        $file->next();
                    }
                    $code = trim(preg_replace('/\s\s+/', '', $code));
                    $begin = strpos($code, 'function(');
                    $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);

                    foreach (array(
                                 'hasMany' => '\Illuminate\Database\Eloquent\Relations\HasMany',
                                 'hasManyThrough' => '\Illuminate\Database\Eloquent\Relations\HasManyThrough',
                                 'belongsToMany' => '\Illuminate\Database\Eloquent\Relations\BelongsToMany',
                                 'hasOne' => '\Illuminate\Database\Eloquent\Relations\HasOne',
                                 'belongsTo' => '\Illuminate\Database\Eloquent\Relations\BelongsTo',
                                 'morphOne' => '\Illuminate\Database\Eloquent\Relations\MorphOne',
                                 'morphTo' => '\Illuminate\Database\Eloquent\Relations\MorphTo',
                                 'morphMany' => '\Illuminate\Database\Eloquent\Relations\MorphMany',
                                 'morphToMany' => '\Illuminate\Database\Eloquent\Relations\MorphToMany',
                                 'morphedByMany' => '\Illuminate\Database\Eloquent\Relations\MorphToMany'
                             ) as $relation => $impl) {
                        $search = '$this->' . $relation . '(';
                        if (stripos($code, $search) || stripos($impl, (string)$type) !== false) {
                            //Resolve the relation's model to a Relation object.
                            $methodReflection = new \ReflectionMethod($model, $method);
                            if ($methodReflection->getNumberOfParameters()) {
                                continue;
                            }

                            $relationObj = $model->$method();

                            if ($relationObj instanceof Relation) {
                                $relatedModel = '\\' . get_class($relationObj->getRelated());

                                $relations = [
                                    'hasManyThrough',
                                    'belongsToMany',
                                    'hasMany',
                                    'morphMany',
                                    'morphToMany',
                                    'morphedByMany',
                                ];
                                if (strpos(get_class($relationObj), 'Many') !== false) {
                                    //Collection or array of models (because Collection is Arrayable)
                                    $this->setProperty(
                                        $method,
                                        $relatedModel . '[]',
                                        true,
                                        null
                                    );
//                                    $this->setProperty(
//                                        Str::snake($method) . '_count',
//                                        'int',
//                                        true,
//                                        false
//                                    );
                                } elseif ($relation === "morphTo") {
                                    // Model isn't specified because relation is polymorphic
                                    $this->setProperty(
                                        $method,
                                        '\Illuminate\Database\Eloquent\Model|\Eloquent',
                                        true,
                                        null
                                    );
                                } else {
                                    //Single model is returned
                                    $this->setProperty(
                                        $method,
                                        $relatedModel,
                                        true,
                                        null,
                                        '',
                                        $this->isRelationForeignKeyNullable($relationObj)
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Check if the foreign key of the relation is nullable
     *
     * @param Relation $relation
     *
     * @return bool
     */
    private function isRelationForeignKeyNullable(Relation $relation)
    {
        $reflectionObj = new \ReflectionObject($relation);
        if (!$reflectionObj->hasProperty('foreignKey')) {
            return false;
        }
        $fkProp = $reflectionObj->getProperty('foreignKey');
        $fkProp->setAccessible(true);

        return isset($this->nullableColumns[$fkProp->getValue($relation)]);
    }

    /**
     * @param string      $name
     * @param string|null $type
     * @param bool|null   $read
     * @param bool|null   $write
     * @param string|null $comment
     * @param bool        $nullable
     */
    protected function setProperty($name, $type = null, $read = null, $write = null, $comment = '', $nullable = false)
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = array();
            $this->properties[$name]['type'] = 'object';
            $this->properties[$name]['read'] = false;
            $this->properties[$name]['write'] = false;
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if ($type !== null) {
            $newType = $this->getTypeOverride($type);
            if ($nullable) {
//                $newType .='|null';
            }
            $this->properties[$name]['type'] = $newType;
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }

    protected function setMethod($name, $type = '', $arguments = array())
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);

        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name] = array();
            $this->methods[$name]['type'] = $type;
            $this->methods[$name]['arguments'] = $arguments;
        }
    }

    /**
     * @param string $class
     * @return array
     */
    protected function createModelDoc($class)
    {
        $doc = [
            'type' => 'object',
            'properties' => []
        ];

        foreach ($this->properties as $name => $property) {

            $doc['properties'][$name] = [];

            $type = $property['type'];
            if (substr($type, 0, 5) == '\App\\') {
                if (substr($type, -2) == '[]') {
                    $doc['properties'][$name]['type'] = 'array';
                    $doc['properties'][$name]['items'] = ['$ref' => "#/definitions/" . substr($type, 5, -2)];
                } else {
                    $doc['properties'][$name]['$ref'] = "#/definitions/" . substr($type, 5);
                }
            } elseif ($type == '\Illuminate\Database\Eloquent\Model|\Eloquent') {
                $doc['properties'][$name]['type'] = 'object';
            } elseif ($type == '\Illuminate\Support\Carbon') {
                $doc['properties'][$name]['type'] = 'string';
                $doc['properties'][$name]['format'] = 'date-time';
            } else {
                $doc['properties'][$name]['type'] = $type;

                if (in_array($type, ['\Illuminate\Notifications\DatabaseNotification[]', '\Laravel\Passport\Client[]', '\Laravel\Passport\Token[]', 'array'])) {
                    $doc['properties'][$name]['type'] = 'array';
                    $doc['properties'][$name]['items'] = [
                        'type' => 'object'
                    ];
                } elseif ($type == 'bool') {

                    $doc['properties'][$name]['type'] = 'boolean';
                }
             };
        }

        return $doc;
    }

    /**
     * Get the parameters and format them correctly
     *
     * @param $method
     * @return array
     */
    public function getParameters($method)
    {
        //Loop through the default values for paremeters, and make the correct output string
        $params = array();
        $paramsWithDefault = array();
        /** @var \ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramClass = $param->getClass();
            $paramStr = (!is_null($paramClass) ? '\\' . $paramClass->getName() . ' ' : '') . '$' . $param->getName();
            $params[] = $paramStr;
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = 'array()';
                } elseif (is_null($default)) {
                    $default = 'null';
                } elseif (is_int($default)) {
                    //$default = $default;
                } else {
                    $default = "'" . trim($default) . "'";
                }
                $paramStr .= " = $default";
            }
            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }

    /**
     * Determine a model classes' collection type.
     *
     * @see http://laravel.com/docs/eloquent-collections#custom-collections
     * @param string $className
     * @return string
     */
    private function getCollectionClass($className)
    {
        // Return something in the very very unlikely scenario the model doesn't
        // have a newCollection() method.
        if (!method_exists($className, 'newCollection')) {
            return '\Illuminate\Database\Eloquent\Collection';
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $className;
        return '\\' . get_class($model->newCollection());
    }

    /**
     * Get method return type based on it DocBlock comment
     *
     * @param \ReflectionMethod $reflection
     *
     * @return null|string
     */
    protected function getReturnTypeFromDocBlock(\ReflectionMethod $reflection)
    {
        $type = null;
        $phpdoc = new DocBlock($reflection);

        if ($phpdoc->hasTag('return')) {
            $type = $phpdoc->getTagsByName('return')[0]->getType();
        }

        return $type;
    }

    /**
     * Generates methods provided by the SoftDeletes trait
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getSoftDeleteMethods($model)
    {
        $traits = class_uses(get_class($model), true);
        if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', $traits)) {
            $this->setMethod('forceDelete', 'bool|null', []);
            $this->setMethod('restore', 'bool|null', []);

            $this->setMethod('withTrashed', '\Illuminate\Database\Query\Builder|\\' . get_class($model), []);
            $this->setMethod('withoutTrashed', '\Illuminate\Database\Query\Builder|\\' . get_class($model), []);
            $this->setMethod('onlyTrashed', '\Illuminate\Database\Query\Builder|\\' . get_class($model), []);
        }
    }

    /**
     * @param ReflectionClass $reflection
     * @return string
     */
    private function getClassKeyword(ReflectionClass $reflection)
    {
        if ($reflection->isFinal()) {
            $keyword = 'final ';
        } elseif ($reflection->isAbstract()) {
            $keyword = 'abstract ';
        } else {
            $keyword = '';
        }

        return $keyword;
    }
}