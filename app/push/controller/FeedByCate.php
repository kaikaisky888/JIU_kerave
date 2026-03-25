<?php

/**
 * 按 cate_id 接入行情：外汇(cate_id=10)、大宗商品(cate_id=11)
 * 使用 Twelve Data WebSocket API: wss://ws.twelvedata.com/v1/quotes/price
 *
 * 后台配置（系统配置 api 组）：
 * - forex_api: Twelve Data API Key，或完整 URL（wss://ws.twelvedata.com/v1/quotes/price?apikey=xxx）
 * - forex_symbol: 外汇交易对，逗号分隔，Twelve Data 格式如 EUR/USD,XAU/USD
 * - commodity_api: 同上
 * - commodity_symbol: 大宗商品，如 XAU/USD,WTI/USD
 *
 * product_lists.code 需与 symbol 对应：EUR/USD -> eurusd，XAU/USD -> xauusd（小写去斜杠）
 */
namespace app\push\controller;

use think\facade\Db;
use Workerman\Lib\Timer;
use app\common\service\KlineService;

class FeedByCate
{
    /** @var int 分类：10=外汇 11=大宗 */
    protected $cateId;
    /** @var string 配置键：api 地址或 API Key */
    protected $apiKey;
    /** @var string 配置键：交易对列表，逗号分隔 */
    protected $symbolKey;

    protected static function tdLog(string $step, string $msg, $extra = null): void
    {
        $logFile = runtime_path() . 'twelvedata_all.log';
        $line = date('Y-m-d H:i:s') . " [{$step}] {$msg}";
        if ($extra !== null) {
            $line .= ' ' . (is_string($extra) ? $extra : json_encode($extra));
        }
        @file_put_contents($logFile, $line . "\n", FILE_APPEND);
    }

    public function __construct(int $cateId, string $apiKey, string $symbolKey)
    {
        $this->cateId    = $cateId;
        $this->apiKey    = $apiKey;
        $this->symbolKey = $symbolKey;
    }

    /**
     * 合并模式：单一连接订阅 forex + commodity，避免同一 API Key 多连被踢
     */
    public static function startMerged(): void
    {
        $api = sysconfig('api', 'forex_api') ?: sysconfig('api', 'commodity_api');
        if (empty($api) || !is_string($api)) {
            self::tdLog("START", "跳过 merged: forex_api 与 commodity_api 均未配置");
            return;
        }
        $forexSyms   = sysconfig('api', 'forex_symbol');
        $commoditySyms = sysconfig('api', 'commodity_symbol');
        $allSymbols  = array_filter(array_merge(
            explode(',', (string)$forexSyms),
            explode(',', (string)$commoditySyms)
        ));
        if (empty($allSymbols)) {
            self::tdLog("START", "跳过 merged: forex_symbol 与 commodity_symbol 均为空");
            return;
        }
        $self = new self(0, 'forex_api', 'forex_symbol');
        $self->symbolToCateId = self::buildSymbolToCateId($forexSyms, $commoditySyms);
        $url = $self->buildUrl($api);
        self::startConnection($self, $url, 'merged');
    }

    protected static function buildSymbolToCateId(?string $forexSyms, ?string $commoditySyms): array
    {
        $map = [];
        foreach (array_filter(explode(',', (string)$forexSyms)) as $s) {
            $map[self::normalizeSymbolForKey($s)] = 10;
        }
        foreach (array_filter(explode(',', (string)$commoditySyms)) as $s) {
            $map[self::normalizeSymbolForKey($s)] = 11;
        }
        return $map;
    }

    protected static function normalizeSymbolForKey(string $s): string
    {
        $s = trim($s);
        if (strpos($s, '/') !== false) {
            return strtoupper($s);
        }
        $s = strtolower($s);
        if (strlen($s) >= 6 && in_array(substr($s, -3), ['usd', 'jpy', 'eur', 'gbp', 'cad', 'chf', 'aud'])) {
            return strtoupper(substr($s, 0, -3)) . '/' . strtoupper(substr($s, -3));
        }
        return strtoupper($s);
    }

    /** @var array symbol => cate_id，仅在 merged 模式使用 */
    protected $symbolToCateId = [];

    /**
     * 若已配置则建立连接并订阅（单分类模式，或由 startMerged 调用）
     */
    public static function start(int $cateId, string $apiKey, string $symbolKey): void
    {
        $label = $cateId === 10 ? 'forex' : ($cateId === 11 ? 'commodity' : "cate{$cateId}");
        self::tdLog("START", "尝试启动 {$label} cate_id={$cateId} apiKey={$apiKey} symbolKey={$symbolKey}");

        $api = sysconfig('api', $apiKey);
        if (empty($api) || !is_string($api)) {
            self::tdLog("START", "跳过 {$label}: {$apiKey} 未配置或为空");
            return;
        }
        $symbols = sysconfig('api', $symbolKey);
        if (empty($symbols)) {
            self::tdLog("START", "跳过 {$label}: {$symbolKey} 未配置或为空");
            return;
        }

        $self = new static($cateId, $apiKey, $symbolKey);
        $url  = $self->buildUrl($api);
        self::startConnection($self, $url, $label);
    }

