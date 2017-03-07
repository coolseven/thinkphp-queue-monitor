<?php
/**
 * File Description:
 * User: coolseven2013@gmail.com
 * Date: 2017/3/7 0007
 * Time: 11:09
 */

\think\Console::addDefaultCommands([
    "coolseven\\ThinkphpQueueMonitor\\Monitor",
]);

// 记录 worker 的启动信息， 生成一个对应的 worker_pid 的 key
\think\Hook::add('worker_daemon_start','coolseven\\ThinkphpQueueMonitor\\behavior\\WorkerEventHandler::daemonStart');

// 更新 worker 的最新状态为 busy
\think\Hook::add('worker_before_process','coolseven\\ThinkphpQueueMonitor\\behavior\\WorkerEventHandler::busy');

// 更新 worker 的最新状态为 idle
\think\Hook::add('worker_before_sleep','coolseven\\ThinkphpQueueMonitor\\behavior\\WorkerEventHandler::idle');

// 更新 worker 的最新状态为 memory_exceeded ，并且停止当前 worker 进程的监控
\think\Hook::add('worker_memory_exceeded','coolseven\\ThinkphpQueueMonitor\\behavior\\WorkerEventHandler::memoryExceeded');

// 更新 worker 的最新状态为 queue_restart，并且停止当前所有的 worker 进程的监控
\think\Hook::add('worker_queue_restart','coolseven\\ThinkphpQueueMonitor\\behavior\\WorkerEventHandler::queueRestart');