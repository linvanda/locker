<?php

namespace Dev\Locker;

/**
 * 锁，基于 Redis 实现
 * 目前的实现版本不涉及分布式 Redis
 * Class Locker
 */
class Locker
{
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
    // 锁状态
    const STATUS_LOCK = 1;
    const STATUS_UNLOCK = 0;

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

        $now = time();
        $val = [$now + $this->timeout, $this->privateKey];

        if ($this->redis->setnx($this->key, json_encode($val))) {
            $this->status = self::STATUS_LOCK;
            // 设置过期时间，防止异常死锁
            $this->redis->expire($this->key, $this->timeout);

            return true;
        } else {
            /**
             * 获取锁失败，以一定的概率对上家的锁进行健康检查
             * 原则上，上家执行完毕会解锁，且在加锁时设置了过期时间
             * 但不排除上家在设置过期时间之前和 Redis 断开了连接（或其它问题）导致后续操作失败，产生永久锁
             * 因而这里采用概率性的容错处理
             */
            if ($this->willCheck()) {
                $lockValStr = $this->redis->get($this->key);
                $lockValue = $lockValStr ? json_decode($lockValStr, true) : [];
                if ($lockValue[0] < $now) {
                    // 上家的锁超时了，此处尝试加锁。此处需要用getSet实现乐观锁事务
                    $oldLockValStr = $this->redis->getSet($this->key, json_encode($val));

                    // 需检查本次修改之前的值，保证不会有其它进程（请求）同时修改该值
                    if ($oldLockValStr === $lockValStr) {
                        $this->status = self::STATUS_LOCK;
                        $this->redis->expire($this->key, $this->timeout);

                        return true;
                    }
                }
            }
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
        if ($this->status === self::STATUS_LOCK) {
            $lockValStr = $this->redis->get($this->key);
            $lockValue = $lockValStr ? json_decode($lockValStr, true) : [];

            if ($lockValue[1] === $this->privateKey) {
                $this->redis->del($this->key);
            }

            $this->status = self::STATUS_UNLOCK;
        }
    }

    private function willCheck()
    {
        // 5s 以内超时的不检测
        if ($this->timeout <= 5) {
            return false;
        }
        return mt_rand(1, 20) === 10;
    }

    private function privateKey()
    {
        return uniqid(posix_getpid());
    }
}
