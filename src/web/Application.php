<?php

namespace swoft\web;

use inhere\console\utils\Show;
use swoft\App;
use swoft\base\RequestContext;
use swoft\console\Console;
use swoft\filter\FilterChain;
use swoft\helpers\ResponseHelper;

/**
 * 应用主体
 *
 * @uses      Application
 * @version   2017年04月25日
 * @author    stelin <phpcrazy@126.com>
 * @copyright Copyright 2010-2016 swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class Application extends \swoft\base\Application
{
    private $tcp;
    private $http;
    private $swoft;
    private $setting;
    private $listen;

    public function init($startFile = '')
    {
        $this->status['startFile'] = $startFile;

        // 注册全局错误错误
        $this->registerErrorHandler();

        $this->loadSwoftIni();
    }

    /**
     * @param bool $daemon
     * @return $this
     */
    public function asDaemon($daemon = null)
    {
        if (null !== $daemon) {
            $this->setting['daemonize'] = (bool)$daemon;
        }

        return $this;
    }

    /**
     * start
     */
    public function start()
    {
        if ($this->isRunning()) {
            echo "The server have been running!(PID: {$this->server['masterPid']})\n";
            exit(0);
        }

        Show::panel([
            'http' => $this->http,
            'tcp' => $this->tcp,
        ]);

        App::$app = $this;

        $this->swoft = new \Swoole\Http\Server($this->http['host'], $this->http['port'], $this->http['model'], $this->http['type']);

        $this->swoft->set($this->setting);
        $this->swoft->on('start', [$this, 'onStart']);
        $this->swoft->on('workerstart', [$this, 'onWorkerStart']);
        $this->swoft->on('managerstart', [$this, 'onManagerStart']);
        $this->swoft->on('request', [$this, 'onRequest']);

        if ((int)$this->tcp['enable'] === 1) {
            $this->listen = $this->swoft->listen($this->tcp['host'], $this->tcp['port'], $this->tcp['type']);
            $this->listen->set([
                'open_eof_check' => false,
                'package_max_length' => 20480,
            ]);
            $this->listen->on('connect', [$this, 'onConnect']);
            $this->listen->on('receive', [$this, 'onReceive']);
            $this->listen->on('close', [$this, 'onClose']);
        }

        $this->swoft->start();
    }

    protected function loadSwoftIni()
    {
        $setings = parse_ini_file($this->settingPath, true);
        if (!isset($setings['tcp'])) {

        }
        if (!isset($setings['http'])) {

        }
        if (!isset($setings['server'])) {

        }

        if (!isset($setings['setting'])) {

        }

        $this->tcp = $setings['tcp'];
        $this->http = $setings['http'];
        $this->server = $setings['server'];
        $this->setting = $setings['setting'];
    }

    public function onConnect(\Swoole\Server $server, int $fd, int $from_id)
    {
        var_dump("connnect------");
    }

    public function onReceive(\Swoole\Server $server, int $fd, int $from_id, string $data)
    {
        try {
            // 解包
            $packer = App::getPacker();
            $data = $packer->unpack($data);

            // 初始化
            $this->beforeReceiver($data);

            // 执行函数调用
            $response = $this->runService($data);
            $data = $packer->pack($response);

            // 处理完成
            $this->after();
        } catch (\Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            $data = ResponseHelper::formatData("", $code, $message);
        }
        $server->send($fd, $data);
    }
    public function onClose(\Swoole\Server $server, int $fd, int $reactorId)
    {
        var_dump("close------");
    }

    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        // chrome两次请求bug修复
        if (isset($request->server['request_uri']) && $request->server['request_uri'] === '/favicon.ico') {
            $response->end('favicon.ico');
            return false;
        }

        $this->count = $this->count + 1;

        echo "count= " . $this->count . "---------\n";

        $this->beforeRequest($request, $response);
        $swfRequest = RequestContext::getRequest();
        $swfResponse = RequestContext::getResponse();

        try {

            App::profileStart("app.route");

            /* @var Router $router*/
            $router = App::getBean('router');

            App::profileStart("app.route.match");
            list($path, $info) = $router->match($swfRequest->getRequestUri(), $swfRequest->getMethod());
            App::profileEnd("app.route.match");

            App::profileEnd("app.route");

            if (!$info) {
                return $this->handleNotFound($path);
            }

            $this->runController($path, $info);

        } catch (\Exception $e) {
            $swfResponse->setResponseContent($e->getMessage());
            $swfResponse->send();
        }

        $this->after();
    }

    /**
     * 执行控制器
     *
     * @param string $path  uri路径
     * @param array  $info  参数
     */
    public function runController(string $path, array $info)
    {
        /* @var Controller $controller */
        list($controller, $actionId, $params) = $this->createController($path, $info);

        /* run controller with filters */
        $this->runControllerWithFilters($controller, $actionId, $params);
    }

    public function handleNotFound($path)
    {
        // ...
    }

    public function onStart(\Swoole\Http\Server $server)
    {
        file_put_contents($this->server['pfile'], $server->master_pid);
        file_put_contents($this->server['pfile'], ',' . $server->manager_pid, FILE_APPEND);
        swoole_set_process_name($this->server['pname'] . " master process (" . $this->status['startFile'] . ")");
    }

    public function onManagerStart(\Swoole\Http\Server $server)
    {
        if($this->useProvider){
            App::getServiceProvider()->registerService("user", '127.0.0.1', 8099);
        }

        swoole_set_process_name($this->server['pname']." manager process");
    }

    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId)
    {
        $setting = $server->setting;
        if ($workerId >= $setting['worker_num']) {
            swoole_set_process_name($this->server['pname'] . " task process");
        } else {
            swoole_set_process_name($this->server['pname'] . " worker process");
        }
    }

    /**
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     *
     * @return string
     */
    public function getViewsPath()
    {
        return $this->viewsPath;
    }

    private function beforeReceiver($data)
    {
        $logid = $data['logid'] ?? uniqid();
        $spanid = $data['spanid'] ?? 0;
        $uri = $data['func'] ?? "null";

        $contextData = [
            'logid' => $logid,
            'spanid' => $spanid,
            'uri' => $uri,
            'requestTime' => microtime(true),
        ];
        RequestContext::setContextData($contextData);
    }

    private function beforeRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        RequestContext::setRequest($request);
        RequestContext::setResponse($response);

        // header获取日志ID和spanid请求跨度ID
        $logid = RequestContext::getRequest()->getHeader('logid', uniqid());
        $spanid = RequestContext::getRequest()->getHeader('spanid', 0);
        $uri = RequestContext::getRequest()->getRequestUri();

        $contextData = [
            'logid' => $logid,
            'spanid' => $spanid,
            'uri' => $uri,
            'requestTime' => microtime(true),
        ];
        RequestContext::setContextData($contextData);
    }

    /**
     * run controller with filters
     *
     * @param Controller $controller 控制器
     * @param string     $actionId   actionID
     * @param array      $params     action参数
     */
    private function runControllerWithFilters(Controller $controller, string $actionId, array $params)
    {
        $request = App::getRequest();
        $response = App::getResponse();

        /* @var FilterChain $filter */
        $filter = App::getBean('filter');
        $result = $filter->doFilter($request, $response, $filter);

        if($result){
            $response = $controller->run($actionId, $params);
            $response->send();
        }
    }

    private function after()
    {
        App::getLogger()->appendNoticeLog();
        RequestContext::destory();
    }

    /**
     * 获取server
     *
     * @return \Swoole\Http\Server
     */
    public function getServer()
    {
        return $this->swoft;
    }
}
