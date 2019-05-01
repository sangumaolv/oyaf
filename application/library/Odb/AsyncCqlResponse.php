<?php
/**
 * Created by PhpStorm.
 * User: chentairen
 * Date: 2019/4/30
 * Time: 下午11:26
 */

namespace Odb;


class AsyncCqlResponse
{
    protected $future;
    protected $handler;

    public function __construct($future, \Closure $handler = null)
    {
        $this->future = $future;
        $this->handler = $handler;
    }

    /**
     * 等待时间
     * @param $timeout
     * @return mixed
     */
    public function wait($timeout = 0.5)
    {
        return $this->future->get($timeout);
    }

    /**
     * 获取结果
     * @return array
     */
    public function get()
    {
        $res = $this->wait(0.5);
        $result = [];
        foreach ($res as $row) {
            $result[] = $this->handler ? ($this->handler)($row) : $row;
        }
        return $result;
    }

    /**
     * 获取第一行
     * @return mixed
     */
    public function first()
    {
        $res = $this->wait(0.5);
        return $res[0];
    }

    /**
     * 获取第一列的值
     * @return null
     */
    public function value()
    {
        $res = $this->wait(0.5);
        if ($res[0] === null) return null;
        $res = array_values($res[0]);
        return $res[0];
    }
}