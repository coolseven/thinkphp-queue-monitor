# thinkphp-queue-monitor

[thinkphp-queue](https://github.com/top-think/think-queue) 的命令行监控工具。
**尚未开发完成**, **请不要在生产环境使用**

本项目基于 [thinkphp-queue](https://github.com/top-think/think-queue) 消息队列库，在该库的基础上增加了消息队列的监控功能。

### 前端效果预览

![监控前端效果预览](https://static.huzhongyuan.com/thinkphp-queue-monitor/queue_statics_preview.png)

### 可监控的项目：

- worker 进程监控 (可关闭) 。监控各个 worker进程 的状态，包括以下信息：

  - worker 进程负责的 queue 名称
  - worker 进程的启动时刻
  - worker 进程的最近状态，包括busy（任务处理中）， idle （无任务）
  - worker 进程从上一次监控到本次监控期间，处理的任务数量，以及 sleep 的次数


- queue 监控 (可关闭) (支持设置部分队列不作监控)。  监控各个 queue 的状态，包括以下信息：

    - queue 名称
    - queue 中等待处理的任务数量
    - queue 中延迟处理的任务数量
    - queue 中正在处理的任务数量

- Redis Server 监控 (可关闭)。监控 redis 服务的状态，包括以下信息：

    - Redis Server 的 Clients 相关信息
    - Redis Server 的 Memory 相关信息
    - Redis Server 的 Persistence 相关信息
    - Redis Server 的 Stats 相关信息
    - Redis Server 的 Replication 相关信息
    - Redis Server 的 CPU  相关信息



### 使用方法

#### 安装tp5的消息队列

```bash
composer require topthink/think-queue
```

#### 安装消息队列监控

```bash
composer require coolseven/thinkphp-queue-monitor
```

#### 添加监控配置文件

新增 `application\extra\queue_monitor.php` 配置文件，配置文件选项参考：

```php
<?php
/**
 * tp5的队列监控： 
 * User: coolseven2013@gmail.com
 * Date: 2017/2/28 0028
 * Time: 16:39
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
        'default',	
    ],
    'interval'  => 2,   // 监控时间间隔，默认每2秒收集一次消息队列的各项信息
    'memory'    => 16,  // 监控工具的内存限制，当监控工具本身的内存超限时，将自动退出监控。
    
    //[ 检查 + 告警 ]
    'alarm'     => false,   // 是否对收集的结果进行检查和告警，暂未实现
    
    //[ 保存监控结果 ]
    'save'       => 'redis',	// 目前只支持将收集的结果保存到 redis
    'expire'     => 60,
    'host'       => '127.0.0.1',
    'port'       => 6379,
    'password'   => 'your_redis_password',
    'select'     => 4,
    'timeout'    => 30,
    'persistent' => true, 
    
     // 'save'      => 'mongodb',  // 暂不支持将收集的结果保存到 mongodb
     // 'save'		=> 'database'  // 暂不支持将收集的结果保存到 database
     // 'save'      => 'file',     // 暂不支持将收集的结果保存到 file 
];
```

#### 启动监控

```bash
php think queue:monitor start
```

#### 启动你的 worker 进程 (建议先启动监控工具，再启动worker)

```
php think queue:work --daemon --queue yourQueueName   --tries 1  --sleep 1
php think queue:work --daemon --queue yourQueueName   --tries 1  --sleep 1
php think queue:work --daemon --queue yourQueueName2  --tries 2  --sleep 2
```

#### 停止监控 (停止监控不会影响worker进程的正常运行)

```bash
php think queue:monitor stop
```

#### 在终端中查看监控结果 (暂未实现)

```bash
php think queue:monitor report
```



### TODO

- [ ] 支持 job 监控
- [ ] 支持 database 驱动下的监控
- [ ] 支持将监控结果保存到 mongodb， database 或 file。 目前仅支持保存到 redis。
- [ ] 在终端中查看监控结果
- [ ] 使用tp5 内置的日志类来保存监控日志
- [ ] 监控 queue_failed 事件
- [ ] 限制监控结果的保存上限


### 备注

**如果需要监控worker进程的状态，那么该监控工具需要放在被监控应用的vendor目录下。**

**如果只监控queue的状态和redis服务器的状态，不监控worker进程的状态，那么该工具可以单独部署到其他机器上，只需保证该机器能够连接到redis队列服务器即可。**

如果你使用的 thinkphp-queue 的版本是 v1.1.3 , 且需要实现 worker 进程的监控，那么需要先在 `vendor\topthink\think-queue\` 的源代码中手动添加worker 相关的进程钩子。

修改步骤如下：

1. 修改 `\vendor\topthink\think-queue\src\queue\command\Work.php` 中的 `execute()` 方法，添加 `worker_daemon_start` 钩子。

   修改前：

   ```php
   public function execute(Input $input, Output $output)
       {
           
           $queue = $input->getOption('queue');

           $delay = $input->getOption('delay');

           $memory = $input->getOption('memory');

           if ($input->getOption('daemon')) {
               $this->daemon(
                   $queue, $delay, $memory,
                   $input->getOption('sleep'), $input->getOption('tries')
               );
           } else {
               $response = $this->worker->pop($queue, $delay, $input->getOption('sleep'), $input->getOption('tries'));
               $this->output($response);
           }
       }
   ```

   修改后：

   ```php
   public function execute(Input $input, Output $output)
       {
           
           $queue = $input->getOption('queue');

           $delay = $input->getOption('delay');

           $memory = $input->getOption('memory');

           if ($input->getOption('daemon')) {
               Hook::listen('worker_daemon_start',$queue);  // 添加了这一行
               $this->daemon(
                   $queue, $delay, $memory,
                   $input->getOption('sleep'), $input->getOption('tries')
               );
           } else {
               $response = $this->worker->pop($queue, $delay, $input->getOption('sleep'), $input->getOption('tries'));
               $this->output($response);
           }
       }
   ```

2. 修改 `\vendor\topthink\think-queue\src\queue\command\Work.php` 中的 `daemon()` 方法，添加 `worker_memory_exceeded` 钩子 和 `worker_queue_restart` 钩子

   修改前：

   ```php
   protected function daemon($queue = null, $delay = 0, $memory = 128, $sleep = 3, $maxTries = 0)
       {
           $lastRestart = $this->getTimestampOfLastQueueRestart();

           while (true) {
               $this->runNextJobForDaemon(
                   $queue, $delay, $sleep, $maxTries
               );

               if ( $this->memoryExceeded($memory) || $this->queueShouldRestart($lastRestart) ) {
                   $this->stop();
               }
           }
       }
   ```

   修改后：

   ```php
   protected function daemon($queue = null, $delay = 0, $memory = 128, $sleep = 3, $maxTries = 0)
       {
           $lastRestart = $this->getTimestampOfLastQueueRestart();

           while (true) {
               $this->runNextJobForDaemon(
                   $queue, $delay, $sleep, $maxTries
               );

               if ( $this->memoryExceeded($memory) ) {				
                   Hook::listen('worker_memory_exceeded', $queue);		// 添加了这一行
                   $this->stop();
               }
               
               if ( $this->queueShouldRestart($lastRestart) ) {
                   Hook::listen('worker_queue_restart', $queue);		// 添加了这一行
                   $this->stop();
               }
           }
       }
   ```

3. 修改 `\vendor\topthink\think-queue\src\queue\Worker.php` 中的 `pop` 方法 ， 添加 `worker_before_process` 钩子 和 `worker_before_sleep` 钩子

   修改前：

   ```php
       public function pop($queue = null, $delay = 0, $sleep = 3, $maxTries = 0)
       {

           $job = $this->getNextJob($queue);

           if (!is_null($job)) {
               return $this->process($job, $maxTries, $delay);
           }
           
           $this->sleep($sleep);                     

           return ['job' => null, 'failed' => false];
       }
   ```

   修改后：

   ```php
       public function pop($queue = null, $delay = 0, $sleep = 3, $maxTries = 0)
       {

           $job = $this->getNextJob($queue);

           if (!is_null($job)) {
               Hook::listen('worker_before_process', $queue);		// 添加了这一行
               return $this->process($job, $maxTries, $delay);
           }
           
           Hook::listen('worker_before_sleep', $queue);			// 添加了这一行
           $this->sleep($sleep);                     

           return ['job' => null, 'failed' => false];
       }
   ```