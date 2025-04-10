<?php
define('ROOT', str_replace("\\", "/", dirname(__FILE__)));

if (!file_exists(ROOT . '/config.php')) {
    echo "Bilibili Ctl 还未进行安装，请先访问网页界面进行安装。\n";
}

if ($argc > 1 && $argv[1] == 'auto' && !file_exists(ROOT . '/config.php')) {
    echo "正在等待安装，安装完成后将自动开始守护进程。\n";
    while (!file_exists(ROOT . '/config.php')) {
        sleep(1);
    }
}

require_once(ROOT . '/config.php');
// time zone
date_default_timezone_set('Asia/Shanghai');

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

class Bilibili
{
    protected $XOR_CODE  = 23442827791579;
    protected $MASK_CODE = 2251799813685247;
    protected $MAX_AID   = 1 << 51;
    protected $BASE      = 58;
    protected $data      = 'FcwAPNKTMug3GV5Lj7EJnHpWsx4tb8haYeviqBz6rkCy12mUSDQX9RdoZf';

    public function av2bv($aid) {
        $bytes = ['B', 'V', '1', '0', '0', '0', '0', '0', '0', '0', '0', '0'];
        $bvIndex = count($bytes) - 1;
        $tmp = (($this->MAX_AID | $aid) ^ $this->XOR_CODE);
        while (($tmp <=> 0) > 0) {
            $bytes[$bvIndex] = $this->data[intval(($tmp % $this->BASE))];
            $tmp = ($tmp / $this->BASE);
            $bvIndex -= 1;
        }
        list($bytes[3], $bytes[9]) = [$bytes[9], $bytes[3]];
        list($bytes[4], $bytes[7]) = [$bytes[7], $bytes[4]];
        $bv = implode('', $bytes);
        $bv = substr($bv, 0, 12);
        $bv = 'BV1' . substr($bv, 3);
        return $bv;
    }
    
    public function bv2av($bvid) {
        $bvidArr = str_split($bvid);
        list($bvidArr[3], $bvidArr[9]) = [$bvidArr[9], $bvidArr[3]];
        list($bvidArr[4], $bvidArr[7]) = [$bvidArr[7], $bvidArr[4]];
        array_splice($bvidArr, 0, 3);
        $data = $this->data;
        $tmp = array_reduce($bvidArr, function($pre, $bvidChar) use ($data) {
            return (($pre * $this->BASE) + strpos($data, $bvidChar));
        }, 0);
        return intval((($tmp & $this->MASK_CODE) ^ $this->XOR_CODE));
    }
}

class Daemon
{
    private $conn;
    private $interval;
    private $cache = [];
    private $redis;
    private $bilibili;

    public function __construct()
    {
        $this->connectDatabase();
        if (!$this->checkDatabase()) {
            die('Cannot connect to database.');
        }

        $this->connectRedis();
        if (!$this->checkRedis()) {
            die('Cannot connect to Redis.');
        }

        $this->refreshConvars();
        $this->interval = $this->getConvar('interval', 30);
        $this->bilibili = new Bilibili();
    }

    public function __destruct()
    {
        $this->conn = null;
    }

