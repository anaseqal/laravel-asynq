# Laravel Asynq

Laravel integration for Asynq task queue system.

## Installation

```bash
composer require anaseqal/laravel-asynq
```

Publish configuration:
```bash
php artisan vendor:publish --provider="AnasEqal\LaravelAsynq\AsynqServiceProvider"
```

## Configuration

Add to your Laravel project's config/database.php under redis connections:
```bash
'asynq' => [
    'host' => env('ASYNQ_REDIS_HOST', '127.0.0.1'),
    'password' => env('ASYNQ_REDIS_PASSWORD', null),
    'port' => env('ASYNQ_REDIS_PORT', 6379),
    'database' => env('ASYNQ_REDIS_DB', 0),
    'prefix' => '',
],
```

Add to your .env:
```env
ASYNQ_REDIS_HOST=127.0.0.1
ASYNQ_REDIS_PORT=6379
ASYNQ_REDIS_DB=0
ASYNQ_DEFAULT_QUEUE=default
```

## Usage

### Basic Usage
```php
use AnasEqal\LaravelAsynq\Facades\Asynq;

// Simple task
$taskId = Asynq::enqueueTask(
    'email:send',
    ['to' => 'user@example.com']
);

// With options
$taskId = Asynq::enqueueTask(
    'report:generate',
    ['reportId' => 123],
    'reports',
    [
        'delay' => 300,
        'retry' => 5,
        'timeout' => 1800
    ]
);
```

### Available Options
- delay: Seconds to wait before processing
- retry: Maximum retry attempts
- timeout: Task timeout in seconds
- retention: Data retention period
- uniqueKey: Unique task identifier
- groupKey: Task group identifier
- deadline: Task deadline offset
