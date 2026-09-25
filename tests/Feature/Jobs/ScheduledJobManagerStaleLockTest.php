<?php

use App\Jobs\ScheduledJobManager;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

/**
 * In-memory stand-in for the phpredis client covering exactly the command surface
 * ScheduledJobManager's lock self-healing uses, with Redis's TTL semantics:
 * -1 for a key that exists without an expiry, -2 for a missing key.
 *
 * The test environment has neither the phpredis extension nor a reachable Redis
 * server, so the Redis facade is pointed at this client through a custom connector.
 * The job's production code path (config prefix -> ttl -> conditional del) runs
 * unchanged against it.
 */
class InMemoryRedisClientForStaleLockTest
{
    /** @var array<string, string> */
    public array $store = [];

    /** @var array<string, int|null> */
    public array $expiry = [];

    public function set($key, $value): bool
    {
        $this->store[$key] = $value;
        $this->expiry[$key] = null;

        return true;
    }

    public function expire($key, $seconds): bool
    {
        if (! array_key_exists($key, $this->store)) {
            return false;
        }

        $this->expiry[$key] = time() + $seconds;

        return true;
    }

    public function ttl($key): int
    {
        if (! array_key_exists($key, $this->store)) {
            return -2;
        }

        if ($this->expiry[$key] === null) {
            return -1;
        }

        return max(0, $this->expiry[$key] - time());
    }

    public function exists(...$keys): int
    {
        $live = array_filter($keys, fn ($key) => $this->ttl($key) > 0 || $this->ttl($key) === -1);

        return count($live);
    }

    public function del(...$keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->store)) {
                unset($this->store[$key], $this->expiry[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }
}

beforeEach(function () {
    $client = new InMemoryRedisClientForStaleLockTest;

    config(['database.redis.client' => 'in-memory-stale-lock-test']);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstances();

    Redis::extend('in-memory-stale-lock-test', fn () => new class($client)
    {
        public function __construct(protected InMemoryRedisClientForStaleLockTest $client) {}

        public function connect(array $config, array $options): PhpRedisConnection
        {
            return new PhpRedisConnection($this->client);
        }
    });

    $this->redis = Redis::connection('default');
});

it('clears stale lock when TTL is -1', function () {
    $cachePrefix = config('cache.prefix');
    $lockKey = $cachePrefix.'laravel-queue-overlap:'.ScheduledJobManager::class.':scheduled-job-manager';

    $this->redis->set($lockKey, 'stale-owner');

    expect($this->redis->ttl($lockKey))->toBe(-1);

    $job = new ScheduledJobManager;
    $job->middleware();

    expect($this->redis->exists($lockKey))->toBe(0);
});

it('preserves valid lock with positive TTL', function () {
    $cachePrefix = config('cache.prefix');
    $lockKey = $cachePrefix.'laravel-queue-overlap:'.ScheduledJobManager::class.':scheduled-job-manager';

    $this->redis->set($lockKey, 'active-owner');
    $this->redis->expire($lockKey, 60);

    expect($this->redis->ttl($lockKey))->toBeGreaterThan(0);

    $job = new ScheduledJobManager;
    $job->middleware();

    expect($this->redis->exists($lockKey))->toBe(1);

    $this->redis->del($lockKey);
});

it('does not fail when no lock exists', function () {
    $cachePrefix = config('cache.prefix');
    $lockKey = $cachePrefix.'laravel-queue-overlap:'.ScheduledJobManager::class.':scheduled-job-manager';

    $this->redis->del($lockKey);

    $job = new ScheduledJobManager;
    $middleware = $job->middleware();

    expect($middleware)->toBeArray()->toHaveCount(1);
});
