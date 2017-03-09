<?php
/**
 * File Description:
 * User: coolseven2013@gmail.com
 * Date: 2017/3/7 0007
 * Time: 10:47
 */

namespace coolseven\ThinkphpQueueMonitor;


use think\console\Command;
use think\Config;
use think\Cache;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\Exception;

class Monitor extends Command {
    /**
     * 监控中的 worker 进程的redis key 前缀
     */
    const worker_status_monitoring_key_prefix = '_monitor:_workers:_monitoring:';
    
    /**
     * 不再监控的 worker 进程的redis key 前缀
     */
    const worker_status_archived_key_prefix   = '_monitor:_workers:_archived:';

    /**
     * 监控结果保存的key
     */
    const monitor_statics_key                 = '_monitor:_statics';

    /**
     * 上次重启时刻的缓存key名
     */
    const cache_last_restart_time_key         = 'think:queue:monitor:lastRestartTime';

    /**
     * @var array
     */
    protected $monitorOptions  = [];

    /**
     * @var int
     */
    protected $memoryLimit  = 0;

    /**
     * @var int
     */
    protected $interval     = 0;

    /**
     * @var  \Redis
     */
    protected $redisClientForQueue = null;

    /**
     * @var  \Redis
     */
    protected $redisClientForStatics = null;

    /**
     * @var int
     */
    protected $lastRestartTime = 0;

    protected function configure()
    {
        $this->setName('queue:monitor')
            ->addArgument('action', Argument::REQUIRED, "The monitor action. Supported actions: start | stop | report")
            ->setDescription('Command line tool to monitor thinkphp-queue');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = trim($input->getArgument('action') );

        switch ($action) {
            case 'start':
                $this->start();
                break;
            case 'stop':
                $this->stop();
                break;
            case 'report':
                $this->report();
                break;
            default:
                throw new Exception('Unknown action '.$action .'. Supported actions are : start | stop | report.');
        }
    }

    protected function start(){
        $this->monitorOptions = Config::get('queue_monitor');

        if (empty($this->monitorOptions)) {
            $this->log('[ Error ] Monitor Start Failed! queue monitor config file not found!');
            throw new Exception('Monitor Config File Not Found',500);
        }

        $this->interval = intval($this->monitorOptions['interval']);
        if ($this->interval == 0) {
            $this->log('[ Error ] Monitor Start Failed! queue monitor interval should be an positive integer.');
            throw new Exception('Config Error',500);
        }

        $this->memoryLimit = intval($this->monitorOptions['memory']);
        if ($this->memoryLimit < 10) {
            $this->log('[ Warning ] Monitor Memory Limit Too Low! please set a more reasonable limit.');
        }

        if ( $this->monitorOptions['save'] != 'redis') {
            $this->log('[ Error ] Monitor Start Failed! only redis supported to save the monitor results.');
            throw new Exception('Config Error',500);
        }

        $this->redisClientForQueue   = $this->getRedisForQueue();
        $this->redisClientForStatics = $this->getRedisForStatics();
        $this->lastRestartTime = Cache::get(self::cache_last_restart_time_key);

        $this->monitor();
    }