    protected static function startConnection(self $self, string $url, string $label): void
    {
        $urlLog = preg_replace('/apikey=[^&\s]+/i', 'apikey=***', $url);
        self::tdLog("CONNECT", "{$label} 构建URL完成", $urlLog);

        $con  = new \Workerman\Connection\AsyncTcpConnection($url);
        if (strpos($url, 'ssl://') === 0) {
            $con->transport = 'ssl';
        }
        $con->protocol = \Workerman\Protocols\Ws::class;
        $con->onWebSocketClose = function ($connection) use ($label) {
            self::tdLog("WS_CLOSE", "{$label} 服务端主动关闭连接");
        };
        $con->onConnect = function ($connection) use ($self, $label) {
            $self->onConnect($connection, $label);
        };
        $con->onMessage = function ($connection, $message) use ($self, $label) {
            $self->onMessage($connection, $message, $label);
        };
        $con->onClose = function ($connection) use ($self, $label) {
            $self->onClose($connection, $label);
            $connection->reConnect(1);
        };
        $con->onError = function (...$args) use ($label) {
            self::tdLog("ERROR", "{$label} 连接错误", $args);
        };
        self::tdLog("CONNECT", "{$label} 发起连接...");
        $con->connect();
    }

    /**
     * 构建 Twelve Data WebSocket URL
     * - 若配置以 wss:// 开头则直接使用
     * - 否则视为 API Key，拼接默认 Twelve Data 地址
     */
    protected function buildUrl(string $api): string
    {
        if (strpos($api, 'wss://') === 0) {
            return str_replace('wss://', 'ssl://', $api);
        }
        if (strpos($api, 'ssl://') === 0) {
            return $api;
        }
        $api = trim($api);
        $base = 'ssl://ws.twelvedata.com/v1/quotes/price';
        $sep  = strpos($base, '?') !== false ? '&' : '?';
        return $base . $sep . 'apikey=' . urlencode($api);
    }

    /**
     * 连接成功后发送 Twelve Data 订阅
     * 格式: {"action":"subscribe","params":{"symbols":"EUR/USD,XAU/USD"}}
     */
    protected function onConnect($connection, string $label = ''): void
    {
        self::tdLog("CONNECTED", "{$label} WebSocket 连接成功");

        if ($this->cateId === 0 && !empty($this->symbolToCateId)) {
            $normalized = array_keys($this->symbolToCateId);
        } else {
            $symbols = sysconfig('api', $this->symbolKey);
            if (empty($symbols)) {
                self::tdLog("SUBSCRIBE", "{$label} 跳过: {$this->symbolKey} 为空");
                return;
            }
            $list = array_filter(array_map('trim', explode(',', $symbols)));
            if (empty($list)) {
                self::tdLog("SUBSCRIBE", "{$label} 跳过: symbol 列表为空");
                return;
            }
            $normalized = [];
            foreach ($list as $s) {
                $normalized[] = self::normalizeSymbolForKey($s);
            }
        }
        if (empty($normalized)) {
            self::tdLog("SUBSCRIBE", "{$label} 跳过: 无有效 symbol");
            return;
        }
        $sub = [
            'action' => 'subscribe',
            'params' => ['symbols' => implode(',', $normalized)],
        ];
        $payload = json_encode($sub);
        $connection->send($payload);
        self::tdLog("SUBSCRIBE", "{$label} 已发送订阅", $sub);

        // Twelve Data 建议定期发送心跳保活
        $timerId = Timer::add(30, function () use ($connection) {
            if (is_object($connection) && isset($connection->send)) {
                @$connection->send(json_encode(['action' => 'heartbeat']));
            }
        });
        $connection->heartbeatTimerId = $timerId;
    }

    protected function onClose($connection, string $label = ''): void
    {
        self::tdLog("CLOSE", "{$label} 连接已关闭，将重连");
        if (isset($connection->heartbeatTimerId)) {
            Timer::del($connection->heartbeatTimerId);
            unset($connection->heartbeatTimerId);
        }
    }

