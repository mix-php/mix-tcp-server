<?php

namespace Mix\Tcp\Server;

use Mix\Core\Bean\AbstractObject;
use Mix\Core\Coroutine;
use Mix\Helper\ProcessHelper;

/**
 * Class TcpServer
 * @package Mix\Tcp\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class TcpServer extends AbstractObject
{

    /**
     * 主机
     * @var string
     */
    public $host = '127.0.0.1';

    /**
     * 端口
     * @var int
     */
    public $port = 9503;

    /**
     * 应用配置文件
     * @var string
     */
    public $configFile = '';

    /**
     * 运行参数
     * @var array
     */
    public $setting = [];

    /**
     * 服务名称
     * @var string
     */
    const SERVER_NAME = 'mix-tcpd';

    /**
     * 默认运行参数
     * @var array
     */
    protected $_defaultSetting = [
        // 开启协程
        'enable_coroutine'       => true,
        // 主进程事件处理线程数
        'reactor_num'            => 8,
        // 工作进程数
        'worker_num'             => 8,
        // 任务进程数
        'task_worker_num'        => 0,
        // PID 文件
        'pid_file'               => '/var/run/mix-tcpd.pid',
        // 日志文件路径
        'log_file'               => '/tmp/mix-tcpd.log',
        // 异步安全重启
        'reload_async'           => true,
        // 退出等待时间
        'max_wait_time'          => 60,
        // 开启后，PDO 协程多次 prepare 才不会有 40ms 延迟
        'open_tcp_nodelay'       => true,
        // 进程的最大任务数
        'max_request'            => 0,
        // 主进程启动事件回调
        'start_callback'         => null,
        // 管理进程启动事件回调
        'manager_start_callback' => null,
        // 管理进程停止事件回调
        'manager_stop_callback'  => null,
        // 工作进程启动事件回调
        'worker_start_callback'  => null,
        // 工作进程停止事件回调
        'worker_stop_callback'   => null,
        // 连接事件回调
        'connect_callback'       => null,
        // 接收事件回调
        'receive_callback'       => null,
        // 关闭事件回调
        'close_callback'         => null,
    ];

    /**
     * 运行参数
     * @var array
     */
    protected $_setting = [];

    /**
     * 服务器
     * @var \Swoole\Server
     */
    protected $_server;

    /**
     * 启动服务
     * @return bool
     */
    public function start()
    {
        // 初始化
        $this->_server = new \Swoole\Server($this->host, $this->port);
        // 配置参数
        $this->_setting = $this->setting + $this->_defaultSetting;
        $this->_server->set($this->_setting);
        // 禁用内置协程
        $this->_server->set([
            'enable_coroutine' => false,
        ]);
        // 绑定事件
        $this->_server->on(SwooleEvent::START, [$this, 'onStart']);
        $this->_server->on(SwooleEvent::MANAGER_START, [$this, 'onManagerStart']);
        $this->_server->on(SwooleEvent::MANAGER_STOP, [$this, 'onManagerStop']);
        $this->_server->on(SwooleEvent::WORKER_START, [$this, 'onWorkerStart']);
        $this->_server->on(SwooleEvent::WORKER_STOP, [$this, 'onWorkerStop']);
        $this->_server->on(SwooleEvent::CONNECT, [$this, 'onConnect']);
        $this->_server->on(SwooleEvent::RECEIVE, [$this, 'onReceive']);
        $this->_server->on(SwooleEvent::CLOSE, [$this, 'onClose']);
        // 欢迎信息
        $this->welcome();
        // 执行回调
        $this->_setting['start_callback'] and call_user_func($this->_setting['start_callback']);
        // 启动
        return $this->_server->start();
    }

    /**
     * 主进程启动事件
     * 仅允许echo、打印Log、修改进程名称，不得执行其他操作
     * @param \Swoole\Http\Server $server
     */
    public function onStart(\Swoole\Http\Server $server)
    {
        // 进程命名
        ProcessHelper::setProcessTitle(static::SERVER_NAME . ": master {$this->host}:{$this->port}");
    }

    /**
     * 管理进程启动事件
     * 可以使用基于信号实现的同步模式定时器swoole_timer_tick，不能使用task、async、coroutine等功能
     * @param \Swoole\Http\Server $server
     */
    public function onManagerStart(\Swoole\Http\Server $server)
    {
        try {

            // 进程命名
            ProcessHelper::setProcessTitle(static::SERVER_NAME . ": manager");
            // 实例化App
            new \Mix\Http\Application(require $this->configFile);
            // 执行回调
            $this->_setting['manager_start_callback'] and call_user_func($this->_setting['manager_start_callback']);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(同步模式)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 管理进程停止事件
     * @param \Swoole\Http\Server $server
     */
    public function onManagerStop(\Swoole\Http\Server $server)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server) {
                call_user_func([$this, 'onManagerStart'], $server);
            });
            return;
        }
        try {

            // 执行回调
            $this->_setting['manager_stop_callback'] and call_user_func($this->_setting['manager_stop_callback']);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 工作进程启动事件
     * @param \Swoole\Http\Server $server
     * @param int $workerId
     */
    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $workerId) {
                call_user_func([$this, 'onWorkerStart'], $server, $workerId);
            });
            return;
        }
        try {

            // 进程命名
            if ($workerId < $server->setting['worker_num']) {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": worker #{$workerId}");
            } else {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": task #{$workerId}");
            }
            // 实例化App
            new \Mix\Http\Application(require $this->configFile);
            // 执行回调
            $this->_setting['worker_start_callback'] and call_user_func($this->_setting['worker_start_callback']);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 工作进程停止事件
     * @param \Swoole\Http\Server $server
     * @param int $workerId
     */
    public function onWorkerStop(\Swoole\Http\Server $server, int $workerId)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $workerId) {
                call_user_func([$this, 'onWorkerStart'], $server, $workerId);
            });
            return;
        }
        try {

            // 执行回调
            $this->_setting['worker_stop_callback'] and call_user_func($this->_setting['worker_stop_callback']);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 连接事件
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $reactorId
     */
    public function onConnect(\Swoole\Server $server, int $fd, int $reactorId)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $fd, $reactorId) {
                call_user_func([$this, 'onConnect'], $server, $fd, $reactorId);
            });
            return;
        }
        try {

            // 前置初始化
            \Mix::$app->tcp->beforeInitialize($server, $fd);
            // 处理消息
            \Mix::$app->runConnect(\Mix::$app->tcp);
            // 执行回调
            $this->_setting['connect_callback'] and call_user_func($this->_setting['connect_callback'], true);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['connect_callback'] and call_user_func($this->_setting['connect_callback'], false);
        } finally {
            // 清扫组件容器(同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 接收事件
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $reactorId
     * @param string $data
     */
    public function onReceive(\Swoole\Server $server, int $fd, int $reactorId, string $data)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $fd, $reactorId, $data) {
                call_user_func([$this, 'onReceive'], $server, $fd, $reactorId, $data);
            });
            return;
        }
        try {

            // 前置初始化
            \Mix::$app->tcp->beforeInitialize($server, $fd);
            // 处理消息
            \Mix::$app->runReceive(\Mix::$app->tcp, $data);
            // 执行回调
            $this->_setting['receive_callback'] and call_user_func($this->_setting['receive_callback'], true);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['receive_callback'] and call_user_func($this->_setting['receive_callback'], false);
        } finally {
            // 清扫组件容器(同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 关闭事件
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $reactorId
     */
    public function onClose(\Swoole\Server $server, int $fd, int $reactorId)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $fd, $reactorId) {
                call_user_func([$this, 'onClose'], $server, $fd, $reactorId);
            });
            return;
        }
        try {

            // 前置初始化
            \Mix::$app->tcp->beforeInitialize($server, $fd);
            // 处理连接关闭
            \Mix::$app->runClose(\Mix::$app->tcp);
            // 执行回调
            $this->_setting['close_callback'] and call_user_func($this->_setting['close_callback'], true);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['close_callback'] and call_user_func($this->_setting['close_callback'], false);
        } finally {
            // 清扫组件容器(同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 欢迎信息
     */
    protected function welcome()
    {
        $swooleVersion = swoole_version();
        $phpVersion    = PHP_VERSION;
        echo <<<EOL
                             _____
_______ ___ _____ ___   _____  / /_  ____
__/ __ `__ \/ /\ \/ /__ / __ \/ __ \/ __ \
_/ / / / / / / /\ \/ _ / /_/ / / / / /_/ /
/_/ /_/ /_/_/ /_/\_\  / .___/_/ /_/ .___/
                     /_/         /_/


EOL;
        println('Server         Name:      ' . static::SERVER_NAME);
        println('System         Name:      ' . strtolower(PHP_OS));
        println("PHP            Version:   {$phpVersion}");
        println("Swoole         Version:   {$swooleVersion}");
        println('Framework      Version:   ' . \Mix::$version);
        $this->_setting['max_request'] == 1 and println('Hot            Update:    enabled');
        $this->_setting['enable_coroutine'] and println('Coroutine      Mode:      enabled');
        println("Listen         Addr:      {$this->host}");
        println("Listen         Port:      {$this->port}");
        println('Reactor        Num:       ' . $this->_setting['reactor_num']);
        println('Worker         Num:       ' . $this->_setting['worker_num']);
        println("Configuration  File:      {$this->configFile}");
    }

}
