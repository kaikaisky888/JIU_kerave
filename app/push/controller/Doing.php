<?php

/*
 * @Author: bluefox
 * @Motto: Running fox looking for dreams
 * @Date: 2021-01-07 15:59:02
 * @LastEditTime: 2021-10-11 17:36:39
 */
namespace app\push\controller;

use app\common\controller\PushController;
use think\facade\Db;
use GatewayWorker\Lib\Gateway;
use app\common\FoxCommon;
use app\common\FoxKline;
use app\common\service\HuobiRedis;
use app\common\service\ElasticService;
use app\common\service\KlineService;
class Doing extends PushController
{
    public static function elastic()
    {
        $obj = new ElasticService();
        return $obj;
    }
    public static function hbrds()
    {
        $setredis = \think\facade\Config::get('cache.stores.redis');
        $hbrds = new HuobiRedis($setredis['host'], $setredis['port'], $setredis['password']);
        return $hbrds;
    }
    /**
     * @Title: 根据用户位置推送数据
     * @param {*} $client_id
     * @param {*} $type
     */
    public static function find_product_list()
    {
        $product = \app\admin\model\ProductLists::where('status', 1)->where('base', 0)->order('sort', 'desc')->select();
        $msgs = [];
        if ($product) {
            $msgs['type'] = 'allticker';
            foreach ($product as $k => $v) {
                $zero_table = 'market_' . $v['code'] . '_kline_1min';
                $msgs['ticker'][$k] = ['market' => $v['code'], 'open' => (double) $v['open'], 'close' => (double) $v['close'], 'high' => (double) $v['high'], 'low' => (double) $v['low'], 'change' => (double) $v['change'], 'amount' => (double) $v['amount'], 'count' => (int) $v['count'], 'vol' => (double) $v['volume'], 'volume' => (double) $v['volume'], 'canvas' => KlineService::search_svg($zero_table, '1min', 30), 'usd' => FoxKline::get_me_price_usdt_to_usd($v['close'])];
            }
            Gateway::sendToAll(json_encode($msgs));
        }
    }
    /**
     * @Title: 给用户推送TICK
     * @param {*} $client_id
     * @param {*} $code
     * @param {*} $type
     * @param {*} $deal
     */
    public static function find_product_tick($client_id, $find, $code, $type, $uid, $deal = 1)
    {
        if ($client_id) {
            $msgs = [];
            $zero_table = 'market_' . $code . '_kline_1min';
            $kinsetinfos = KlineService::search($zero_table, null, null, $type, 1);
            if (isset($kinsetinfos[0])) {
                $kinsetinfo = $kinsetinfos[0];
                $msgs['market'] = $code;
                $product = \app\admin\model\ProductLists::where('code', $code)->field('close,change')->find();
                $msgs['tick'] = ['open' => (double) $kinsetinfo['close'], 'close' => (double) $product['close'], 'high' => (double) $kinsetinfo['high'], 'low' => (double) $kinsetinfo['low'], 'change' => (double) $product['change'], 'amount' => (double) $kinsetinfo['amount'], 'count' => (int) $kinsetinfo['count'], 'id' => (int) $kinsetinfo['time'], 'vol' => (double) $kinsetinfo['vol'], 'volume' => (double) $kinsetinfo['vol']];
                if ($uid && $find == 'leverdeal') {
                    $memberId = intVal($uid);
                    if ($memberId > 0) {
                        $productwhere[] = ['types', 'like', '%2%'];
                        $productwhere[] = ['status', '=', '1'];
                        $product = \app\admin\model\ProductLists::where($productwhere)->field('id,code,last_price')->order('sort', 'desc')->select();
                        $m_order = new \app\admin\model\OrderLeverdeal();
                        if ($product) {
                            foreach ($product as $k => $v) {
                                $money = \app\admin\model\MemberWallet::where('product_id', $v['id'])->where('uid', $memberId)->value('le_money');
                                $user_order = \app\admin\model\OrderLeverdeal::where('product_id', $v['id'])->where('uid', $memberId)->where('status', 1)->field('id')->select();
                                if ($user_order) {
                                    foreach ($user_order as $uk => $uv) {
                                        $order = $m_order->where('id', $uv['id'])->find();
                                        //加锁
                                        $rate = bc_mul(bc_mul($order['buy_price'], $order['account']), $order['play_rate']);
                                        $coin_rate = bc_div($rate, $order['buy_price']);
                                        //化为币
                                        $salf = bc_mul(bc_sub($v['last_price'], $order['buy_price']), $order['account']);
                                        $coin_salf = bc_div($salf, $v['last_price']);
                                        //化为币
                                        $deal_salf = bc_mul(bc_sub($coin_salf, $coin_rate), $order['account']);
                                        $long = bc_sub($v['last_price'], $order['buy_price']);
                                        if ($order['style'] == 1 && $long > 0) {
                                            //买涨实涨：盈
                                            if ($deal_salf < 0) {
                                                $deal_salf = 0 - $deal_salf;
                                            }
                                        } else {
                                            if ($order['style'] == 1 && $long < 0) {
                                                //买涨实跌：亏
                                                if ($deal_salf > 0) {
                                                    $deal_salf = 0 - $deal_salf;
                                                }
                                            } else {
                                                if ($order['style'] == 2 && $long > 0) {
                                                    //买跌实涨：亏
                                                    if ($deal_salf > 0) {
                                                        $deal_salf = 0 - $deal_salf;
                                                    }
                                                } else {
                                                    if ($order['style'] == 2 && $long < 0) {
                                                        //买跌实跌：盈
                                                        if ($deal_salf < 0) {
                                                            $deal_salf = 0 - $deal_salf;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        $money += $deal_salf;
                                    }
                                }
                                $money = round_pad_zero($money, 8);
                                $msgs['usermoney'][$v['code']] = $money;
                            }
                        }
                    }
                }
                if (Gateway::isOnline($client_id)) {
                    Gateway::sendToClient($client_id, json_encode($msgs));
                }
            }
        }
    }
    /**
     * @Title: 推送TRADE
     * @param {*} $client_id
     * @param {*} $code
     */
    public static function find_product_trade($client_id, $find, $code)
    {
        $msg = [];
        $msgs = [];
        $stable = 'tradelogs_' . $code;
        $sinfo = self::hbrds()->read($stable);
        $dtable = 'depthlist_' . $code;
        $dinfo = self::hbrds()->read($dtable);
        $msg['bid'] = json_decode($dinfo['bid'], true);
        $msg['ask'] = json_decode($dinfo['ask'], true);
        $msgs['tradelog'] = json_decode($sinfo['data'], true);
        $msgs['market'] = $code;
        $msgs['depthlist'] = $msg;
        if (Gateway::isOnline($client_id)) {
            Gateway::sendToClient($client_id, json_encode($msgs));
        }
    }
    public static function foxRand($pro = ['40' => 40, '60' => 60])
    {
        $ret = '';
        $sum = array_sum($pro);
        foreach ($pro as $k => $v) {
            $r = mt_rand(1, $sum);
            if ($r <= $v) {
                $ret = $k;
                break;
            } else {
                $sum = max(0, $sum - $v);
            }
        }
        return $ret;
    }
    /**
     * @Title: 生成空气币K线
     */
    public static function kong_kline()
    {
        $day = date("d", time());
        $dh = date("H", time());
        $di = date("i", time());
        $ds = date("s", time());
        $product = \app\admin\model\ProductLists::where('status', 1)->where('is_kong', 1)->field('id,code,kong_min,kong_max,last_price,volume,kong_zero,kong_type')->find();
        if ($product) {
            $_new_rand = self::foxRand();
            $where[] = ['code', '=', $product->code];
            if ($product->last_price == 0) {
                $open_price = $product->kong_min;
                $close_price = FoxCommon::generateRand($product->kong_min, $product->kong_max);
            } else {
                $o_new_rand = rand(0, 9);
                if ($product->kong_min < $product->kong_max) {
                    //价格范围为涨
                    if ($product->last_price < $product->kong_min) {
                        $open_price = $product->kong_min;
                        $close_price = $open_price + FoxCommon::kline_k_price($open_price);
                        Db::name('product_lists')->where($where)->update(['kong_type' => 1]);
                        //涨
                    } else {
                        if ($product->last_price > $product->kong_max) {
                            $open_price = $product->kong_max;
                            $close_price = $open_price - FoxCommon::kline_k_price($open_price);
                            Db::name('product_lists')->where($where)->update(['kong_type' => 2]);
                            //跌
                        } else {
                            $open_price = $product->last_price;
                            if ($o_new_rand % 2 == 0) {
                                $close_price = $open_price - FoxCommon::kline_k_price($open_price);
                            } else {
                                $close_price = $open_price + FoxCommon::kline_k_price($open_price);
                            }
                        }
                    }
                } else {
                    if ($product->kong_min > $product->kong_max) {
                        //价格范围为跌
                        if ($product->last_price > $product->kong_min) {
                            $open_price = $product->kong_min;
                            $close_price = $open_price - FoxCommon::kline_k_price($open_price);
                            Db::name('product_lists')->where($where)->update(['kong_type' => 2]);
                            //跌
                        } else {
                            if ($product->last_price < $product->kong_max) {
                                $open_price = $product->kong_max;
                                $close_price = $open_price + FoxCommon::kline_k_price($open_price);
                                Db::name('product_lists')->where($where)->update(['kong_type' => 1]);
                                //涨
                            } else {
                                $open_price = $product->last_price;
                                if ($o_new_rand % 2 == 0) {
                                    $close_price = $open_price - FoxCommon::kline_k_price($open_price);
                                } else {
                                    $close_price = $open_price + FoxCommon::kline_k_price($open_price);
                                }
                            }
                        }
                    } else {
                        //万一出现傻B弄成一样呢
                        $open_price = $product->kong_min;
                        $close_price = FoxCommon::generateRand($product->kong_min, $product->kong_max);
                    }
                }
            }
            $dtime = strtotime(date('Y-m-d H:i'));
            if ($ds == '00' || $ds == '01') {
                Db::name('product_lists')->where($where)->update(['vol_rand' => 0]);
                $vol_rand = 0;
            } else {
                $vol_rand = \app\admin\model\ProductLists::where('status', 1)->where('is_kong', 1)->value('vol_rand');
            }
            if ($open_price <= 0) {
                $open_price = FoxCommon::generateRand($product->kong_min, $product->kong_max);
            }
            if ($close_price <= 0) {
                $close_price = FoxCommon::generateRand($product->kong_min, $product->kong_max);
            }
            $es_table = 'market_' . $product->code . '_kline_1min';
            $dvolume = FoxCommon::generateRand(1000.0001, 99999.0009, 8);
            $damount = FoxCommon::generateRand(1000.0001, 99999.0009, 8);
            $dcount = rand(1000, 99999);
            if ($einfo = KlineService::search_day($es_table)) {
                $high = $einfo['high'];
                $low = $einfo['low'];
                $volume = $einfo['volume'] + $vol_rand;
                $amount = $einfo['amount'] + $vol_rand;
                $count = $einfo['count'] + $vol_rand;
            } else {
                if ($open_price > $close_price) {
                    $high = $open_price;
                    $low = $close_price;
                } else {
                    $high = $close_price;
                    $low = $open_price;
                }
                $volume = $dvolume;
                $amount = $damount;
                $count = $dcount;
            }
            //数组控制
            $open_price = round_pad_zero($open_price, $product['kong_zero']);
            $close_price = round_pad_zero($close_price, $product['kong_zero']);
            $high = round_pad_zero($high, $product['kong_zero']);
            $low = round_pad_zero($low, $product['kong_zero']);
            //结束
            $msg['type'] = "tradingvew";
            $msg['ch'] = str_replace('_', '.', $es_table);
            $msg['symbol'] = $product->code;
            //火币对
            $msg['period'] = '1min';
            //分期
            $msg['open'] = $open_price;
            $msg['close'] = $close_price;
            $msg['low'] = round_pad_zero($open_price - FoxCommon::kline_k_price($open_price), $product['kong_zero']);
            $msg['vol'] = $dvolume;
            $msg['high'] = round_pad_zero($open_price + FoxCommon::kline_k_price($open_price), $product['kong_zero']);
            $msg['count'] = $dcount;
            $msg['amount'] = $damount;
            $msg['time'] = $dtime;
            $msg['ranges'] = fox_time($dtime);
            KlineService::save($es_table, $msg);
            $zero_time = strtotime(date("Y-m-d"), time());
            $zero_open = $open_price;
            $zero_data = KlineService::search_one($es_table, $zero_time);
            if (isset($zero_data[0])) {
                $zero_open = $zero_data[0]['close'];
            } else {
                $zero_data_day = KlineService::search_one_day($es_table, $zero_time);
                if (isset($zero_data_day[0])) {
                    $zero_open = $zero_data_day[0]['close'];
                }
            }
            $ck = 1;
            $cc = $close_price * $ck;
            $co = $zero_open * $ck;
            $change = round(($cc - $co) / $co * 100, 4);
            $ladata = ['open' => $open_price, 'close' => $close_price, 'high' => $high, 'low' => $low, 'change' => $change, 'amount' => $amount, 'count' => $count, 'volume' => $volume, 'last_price' => $close_price, 'vol_rand' => $vol_rand + FoxCommon::generateRand(0.0001, 1.9999, 8)];
            Db::name('product_lists')->where($where)->update($ladata);
            //入库
            //depth
            $depth['type'] = "depthlist";
            $depth['market'] = $product->code;
            //火币对
            $depth['bid'] = [];
            //买入
            $depth['ask'] = [];
            //卖出
            $bids = 20;
            $df = FoxCommon::generateRand(0.0001, 3.0009, 4);
            $dfs = FoxCommon::generateRand(0.0001, 3.0009, 4);
            for ($i = 0; $i < $bids; $i++) {
                //出价  买入
                $depth['bid'][$i]['id'] = $i;
                $depth['bid'][$i]['price'] = $close_price;
                $depth['bid'][$i]['quantity'] = $df;
                $depth['bid'][$i]['total'] = $df * ($i + 1);
                $depth['ask'][$i]['id'] = $i;
                $depth['ask'][$i]['price'] = $close_price;
                $depth['ask'][$i]['quantity'] = $df;
                $depth['ask'][$i]['total'] = $dfs * ($i + 1);
            }
            $msgs['bid'] = json_encode($depth['bid']);
            $msgs['ask'] = json_encode($depth['ask']);
            $stable = $depth['type'] . '_' . $depth['market'];
            self::hbrds()->write($stable, $msgs);
            //trade
            $trade['market'] = $product->code;
            //货币对
            $trade['id'] = time() * 1000;
            $trade['price'] = (double) $close_price;
            $td = FoxCommon::generateRand(10.0001, 30.0009, 4);
            $trade['tradeId'] = time() * 100;
            $new_rand = rand(0, 9);
            if ($new_rand % 2 == 0) {
                $trade['trade_type'] = 2;
                $trade['num'] = $td + FoxCommon::generateRand(0.0001, 3.0009, 4);
            } else {
                $trade['trade_type'] = 1;
                $trade['num'] = $td - FoxCommon::generateRand(0.0001, 3.0009, 4);
            }
            $trade['time'] = (string) time();
            $msgt['type'] = "tradelog";
            $stable = 'tradelogs_' . $trade['market'];
            $msgt['data'] = json_encode($trade);
            self::hbrds()->write($stable, $msgt);
        }
    }
    public static function do_deal_order()
    {
        $m_order = new \app\admin\model\OrderDeal();
        $deal_orders = $m_order->field('id')->where('type', 1)->where('status', 1)->order('create_time', 'desc')->limit(20)->select();
        if ($deal_orders) {
            foreach ($deal_orders as $k => $v) {
                $m_order->startTrans();
                //开启事务
                try {
                    $m_product = new \app\admin\model\ProductLists();
                    $m_wallet = new \app\admin\model\MemberWallet();
                    $m_user = new \app\admin\model\MemberUser();
                    $m_log = new \app\admin\model\MemberWalletLog();
                    $order = $m_order->lock(true)->where('id', $v['id'])->field('id,uid,price,account_product,title,direction,product_id')->find();
                    //加锁
                    $is_test = $m_user->where('id', $order['uid'])->value('is_test');
                    $pro = $m_product->where('id', $order['product_id'])->field('id,close')->find();
                    $user_wallet = $m_wallet->where('product_id', $pro['id'])->where('uid', $order['uid'])->field('id,product_id,ex_money')->find();
                    /* if (floatcmp($order['price'], $pro['close'])) {
                         if ($order['direction'] == 1) {
                             //买入
                             $now_money = bc_add($user_wallet['ex_money'], $order['account_product']);
                             if ($m_wallet->where('id', $user_wallet['id'])->update(['ex_money' => $now_money])) {
                                 $m_order->where('id', $order['id'])->update(['status' => 2, 'update_time' => time(), 'price_product' => $pro['close']]);
                                 $lgdata['account'] = $order['account_product'];
                                 $lgdata['wallet_id'] = $user_wallet['id'];
                                 $lgdata['product_id'] = $user_wallet['product_id'];
                                 $lgdata['uid'] = $order['uid'];
                                 $lgdata['is_test'] = $is_test;
                                 $lgdata['before'] = $user_wallet['ex_money'];
                                 $lgdata['after'] = bc_sub($user_wallet['ex_money'], $order['account_product']);
                                 $lgdata['account_sxf'] = 0;
                                 $lgdata['all_account'] = bc_sub($lgdata['account'], $lgdata['account_sxf']);
                                 $lgdata['type'] = 4;
                                 $lgdata['title'] = $order['title'];
                                 $lgdata['order_type'] = 11;
                                 //买得
                                 $lgdata['order_id'] = $order['id'];
                                 $m_log->save($lgdata);
                             }
                         } else {
                             if ($order['direction'] == 2) {
                                 //卖出
                                 $productBase = $m_product->where('base', 1)->field('id,title')->find();
                                 $base_wallet = $m_wallet->where('product_id', $productBase['id'])->where('uid', $order['uid'])->field('id,product_id,ex_money')->find();
                                 $now_ex_money = bc_add($base_wallet['ex_money'], $order['account_product']);
                                 if ($m_wallet->where('id', $base_wallet['id'])->update(['ex_money' => $now_ex_money])) {
                                     $m_order->where('id', $order['id'])->update(['status' => 2, 'update_time' => time(), 'price_product' => $pro['close']]);
                                     $lgdata['account'] = $order['account_product'];
                                     $lgdata['wallet_id'] = $base_wallet['id'];
                                     $lgdata['product_id'] = $base_wallet['product_id'];
                                     $lgdata['uid'] = $order['uid'];
                                     $lgdata['is_test'] = $is_test;
                                     $lgdata['before'] = $base_wallet['ex_money'];
                                     $lgdata['after'] = bc_add($base_wallet['ex_money'], $order['account_product']);
                                     $lgdata['account_sxf'] = 0;
                                     $lgdata['all_account'] = bc_sub($lgdata['account'], $lgdata['account_sxf']);
                                     $lgdata['type'] = 4;
                                     $lgdata['title'] = $order['title'];
                                     $lgdata['order_type'] = 22;
                                     //卖出得
                                     $lgdata['order_id'] = $order['id'];
                                     $m_log->save($lgdata);
                                 }
                             }
                         }
                     }*/

                    if ($order['direction'] == 1) {
                        if ($pro['close']>=$order['price']) {
                            //买入
                            $now_money = bc_add($user_wallet['ex_money'], $order['account_product']);
                            if ($m_wallet->where('id', $user_wallet['id'])->update(['ex_money' => $now_money])) {
                                $m_order->where('id', $order['id'])->update(['status' => 2, 'update_time' => time(), 'price_product' => $pro['close']]);
                                $lgdata['account'] = $order['account_product'];
                                $lgdata['wallet_id'] = $user_wallet['id'];
                                $lgdata['product_id'] = $user_wallet['product_id'];
                                $lgdata['uid'] = $order['uid'];
                                $lgdata['is_test'] = $is_test;
                                $lgdata['before'] = $user_wallet['ex_money'];
                                $lgdata['after'] = bc_sub($user_wallet['ex_money'], $order['account_product']);
                                $lgdata['account_sxf'] = 0;
                                $lgdata['all_account'] = bc_sub($lgdata['account'], $lgdata['account_sxf']);
                                $lgdata['type'] = 4;
                                $lgdata['title'] = $order['title'];
                                $lgdata['order_type'] = 11;
                                //买得
                                $lgdata['order_id'] = $order['id'];
                                $m_log->save($lgdata);
                            }
                        } else if($order['direction'] == 2){
                            if ($pro['close']<=$order['price']) {
                                //卖出
                                $productBase = $m_product->where('base', 1)->field('id,title')->find();
                                $base_wallet = $m_wallet->where('product_id', $productBase['id'])->where('uid', $order['uid'])->field('id,product_id,ex_money')->find();
                                $now_ex_money = bc_add($base_wallet['ex_money'], $order['account_product']);
                                if ($m_wallet->where('id', $base_wallet['id'])->update(['ex_money' => $now_ex_money])) {
                                    $m_order->where('id', $order['id'])->update(['status' => 2, 'update_time' => time(), 'price_product' => $pro['close']]);
                                    $lgdata['account'] = $order['account_product'];
                                    $lgdata['wallet_id'] = $base_wallet['id'];
                                    $lgdata['product_id'] = $base_wallet['product_id'];
                                    $lgdata['uid'] = $order['uid'];
                                    $lgdata['is_test'] = $is_test;
                                    $lgdata['before'] = $base_wallet['ex_money'];
                                    $lgdata['after'] = bc_add($base_wallet['ex_money'], $order['account_product']);
                                    $lgdata['account_sxf'] = 0;
                                    $lgdata['all_account'] = bc_sub($lgdata['account'], $lgdata['account_sxf']);
                                    $lgdata['type'] = 4;
                                    $lgdata['title'] = $order['title'];
                                    $lgdata['order_type'] = 22;
                                    //卖出得
                                    $lgdata['order_id'] = $order['id'];
                                    $m_log->save($lgdata);
                                }
                            }
                        }
                    }

                    /*$m_order->where('id', $order['id'])->update(['status' => 2, 'price_product' => $order['price'], 'update_time' => time()]);*/
                    $m_order->commit();
                    //事务提交
                } catch (\Throwable $e) {
                    $m_order->rollback();
                }
            }
        }
    }
    /**
     * @Title: 期权订单结算
     */
    /**
     * @Title: 期权订单结算
     */
    public static function do_seconds_order()
    {
        $seconds_kong_num = sysconfig('trade', 'seconds_kong_num');
        $m_order = new \app\admin\model\OrderSeconds();
        $nowtime = time();
        $s_rand = rand(6, 9);
        $map[] = ['op_status', '=', 0];
        $map[] = ['orders_time', '<', $nowtime + $s_rand];
        $orderlist = $m_order->field('id')->where($map)->limit(20)->select();
        if ($orderlist) {
            foreach ($orderlist as $k => $v) {
                $traceParts = ['秒期权结算', '订单ID', (string)$v['id']];
                try {
                    $m_order->startTrans();
                    //开启事务
                    $m_product = new \app\admin\model\ProductLists();
                    $m_wallet = new \app\admin\model\MemberWallet();
                    $m_user = new \app\admin\model\MemberUser();
                    $m_log = new \app\admin\model\MemberWalletLog();

                    $order = $m_order->lock(true)->where('id', $v['id'])->find();
                    //加锁

                    $orderNo = !empty($order['order_no'])
                        ? $order['order_no']
                        : (!empty($order['order_sn']) ? $order['order_sn'] : $order['id']);

                    $is_test = $m_user->where('id', $order['uid'])->value('is_test');
                    $productBase = $m_product->where('base', 1)->field('id,title')->find();
                    $pro = $m_product->where('id', $order['product_id'])
                        ->field('id,title,close,op_kong_min,op_kong_max,op_sx_fee,op_order_kong')
                        ->find();
                    $user_wallet = $m_wallet->where('product_id', $productBase['id'])->where('uid', $order['uid'])->field('id,product_id,op_money')->find();

                    $symbol = !empty($pro['title']) ? $pro['title'] : (string)$order['product_id'];

                    $traceParts = [
                        '秒期权结算',
                        '订单ID', (string)$order['id'],
                        '订单号', (string)$orderNo,
                        '币种', (string)$symbol,
                        '用户ID', (string)$order['uid'],
                        '订单方向', ((int)$order['op_style'] === 1 ? '买涨' : (((int)$order['op_style'] === 2) ? '买跌' : '未知')),
                        '控单类型', ((int)$order['kong_type'] === 1 ? '控赢' : (((int)$order['kong_type'] === 2) ? '控亏' : '不控'))
                    ];

                    $op_k_num = bc_mul($pro['close'], bc_div(FoxCommon::kong_generateRand($pro['op_kong_min'], $pro['op_kong_max']), 100));
                    $now_num_price = bc_sub($pro['close'], $order['start_price']);
                    if ($now_num_price < 0) {
                        $num_aa = 0 - $now_num_price;
                    } else {
                        $num_aa = $now_num_price;
                    }
                    $num_bb = bc_mul($order['start_price'], $seconds_kong_num / 100);

                    $traceParts[] = '实时价';
                    $traceParts[] = (string)$pro['close'];
                    $traceParts[] = '开仓价';
                    $traceParts[] = (string)$order['start_price'];
                    $traceParts[] = '波动绝对值';
                    $traceParts[] = (string)$num_aa;
                    $traceParts[] = '真实行情阈值';
                    $traceParts[] = (string)$num_bb;
                    $traceParts[] = '配置阈值百分比';
                    $traceParts[] = (string)$seconds_kong_num;

                    if ($num_bb > 0 && $num_aa > $num_bb) {
                        $traceParts[] = '分支';
                        $traceParts[] = '真实行情结算';

                        $odata['end_price'] = $pro['close'];
                        $odata['op_status'] = 1;
                        $odata['update_time'] = time();

                        if ($order['op_style'] == 1) {
                            //买涨
                            $traceParts[] = '子分支';
                            $traceParts[] = '真实行情';
                            $traceParts[] = '买涨';

                            if ($now_num_price > 0) {
                                $odata['is_win'] = 1;
                                $traceParts[] = '结果';
                                $traceParts[] = '赢';
                            } else {
                                $odata['is_win'] = 2;
                                $traceParts[] = '结果';
                                $traceParts[] = '输';
                            }
                        } else {
                            if ($order['op_style'] == 2) {
                                //买跌
                                $traceParts[] = '子分支';
                                $traceParts[] = '真实行情';
                                $traceParts[] = '买跌';

                                if ($now_num_price > 0) {
                                    $odata['is_win'] = 2;
                                    $traceParts[] = '结果';
                                    $traceParts[] = '输';
                                } else {
                                    $odata['is_win'] = 1;
                                    $traceParts[] = '结果';
                                    $traceParts[] = '赢';
                                }
                            }
                        }

                        if ($odata['is_win'] == 1) {
                            //赢了
                            $traceParts[] = '资金处理';
                            $traceParts[] = '盈利结算';

                            $odata['true_fee'] = bc_add($order['op_number'], bc_mul($order['op_number'], $order['play_prop'] / 100));
                            $odata['sx_fee'] = bc_mul($odata['true_fee'], $pro['op_sx_fee']);
                            $odata['all_fee'] = bc_sub($odata['true_fee'], $odata['sx_fee']);
                            $now_money = bc_add($user_wallet['op_money'], $odata['all_fee']);

                            if ($m_order->where('id', $order['id'])->update($odata)) {
                                $m_wallet->where('id', $user_wallet['id'])->update(['op_money' => $now_money]);
                                $lgdata['account'] = $order['op_number'];
                                $lgdata['wallet_id'] = $user_wallet['id'];
                                $lgdata['product_id'] = $user_wallet['product_id'];
                                $lgdata['uid'] = $order['uid'];
                                $lgdata['is_test'] = $is_test;
                                $lgdata['before'] = $user_wallet['op_money'];
                                $lgdata['after'] = $now_money;
                                $lgdata['account_sxf'] = $odata['sx_fee'];
                                $lgdata['all_account'] = $odata['all_fee'];
                                $lgdata['type'] = 6;
                                $lgdata['title'] = $productBase['title'];
                                $lgdata['order_type'] = 2;
                                //赢返
                                $lgdata['order_id'] = $order['id'];
                                $m_log->save($lgdata);

                                $traceParts[] = '订单更新';
                                $traceParts[] = '成功';
                            } else {
                                $traceParts[] = '订单更新';
                                $traceParts[] = '失败';
                            }
                        } else {
                            $traceParts[] = '资金处理';
                            $traceParts[] = '亏损结算';

                            $odata['true_fee'] = $order['op_number'];
                            $odata['sx_fee'] = 0;
                            $odata['all_fee'] = $order['op_number'];
                            $m_order->where('id', $order['id'])->update($odata);

                            $traceParts[] = '订单更新';
                            $traceParts[] = '已执行';
                        }
                    } else {
                        $traceParts[] = '分支';
                        $traceParts[] = '非真实行情结算';

                        if ($order['kong_type'] == 1) {
                            //控赢
                            $traceParts[] = '子分支';
                            $traceParts[] = '控赢';

                            if ($order['op_style'] == 1) {
                                //买涨
                                $traceParts[] = '控赢方向';
                                $traceParts[] = '买涨';
                                $odata['end_price'] = bc_add($order['start_price'], $op_k_num);
                            } else {
                                if ($order['op_style'] == 2) {
                                    //买跌
                                    $traceParts[] = '控赢方向';
                                    $traceParts[] = '买跌';
                                    $odata['end_price'] = bc_sub($order['start_price'], $op_k_num);
                                }
                            }
                            $odata['update_time'] = time();
                            $odata['is_win'] = 1;
                            $odata['op_status'] = 1;
                            $odata['true_fee'] = bc_add($order['op_number'], bc_mul($order['op_number'], $order['play_prop'] / 100));
                            $odata['sx_fee'] = bc_mul($odata['true_fee'], $pro['op_sx_fee']);
                            $odata['all_fee'] = bc_sub($odata['true_fee'], $odata['sx_fee']);
                            $now_money = bc_add($user_wallet['op_money'], $odata['all_fee']);

                            if ($m_order->where('id', $order['id'])->update($odata)) {
                                $m_wallet->where('id', $user_wallet['id'])->update(['op_money' => $now_money]);
                                $lgdata['account'] = $order['op_number'];
                                $lgdata['wallet_id'] = $user_wallet['id'];
                                $lgdata['product_id'] = $user_wallet['product_id'];
                                $lgdata['uid'] = $order['uid'];
                                $lgdata['is_test'] = $is_test;
                                $lgdata['before'] = $user_wallet['op_money'];
                                $lgdata['after'] = $now_money;
                                $lgdata['account_sxf'] = $odata['sx_fee'];
                                $lgdata['all_account'] = $odata['all_fee'];
                                $lgdata['type'] = 6;
                                $lgdata['title'] = $productBase['title'];
                                $lgdata['order_type'] = 2;
                                //赢返
                                $lgdata['order_id'] = $order['id'];
                                $m_log->save($lgdata);

                                $traceParts[] = '结果';
                                $traceParts[] = '赢';
                                $traceParts[] = '订单更新';
                                $traceParts[] = '成功';
                            } else {
                                $traceParts[] = '结果';
                                $traceParts[] = '赢';
                                $traceParts[] = '订单更新';
                                $traceParts[] = '失败';
                            }
                        } else {
                            if ($order['kong_type'] == 2) {
                                //控亏
                                $traceParts[] = '子分支';
                                $traceParts[] = '控亏';

                                if ($order['op_style'] == 1) {
                                    //买涨
                                    $traceParts[] = '控亏方向';
                                    $traceParts[] = '买涨';
                                    $odata['end_price'] = bc_sub($order['start_price'], $op_k_num);
                                } else {
                                    if ($order['op_style'] == 2) {
                                        //买跌
                                        $traceParts[] = '控亏方向';
                                        $traceParts[] = '买跌';
                                        $odata['end_price'] = bc_add($order['start_price'], $op_k_num);
                                    }
                                }
                                $odata['update_time'] = time();
                                $odata['is_win'] = 2;
                                $odata['op_status'] = 1;
                                $odata['true_fee'] = $order['op_number'];
                                $odata['sx_fee'] = 0;
                                $odata['all_fee'] = $order['op_number'];
                                $m_order->where('id', $order['id'])->update($odata);

                                $traceParts[] = '结果';
                                $traceParts[] = '输';
                                $traceParts[] = '订单更新';
                                $traceParts[] = '已执行';
                            } else {
                                //不控
                                $traceParts[] = '子分支';
                                $traceParts[] = '不控';

                                $u_op_order_kong = $m_user->where('id', $order['uid'])->value('op_order_kong');
                                $op_order_kong = 50;
                                if ($pro['op_order_kong'] > 0) {
                                    $op_order_kong = $pro['op_order_kong'];
                                }
                                if ($u_op_order_kong > 0) {
                                    $op_order_kong = $u_op_order_kong;
                                }

                                $traceParts[] = '产品胜率配置';
                                $traceParts[] = (string)$pro['op_order_kong'];
                                $traceParts[] = '用户胜率配置';
                                $traceParts[] = (string)$u_op_order_kong;
                                $traceParts[] = '最终胜率配置';
                                $traceParts[] = (string)$op_order_kong;

                                $new_rand = mt_rand(0, 100);
                                $traceParts[] = '随机值';
                                $traceParts[] = (string)$new_rand;

                                if ($new_rand <= $op_order_kong) {
                                    //赢
                                    $traceParts[] = '随机结算结果';
                                    $traceParts[] = '赢';

                                    if ($order['op_style'] == 1) {
                                        //买涨
                                        $traceParts[] = '随机结算方向';
                                        $traceParts[] = '买涨';
                                        $odata['end_price'] = bc_add($order['start_price'], $op_k_num);
                                    } else {
                                        if ($order['op_style'] == 2) {
                                            //买跌
                                            $traceParts[] = '随机结算方向';
                                            $traceParts[] = '买跌';
                                            $odata['end_price'] = bc_sub($order['start_price'], $op_k_num);
                                        }
                                    }
                                    $odata['update_time'] = time();
                                    $odata['is_win'] = 1;
                                    $odata['op_status'] = 1;
                                    $odata['true_fee'] = bc_add($order['op_number'], bc_mul($order['op_number'], $order['play_prop'] / 100));
                                    $odata['sx_fee'] = bc_mul($odata['true_fee'], $pro['op_sx_fee']);
                                    $odata['all_fee'] = bc_sub($odata['true_fee'], $odata['sx_fee']);
                                    $now_money = bc_add($user_wallet['op_money'], $odata['all_fee']);

                                    if ($m_order->where('id', $order['id'])->update($odata)) {
                                        $m_wallet->where('id', $user_wallet['id'])->update(['op_money' => $now_money]);
                                        $lgdata['account'] = $order['op_number'];
                                        $lgdata['wallet_id'] = $user_wallet['id'];
                                        $lgdata['product_id'] = $user_wallet['product_id'];
                                        $lgdata['uid'] = $order['uid'];
                                        $lgdata['is_test'] = $is_test;
                                        $lgdata['before'] = $user_wallet['op_money'];
                                        $lgdata['after'] = $now_money;
                                        $lgdata['account_sxf'] = $odata['sx_fee'];
                                        $lgdata['all_account'] = $odata['all_fee'];
                                        $lgdata['type'] = 6;
                                        $lgdata['title'] = $productBase['title'];
                                        $lgdata['order_type'] = 2;
                                        //赢返
                                        $lgdata['order_id'] = $order['id'];
                                        $m_log->save($lgdata);

                                        $traceParts[] = '订单更新';
                                        $traceParts[] = '成功';
                                    } else {
                                        $traceParts[] = '订单更新';
                                        $traceParts[] = '失败';
                                    }
                                } else {
                                    $traceParts[] = '随机结算结果';
                                    $traceParts[] = '输';

                                    if ($order['op_style'] == 1) {
                                        //买涨
                                        $traceParts[] = '随机结算方向';
                                        $traceParts[] = '买涨';
                                        $odata['end_price'] = bc_sub($order['start_price'], $op_k_num);
                                    } else {
                                        if ($order['op_style'] == 2) {
                                            //买跌
                                            $traceParts[] = '随机结算方向';
                                            $traceParts[] = '买跌';
                                            $odata['end_price'] = bc_add($order['start_price'], $op_k_num);
                                        }
                                    }
                                    $odata['update_time'] = time();
                                    $odata['is_win'] = 2;
                                    $odata['op_status'] = 1;
                                    $odata['true_fee'] = $order['op_number'];
                                    $odata['sx_fee'] = 0;
                                    $odata['all_fee'] = $order['op_number'];
                                    $m_order->where('id', $order['id'])->update($odata);

                                    $traceParts[] = '订单更新';
                                    $traceParts[] = '已执行';
                                }
                            }
                        }
                    }

                    $m_order->commit();
                    //事务提交

                    \think\facade\Log::info(implode('.', $traceParts));
                } catch (\Throwable $e) {
                    $m_order->rollback();

                    $traceParts[] = '结果';
                    $traceParts[] = '异常回滚';
                    $traceParts[] = '异常信息';
                    $traceParts[] = $e->getMessage();

                    \think\facade\Log::error(implode('.', $traceParts));
                }
            }
        }
    }
    /**
     * @Title: 理财订单结算
     */
    public static function do_good_order()
    {
        $today = strtotime(date("Y-m-d H:i:s"));
        $m_order = new \app\admin\model\OrderGood();
        $orderlist = $m_order->field('id')->where('lock_time', '<', $today)->where('status', 1)->limit(50)->select();
        $productBase = \app\admin\model\ProductLists::where('base', 1)->field('id,title')->find();
        if ($orderlist) {
            foreach ($orderlist as $k => $v) {
                $m_order->startTrans();
                //开启事务
                try {
                    $m_wallet = new \app\admin\model\MemberWallet();
                    $m_user = new \app\admin\model\MemberUser();
                    $m_log = new \app\admin\model\MemberWalletLog();
                    $m_good = new \app\admin\model\GoodLists();
                    $order = $m_order->lock(true)->where('id', $v['id'])->find();
                    //加锁
                    $user_base_wallet = $m_wallet->where('product_id', $productBase['id'])->where('uid', $order['uid'])->field('id,up_money')->find();
                    $is_test = $m_user->where('id', $order['uid'])->value('is_test');
                    $account = $order['buy_account'] * $order['rate'];
                    //收益
                    $rate_account = $order['rate_account'] + $account;
                    $lock = $order['lock'] - 1;
                    $t = $order['lock_time'] + 60 * 60 * 24;
                    $good_tit = $m_good->where('id', $order['good_id'])->value('title');
                    if ($lock >= 0) {
                        $update = $m_order->update(['lock' => $lock, 'rate_account' => $rate_account, 'lock_time' => $t], ['id' => $order['id']]);
                        if ($update) {
                            $now_up_money = bc_add($user_base_wallet['up_money'], $rate_account);
                            $m_wallet->where('id', $user_base_wallet['id'])->update(['up_money' => $now_up_money]);
                            $logdata['account'] = $account;
                            $logdata['wallet_id'] = $user_base_wallet['id'];
                            $logdata['product_id'] = $productBase['id'];
                            $logdata['uid'] = $order['uid'];
                            $logdata['is_test'] = $is_test;
                            $logdata['before'] = $user_base_wallet['up_money'];
                            $logdata['after'] = $now_up_money;
                            $logdata['account_sxf'] = 0;
                            $logdata['all_account'] = bc_sub($logdata['account'], $logdata['account_sxf']);
                            $logdata['type'] = 7;
                            //购买理财
                            $logdata['title'] = $productBase['title'];
                            $logdata['remark'] = $good_tit;
                            $logdata['order_type'] = 2;
                            //收益返息
                            $logdata['order_id'] = $order['id'];
                            $inlog = $m_log->save($logdata);
                        }
                    } else {
                        $update = $m_order->update(['lock' => $lock, 'status' => 2, 'lock_time' => time()], ['id' => $order['id']]);
                        if ($update) {
                            $now_up_money = bc_add($user_base_wallet['up_money'], $order['buy_account']);
                            $m_wallet->where('id', $user_base_wallet['id'])->update(['up_money' => $now_up_money]);
                            $logdata['account'] = $order['buy_account'];
                            $logdata['wallet_id'] = $user_base_wallet['id'];
                            $logdata['product_id'] = $productBase['id'];
                            $logdata['uid'] = $order['uid'];
                            $logdata['is_test'] = $is_test;
                            $logdata['before'] = $user_base_wallet['up_money'];
                            $logdata['after'] = $now_up_money;
                            $logdata['account_sxf'] = 0;
                            $logdata['all_account'] = bc_sub($logdata['account'], $logdata['account_sxf']);
                            $logdata['type'] = 7;
                            //购买理财
                            $logdata['title'] = $productBase['title'];
                            $logdata['remark'] = $good_tit;
                            $logdata['order_type'] = 3;
                            //理财返本
                            $logdata['order_id'] = $order['id'];
                            $inlog = $m_log->save($logdata);
                        }
                    }
                    $m_order->commit();
                    //事务提交
                } catch (\Throwable $e) {
                    $m_order->rollback();
                }
            }
        }
    }
    /**
     * @Title: 处理挖矿
     */
    public static function do_winer_order()
    {
        $today = strtotime(date("Y-m-d"));
        $m_order = new \app\admin\model\OrderWiner();
        $orderlist = $m_order->field('id')->where('lock_time', '<', $today)->where('status', 1)->limit(50)->select();
        $productBase = \app\admin\model\ProductLists::where('base', 1)->field('id,title')->find();
        if ($orderlist) {
            foreach ($orderlist as $k => $v) {
                $m_order->startTrans();
                //开启事务
                try {
                    $m_wallet = new \app\admin\model\MemberWallet();
                    $m_user = new \app\admin\model\MemberUser();
                    $m_log = new \app\admin\model\MemberWalletLog();
                    $m_pro = new \app\admin\model\ProductLists();
                    $order = $m_order->lock(true)->where('id', $v['id'])->find();
                    //加锁
                    $is_test = $m_user->where('id', $order['uid'])->value('is_test');
                    $rate = FoxCommon::generateRand($order['min_rate'], $order['max_rate']);
                    $pro = $m_pro->where('id', $order['product_id'])->field('id,close,title')->find();
                    $user_pro_wallet = $m_wallet->where('product_id', $order['product_id'])->where('uid', $order['uid'])->field('id,ex_money')->find();
                    //价格换算
                    $pprice = str_replace(',', '', FoxKline::get_me_price_usdt_to_usd($pro['close'], 8));
                    $account = bc_mul(bc_div($order['buy_account'], $pprice), $rate);
                    //币的收益
                    $rate_account = $order['rate_account'] + $account;
                    $lock = $order['lock'] - 1;
                    if ($lock >= 0) {
                        $update = $m_order->update(['lock' => $lock, 'rate_account' => $rate_account, 'lock_time' => time()], ['id' => $order['id']]);
                        if ($update) {
                            $now_ex_money = bc_add($user_pro_wallet['ex_money'], $rate_account);
                            $m_wallet->where('id', $user_pro_wallet['id'])->update(['ex_money' => $now_ex_money]);
                            $logdata['account'] = $account;
                            $logdata['wallet_id'] = $user_pro_wallet['id'];
                            $logdata['product_id'] = $productBase['id'];
                            $logdata['uid'] = $order['uid'];
                            $logdata['is_test'] = $is_test;
                            $logdata['before'] = $user_pro_wallet['ex_money'];
                            $logdata['after'] = $now_ex_money;
                            $logdata['account_sxf'] = 0;
                            $logdata['all_account'] = bc_sub($logdata['account'], $logdata['account_sxf']);
                            $logdata['type'] = 9;
                            //矿机
                            $logdata['title'] = $productBase['title'];
                            $logdata['remark'] = $pro['title'];
                            $logdata['order_type'] = 2;
                            //返息
                            $logdata['status'] = 33;
                            //挖矿回报
                            $logdata['order_id'] = $order['id'];
                            $inlog = $m_log->save($logdata);
                        }
                    } else {
                        $update = $m_order->update(['lock' => $lock, 'status' => 2, 'lock_time' => time()], ['id' => $order['id']]);
                        if ($update) {
                            $productBase = \app\admin\model\ProductLists::where('base', 1)->field('id,title')->find();
                            $user_base_wallet = $m_wallet->where('product_id', $productBase['id'])->where('uid', $order['uid'])->field('id,ex_money')->find();
                            $now_up_money = bc_add($user_base_wallet['ex_money'], $order['buy_account']);
                            $m_wallet->where('id', $user_base_wallet['id'])->update(['ex_money' => $now_up_money]);
                            $logdata['account'] = $order['buy_account'];
                            $logdata['wallet_id'] = $user_base_wallet['id'];
                            $logdata['product_id'] = $productBase['id'];
                            $logdata['uid'] = $order['uid'];
                            $logdata['is_test'] = $is_test;
                            $logdata['before'] = $user_base_wallet['ex_money'];
                            $logdata['after'] = $now_up_money;
                            $logdata['account_sxf'] = 0;
                            $logdata['all_account'] = bc_sub($logdata['account'], $logdata['account_sxf']);
                            $logdata['type'] = 9;
                            //挖矿
                            $logdata['title'] = $productBase['title'];
                            $logdata['remark'] = $pro['title'];
                            $logdata['order_type'] = 3;
                            //释放
                            $logdata['status'] = 32;
                            //挖矿释放
                            $logdata['order_id'] = $order['id'];
                            $inlog = $m_log->save($logdata);
                        }
                    }
                    $m_order->commit();
                    //事务提交
                } catch (\Throwable $e) {
                    $m_order->rollback();
                }
            }
        }
    }
    public static function do_leverdeal_order()
    {
        $orderlist = \app\admin\model\OrderLeverdeal::where('status', 1)->group('uid')->field('uid')->limit(50)->select();
        if ($orderlist) {
            $m_order = new \app\admin\model\OrderLeverdeal();
            $m_user = new \app\admin\model\MemberUser();
            $m_log = new \app\admin\model\MemberWalletLog();
            foreach ($orderlist as $k => $v) {
                $pro_order = \app\admin\model\OrderLeverdeal::where('uid', $v['uid'])->group('product_id')->where('status', 1)->field('product_id,uid')->select();
                foreach ($pro_order as $pk => $pv) {
                    $user_order = \app\admin\model\OrderLeverdeal::where('product_id', $pv['product_id'])->where('status', 1)->field('id')->select();
                    $win = 0;
                    $close_price = \app\admin\model\ProductLists::where('id', $pv['product_id'])->value('last_price');
                    foreach ($user_order as $uk => $uv) {
                        $order = $m_order->where('id', $uv['id'])->find();
                        //加锁
                        $rate = bc_mul(bc_mul($order['buy_price'], $order['account']), $order['play_rate']);
                        $coin_rate = bc_div($rate, $order['buy_price']);
                        //化为币
                        $salf = bc_mul(bc_sub($close_price, $order['buy_price']), $order['account']);
                        $coin_salf = bc_div($salf, $close_price);
                        //化为币
                        $deal_salf = bc_mul(bc_sub($coin_salf, $coin_rate), $order['account']);
                        $long = bc_sub($close_price, $order['buy_price']);
                        if ($order['style'] == 1 && $long > 0) {
                            //买涨实涨：盈
                            if ($deal_salf < 0) {
                                $deal_salf = 0 - $deal_salf;
                            }
                            \app\admin\model\OrderLeverdeal::where('id', $order['id'])->update(['is_win' => 1, 'win_account' => $deal_salf, 'now_price' => $close_price]);
                        } else {
                            if ($order['style'] == 1 && $long < 0) {
                                //买涨实跌：亏
                                if ($deal_salf > 0) {
                                    $deal_salf = 0 - $deal_salf;
                                }
                                \app\admin\model\OrderLeverdeal::where('id', $order['id'])->update(['is_win' => 2, 'win_account' => $deal_salf, 'now_price' => $close_price]);
                            } else {
                                if ($order['style'] == 2 && $long > 0) {
                                    //买跌实涨：亏
                                    if ($deal_salf > 0) {
                                        $deal_salf = 0 - $deal_salf;
                                    }
                                    \app\admin\model\OrderLeverdeal::where('id', $order['id'])->update(['is_win' => 2, 'win_account' => $deal_salf, 'now_price' => $close_price]);
                                } else {
                                    if ($order['style'] == 2 && $long < 0) {
                                        //买跌实跌：盈
                                        if ($deal_salf < 0) {
                                            $deal_salf = 0 - $deal_salf;
                                        }
                                        \app\admin\model\OrderLeverdeal::where('id', $order['id'])->update(['is_win' => 1, 'win_account' => $deal_salf, 'now_price' => $close_price]);
                                    }
                                }
                            }
                        }
                        $win += $deal_salf;
                    }
                    $user_wallet = \app\admin\model\MemberWallet::where('product_id', $pv['product_id'])->where('uid', $pv['uid'])->field('le_money,id')->find();
                    if (bc_add($win, $user_wallet['le_money']) <= 0) {
                        $logdata = [];
                        foreach ($user_order as $uk => $uv) {
                            $is_test = $m_user->where('id', $uv['uid'])->value('is_test');
                            $info = \app\admin\model\OrderLeverdeal::where('id', $uv['id'])->find();
                            //加锁
                            $ouser_wallet = \app\admin\model\MemberWallet::where('product_id', $info['product_id'])->where('uid', $info['uid'])->field('id,le_money')->find();
                            $now_le_money = bc_add($ouser_wallet['le_money'], $info['win_account']);
                            $m_order->where('id', $uv['id'])->update(['close_price' => $close_price, 'status' => 2, 'is_lock' => 2]);
                            $logdata[$uk]['account'] = $info['win_account'];
                            $logdata[$uk]['wallet_id'] = $ouser_wallet['id'];
                            $logdata[$uk]['product_id'] = $info['product_id'];
                            $logdata[$uk]['uid'] = $info['uid'];
                            $logdata[$uk]['is_test'] = $is_test;
                            $logdata[$uk]['before'] = $ouser_wallet['le_money'];
                            $logdata[$uk]['after'] = $now_le_money;
                            $logdata[$uk]['account_sxf'] = 0;
                            $logdata[$uk]['all_account'] = $info['win_account'];
                            $logdata[$uk]['type'] = 5;
                            //合约订单
                            $logdata[$uk]['title'] = $info['title'];
                            $logdata[$uk]['order_type'] = $info['is_win'] + 10;
                            //自动平
                            $logdata[$uk]['order_id'] = $info['id'];
                        }
                        $inlog = $m_log->saveAll($logdata);
                        \app\admin\model\MemberWallet::where('id', $user_wallet['id'])->update(['le_money' => 0]);
                    }
                }
            }
        }
    }
}