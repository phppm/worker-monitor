<?php

namespace ZanPHP\WorkerMonitor;

use ZanPHP\Contracts\Config\Repository;
use ZanPHP\Hawk\Constant;
use ZanPHP\Hawk\Hawk;
use ZanPHP\Support\Singleton;
use ZanPHP\Timer\Timer;


class WorkerMonitor
{
    use Singleton;

    const GAP_TIME = 180000;
    const GAP_REACTION_NUM = 1500;
    const GAP_MSG_NUM = 5000;
    const DEFAULT_MAX_CONCURRENCY = 500;
    const DEFAULT_CHECK_INTERVAL = 5000;
    const DEFAULT_LIVE_TIME = 1800000;

    public $classHash;
    public $workerId;
    public $server;
    public $config;

    public $reactionNum;
    public $totalReactionNum;
    public $maxConcurrency;

    private $totalMsgNum = 0;
    private $checkMqReadyClose;
    private $mqReadyClosePre;

    private $cpuInfo;

    private $isDenyRequest;

    public function init($server,$config)
    {
        if(!is_array($config)){
            return ;
        }

        $this->isDenyRequest = false;
        $this->classHash = spl_object_hash($this);
        $this->server = $server;
        $this->workerId = $server->swooleServer->worker_id;
        $this->config = $config;
        $this->reactionNum = 0;
        $this->totalReactionNum = 0;
        $this->maxConcurrency = isset($this->config['max_concurrency']) ? $this->config['max_concurrency'] : self::DEFAULT_MAX_CONCURRENCY;
        $this->cpuInfo = array(
            'pre_total_cpu_time' => 0,
            'pre_total_process_cpu_time' => 0,
            'limit_count' => 0
        );
        $this->restart();
        $this->checkStart();
        //add by chiyou
        $this->hawk();
    }

    public function restart()
    {
        $time = isset($this->config['max_live_time'])?$this->config['max_live_time']:self::DEFAULT_LIVE_TIME;
        $time += $this->workerId * self::GAP_TIME;

        Timer::after($time, [$this,'closePre'], $this->classHash.'_restart');
    }

    public function checkStart()
    {
        $time = isset($this->config['check_interval'])?$this->config['check_interval']:self::DEFAULT_CHECK_INTERVAL;

        Timer::tick($time, [$this,'check'], $this->classHash.'_check');
    }

    public function check()
    {
        $this->output('check');

        $memory =  memory_get_usage();
        $memory_limit = isset($this->config['memory_limit']) ? $this->config['memory_limit'] : 1024 * 1024 * 1024 * 1.5;

        $reaction_limit = isset($this->config['max_request']) ? $this->config['max_request'] : 100000;
        $reaction_limit = $reaction_limit + $this->workerId * self::GAP_REACTION_NUM;

        $msgLimit = isset($this->config['msg_limit']) ? $this->config['msg_limit'] : 100000;
        $msgLimit = $msgLimit + $this->workerId * self::GAP_MSG_NUM;

        $cpuUsage = $this->cpu_get_usage();
        //$cpuLimit = isset($this->config['cpu_limit']) ? $this->config['cpu_limit'] : 80;
        $cpuLimit = 90; //todo 暂时设定为90，设置的cpu_limit未生效
        if($cpuUsage > $cpuLimit){
            $this->cpuInfo['limit_count']++;
        }
        else{
            $this->cpuInfo['limit_count'] = 0;
        }
        $check_interval = isset($this->config['check_interval'])?$this->config['check_interval']:self::DEFAULT_CHECK_INTERVAL;

        if($this->cpuInfo['limit_count']*$check_interval >= 60000 && $this->cpuInfo['limit_count'] >= 3){
            sys_echo("worker restart caused by CPU_LIMIT");
            $this->closePre();
        }
        elseif($memory > $memory_limit || $this->totalReactionNum > $reaction_limit || $this->totalMsgNum > $msgLimit){
            $this->closePre();
        }
    }

    public function closePre()
    {
        $this->output('ClosePre');

        Timer::clearTickJob($this->classHash.'_check');

        // TODO: 兼容zan接口修改, 全部迁移到连接池版本swoole后移除
        /* @var $this->server Server */
        if (method_exists($this->server->swooleServer, "denyRequest")) {
            $this->server->swooleServer->denyRequest($this->workerId);
        } else {
            $this->server->swooleServer->deny_request($this->workerId);
        }

        $this->isDenyRequest = true;

        if (is_callable($this->mqReadyClosePre)) {
            call_user_func($this->mqReadyClosePre);
        }

        $this->closeCheck();
    }

