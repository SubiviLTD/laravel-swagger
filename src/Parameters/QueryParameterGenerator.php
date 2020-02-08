<?php

namespace Mtrajano\LaravelSwagger\Parameters;

class QueryParameterGenerator implements ParameterGenerator
{
    use Concerns\GeneratesFromRules;

    protected $rules;
    protected $customParams;

    public function __construct($rules, $customParams)
    {
        $this->rules = $rules;
        $this->customParams = $customParams;
    }

    public function getParameters()
    {
        $params = [];
        $arrayTypes = [];

        foreach  ($this->rules as $param => $rule) {
            $paramRules = $this->splitRules($rule);
            $enums = $this->getEnumValues($paramRules);
            $type = $this->getParamType($paramRules, $param);

            if ($param == 'order_by.*') {
                $param = $this->getArrayKey($param);
            }

            if ($this->isArrayParameter($param)) {
                $arrayKey = $this->getArrayKey($param);
                $arrayTypes[$arrayKey] = [
                    'type' => $type,
                    'enums' => $enums
                ];
                continue;
            }

            $paramObj = [
                'in' => $this->getParamLocation(),
                'name' => $param,
                'type' => $type,
                'required' => $this->isParamRequired($paramRules),
                'description' => '',
            ];

            if (!empty($enums)) {
                $paramObj['enum'] = $enums;
            }

            if ($type === 'array') {
                $paramObj['items'] = ['type' => 'string'];
            }

            $params[$param] = $paramObj;
        }

        $params = array_values($this->addArrayTypes($params, $arrayTypes));

        if (!empty($this->customParams)) {
            $params = array_merge($params, $this->customParams);
        }

        return $params;
    }

    protected function addArrayTypes($params, $arrayTypes)
    {
        foreach ($arrayTypes as $arrayKey => $obj) {
            if (!isset($params[$arrayKey])) {
                $params[$arrayKey] = [
                    'in' => $this->getParamLocation(),
                    'name' => $arrayKey,
                    'type' => 'array',
                    'required' => false,
                    'description' => '',
                    'items' => [
                        'type' => $obj['type'],
                    ],
                ];

                if (!empty($obj['enums'])) {
                    $params[$arrayKey]['items']['enum'] = array_map(function($val) {
                        return trim($val, '"');
                    }, $obj['enums']);
                }
            } else {
                $params[$arrayKey]['type'] = 'array';
                $params[$arrayKey]['items']['type'] = $obj['type'];

                if (!empty($obj['enums'])) {
                    $params[$arrayKey]['items']['enum'] =
                    $propObj['enum'] = array_map(function($val) {
                        return trim($val, '"');
                    }, $obj['enums']);
                }
            }
        }

        return $params;
    }

    public function getParamLocation()
    {
        return 'query';
    }
}