<?php
/**
 * File Description:
 * User: coolseven2013@gmail.com
 * Date: 2017/3/7 0007
 * Time: 10:51
 */

namespace coolseven\ThinkphpQueueMonitor\behavior;

use think\Config;

class WorkerEventHandler {
    /**
     * 使用缓冲的形式，按照事件发生次数，或者时间间隔，来延缓写入
     * 写入时，记录本次缓冲的数据中，发生了多少次 busy 事件，多少次 idle 事件，以及最近一次的 busy 时间和最近一次的 idle 时间
     * @var int
     */
    protected static $_busy_event_buffer_counter = 0;          // 本次监控期间发生了多少次 busy 事件
    protected static $_idle_event_buffer_counter = 0;          // 本次监控期间发生了多少次 sleep 事件

    protected static $_last_busy_ts              = 0;          // 最近一次 busy 事件的发生时间
    protected static $_last_idle_ts              = 0;          // 最近一次 idle 事件的发生时间

    protected static $_event_buffer_count_limit  = 50;         // 规定的时间内[ 已达到 ]缓冲事件次数上限，开始写入并清空缓冲
    protected static $_event_buffer_time_limit   = 5;          // 规定的时间内[ 未达到 ]缓冲事件次数上限，开始写入并清空缓冲

    protected static $_buffer_last_flush_ts      = 0;          // 上一次写入并清空缓冲的时间戳
    protected static $_my_pid                    = 0;          // 当前进程的 pid， 和 worker 进程的 pid 是同一个


    /**
     * 记录 worker 进程的启动事件
     * @param $queue
     */
    public static function daemonStart(&$queue){
        $recordHandler = self::getRedisForStatics();

        $worker_pid = self::getPid();
        $key        = self::worker_status_monitoring_key_prefix . $worker_pid;
        $value      = json_encode([
            'queue'   => $queue,
            'comment' => 'worker_daemon_start',
            'ts'      => time(),
        ]);
        $recordHandler->lPush($key, $value);                // 存到 list 的头部
        unset($recordHandler);
    }

    /**
     * 缓冲 worker 进程的 busy 事件， busy事件在 process_job 前 时触发
     * @param $queue
     */
    public static function busy(&$queue){

        self::$_busy_event_buffer_counter ++;
        self::$_last_busy_ts = time();

        self::_buffer($queue);
    }

    /**
     * 缓冲 worker 进程的 idle 事件， busy事件在 sleep 前 时触发
     * @param $queue
     */
    public static function idle(&$queue){

        self::$_idle_event_buffer_counter ++;
        self::$_last_idle_ts = time();

        self::_buffer($queue);
    }

    /**
     * 缓冲 worker 进程的 idle 事件或者 busy 事件，当事件数量达到一定次数，或时间间隔达到条件时，保存事件信息到redis并清空缓冲
     * @param $queue
     */
    protected static function _buffer($queue){
        $should_flush_buffer = false;

        $event_buffer_counted       = self::$_busy_event_buffer_counter + self::$_idle_event_buffer_counter;
        $event_buffer_time_consumed = time() - self::$_buffer_last_flush_ts;

        if ( $event_buffer_counted >= self::$_event_buffer_count_limit) {
            self::log('[ Info ] event buffer counter limit reached . time cost : ' .$event_buffer_time_consumed);
            $should_flush_buffer = true;
        }

        if ( $event_buffer_time_consumed >= self::$_event_buffer_time_limit ) {
            self::log('[ Info ] event buffer time limit reached .');
            $should_flush_buffer = true;
        }

        if ($should_flush_buffer) {
            self::log('[ Info ] start flushing to redis...');
            self::_flush($queue);
        }
    }

    /**
     * TODO use lTrim to limit the length of LIST
     * 保存事件信息到redis并清空缓冲
     * @param $queue
     */
    protected static function _flush($queue){
        self::_saveBuffer($queue);
        self::_clearBuffer();
    }

