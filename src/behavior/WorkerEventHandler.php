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
    protected static $_idle_event_buffer_counter = 0;          // 本次监控期间发生了多少次 busy 事件

    protected static $_last_busy_ts       = 0;                 // 最近一次 busy 事件的发生时间
    protected static $_last_idle_ts       = 0;                 // 最近一次 idle 事件的发生时间

    protected static $_event_buffer_count_limit   = 500;        // 规定的时间内达到了缓冲事件次数上限，开始写入并清空缓冲
    protected static $_event_buffer_time_limit    = 5;         // 规定的时间内未达到缓冲事件次数上限，开始写入并清空缓冲

    protected static $_buffer_last_flush_ts = 0;               // 上一次写入并清空缓冲的时间戳
    protected static $_my_pid               = 0;                 // 当前进程的 pid， 和 worker 进程的 pid 是同一个

    const worker_status_monitoring_key_prefix = '_monitor:_workers:_monitoring:';     // 监控中的 worker 进程的redis key 前缀
    const worker_status_archived_key_prefix   = '_monitor:_workers:_archived:';       // 不再监控的 worker 进程的redis key 前缀

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

        $worker_last_status = self::$_last_busy_ts >= self::$_last_idle_ts ? 'busy' : 'idle';

        $worker_pid = self::getPid();
        $worker_key = self::worker_status_monitoring_key_prefix.$worker_pid;
        $value = json_encode([
            'queue'                 => $queue,
            'comment'               => 'worker_status_report',
            'worker_last_status'    => $worker_last_status,
            'ts'                    => time(),  // 最后一次捕捉到该 worker 进程的事件的时间，作为 worker 的最后 alive 时间
            'busy'                  => [
                'process_count_meanwhile'   => self::$_busy_event_buffer_counter,
                'last_process_ts'           => self::$_last_busy_ts,
            ],
            'idle'                  => [
                'sleep_count_meanwhile'     => self::$_idle_event_buffer_counter,
                'last_sleep_ts'             => self::$_last_idle_ts,
            ],
        ]);

        $flushHandler = self::getRedisForStatics();
        $flushHandler->rPush($worker_key ,$value);     // 添加到 List 的尾部
        unset($flushHandler);

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
        self::archiveWorkerByPid( self::getPid() );
    }

    public static function queueRestart(&$queue){
        self::archiveAllWorkers();
    }

    protected static function archiveWorkerByPid($workerPid){
        $redisClient = self::getRedisForStatics();
        if ($workerPid) {
            self::log('[ Info ] worker '.$workerPid .' will be archived');

            $worker_key = self::worker_status_monitoring_key_prefix.$workerPid;
            $redisClient->renameKey($worker_key , self::worker_status_archived_key_prefix.$workerPid.':'.time());    // 加上时间戳，以免workerPid 重复了
        }
    }

    protected static function archiveAllWorkers(){
        $redisClient = self::getRedisForStatics();
        self::log('[ Info ] all workers will be archived, due to queue restart command.');

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