<?php

namespace Zan\Framework\Network\Server\Monitor;

class Worker
{
    private $Worker;

    public function __construct()
    {
        $this->Worker = new \ZanPHP\WorkerMonitor\WorkerMonitor();
    }

    public function init($server,$config)
    {
        $this->Worker->init($server,$config);
    }

    public function restart()
    {
        $this->Worker->restart();
    }

    public function checkStart()
    {
        $this->Worker->checkStart();
    }

    public function check()
    {
        $this->Worker->check();
    }

    public function closePre()
    {
        $this->Worker->closePre();
    }

    public function closeCheck()
    {
        $this->Worker->closeCheck();
    }

    public function close()
    {
        $this->Worker->close();
    }

    public function hawk()
    {
        $this->Worker->hawk();
    }

    public function callHawk()
    {
        $this->Worker->callHawk() ;
    }

    public function reactionReceive()
    {
        $this->Worker->reactionReceive();
    }

    public function reactionRelease()
    {
        $this->Worker->reactionRelease();
    }

    public function incrMsgCount()
    {
        $this->Worker->incrMsgCount();
    }

    public function setCheckMqReadyCloseCallback(callable $callback)
    {
        $this->Worker->setCheckMqReadyCloseCallback($callback);
    }

    public function setMqReadyClosePreCallback(callable $callback)
    {
        $this->Worker->setMqReadyClosePreCallback($callback);
    }

    public function output($str)
    {
        $this->Worker->output($str);
    }

    public function isDenyRequest()
    {
        $this->Worker->isDenyRequest();
    }

}