    public function closeCheck()
    {
        $this->output('CloseCheck');

        $ready = is_callable($this->checkMqReadyClose) ? call_user_func($this->checkMqReadyClose) : true;

        if($this->reactionNum > 0 or !$ready){
            Timer::after(1000,[$this,'closeCheck']);
        }else{
            $this->close();
        }
    }

    public function close()
    {
        $this->output('Close');

        sys_echo("close:workerId->".$this->workerId);

        $this->server->swooleServer->exit();
    }

    public function hawk()
    {
        $repository = make(Repository::class);
        $run = $repository->get('hawk.run');
        if (!$run) {
            return;
        }
        $time = $repository->get('hawk.time');
        Timer::tick($time, [$this,'callHawk']);
    }

    public function callHawk()
    {
        $hawk = Hawk::getInstance();
        $memory =  memory_get_usage();
        $hawk->add(Constant::BIZ_WORKER_MEMORY,
                    ['used' => $memory]);
    }

    public function reactionReceive()
    {
        //触发限流
        if ($this->reactionNum > $this->maxConcurrency) {
            return false;
        }
        $this->totalReactionNum++;
        $this->reactionNum ++;
        return true;
    }

    public function reactionRelease()
    {
        $this->reactionNum --;
    }

    public function incrMsgCount()
    {
        $this->totalMsgNum++;
    }

    public function setCheckMqReadyCloseCallback(callable $callback)
    {
        $this->checkMqReadyClose = $callback;
    }

    public function setMqReadyClosePreCallback(callable $callback)
    {
        $this->mqReadyClosePre = $callback;
    }

    public function output($str)
    {
        if(isset($this->config['debug']) && true == $this->config['debug']){
            $output = "###########################\n";
            $output .= $str.":workerId->".$this->workerId."\n";
            $output .= 'time:'.time()."\n";
            $output .= "request number:".$this->reactionNum."\n";
            $output .= "total request number:".$this->totalReactionNum."\n";
            $output .= "###########################\n\n";
            echo $output;
        }
    }

    /**
     * @return bool
     */
    public function isDenyRequest()
    {
        return $this->isDenyRequest;
    }

    /**
     * 获取对应pid的cpu占用率，暂时只支持linux环境
     * @return float
     */
    private function cpu_get_usage(){
        $workPid =  posix_getpid();
        if(file_exists('/proc/stat') && file_exists('/proc/'.$workPid.'/stat')){
            $sysCpuStr = file_get_contents('/proc/stat');
            $pidCpuStr = file_get_contents('/proc/'.$workPid.'/stat');
            $sysCpuStr1 = explode(PHP_EOL,$sysCpuStr);
            $sysCpuArray = explode(' ',$sysCpuStr1[0]);
            $pidCpuArray = explode(' ',$pidCpuStr);

            $user = $sysCpuArray[2];
            $nice = $sysCpuArray[3];
            $system = $sysCpuArray[4];
            $idle = $sysCpuArray[5];
            $iowait = $sysCpuArray[6];
            $irq = $sysCpuArray[7];
            $softirq = $sysCpuArray[8];
            $stealstolen = $sysCpuArray[9];
            $guest = $sysCpuArray[10];
            $totalCpuTime = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $stealstolen + $guest;

            $utime = $pidCpuArray[13];
            $stime = $pidCpuArray[14];
            $cutime = $pidCpuArray[15];
            $cstime = $pidCpuArray[16];
            $totalProcessCpuTime = $utime + $stime + $cutime + $cstime;

            $cpuUsage = 0;
            if($this->cpuInfo['pre_total_cpu_time'] != 0){
                $cpuTime = $totalCpuTime- $this->cpuInfo['pre_total_cpu_time'];
                $processCpuTime = $totalProcessCpuTime - $this->cpuInfo['pre_total_process_cpu_time'];
                $cpuUsage = round($processCpuTime/$cpuTime * 100 * swoole_cpu_num(),1);
            }
            $this->cpuInfo['pre_total_cpu_time'] = $totalCpuTime;
            $this->cpuInfo['pre_total_process_cpu_time'] = $totalProcessCpuTime;
        }
        else{
            $cpuUsage = 0.0;
        }
        return $cpuUsage;
    }

}