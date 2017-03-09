<?php
/**
 * 监控结果的查询器
 * 提供监控结果的查询和转换，方便前端或者其他系统调用
 * Author: coolseven2013@gmail.com
 * Date: 2017/3/9 0009
 * Time: 19:49
 */

namespace coolseven\ThinkphpQueueMonitor\dataProcess;


class MonitorDataQuerier {

    protected $options = [
        'search_from_ts' => 0,          // 查询的时间起点，用于查询历史数据
        'search_until_ts' => 0,         // 查询的时间终点，用于查询历史数据，不传时，视为查询最新的数据
        'items_per_search' => 10,       // 每次查询的数量(上限)
    ];
    
    /**
     * MonitorDataQuerier constructor.
     */
    public function __construct($userOptions = []) {
        $this->options = array_merge( $this->options , $userOptions);
    }

    /**
     * 查询监控的队列列表
     */
    public function getQueueNameList(){
        
    }

    /**
     * 查询当前活跃的 worker 进程列表
     */
    public function getWorkerPidList(){
        
    }
    
    
}