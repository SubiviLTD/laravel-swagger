<?php

namespace Mtrajano\LaravelSwagger;

use App\User;
use Composer\Autoload\ClassMapGenerator;
use ReflectionMethod;
use Illuminate\Support\Str;
use Illuminate\Routing\Route;
use Illuminate\Foundation\Http\FormRequest;
use phpDocumentor\Reflection\DocBlockFactory;

class Generator
{
    protected $config;

    protected $routeFilter;

    protected $docs;

    protected $uri;

    protected $originalUri;

    protected $method;

    protected $action;

    private $middlewares;

    public function __construct($config, $routeFilter = null)
    {
        auth()->setUser(User::first());
        $this->config = $config;
        $this->routeFilter = $routeFilter;
        $this->docParser = DocBlockFactory::createInstance();
    }

    public function generate()
    {
        $this->docs = $this->getBaseInfo();

        foreach ($this->getAppRoutes() as $route) {

            $this->originalUri = $uri = $this->getRouteUri($route);
            $this->uri = strip_optional_char($uri);

            if ($this->routeFilter && !preg_match('/^' . preg_quote($this->routeFilter, '/') . '/', $this->uri)) {
                continue;
            }

            $this->middlewares = $route->gatherMiddleware();

            $this->action = $route->getAction()['uses'];
            $methods = $route->methods();

            if (!isset($this->docs['paths'][$this->uri])) {
                $this->docs['paths'][$this->uri] = [];
            }

            foreach ($methods as $method) {
                $this->method = strtolower($method);

                if (in_array($this->method, $this->config['ignoredMethods'])) continue;

                $this->generatePath();
            }
        }

        return $this->docs;
    }

    protected function getBaseInfo()
    {
        $baseInfo = [
            'swagger' => '2.0',
            'info' => [
                'title' => $this->config['title'],
                'description' => $this->config['description'],
                'version' => $this->config['appVersion'],
            ],
            'host' => preg_replace('#^https?://#', '', $this->config['host']),
            'basePath' => $this->config['basePath'],
            'securityDefinitions' => ['Bearer' => [
                'type' => 'apiKey',
                'name' => 'Authorization',
                'in' => 'header'
            ]]
        ];

        if (!empty($this->config['schemes'])) {
            $baseInfo['schemes'] = $this->config['schemes'];
        }

        if (!empty($this->config['consumes'])) {
            $baseInfo['consumes'] = $this->config['consumes'];
        }

        if (!empty($this->config['produces'])) {
            $baseInfo['produces'] = $this->config['produces'];
        }

        $baseInfo['paths'] = [];

        $definitions = $this->config['definitions'];
        $baseInfo['definitions'] = array_merge($definitions, (new ModelsGenerator())->generate());
        $baseInfo['responses'] = $this->config['responses'];

        return $baseInfo;
    }

    protected function getAppRoutes()
    {
        return app('router')->getRoutes();
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

    protected function getRouteUri(Route $route)
    {
        $uri = $route->uri();

        if (!Str::startsWith($uri, '/')) {
            $uri = '/' . $uri;
        }

        return $uri;
    }

    protected function generatePath()
    {
        $actionInstance = is_string($this->action) ? $this->getActionClassInstance($this->action) : null;
        $docBlock = $actionInstance ? ($actionInstance->getDocComment() ?: "") : "";

        list($isDeprecated, $summary, $description) = $this->parseActionDocBlock($docBlock);

        $doc = [
            'summary' => $summary,
            'description' => $description,
            'deprecated' => $isDeprecated,
            'responses' => [
                '200' => [
                    'description' => 'OK'
                ]
            ],
        ];

        if (in_array('auth:api', $this->middlewares)) {
            $doc['security'] = [
                [
                    "Bearer" => []
                ]
            ];
            $doc['responses']['401'] = [
                '$ref' => '#/responses/Unauthorized'
            ];
        }

        $this->docs['paths'][$this->uri][$this->method] = $doc;

        $this->addActionParameters();
    }

    protected function addActionParameters()
    {
        $rules = $this->getFormRules() ?: [];

        $parameters = (new Parameters\PathParameterGenerator($this->originalUri))->getParameters();

        if (!empty($rules)) {
            $parameterGenerator = $this->getParameterGenerator($rules);

            $parameters = array_merge($parameters, $parameterGenerator->getParameters());
        }

        if (!empty($parameters)) {
            $this->docs['paths'][$this->uri][$this->method]['parameters'] = $parameters;
            $this->docs['paths'][$this->uri][$this->method]['responses']['422'] = [
                '$ref' => '#/responses/ValidationError'
            ];
        }
    }

    protected function getFormRules()
    {
        if (!is_string($this->action)) return false;

        $parameters = $this->getActionClassInstance($this->action)->getParameters();

        foreach ($parameters as $parameter) {
            $class = (string)$parameter->getType();

            if (is_subclass_of($class, FormRequest::class)) {
                app()->bind($class, function () use ($class) {
                    $mock = \Mockery::mock($class)->makePartial();
                    $mock->shouldReceive('route')
                        ->andReturnUsing(function ($argument) {
                            return new class($argument)
                            {
                                protected $model;

                                public function __construct($model)
                                {
                                    $this->model = $model;
                                }

                                public function __get($name)
                                {
                                    return '<' . $this->model . '-' . $name . '>';
                                }

                                public function __toString()
                                {
                                    return '<' . $this->model . '-id>';
                                }
                            };
                        });
                    $mock->shouldReceive('authorize')->andReturn(true);
                    $mock->shouldReceive('user')->andReturn(auth()->user());

                    $mock->shouldReceive('validateResolved')->andReturnNull();

                    return $mock;
                });

                return app()->call([app($class), 'rules']);
            }
        }
    }

    protected function getParameterGenerator($rules)
    {
        switch ($this->method) {
            case 'post':
            case 'put':
            case 'patch':
                return new Parameters\BodyParameterGenerator($rules);
            default:
                return new Parameters\QueryParameterGenerator($rules);
        }
    }

    private function getActionClassInstance(string $action)
    {
        list($class, $method) = Str::parseCallback($action);

        return new ReflectionMethod($class, $method);
    }

    private function parseActionDocBlock(string $docBlock)
    {
        if (empty($docBlock) || !$this->config['parseDocBlock']) {
            return [false, "", ""];
        }

        try {
            $parsedComment = $this->docParser->create($docBlock);

            $isDeprecated = $parsedComment->hasTag('deprecated');

            $summary = $parsedComment->getSummary();
            $description = (string)$parsedComment->getDescription();

            return [$isDeprecated, $summary, $description];
        } catch (\Exception $e) {
            return [false, "", ""];
        }
    }
}