    protected function monitor(){
        $this->log('[ Info ] monitor loop started...'.PHP_EOL);

        while (true){

            try {
                $woke_up_time_micro_second      = microtime(true);                      // 本次循环开始时间，精确到 ms
                $woke_up_time_second            = intval($woke_up_time_micro_second);   // 本次循环开始时间，精确到 s  , 注： intval(1.9) == 1

                $this->log('[ Info ] monitor woke up at '.$woke_up_time_micro_second . ' ,treated as '.$woke_up_time_second);

                $staticsArr  = $this->getStatics();

                if ( $this->monitorOptions['alarm'] ) {
                    $this->reviewStatics($staticsArr);
                }

                $this->saveStatics($staticsArr,$woke_up_time_second);

                $time_consumed   = microtime(true) - $woke_up_time_micro_second ;
                if ( $time_consumed  > $this->interval) {    // 本次监控查询所消耗的时间大于给定的间隔，则记录该异常
                    $this->log('[ Warning ] too much time consumed on this round .');
                }

                if ( $this->lastRestartTime != Cache::get(self::cache_last_restart_time_key) ) {
                    $this->log('[ Info ] monitor stop command received. Quiting...');
                    $this->quit();
                }
                if ( $this->memoryLimit <  ( memory_get_usage()/ 1024 / 1024 ) ) {
                    $this->log('[ Info ] monitor memory limit exceeded. Quiting...');
                    $this->quit();
                }

                /**
                 * 计算下次循环开始的时间戳
                 * 预期最晚结束时间 = 开始时间 + 时间间隔的时间戳
                 * 当前时间 小于 预期最晚结束时间， 表示在规定的时间内完成了查询，那么应该取 预期最晚结束时间 作为 下次循环开始的时间戳
                 * 当前时间 大于 预期最晚结束时间， 消耗的时间超过了设置的时间间隔，那么应该取 当前时间的靠右边的整数时间戳 作为 下次循环开始的时间戳
                 * 因此，应该取 当前时间 与 预期最晚结束时间 的较大值，并且取较大值的右边的时间戳，作为下次循环开始的时间
                 */
                $expected_wake_up_time_second       = $woke_up_time_second + $this->interval ;
                $sleep_until_time_second            = max( $expected_wake_up_time_second, ceil( microtime(true) ) )  ;
                $this->log(PHP_EOL);
                $this->log('[ Info ] monitor finished. next round should start at : '.$sleep_until_time_second.PHP_EOL );

                time_sleep_until($sleep_until_time_second);

            } catch (\Exception $e) {
                $this->log('[ Exception ]'.$e->getMessage() .' File : '.$e->getFile() .' Line : '.$e->getLine() );
            }
        }
    }

    /**
     * 查询各项监控指标
     * @return array
     */
    protected function getStatics(){

        $statics = [];

        if ($this->monitorOptions['monitorOn']['jobs']) {
            // TODO
        }

        if ($this->monitorOptions['monitorOn']['queues']) {
            $statics['queues']  = $this->getQueueStatics();
        }

        if ($this->monitorOptions['monitorOn']['workers']) {
            $statics['workers']  = $this->getWorkerStatics();
        }

        if ($this->monitorOptions['monitorOn']['server']) {
            $statics['server']   = $this->getServerStatics();
        }


        return $statics;
    }

    /**
     * 对查询到的结果进行检查，如果存在异常的数据，则进行告警 //TODO
     * @param $staticsArr
     */
    private function reviewStatics($staticsArr) {
        $this->log('[ Info ] reviewing statics started...');
        // review logic here 
        // TODO 
        $this->log('[ Info ] reviewing statics finished.');
    }


    /**
     * @param $statics      array  本次监控获取的信息
     * @param $timeStamp    int    本次监控保存的时间点
     */
    protected function saveStatics($statics,$timeStamp){
        $this->log('[ Info ] saving statics started...');

        $redisKey = self::monitor_statics_key;
        $score    = $timeStamp ? $timeStamp : time();
        $isAdded  = $this->redisClientForStatics->zAdd($redisKey , $score , json_encode($statics));

        if (!$isAdded) {
            $this->log('[ Error ] saving statics failed.');
        }else{
            $this->log('[ Info ] saving statics success.');
        }
    }

    protected function getQueueStatics(){
        $queueStatic = [];

        $queueNameList  = $this->getQueueNameListForMonitor();

        if (!empty($queueNameList)) {
            $this->redisClientForQueue->multi(\REDIS::PIPELINE);

            foreach ($queueNameList as $queueName) {
                if ( stripos($queueName ,':delayed')){
                    $this->redisClientForQueue->zSize($queueName);
                }else if ( stripos($queueName ,':reserved')){
                    $this->redisClientForQueue->zSize($queueName);
                }else{
                    $this->redisClientForQueue->lLen($queueName);
                }
            }

            $queue_value_result_arr = $this->redisClientForQueue->exec();

            $queueStatic = array_combine($queueNameList , $queue_value_result_arr);
        }

        return $queueStatic;
    }