    /**
     * Twelve Data 价格推送格式示例：
     * {"event":"price","symbol":"EUR/USD","price":"1.08500","name":"Euro vs US Dollar"}
     * 或含 OHLC: open, high, low, close, volume
     */
    protected function onMessage($connection, $message, string $label = ''): void
    {
        $data = is_string($message) ? json_decode($message, true) : $message;
        if (!$data) {
            $raw = @gzdecode($message);
            if ($raw) {
                $data = json_decode($raw, true);
            }
        }
        if (!$data) {
            return;
        }
        self::tdLog("MESSAGE", "{$label} 收到消息", $data);

        // 连接确认、订阅确认、心跳响应等忽略
        if (isset($data['status']) && ($data['status'] === 'ok' || $data['status'] === 'success')) {
            return;
        }
        if (($data['event'] ?? '') === 'subscribe-status') {
            return;
        }
        if (isset($data['ping'])) {
            $connection->send(json_encode(['pong' => $data['ping']]));
            return;
        }

        $symbol = $data['symbol'] ?? null;
        if (!$symbol) {
            return;
        }
        $price = (float)($data['price'] ?? $data['close'] ?? 0);
        if ($price <= 0) {
            return;
        }

        // 合并模式：根据 symbol 解析 cate_id
        $cateId = $this->cateId;
        if ($cateId === 0 && !empty($this->symbolToCateId)) {
            $key = strtoupper(str_replace(' ', '', $symbol));
            $cateId = $this->symbolToCateId[$key] ?? 0;
            if ($cateId === 0) {
                return;
            }
        }

        $tick = [
            'open'  => (float)($data['open'] ?? $price),
            'close' => $price,
            'high'  => (float)($data['high'] ?? $price),
            'low'   => (float)($data['low'] ?? $price),
            'vol'   => (float)($data['volume'] ?? $data['vol'] ?? 0),
        ];

        $code = strtolower(str_replace('/', '', $symbol));
        $this->updateProductList($code, $tick, $cateId);
        $this->saveKline($code, $tick, $data);
    }

    /**
     * 将价格聚合为 1 分钟 K 线写入 curve_2，供 TradingView 图表使用
     */
    protected function saveKline(string $code, array $tick, array $raw): void
    {
        $price = (float)($tick['close'] ?? 0);
        if ($price <= 0) {
            return;
        }
        // 当前分钟起始时间戳（秒）
        $ts    = (int)($raw['timestamp'] ?? $raw['datetime'] ?? time());
        if (isset($raw['datetime']) && is_string($raw['datetime'])) {
            $ts = strtotime($raw['datetime']) ?: time();
        }
        $time  = (int)floor($ts / 60) * 60;
        $open  = (float)($tick['open'] ?? $price);
        $high  = (float)($tick['high'] ?? $price);
        $low   = (float)($tick['low'] ?? $price);
        $vol   = (float)($tick['vol'] ?? 0);

        $tablename = 'market_' . $code . '_kline_1min';
        try {
            KlineService::instance()->detectTable($tablename);
        } catch (\Throwable $e) {
            return;
        }
        $msg = [
            'type'    => 'tradingvew',
            'symbol'  => $code,
            'ch'      => 'market.' . $code . '.kline.1min',
            'period'  => '1min',
            'open'    => $open,
            'high'    => $high,
            'low'     => $low,
            'close'   => $price,
            'vol'     => $vol,
            'count'   => 0,
            'amount'  => 0,
            'time'    => $time,
            'ranges'  => fox_time($time),
        ];
        KlineService::saveOrUpdate($tablename, $msg);
    }

    /**
     * 更新 product_lists 中该 code 且 cate_id 匹配的行情
     */
    protected function updateProductList(string $code, array $tick, int $cateId = 0): void
    {
        $cateId = $cateId ?: $this->cateId;
        $open  = (float)($tick['open'] ?? $tick['close'] ?? 0);
        $close = (float)($tick['close'] ?? $open);
        $high  = (float)($tick['high'] ?? max($open, $close));
        $low   = (float)($tick['low'] ?? min($open, $close));
        $vol   = (float)($tick['vol'] ?? $tick['volume'] ?? 0);
        $count = (int)($tick['count'] ?? 0);
        $amount = (float)($tick['amount'] ?? 0);
        if ($close <= 0) {
            return;
        }
        $exists = Db::name('product_lists')->where('code', $code)->where('cate_id', $cateId)->find();
        if (!$exists) {
            return;
        }
        $change = $open > 0 ? round(($close - $open) / $open * 100, 4) : 0;
        $ladata = [
            'open'        => $open,
            'close'       => $close,
            'high'        => $high,
            'low'         => $low,
            'change'      => $change,
            'amount'      => $amount,
            'count'       => $count,
            'volume'      => $vol,
            'last_price'  => $close,
        ];
        Db::name('product_lists')->where('code', $code)->where('cate_id', $cateId)->update($ladata);
    }
}
