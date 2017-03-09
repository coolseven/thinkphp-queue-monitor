<?php
/**
 * 监控结果检查
 * 对各项监控指标进行检查，当出现了异常事件时，触发对应的异常事件，使得监听了对应事件的类可以进行告警
 * Author: coolseven2013@gmail.com
 * Date: 2017/3/9 0009
 * Time: 19:48
 */

namespace coolseven\ThinkphpQueueMonitor\dataProcess;


class MonitorDataReviewer {

    /**
     * 监控事件，worker 进程无响应时触发
     */
    const EVENT_WORKER_DEAD = 'monitor_worker_dead';

    /**
     * 监控事件， 某个队列积压的 waiting 数量太高时触发
     */
    const EVENT_WORKER_NOT_ENOUGH = 'monitor_worker_not_enough';

    /**
     * 监控事件，某个队列产生的失败任务太多时触发
     */
    const EVENT_TOO_MANY_FAILED_JOB = 'monitor_too_many_failed_job';
}