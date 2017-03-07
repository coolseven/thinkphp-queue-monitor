# thinkphp-queue-monitor

[thinkphp-queue](https://github.com/top-think/think-queue) 的命令行监控工具。
**尚未开发完成**, **请不要在生产环境使用**

本项目基于 [thinkphp-queue](https://github.com/top-think/think-queue) 这个消息队列库，在该库的基础上增加了消息队列的监控功能。

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

#### 启动监控

```bash
php think queue:monitor start
```

#### 启动 worker 进程

```
php think queue:work --daemon --queue yourQueueName
```

#### 停止监控

```bash
php think queue:monitor stop
```

#### 在终端中查看监控结果 (暂未实现)

```bash
php think queue:monitor report
```



### TODO

- [ ] job 监控
- [ ] 支持 database 驱动下的监控
- [ ] 支持将监控结果保存到 mongodb 或者 database。 目前仅支持保存到 redis。
- [ ] 在终端中查看监控结果
- [ ] 使用tp5 内置的日志类来保存监控日志
- [ ] 监控 queue_failed 事件



### 备注

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
               Hook::listen('worker_daemon_start',$queue);
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
                   Hook::listen('worker_memory_exceeded', $queue);
                   $this->stop();
               }
               
               if ( $this->queueShouldRestart($lastRestart) ) {
                   Hook::listen('worker_queue_restart', $queue);
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
               Hook::listen('worker_before_process', $queue);
               return $this->process($job, $maxTries, $delay);
           }
           
           Hook::listen('worker_before_sleep', $queue);
           $this->sleep($sleep);                     

           return ['job' => null, 'failed' => false];
       }
   ```