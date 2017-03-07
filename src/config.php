<?php
/**
 * File Description:
 * User: coolseven2013@gmail.com
 * Date: 2017/3/7 0007
 * Time: 11:12
 */
return [

    //监控系统： 收集，检查，告警，保存，展示

    // 开启监控 php think queue:monitor start
    // 结束监控 php think queue:monitor stop
    // 查看状态 php think queue:monitor status

    // [ 收集 ]
    'monitorOn' => [
        'jobs'      => false,
        'queues'    => true,
        'workers'   => true,       // 思路： worker 每次循环时，触发一个事件，在该事件的执行代码中保存当前的 pid,uptime
        'server'    => true,
    ],
    'excludeQueues'  => [
        'default',
        //'helloJobQueue',
    ],
    'interval'  => 2,   // time interval for monitoring , default is 2 seconds
    'memory'    => 16,  // memory limit of monitor process, default is 16M.


    //[ 检查 + 告警 ]
    'alarm'         => true,          // will review the statics and trigger some events if some statics are abnormal


    //[ 保存监控结果 ]
    'save'       => 'redis',
    'expire'     => 60,
    'host'       => '127.0.0.1',
    'port'       => 6379,
    'password'   => 'my_redis_password',
    'select'     => 4,
    'timeout'    => 30,
    'persistent' => true,       // TODO

//    'save'      => 'mongodb',

//    'save'      => 'file',
//    'baseDir'   =>   '',
//    'name'      => 'queue:monitor:result',
//    'maxSize'   => '10M',
];