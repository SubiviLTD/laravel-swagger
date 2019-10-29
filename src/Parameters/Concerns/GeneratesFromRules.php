<?php

namespace Mtrajano\LaravelSwagger\Parameters\Concerns;

use Illuminate\Support\Str;

trait GeneratesFromRules
{
    protected function splitRules($rules)
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        } else {
            return $rules;
        }
    }

    protected function getParamType(array $paramRules, $paramName = null)
    {
        if ($paramName && substr($paramName, -2) == 'id' && strpos($paramName, 'original_id') === false) {
            return 'integer';
        }

        if (in_array('integer', $paramRules)) {
            return 'integer';
        } else if (in_array('numeric', $paramRules)) {
            return 'number';
        } else if (in_array('boolean', $paramRules)) {
            return 'boolean';
        } else if (in_array('array', $paramRules)) {
            return 'array';
        } else {
            //date, ip, email, etc..
            return 'string';
        }
    }

    protected function isParamRequired(array $paramRules)
    {
        return in_array('required', $paramRules);
    }

    protected function isArrayParameter($param)
    {
        return Str::contains($param, '*');
    }

    protected function getArrayKey($param)
    {
        return current(explode('.', $param));
    }

    protected function getEnumValues(array $paramRules)
    {
        $in = $this->getInParameter($paramRules);

        if (!$in) {
            return [];
        }

        list($param, $vals) = explode(':', $in);

        return explode(',', $vals);
    }

    private function getInParameter(array $paramRules)
    {
        foreach ($paramRules as $rule) {
            if (is_array($rule)) {
                foreach ($rule as $item) {
                    if (Str::startsWith((string) $item, 'in:')) {
                        return $item;
                    }
                }
            } else {
                if (Str::startsWith((string) $rule, 'in:')) {
                    return $rule;
                }
            }
        }

        return false;
    }
}