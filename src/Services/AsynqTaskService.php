<?php

namespace AnasEqal\LaravelAsynq\Services;

use AnasEqal\LaravelAsynq\Protobuf\TaskMessage;
use Illuminate\Support\Facades\Redis;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class AsynqTaskService
{
    private const DEFAULT_QUEUE = 'default';
    private const DEFAULT_RETRY = 3;
    private const DEFAULT_TIMEOUT = 60;
    private const DEFAULT_DEADLINE = 3600;

    private array $defaultOptions;

    public function __construct()
    {
        $this->defaultOptions = [
            'delay' => 0,
            'retry' => config('asynq.defaults.retry', self::DEFAULT_RETRY),
            'timeout' => config('asynq.defaults.timeout', self::DEFAULT_TIMEOUT),
            'retention' => 0,
            'uniqueKey' => '',
            'groupKey' => '',
        ];
    }

    private function createTaskOptions(array $options): array
    {
        $options = array_merge($this->defaultOptions, $options);

        return [
            'delay' => max(0, (int)$options['delay']),
            'retry' => max(0, (int)$options['retry']),
            'timeout' => max(0, (int)$options['timeout']),
            'retention' => max(0, (int)$options['retention']),
            'uniqueKey' => (string)$options['uniqueKey'],
            'groupKey' => (string)$options['groupKey'],
            'deadline' => (int)($options['deadline'] ??
                ($options['timeout'] > 0 ? $options['timeout'] : self::DEFAULT_DEADLINE))
        ];
    }

    private function createTaskMessage(
        string $type,
        array $payload,
        string $taskId,
        string $queue,
        array $options,
        int $processAt,
        int $deadline
    ): TaskMessage {
        $taskMessage = new TaskMessage();
        return $taskMessage->setType($type)
            ->setPayload(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setId($taskId)
            ->setQueue($queue)
            ->setRetry($options['retry'])
            ->setRetried(0)
            ->setTimeout($options['timeout'])
            ->setDeadline($deadline)
            ->setRetention($options['retention'])
            ->setUniqueKey($options['uniqueKey'])
            ->setGroupKey($options['groupKey']);
    }

    public function enqueueTask(
        string $type,
        array $payload,
        string $queue = self::DEFAULT_QUEUE,
        array $options = [],
        $redisClient = null
    ): string {
        $currentTime = time();
        $options = $this->createTaskOptions($options);
        $processAt = $currentTime + $options['delay'];
        $deadline = $processAt + $options['deadline'];
        $taskId = Uuid::uuid4()->toString();

        $taskMessage = $this->createTaskMessage(
            $type,
            $payload,
            $taskId,
            $queue,
            $options,
            $processAt,
            $deadline
        );

        $redis = $redisClient ?: Redis::connection(config('asynq.connection', 'asynq'));
        $taskKey = "asynq:{$queue}:t:{$taskId}";

        try {
            $pipe = $redis->pipeline();

            if ($options['uniqueKey']) {
                $uniqueKeyLock = "asynq:{$queue}:unique:{$options['uniqueKey']}";
                $pipe->set(
                    $uniqueKeyLock,
                    $taskId,
                    ['NX', 'EX' => $deadline - $currentTime]
                );
            }

            $msg = $taskMessage->serializeToString();

            if ($options['delay'] > 0) {
                $pipe->hmset($taskKey, [
                    'msg' => $msg,
                    'state' => 'scheduled',
                    'score' => $processAt
                ]);
                $pipe->zadd("asynq:{$queue}:scheduled", $processAt, $taskId);
            } else {
                $pipe->hmset($taskKey, [
                    'msg' => $msg,
                    'state' => 'pending'
                ]);
                $pipe->rpush("asynq:{$queue}:pending", $taskId);
            }

            $pipe->exec();

            return $taskId;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to enqueue task: {$e->getMessage()}", 0, $e);
        }
    }
}
