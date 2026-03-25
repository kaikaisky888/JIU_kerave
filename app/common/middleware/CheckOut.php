<?php
/*
 * @Author: Fox Blue
 * @Date: 2021-05-31 13:44:29
 * @LastEditTime: 2021-09-22 22:47:19
 * @Description: Forward, no stop
 */

namespace app\common\middleware;

use think\Request;
use think\facade\Cache;

/**
 * 检测
 * @package app\common\middleware
 */
class CheckOut
{
    
    public function handle(Request $request, \Closure $next)
    {
        return $next($request);
    }

}