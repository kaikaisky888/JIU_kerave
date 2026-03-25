<?php
/*
 * @Author: Fox Blue
 * @Date: 2021-06-12 16:26:51
 * @LastEditTime: 2021-08-17 00:28:19
 * @Description: Forward, no stop
 */

use think\facade\Env;

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// +----------------------------------------------------------------------
// | Workerman设置 仅对 php think worker:gateway 指令有效
// +----------------------------------------------------------------------
return [
    // 扩展自身需要的配置
    'protocol'              => Env::get('gateway.protocol', 'websocket'), // 协议 支持 tcp udp unix http websocket text
    'host'                  => Env::get('gateway.host', '0.0.0.0'), // 监听地址
    'port'                  => (int) Env::get('gateway.port', 2348), // 监听端口
    'socket'                => '', // 完整监听地址
    'context'               => [], // socket 上下文选项
    'register_deploy'       => true, // 是否需要部署register
    'businessWorker_deploy' => true, // 是否需要部署businessWorker
    'gateway_deploy'        => true, // 是否需要部署gateway

    // Register配置
    'registerAddress'       => Env::get('gateway.register_address', '127.0.0.1:1236'),

    // Gateway配置
    'name'                  => Env::get('gateway.name', 'ffphp'),
    'count'                 => (int) Env::get('gateway.count', 1),
    'lanIp'                 => Env::get('gateway.lan_ip', '127.0.0.1'),
    'startPort'             => (int) Env::get('gateway.start_port', 2000),
    'daemonize'             => false,
    'pingInterval'          => (int) Env::get('gateway.ping_interval', 30),
    'pingNotResponseLimit'  => (int) Env::get('gateway.ping_not_response_limit', 0),
    'pingData'              => Env::get('gateway.ping_data', '{"type":"ping"}'),

    // BusinsessWorker配置
    'businessWorker'        => [
        'name'         => Env::get('gateway.business_worker_name', 'BusinessWorker'),
        'count'        => (int) Env::get('gateway.business_worker_count', 1),
        'eventHandler' => Env::get('gateway.event_handler', '\app\push\controller\Events'),
    ],

];