    public function start(): void
    {
        while (true) {
            $cookie = $this->getConvar('cookie');
            $this->interval = $this->getConvar('interval', 30);
            if ($cookie && !empty($cookie) && $this->validCookie($cookie, true)) {
                $url = "https://api.bilibili.com/x/v2/reply/up/fulllist";
                $queries = [
                    "order"  => $this->getConvar('req_order', '1'),
                    "filter" => $this->getConvar('req_filter', '-1'),
                    "type"   => $this->getConvar('req_type', '1'),
                    "pn"     => $this->getConvar('req_pn', '1'),
                    "ps"     => $this->getConvar('req_ps', '20'),
                ];
                $headers = [
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3",
                    "Referer: https://www.bilibili.com/",
                ];
                $response = $this->httpRequest($url, 'GET', $queries, $headers, $cookie);
                $data = json_decode($response['data'], true);
                if ($data['code'] === 0) {
                    $replies = $data['data'] ? $data['data']['list'] : [];
                    foreach ($replies as $reply) {
                        $oid     = $reply['oid'];
                        $type    = $reply['type'];
                        $rpid    = $reply['rpid'];
                        $mid     = $reply['mid'];
                        $user    = $reply['member']['uname'];
                        $content = $reply['content']['message'];
                        if ($this->shouldCheckVideo($oid)) {
                            if (!$this->isWhiteListUser($user, $mid)) {
                                if ($this->isBlackListUser($user, $mid)) {
                                    $this->log('检测到黑名单用户评论:', sprintf("{$user} ($mid)"), $content);
                                    $this->deleteComment($oid, $type, $rpid);
                                    $this->logDeleteComment($oid, $rpid, $type, $user, $content, 'user');
                                } elseif ($this->isBlackListWord($content)) {
                                    $this->log('检测到黑名单关键字评论:', sprintf("{$user} ($mid)"), $content);
                                    $this->deleteComment($oid, $type, $rpid);
                                    $this->logDeleteComment($oid, $rpid, $type, $user, $content, 'words');
                                } elseif ($this->isBlackListRegex($content)) {
                                    $this->log('检测到黑名单正则评论:', sprintf("{$user} ($mid)"), $content);
                                    $this->deleteComment($oid, $type, $rpid);
                                    $this->logDeleteComment($oid, $rpid, $type, $user, $content, 'regex');
                                }
                            }
                        }
                    }
                    $this->log('读取完成，已检测', count($replies), '条评论');
                    // AI 检测
                    $deepseekKey = $this->getConvar('deepseek_api_key', '');
                    $deepseekPromot = $this->getConvar('deepseek_prompt', '以下是我发布的视频里收到的一些评论，格式为 ID|评论内容，每行一条，请找出那些骂人的评论，并将它们的 ID 用 JSON {"comments":[]} 的形式告诉我。');
                    $deepseekInterval = $this->getConvar('deepseek_interval', 300);
                    if (!empty($deepseekKey) && !empty($deepseekPromot)) {
                        $lastAICheck = $this->getCacheValue('btl_last_ai_check', 0);
                        $censoredComments = json_decode($this->getCacheValue('btl_censored_comments', '[]'), true) ?: [];
                        if (time() - $lastAICheck > $deepseekInterval) {
                            $this->log('正在进行 AI 检测，请稍候……');
                            $promptPart = "";
                            $promptArray = [];
                            foreach ($replies as $reply) {
                                $oid     = $reply['oid'];
                                $type    = $reply['type'];
                                $rpid    = $reply['rpid'];
                                $mid     = $reply['mid'];
                                $user    = $reply['member']['uname'];
                                $content = str_replace("\n", "\\n", $reply['content']['message']);
                                // 截断太长的评论
                                if (mb_strlen($content) > 200) {
                                    $content = mb_substr($content, 0, 200);
                                }
                                // 跳过已审查的评论
                                if (in_array($rpid, $censoredComments)) {
                                    continue;
                                }
                                if ($this->shouldCheckVideo($oid)) {
                                    if (!$this->isWhiteListUser($user, $mid)) {
                                        if ($this->isBlackListUser($user, $mid)) {
                                            continue;
                                        } elseif ($this->isBlackListWord($content)) {
                                            continue;
                                        } elseif ($this->isBlackListRegex($content)) {
                                            continue;
                                        }
                                    }
                                    $promptPart .= sprintf("%d_%d|%s\n", $oid, $rpid, $content);
                                    $promptArray[sprintf("%d_%d", $oid, $rpid)] = $reply;
                                    $censoredComments[] = $rpid;
                                }
                            }
                            $prompt = sprintf("%s\n%s", $deepseekPromot, $promptPart);
                            $result = $this->requestDeepSeek($prompt);
                            if ($result['success']) {
                                $jsonResult = json_decode($result['message'], true) ?: [];
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    $this->log('AI 检测失败，返回数据格式错误：', $result['message']);
                                    continue;
                                }
                                if (!isset($jsonResult['comments'])) {
                                    $this->log('AI 检测失败，返回数据格式错误：', $result['message']);
                                    continue;
                                }
                                foreach($jsonResult["comments"] as $rep) {
                                    $exp = explode('_', $rep);
                                    if (count($exp) == 2) {
                                        $oid = $exp[0];
                                        $rpid = $exp[1];
                                        $comment = $promptArray[$rep] ?? '';
                                        $this->log('AI 认为该评论需要删除：', sprintf("%s: %s", $comment['member']['uname'], $comment['content']['message']));
                                        $this->deleteComment($oid, 1, $rpid);
                                        $this->logDeleteComment($oid, $rpid, 1, $comment['member']['uname'], $comment['content']['message'], 'ai');
                                    }
                                }
                            }
                            // 更新最后一次 AI 检测时间
                            $this->setCacheValue('btl_last_ai_check', time());
                        }
                    }
                } else {
                    $this->log('无法读取评论：', $data['message']);
                }
            } else {
                $this->log('您还未配置 Cookie 或 Cookie 已失效，请先配置 Cookie。');
                $this->log('配置教程：https://github.com/ZeroDream-CN/bilibili-ctl-web/');
                if (CONFIG_ON_INVALID) {
                    $this->configureCookie();
                }
            }
            $this->setCacheValue('btl_last_check', time());
            sleep($this->interval);
        }
    }

    /* Comments */

    private function isBlackListUser(string $user, int $mid): bool
    {
        $blacklist = $this->getConvar('blacklist_users', '[]');
        $blacklist = json_decode($blacklist, true) ?: [];
        return in_array($user, $blacklist) || in_array($mid, $blacklist);
    }

    private function isBlackListWord(string $content): bool
    {
        $blacklist = $this->getConvar('blacklist_words', '[]');
        $blacklist = json_decode($blacklist, true) ?: [];
        foreach ($blacklist as $word) {
            if (strpos($content, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isBlackListRegex(string $content): bool
    {
        $blacklist = $this->getConvar('blacklist_regex', '[]');
        $blacklist = json_decode($blacklist, true) ?: [];
        foreach ($blacklist as $regex) {
            if (preg_match($regex, $content)) {
                return true;
            }
        }
        return false;
    }

    private function shouldCheckVideo(int $oid): bool
    {
        $checkMode = $this->getConvar('check_mode', '0');
        if ($checkMode === '0') {
            return true;
        } else {
            $list = $this->getConvar('check_list', '[]');
            $list = json_decode($list, true) ?: [];
            if ($checkMode === '1') {
                return in_array($oid, $list) || in_array($this->bilibili->av2bv($oid), $list);
            } else {
                return !in_array($oid, $list) && !in_array($this->bilibili->av2bv($oid), $list);
            }
        }
    }

    private function isWhiteListUser(string $user, int $mid): bool
    {
        $whitelist = $this->getConvar('whitelist_users', '[]');
        $whitelist = json_decode($whitelist, true) ?: [];
        return in_array($user, $whitelist) || in_array($mid, $whitelist);
    }

    private function logDeleteComment(int $oid, int $rpid, int $type, string $username, string $message, string $match): void
    {
        $stmt = $this->conn->prepare(sprintf('INSERT INTO `%s` (`oid`, `rpid`, `type`, `username`, `message`, `match`, `time`) VALUES (?, ?, ?, ?, ?, ?, ?)', $this->getTableName('deleted')));
        $stmt->execute([$oid, $rpid, $type, $username, $message, $match, time()]);
        $this->log('已记录被删除的评论：', $rpid, $username, $message, $match);
    }

    private function deleteComment(int $oid, int $type, int $rpid): void
    {
        $cookie = $this->getConvar('cookie');
        $cArray = $this->parseCookie($cookie);
        $csrf   = $cArray['bili_jct'];
        $url    = "https://api.bilibili.com/x/v2/reply/del";
        $data   = [
            "oid"   => $oid,
            "type"  => $type,
            "rpid"  => $rpid,
            "jsonp" => "jsonp",
            "csrf"  => $csrf,
        ];
        $headers = [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3",
            "Referer: https://www.bilibili.com/",
        ];
        $response = $this->httpRequest($url, 'POST', $data, $headers, $cookie);
        $data = json_decode($response['data'], true);
        if ($data['code'] === 0) {
            $this->log('删除评论成功：', $rpid);
        } else {
            $this->log('删除评论失败：', $data['message']);
        }
    }

    private function requestDeepSeek(string $message): array
    {
        $token = $this->getConvar('deepseek_api_key', '');
        if (empty($token)) {
            return ['success' => false, 'message' => '请先配置 DeepSeek API Key'];
        }
        $url = "https://api.deepseek.com/chat/completions";
        $data = [
            "model" => "deepseek-chat",
            "messages" => [
                ["role" => "user", "content" => $message]
            ],
            "temperature" => 0.7,
            "max_tokens" => 1000,
            "response_format" => [
                "type" => "json_object",
            ],
        ];
        $headers = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json",
            "Accept: application/json",
        ];
        $response = $this->httpRequest($url, 'POST', json_encode($data), $headers, $token);
        $data = json_decode($response['data'], true);
        if ($data && isset($data['choices'])) {
            return ['success' => true, 'message' => $data['choices'][0]['message']['content']];
        } else {
            return ['success' => false, 'message' => $response['data']];
        }
    }


    /* Utils */

    private function log(): void
    {
        $message = implode(' ', func_get_args());
        $time = date('Y-m-d H:i:s');
        echo sprintf('[%s] %s' . PHP_EOL, $time, $message);
    }

    private function getTableName(string $name): string
    {
        return DB_PFIX . $name;
    }

    public function getConvar(string $key, string $default = ""): string
    {
        $result = $this->getCacheValue($key);
        if ($result) {
            return $result;
        }
        $stmt = $this->conn->prepare(sprintf('SELECT `value` FROM `%s` WHERE `key` = ?', $this->getTableName('convars')));
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $result = $result ? $result['value'] : $default;
        $this->setCacheValue($key, $result, 86400);
        return $result;
    }

    public function setConvar(string $key, string $value): void
    {
        if (DB_TYPE == 'mysql') {
            $stmt = $this->conn->prepare(sprintf('INSERT INTO `%s` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?', $this->getTableName('convars')));
            $stmt->execute([$key, $value, $value]);
        } elseif (DB_TYPE == 'sqlite') {
            $stmt = $this->conn->prepare(sprintf('INSERT OR REPLACE INTO `%s` (`key`, `value`) VALUES (?, ?)', $this->getTableName('convars')));
            $stmt->execute([$key, $value]);
        }
        $this->setCacheValue($key, $value, 86400);
    }

    public function refreshConvars(): void
    {
        if (CACHE_TYPE == 'file') {
            // 删除缓存文件
            if (file_exists(CACHE_PATH)) {
                unlink(CACHE_PATH);
            }
        }
        $stmt = $this->conn->query(sprintf('SELECT `key`, `value` FROM `%s`', $this->getTableName('convars')));
        $convars = $stmt->fetchAll();
        foreach ($convars as $convar) {
            $this->setCacheValue($convar['key'], $convar['value'], 86400);
        }
    }

    public function getCacheValue(string $key, $default = null)
    {
        if (CACHE_TYPE == 'redis') {
            $result = $this->redis->get($key);
            if ($result) {
                return $result;
            }
        } elseif (CACHE_TYPE == 'file') {
            $result = $this->cache[$key] ?? null;
            if ($result && (!$result['expire'] || $result['expire'] > time())) {
                return $result['value'];
            }
        }
        return $default;
    }

    public function setCacheValue(string $key, $value, $expire = false): void
    {
        if (CACHE_TYPE == 'redis') {
            $this->redis->set($key, $value);
            if ($expire) {
                $this->redis->expire($key, $expire);
            }
        } elseif (CACHE_TYPE == 'file') {
            $this->cache[$key] = ['value' => $value, 'expire' => $expire ? time() + $expire : false];
            file_put_contents(CACHE_PATH, json_encode($this->cache));
        }
    }

    /* Cookies */

    private function configureCookie(): void
    {
        $cookie = readline('请输入您的 Cookie > ');
        if (!$cookie || empty($cookie)) {
            $this->log('Cookie 不能为空，请重新输入。');
            $this->configureCookie();
        } else {
            // Check if the cookie is valid
            $this->log('正在验证 Cookie，请稍候……');
            if ($this->validCookie($cookie)) {
                $this->setConvar('cookie', $cookie);
                $this->log('Cookie 配置成功！');
            } else {
                $this->log('Cookie 配置失败，请检查 Cookie 是否正确。');
                $this->configureCookie();
            }
        }
    }

    public function validCookie(string $cookie, bool $shouldUpdate = false): array
    {
        $cookieArray = $this->parseCookie($cookie);
        if (!isset($cookieArray['bili_jct'])) {
            return false;
        }
        $url = "https://api.bilibili.com/x/space/myinfo";
        $headers = [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3",
            "Referer: https://www.bilibili.com/",
        ];
        $response = $this->httpRequest($url, 'GET', [], $headers, $cookie);
        $data = json_decode($response['data'], true);
        if ($data && $data['code'] === 0) {
            return ['success' => true, 'message' => 'Cookie 有效。'];
        } else {
            return ['success' => false, 'message' => sprintf('HTTP Code: %d | %s', $response['status'], $response['error'])];
        }
    }

    public function parseCookie(string $cookie): array
    {
        if (empty($cookie)) {
            return [];
        }
        $cookie = explode(';', $cookie);
        $cookie = array_reduce($cookie, function ($carry, $item) {
            $exp = explode('=', $item);
            if (count($exp) == 2) {
                $carry[trim($exp[0])] = trim($exp[1]);
            } else {
                $carry[trim($exp[0])] = '';
            }
            return $carry;
        }, []);
        return $cookie;
    }

    /* Connections */

    public function httpRequest(string $url, string $method = 'GET', mixed $data = [], array $headers = [], string $cookie = ""): array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        if ($method === 'POST') {
            if (is_array($data)) {
                $data = http_build_query($data);
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        } elseif ($method === 'GET') {
            $exp = explode('?', $url);
            if (count($exp) > 1) {
                $url = $exp[0];
                $data = array_merge($data, array_reduce(explode('&', $exp[1]), function ($carry, $item) {
                    $exp = explode('=', $item);
                    $carry[$exp[0]] = $exp[1];
                    return $carry;
                }, []));
            } else {
                $url = $exp[0];
            }
            $url .= '?' . http_build_query($data);
            curl_setopt($curl, CURLOPT_URL, $url);
        }
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return ['data' => $response, 'error' => $error, 'status' => $status];
    }

    private function connectDatabase(): void
    {
        if (DB_TYPE == 'mysql') {
            $this->conn = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', DB_HOST, DB_PORT, DB_NAME), DB_USER, DB_PASS);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->exec('SET NAMES utf8mb4');
        } elseif (DB_TYPE == 'sqlite') {
            $this->conn = new PDO('sqlite:' . DB_FILE);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->exec('PRAGMA foreign_keys = ON');
        } else {
            die('Database type not supported.');
        }
    }

    private function connectRedis(): void
    {
        if (CACHE_TYPE == 'redis') {
            $this->redis = new Redis();
            $this->redis->connect(REDIS_HOST, REDIS_PORT);
            if (REDIS_PASS && !empty(REDIS_PASS)) {
                $this->redis->auth(REDIS_PASS);
            }
        } elseif (CACHE_TYPE == 'file') {
            if (!file_exists(CACHE_PATH)) {
                file_put_contents(CACHE_PATH, '[]');
            }
            $data = file_get_contents(CACHE_PATH);
            $data = json_decode($data, true);
            $this->cache = $data;
        } else {
            die('Cache type not supported.');
        }
    }

    private function checkDatabase(): bool
    {
        if (!$this->conn) return false;

        try {
            $this->conn->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        if (CACHE_TYPE == 'file') return true;
        if (!$this->redis) return false;

        try {
            $this->redis->ping();
            return true;
        } catch (RedisException $e) {
            return false;
        }
    }
}

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

if (!function_exists("readline")) {
    function readline($prompt = null)
    {
        if ($prompt) {
            echo $prompt;
        }
        $fp = fopen("php://stdin", "r");
        $result = "";
        // read until there is a newline
        while (!feof($fp)) {
            $result .= fgets($fp, 1024);
            if (strpos($result, "\n") !== false) {
                break;
            }
        }
        return rtrim($result);
    }
}

$daemon = new Daemon();
$daemon->start();