    protected function getWorkerStatics() {
        $workerStatics = [];

        $workerPids = $this->getRunningWorkerPids();

        if (!empty($workerPids)) {
            foreach ($workerPids as $workerPid){
                $key = self::worker_status_monitoring_key_prefix.$workerPid ;

                $daemon_start_info        = $this->redisClientForStatics->lIndex($key , 0);        // first element  , daemon info
                $worker_latest_report     = $this->redisClientForStatics->lIndex($key , -1);       // last element   , latest report

                $workerStatics[$workerPid]       = [
                    'daemon'    => json_decode($daemon_start_info,true),
                    'latest'    => json_decode($worker_latest_report,true),
                ];
            }
        }

        return $workerStatics;
    }

    protected function getServerStatics(){
        return $this->redisClientForQueue->info();
    }

    // TODO cache the result for better performance 
    // 可以直接实时从 redis 中查询
    // 可以把要监控的 queue 列表直接写在 配置文件中
    // 也可以和 work_daemon_start 事件配合，在 work_daemon_start 事件中更新待监控的queue 列表
    /**
     * 查询队列的信息， 包括 每个queue 中包含的元素个数
     * @return array [ 'queueName' => queueElementsCount ]
     *
     */
    protected function getQueueNameListForMonitor(){

        $queueNameListInRedis = $this->redisClientForQueue->keys('queues:*');

        $excludeQueueNameList = Config::get('queue_monitor')['excludeQueues'];
        $excludeFullQueueNameList = [];

        if (!empty($excludeQueueNameList)) {
            foreach ($excludeQueueNameList as $excludeQueueName){
                $excludeFullQueueNameList[] = 'queues:'.$excludeQueueName;
                $excludeFullQueueNameList[] = 'queues:'.$excludeQueueName.':delayed';
                $excludeFullQueueNameList[] = 'queues:'.$excludeQueueName.':reserved';
            }
        }

        return  array_diff($queueNameListInRedis , $excludeFullQueueNameList);
    }

    /**
     * 从 statics 中获取正在监控的 worker 进程id 数组
     * TODO 使用 ps 来获取的话是否会有性能问题
     * 亦或是改成缓存的方式？
     */
    protected function getRunningWorkerPids(){
        $workerPids = [];
        $workerPidsInRedis = $this->redisClientForStatics->keys(self::worker_status_monitoring_key_prefix.'*');
        if (!empty($workerPidsInRedis)) {
            foreach ($workerPidsInRedis as $workerPidKey){
                $workerPids[] = intval( str_replace( self::worker_status_monitoring_key_prefix , '' ,$workerPidKey ) );
            }
        }
        return $workerPids;
    }

    protected function stop(){
        Cache::set(self::cache_last_restart_time_key,time());
        $this->log('[ Info ] monitor stop command received. quiting...');
        $this->quit();
    }

    /**
     * TODO
     * save to file
     * @param string $message
     */
    protected function log($message=''){
        $this->output->writeln($message);
    }

    protected function quit(){
        $this->log('[ Info ] Bye.');
        die;
    }

    /**
     * @return \Redis
     */
    protected function getRedisForQueue(){
        $redisConfigForQueue = Config::get('queue');

        if (empty($redisConfigForQueue)) {
            $this->log('[ Error ] Monitor Start Failed! queue config file not found!');
            throw new Exception('Queue Config File Not Found',500);
        }

        $redisClient = new \Redis();
        $redisClient->pconnect($redisConfigForQueue['host'],$redisConfigForQueue['port'],$redisConfigForQueue['timeout']);
        $redisClient->auth($redisConfigForQueue['password']);
        $redisClient->select($redisConfigForQueue['select']);

        return $redisClient;
    }

    /**
     * @return \Redis
     * @throws \Exception
     */
    protected function getRedisForStatics(){
        $redisConfigForMonitor = Config::get('queue_monitor');

        $redisClient = new \Redis();
        $redisClient->pconnect($redisConfigForMonitor['host'],$redisConfigForMonitor['port'],$redisConfigForMonitor['timeout']);
        $redisClient->auth($redisConfigForMonitor['password']);
        $redisClient->select($redisConfigForMonitor['select']);

        return $redisClient;
    }

    /**
     * 在终端中查看队列的运行状态
     */
    protected function report(){
        $this->log('[ Notice ] Action Report not supported yet.');
        $this->quit();
    }
}