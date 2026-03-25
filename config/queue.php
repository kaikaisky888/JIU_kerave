<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    'default'     => 'sync',
    'connections' => [
        'sync'     => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'queue'      => 'default',
            'table'      => 'jobs',
            'connection' => null,
        ],
        'redis'    => [
            'type'       => 'redis',
            'queue'      => Env::get('queue.redis.queue', 'default'),
            'host'       => Env::get('queue.redis.host', Env::get('redis.host', '127.0.0.1')),
            'port'       => (int) Env::get('queue.redis.port', Env::get('redis.port', 6379)),
            'password'   => Env::get('queue.redis.password', Env::get('redis.password', '')),
            'select'     => (int) Env::get('queue.redis.select', Env::get('redis.select', 0)),
            'timeout'    => (int) Env::get('queue.redis.timeout', Env::get('redis.timeout', 0)),
            'persistent' => (bool) Env::get('queue.redis.persistent', false),
        ],
    ],
    'failed'      => [
        'type'  => 'none',
        'table' => 'failed_jobs',
    ],
];