    /**
     * 保存 worker 进程的 process 事件 和 sleep 事件的相关数据到redis
     * @param $queue
     */
    protected static function _saveBuffer($queue){
        $worker_last_report_status  = self::$_last_busy_ts >= self::$_last_idle_ts ? 'busy' : 'idle';
        $worker_last_report_ts      = max( self::$_last_busy_ts ,self::$_last_idle_ts );
        
        $worker_pid = self::getPid();
        $worker_key = self::worker_status_monitoring_key_prefix.$worker_pid;
        $value = json_encode([
            'queue'                     => $queue,
            'comment'                   => 'worker_status_report',
            'worker_last_report_status' => $worker_last_report_status,      // worker 进程最后一次触发事件时的状态
            'worker_last_report_ts'     => $worker_last_report_ts,          // worker 进程最后一次触发事件的时间
            'save_ts'                   => time(),                          // 本次缓冲中的数据保存到 redis 的时间
            'last_save_ts'              => self::$_buffer_last_flush_ts,    // 上次缓冲中的数据保存到 redis 的时间
            'busy'                      => [
                'process_count_meanwhile' => self::$_busy_event_buffer_counter, // 可用于计算任务的消费速度
                'last_process_ts'         => self::$_last_busy_ts,
            ],
            'idle'                      => [
                'sleep_count_meanwhile' => self::$_idle_event_buffer_counter,
                'last_sleep_ts'         => self::$_last_idle_ts,
            ],
        ]);

        $flushHandler = self::getRedisForStatics();
        $flushHandler->rPush($worker_key ,$value);     // 添加到 List 的尾部
        unset($flushHandler);
    }

    /**
     * 清空buffer
     */
    protected static function _clearBuffer(){
        // reset buffer related variables
        self::$_busy_event_buffer_counter = 0;
        self::$_idle_event_buffer_counter = 0;
        self::$_buffer_last_flush_ts = time();
    }

    /**
     * @return \Redis
     */
    protected static function getRedisForStatics(){
        $monitorOptions = Config::get('queue_monitor');
        $redisHandler = new \Redis();
        $redisHandler->pconnect($monitorOptions['host'],$monitorOptions['port'],$monitorOptions['timeout']);
        $redisHandler->auth($monitorOptions['password']);
        $redisHandler->select($monitorOptions['select']);

        return $redisHandler;
    }



    public static function memoryExceeded(&$queue){
        self::_flush($queue);
        self::archiveWorkerByPid( self::getPid() );
    }

    public static function queueRestart(&$queue){
        self::_flush($queue);
        self::archiveAllWorkers();
    }

    protected static function archiveWorkerByPid($workerPid){
        $redisClient = self::getRedisForStatics();
        if ($workerPid) {
            self::log('[ Info ] statics of worker process '.$workerPid .' will be archived');

            $worker_key = self::worker_status_monitoring_key_prefix.$workerPid;
            $redisClient->renameKey($worker_key , self::worker_status_archived_key_prefix.$workerPid.':'.time());    // 加上时间戳，以免workerPid 重复了
        }
    }

    protected static function archiveAllWorkers(){
        $redisClient = self::getRedisForStatics();
        self::log('[ Info ] queue restart command received. statics of all workers processes will be archived.');

        $workers = $redisClient->keys(self::worker_status_monitoring_key_prefix .'*');
        foreach ($workers as $worker_key){
            $workerPid = str_replace( self::worker_status_monitoring_key_prefix , '' ,$worker_key ) ;
            $redisClient->renameKey($worker_key , self::worker_status_archived_key_prefix. $workerPid. ':'.time());
        }
    }

    /**
     * @return int
     */
    private static function getPid() {
        if (!self::$_my_pid) {
            self::$_my_pid = getmypid();
        }
        return self::$_my_pid;
    }


    protected static function log($message){
        echo $message.PHP_EOL;
        // TODO cache and save to log file
    }

}