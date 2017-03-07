<?php
/**
 * File Description:
 * User: coolseven2013@gmail.com
 * Date: 2017/3/7 0007
 * Time: 11:12
 */
return [
    
    // [支持的命令 ]
    // 开启监控 php think queue:monitor start
    // 结束监控 php think queue:monitor stop
    // 查看状态 php think queue:monitor report
    
    // [ 监控的项目 ]
    'monitorOn' => [
        'jobs'      => false,    // 暂不支持
        'queues'    => true,     // 监控队列
        'workers'   => true,     // 监控 worker 进程  
        'server'    => true,     // 监控 Redis Server 状态
    ],
    // [ 不作监控的 queue ]
    'excludeQueues'  => [        
    ],
    'interval'  => 2,   // 监控时间间隔，默认每2秒收集一次消息队列的各项信息
    'memory'    => 16,  // 监控工具的内存限制，当监控工具本身的内存超限时，将自动退出监控。
    
    //[ 检查 + 告警 ]
    'alarm'     => false,   // 是否对收集的结果进行检查和告警，暂未实现
    
    //[ 保存监控结果 ]
    'save'       => 'redis',    // 目前只支持将收集的结果保存到 redis
    'expire'     => 60,
    'host'       => '127.0.0.1',
    'port'       => 6379,
    'password'   => 'your_redis_password',
    'select'     => 4,
    'timeout'    => 30,
    'persistent' => true, 
    
     // 'save'      => 'mongodb',  // 暂不支持将收集的结果保存到 mongodb
     // 'save'      => 'database'  // 暂不支持将收集的结果保存到 database
     // 'save'      => 'file',     // 暂不支持将收集的结果保存到 file 
];