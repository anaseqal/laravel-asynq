<?php

return [
    'connection' => env('ASYNQ_REDIS_CONNECTION', 'asynq'),

    'redis' => [
        'host' => env('ASYNQ_REDIS_HOST', '127.0.0.1'),
        'password' => env('ASYNQ_REDIS_PASSWORD', null),
        'port' => env('ASYNQ_REDIS_PORT', 6379),
        'database' => env('ASYNQ_REDIS_DB', 0),
    ],

    'defaults' => [
        'queue' => env('ASYNQ_DEFAULT_QUEUE', 'default'),
        'retry' => env('ASYNQ_DEFAULT_RETRY', 3),
        'timeout' => env('ASYNQ_DEFAULT_TIMEOUT', 60),
        'deadline' => env('ASYNQ_DEFAULT_DEADLINE', 3600),
    ],
];
