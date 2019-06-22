<?php

namespace Dev\Locker;

/**
 * 锁，基于 Redis 实现
 * 目前的实现版本不涉及分布式 Redis
 * Class Locker
 */
class Locker
{
    const STATUS_LOCK = 1;
    const STATUS_UNLOCK = 0;

    private $redis;
    // 锁的公开标示
    private $key;
    private $keyPrefix = 'redis-lock-';
    // 锁超时时间
    private $timeout;
    // 锁状态
    private $status;
    // 锁对象内部标示，每个对象不同
    private $privateKey;

    public function __construct(\Redis $redis, $key, $timeout = 5)
    {
        $this->redis = $redis;
        $this->key = $this->keyPrefix . $key;
        $this->timeout = $timeout;

        // 初始化锁状态
        $this->status = self::STATUS_UNLOCK;

        // 内部标示
        $this->privateKey = $this->privateKey();
    }

    /**
     * 对象销毁前强制解锁
     */
    public function __destruct()
    {
        $this->unlock();
    }

    /**
     * 加锁
     * @return bool
     */
    public function lock()
    {
        if ($this->status === self::STATUS_LOCK) {
            return true;
        }

        if ($this->redis->set($this->key, $this->privateKey, ['nx', 'ex' => $this->timeout])) {
            $this->status = self::STATUS_LOCK;
            return true;
        }

        return false;
    }

    /**
     * 解锁
     * 只能解自己加的锁
     * 注意此处由于分成 get 和 del 两步操作，原则上并非原子性，但实际中这种差可以接受的
     */
    public function unlock()
    {
        if ($this->status !== self::STATUS_LOCK) {
            return;
        }

        if ($this->redis->get($this->key) === $this->privateKey) {
            $this->redis->del($this->key);
        }

        $this->status = self::STATUS_UNLOCK;
    }

    private function privateKey()
    {
        return uniqid(posix_getpid());
    }
}
