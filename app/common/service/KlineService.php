<?php

/*
 * @Author: Fox Blue
 * @Date: 2021-08-16 11:50:11
 * @LastEditTime: 2021-09-08 11:21:50
 * @Description: Forward, no stop
 */
namespace app\common\service;

use think\facade\Db;
use think\facade\Config;
use app\common\service\HuobiRedis;
/**
 * Kline表
 */
class KlineService
{
    /**
     * 当前实例
     * @var object
     */
    protected static $instance;
    /**
     * 表前缀
     * @var string
     */
    protected $tablePrefix;
    /**
     * 构造方法
     * SystemLogService constructor.
     */
    protected function __construct()
    {
        $this->tablePrefix = Config::get('database.connections.kline.prefix');
        return $this;
    }
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new static();
        }
        return self::$instance;
    }
    public static function hbrds()
    {
        $setredis = \think\facade\Config::get('cache.stores.redis');
        $hbrds = new HuobiRedis($setredis['host'], $setredis['port'], $setredis['password']);
        return $hbrds;
    }
    /**
     * 保存数据
     * @param $data
     * @return bool|string
     */
    public static function save($tablename, $data)
    {
        Db::startTrans();
        try {
            // $line = Db::connect('kline')->table($tablename)->where('time',$data['time'])->lock(true)->find();
            $t = self::hbrds()->get($tablename);
            if ($t != $data['time']) {
                Db::connect('kline')->table($tablename)->insert($data, true);
                self::hbrds()->set($tablename, $data['time'], 60);
                Db::commit();
            } else {
                Db::rollback();
            }
        } catch (\Throwable $e) {
            Db::rollback();
        }
        return true;
    }

    /**
     * 流式价格聚合：同一分钟内多次更新时，用新 price 更新 high/low/close（用于 Twelve Data 等逐笔推送）
     * @param string $tablename 如 market_eurusd_kline_1min
     * @param array $data 必须含 time, open, high, low, close, vol, symbol, type, ch, period, ranges
     */
    public static function saveOrUpdate($tablename, $data)
    {
        try {
            $conn = Db::connect('kline');
            $prefix = Config::get('database.connections.kline.prefix') ?: '';
            $tbl   = ($prefix ? $prefix : '') . $tablename;
            $time  = (int)($data['time'] ?? 0);
            $open  = (float)($data['open'] ?? 0);
            $high  = (float)($data['high'] ?? 0);
            $low   = (float)($data['low'] ?? 0);
            $close = (float)($data['close'] ?? 0);
            $vol   = (float)($data['vol'] ?? 0);
            $amount= (float)($data['amount'] ?? 0);
            $count = (int)($data['count'] ?? 0);
            $type  = $data['type'] ?? 'tradingvew';
            $symbol= $data['symbol'] ?? '';
            $ch    = $data['ch'] ?? '';
            $period= $data['period'] ?? '1min';
            $ranges= $data['ranges'] ?? ',1min';

            $sql = "INSERT INTO `{$tbl}` (type,symbol,ch,period,open,high,low,close,vol,count,amount,time,ranges) 
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) 
ON DUPLICATE KEY UPDATE 
  high=GREATEST(high,?), low=LEAST(low,?), close=?, vol=vol+?, amount=amount+?, count=count+?";
            $conn->execute($sql, [
                $type, $symbol, $ch, $period, $open, $high, $low, $close, $vol, $count, $amount, $time, $ranges,
                $high, $low, $close, $vol, $amount, $count,
            ]);
        } catch (\Throwable $e) {
            // 表不存在时忽略，由 detectTable 在首次请求时创建
        }
    }
    /**
     * 检测数据表
     * @return bool
     */
    public function detectTable($tablename)
    {
        $check = Db::connect('kline')->query("show tables like '{$tablename}'");
        if (empty($check)) {
            $sql = $this->getCreateSql($tablename);
            Db::connect('kline')->execute($sql);
        }
        return true;
    }
    public static function search_one($tablename, $time)
    {
        return Db::connect('kline')->name($tablename)->where('time', '<', $time)->order('time', 'desc')->field('close')->limit(1)->select();
    }
    public static function search_one_day($tablename, $time)
    {
        return Db::connect('kline')->name($tablename)->where('time', '>', $time)->order('time', 'asc')->field('close')->limit(1)->select();
    }
    /**
     * 查询 K 线数据，返回前端图表所需格式（id/open/high/low/close/vol 等，id 与 time 均为秒级时间戳）
     * @param string $tablename 表名，如 market_btcusdt_kline_1min
     * @param int|null $from 起始时间戳（秒）
     * @param int|null $to 结束时间戳（秒），未传时取最近 $size 条
     * @param string $type 周期，如 1min/5min/60min/4hour/1day
     * @param int $size 条数上限
     */
    public static function search($tablename, $from = null, $to = null, $type = '1min', $size = 1200)
    {
        $where[] = ['ranges', 'like', '%,' . $type . '%'];
        $hasRange = $from !== null && $to !== null && $from !== '' && $to !== '';
        if ($hasRange) {
            $where[] = ['time', '>=', (int) $from];
            $where[] = ['time', '<=', (int) $to];
        }
        $query = Db::connect('kline')->name($tablename)->where($where);
        if ($hasRange) {
            $data = $query->order('time', 'asc')->limit($size)->select()->toArray();
        } else {
            $data = $query->order('time', 'desc')->limit($size)->select()->toArray();
            $data = array_reverse($data);
        }
        $datas = [];
        foreach ($data as $k => $v) {
            $t = (int) $v['time'];
            $datas[$k] = [
                'id'          => $t,
                'open'        => (double) $v['open'],
                'high'        => (double) $v['high'],
                'low'         => (double) $v['low'],
                'close'       => (double) $v['close'],
                'vol'         => (double) $v['vol'],
                'volume'      => (double) $v['vol'],
                'amount'      => (double) $v['amount'],
                'count'       => (int) $v['count'],
                'time'        => $t,
                'isBarClosed' => true,
                'isLastBar'   => false,
            ];
        }
        return $datas;
    }
    public static function search_day($tablename, $size = 0)
    {
        $where[] = ['ranges', 'like', '%,1min%'];
        $from = strtotime(date('Y-m-d'));
        $to = time();
        $where[] = ['time', '>=', $from];
        $where[] = ['time', '<=', $to];
        $data['high'] = Db::connect('kline')->name($tablename)->max('high');
        $data['low'] = Db::connect('kline')->name($tablename)->max('low');
        $data['volume'] = Db::connect('kline')->name($tablename)->sum('vol');
        $data['amount'] = Db::connect('kline')->name($tablename)->sum('amount');
        $data['count'] = Db::connect('kline')->name($tablename)->sum('count');
        return $data;
    }
    public static function search_svg($tablename, $type = '1min', $size = 20)
    {
        $where[] = ['ranges', 'like', '%,' . $type . '%'];
        $data = $data = Db::connect('kline')->name($tablename)->where($where)->order('time', 'desc')->limit($size)->select();
        $datas = [];
        if (isset($data)) {
            foreach ($data as $k => $v) {
                $datas[$k] = (double) $v['close'];
            }
        }
        $datas = array_reverse($datas);
        return $datas;
    }
    /**
     * 根据后缀获取创建表的sql
     * @return string
     */
    protected function getCreateSql($tablename)
    {
        return <<<EOT
CREATE TABLE `{$tablename}` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type` varchar(50) NOT NULL COMMENT '类型',
    `symbol` varchar(30) NOT NULL COMMENT '币CODE',
    `ch` varchar(100) DEFAULT NULL COMMENT '交易对',
    `period` varchar(20) DEFAULT NULL COMMENT '分期',
    `open` decimal(30,8) DEFAULT NULL COMMENT 'OPEN',
    `close` decimal(30,8) DEFAULT NULL COMMENT 'CLOSE',
    `low` decimal(30,8) DEFAULT NULL COMMENT 'LOW',
    `high` decimal(30,8) DEFAULT NULL COMMENT 'HIGH',
    `vol` decimal(30,8) DEFAULT NULL COMMENT 'VO',
    `count` bigint(30) DEFAULT NULL COMMENT 'COUNT',
    `amount` decimal(30,8) DEFAULT NULL COMMENT 'AMOUNT',
    `time` int(11) DEFAULT NULL COMMENT 'TIME',
    `ranges` varchar(255) DEFAULT NULL COMMENT 'RANGES',
    PRIMARY KEY (`id`),
    UNIQUE KEY `time` (`time`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT COMMENT='Kline表';
EOT;
    }
}