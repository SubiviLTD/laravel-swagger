<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Basic Info
    |--------------------------------------------------------------------------
    |
    | The basic info for the application such as the title description,
    | description, version, etc...
    |
    */

    'title' => env('APP_NAME'),

    'description' => '',

    'appVersion' => '1.0.0',

    'host' => env('APP_URL'),

    'basePath' => '/',

    'schemes' => [
        // 'http',
        // 'https',
    ],

    'consumes' => [
        // 'application/json',
    ],

    'produces' => [
        // 'application/json',
    ],

    'definitions' => [
        'ResponsePagination' => [
            'type' => 'object',
            'properties' => [
                'total' => [
                    'type' => 'integer'
                ],
                'per_page' => [
                    'type' => 'integer'
                ],
                'current_page' => [
                    'type' => 'integer'
                ],
                'last_page' => [
                    'type' => 'integer'
                ],
                'next_page_url' => [
                    'type' => 'string'
                ],
                'prev_page_url' => [
                    'type' => 'string'
                ],
                'from' => [
                    'type' => 'integer'
                ],
                'to' => [
                    'type' => 'integer'
                ],
            ]
        ],

        'Response422' => [
            'title' => 'error',
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Error description'
                ],
                'data' => [
                    'type' => 'array',
                    'description' => 'The parameters that fails with validation',
                    'items' => [
                        'type' => 'object'
                    ]
                ]
            ]
        ],

        'Response404' => [
            'title' => 'error',
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Object / resource is not found'
                ]
            ]
        ],

        'Response401' => [
            'title' => 'error',
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Unauthorized'
                ]
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore methods
    |--------------------------------------------------------------------------
    |
    | Methods in the following array will be ignored in the paths array
    |
    */

    'ignoredMethods' => [
        'head',
    ],

    /*
    |--------------------------------------------------------------------------
    | Parse summary and descriptions
    |--------------------------------------------------------------------------
    |
    | If true will parse the action method docBlock and make it's best guess
    | for what is the summary and description. Usually the first line will be
    | used as the route's summary and any paragraphs below (other than
    | annotations) will be used as the description. It will also parse any
    | appropriate annotations, such as @deprecated.
    |
    */

    'parseDocBlock' => true,
];