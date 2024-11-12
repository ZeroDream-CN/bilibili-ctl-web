<?php
define('ROOT', dirname(__FILE__));
// time zone
date_default_timezone_set('Asia/Shanghai');

class Bilibili
{
    protected $XOR_CODE  = 23442827791579;
    protected $MASK_CODE = 2251799813685247;
    protected $MAX_AID   = 1 << 51;
    protected $BASE      = 58;
    protected $data      = 'FcwAPNKTMug3GV5Lj7EJnHpWsx4tb8haYeviqBz6rkCy12mUSDQX9RdoZf';

    public function av2bv($aid)
    {
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

    public function bv2av($bvid)
    {
        $bvidArr = str_split($bvid);
        list($bvidArr[3], $bvidArr[9]) = [$bvidArr[9], $bvidArr[3]];
        list($bvidArr[4], $bvidArr[7]) = [$bvidArr[7], $bvidArr[4]];
        array_splice($bvidArr, 0, 3);
        $data = $this->data;
        $tmp = array_reduce($bvidArr, function ($pre, $bvidChar) use ($data) {
            return (($pre * $this->BASE) + strpos($data, $bvidChar));
        }, 0);
        return intval((($tmp & $this->MASK_CODE) ^ $this->XOR_CODE));
    }
}

class BiliComments
{
    private $conn;
    public $redis;
    public $cache = [];
    public $bilibili;

    public function init(): void
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
        $this->bilibili = new Bilibili();
    }

    /* Utils */

    public function getTableName(string $name): string
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
            $cachePath = sprintf('%s/../%s', ROOT, CACHE_PATH);
            $this->cache[$key] = ['value' => $value, 'expire' => $expire ? time() + $expire : false];
            file_put_contents($cachePath, json_encode($this->cache));
        }
    }

    public function readableTime(int $time): string
    {
        $diff = time() - $time;
        if ($diff < 60) {
            return '刚刚';
        } elseif ($diff < 3600) {
            return sprintf('%d 分钟前', $diff / 60);
        } elseif ($diff < 86400) {
            return sprintf('%d 小时前', $diff / 3600);
        } elseif ($diff < 2592000) {
            return sprintf('%d 天前', $diff / 86400);
        } else {
            return date('Y-m-d H:i:s', $time);
        }
    }

    /* Cookies */

    public function validCookie(string $cookie): array
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

    public function httpRequest(string $url, string $method = 'GET', array $data = [], array $headers = [], string $cookie = ""): array
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
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
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

    public function getDatabase(): PDO
    {
        return $this->conn;
    }

    public function getRedis(): Redis
    {
        return $this->redis;
    }

    private function connectDatabase(): void
    {
        if (DB_TYPE == 'mysql') {
            $this->conn = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', DB_HOST, DB_PORT, DB_NAME), DB_USER, DB_PASS);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->exec('SET NAMES utf8mb4');
        } elseif (DB_TYPE == 'sqlite') {
            $this->conn = new PDO('sqlite:../' . DB_FILE);
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
            $cachePath = sprintf('%s/../%s', ROOT, CACHE_PATH);
            if (!file_exists($cachePath)) {
                file_put_contents($cachePath, '[]');
            }
            $data = file_get_contents($cachePath);
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

    private function isTableExists(string $name): bool
    {
        $stmt = $this->conn->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$this->getTableName($name)]);
        return $stmt->rowCount() > 0;
    }

    public function getTemplate($name, $vars): string
    {
        $template = sprintf('%s/../data/templates/%s.tpl', ROOT, $name);
        if (!file_exists($template)) {
            return 'Template not found.';
        }
        $content = file_get_contents($template);
        foreach ($vars as $key => $value) {
            $content = str_replace("{{{$key}}}", $value, $content);
        }
        return $content;
    }

    public function getErrorTemplate($title, $description): string
    {
        return $this->getTemplate('error', ['title' => $title, 'description' => $description]);
    }

    public function basicAuth(): void
    {
        die($this->getTemplate('login', []));
    }

    public function checkInstall(): bool
    {
        $path = sprintf('%s/../data/install.lock', ROOT);
        return file_exists($path);
    }

    public function getParentPath($path): string
    {
        $current = ROOT;
        $exp = explode(DIRECTORY_SEPARATOR, $current);
        $parent = '';
        for ($i = 0; $i < count($exp) - 1; $i++) {
            $parent .= $exp[$i] . DIRECTORY_SEPARATOR;
        }
        $parent = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent);
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        return $parent . $path;
    }

    public function doInstall(): void
    {
        if ($this->checkInstall()) {
            die($this->getErrorTemplate('重复安装', '当前系统已进行过安装，如需重新安装请删除 data/install.lock 文件。'));
        }

        if (!isset($_POST['action']) || !is_string($_POST['action'])) {
            $vars = [
                'db_type' => 'mysql',
                'db_host' => isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost',
                'db_port' => isset($_POST['db_port']) ? $_POST['db_port'] : 3306,
                'db_user' => isset($_POST['db_user']) ? $_POST['db_user'] : '',
                'db_pass' => isset($_POST['db_pass']) ? $_POST['db_pass'] : '',
                'db_name' => isset($_POST['db_name']) ? $_POST['db_name'] : 'bilibili_ctl',
                'db_pfix' => isset($_POST['db_pfix']) ? $_POST['db_pfix'] : '',
                'db_file' => isset($_POST['db_file']) ? $_POST['db_file'] : 'data/bilibili_ctl.db',
                'cache_type' => 'redis',
                'redis_host' => isset($_POST['redis_host']) ? $_POST['redis_host'] : '',
                'redis_port' => isset($_POST['redis_port']) ? $_POST['redis_port'] : 6379,
                'redis_pass' => isset($_POST['redis_pass']) ? $_POST['redis_pass'] : '',
                'cache_path' => isset($_POST['cache_path']) ? $_POST['cache_path'] : 'data/cache.json',
                'admin_user' => isset($_POST['admin_user']) ? $_POST['admin_user'] : 'admin',
                'admin_pass' => isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '',
                'api_token' => isset($_POST['api_token']) ? $_POST['api_token'] : sha1(uniqid()),
            ];
            die($this->getTemplate('install', $vars));
        } else {
            switch ($_POST['action']) {
                case 'install':
                    if (!isset($_POST['db_type'], $_POST['cache_type']) || !is_string($_POST['db_type']) || !is_string($_POST['cache_type'])) {
                        die($this->getErrorTemplate('安装失败', '数据库类型或缓存类型不正确'));
                    }
                    if ($_POST['db_type'] !== 'mysql' && $_POST['db_type'] !== 'sqlite') {
                        die($this->getErrorTemplate('安装失败', '数据库类型不支持。'));
                    }
                    if ($_POST['cache_type'] !== 'redis' && $_POST['cache_type'] !== 'file') {
                        die($this->getErrorTemplate('安装失败', '缓存类型不支持。'));
                    }
                    if ($_POST['db_type'] == 'mysql') {
                        $checkList = ['db_host', 'db_port', 'db_user', 'db_pass', 'db_name', 'admin_user', 'admin_pass', 'api_token'];
                        foreach ($checkList as $key) {
                            if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                                die($this->getErrorTemplate('安装失败', '请填写所有必要的信息。'));
                            }
                        }
                    } elseif ($_POST['db_type'] == 'sqlite') {
                        $checkList = ['db_file', 'admin_user', 'admin_pass', 'api_token'];
                        foreach ($checkList as $key) {
                            if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                                die($this->getErrorTemplate('安装失败', '请填写所有必要的信息。'));
                            }
                        }
                    }
                    if ($_POST['cache_type'] == 'redis') {
                        $checkList = ['redis_host', 'redis_port', 'redis_pass'];
                        foreach ($checkList as $key) {
                            if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                                die($this->getErrorTemplate('安装失败', '请填写缓存配置。'));
                            }
                        }
                    } elseif ($_POST['cache_type'] == 'file') {
                        if (!isset($_POST['cache_path']) || !is_string($_POST['cache_path'])) {
                            die($this->getErrorTemplate('安装失败', '请填写缓存路径。'));
                        }
                    }
                    // Check username
                    if (!preg_match('/^[a-zA-Z0-9_]{2,16}$/', $_POST['admin_user'])) {
                        die($this->getErrorTemplate('安装失败', '用户名不符合规范 (2-16 位字母、数字或下划线)。'));
                    }
                    // Start install
                    if ($_POST['db_type'] == 'mysql') {
                        // Check prefix
                        if (!preg_match('/^[a-zA-Z0-9_]{0,16}$/', $_POST['db_pfix'])) {
                            die($this->getErrorTemplate('安装失败', '表前缀不符合规范 (0-16 位字母、数字或下划线)。'));
                        }
                        // Check db name
                        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $_POST['db_name'])) {
                            die($this->getErrorTemplate('安装失败', '数据库名不符合规范 (1-64 位字母、数字、下划线或短横线)。'));
                        }
                        $conn = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $_POST['db_host'], $_POST['db_port'], $_POST['db_name']), $_POST['db_user'], $_POST['db_pass']);
                        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                        $conn->exec('SET NAMES utf8mb4');
                        // Check if database exists
                        $stmt = $conn->prepare('SHOW DATABASES LIKE ?');
                        $stmt->execute([$_POST['db_name']]);
                        if ($stmt->rowCount() === 0) {
                            die($this->getErrorTemplate('安装失败', '数据库不存在。'));
                        }
                        // Create tables
                        $installSQL = file_get_contents(sprintf('%s/../data/install.sql', ROOT));
                        $installSQL = str_replace('{{db_pfix}}', $_POST['db_pfix'] ?: '', $installSQL);
                        $conn->exec($installSQL);
                        // Insert admin user
                        $salt = uniqid();
                        $password = password_hash($_POST['admin_pass'] . $salt, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare(sprintf('INSERT INTO `%s` (`username`, `password`, `salt`, `time`) VALUES (?, ?, ?, ?)', $_POST['db_pfix'] . 'users'));
                        $stmt->execute([$_POST['admin_user'], $password, $salt, time()]);
                    } elseif ($_POST['db_type'] == 'sqlite') {
                        // Check db file
                        if (empty($_POST['db_file'])) {
                            die($this->getErrorTemplate('安装失败', '请填写数据库文件路径。'));
                        }
                        $conn = new PDO('sqlite:../' . $_POST['db_file']);
                        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                        $conn->exec('PRAGMA foreign_keys = ON');
                        // Create tables
                        $installSQL = file_get_contents(sprintf('%s/../data/install-sqlite.sql', ROOT));
                        $installSQL = str_replace('{{db_pfix}}', '', $installSQL);
                        $conn->exec($installSQL);
                        // Insert admin user
                        $salt = uniqid();
                        $password = password_hash($_POST['admin_pass'] . $salt, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare('INSERT INTO `users` (`username`, `password`, `salt`, `time`) VALUES (?, ?, ?, ?)');
                        $stmt->execute([$_POST['admin_user'], $password, $salt, time()]);
                    }
                    // Escape strings
                    $_POST['db_type'] = $conn->quote($_POST['db_type']);
                    $_POST['db_host'] = $conn->quote($_POST['db_host']);
                    $_POST['db_user'] = $conn->quote($_POST['db_user']);
                    $_POST['db_pass'] = $conn->quote($_POST['db_pass']);
                    $_POST['db_name'] = $conn->quote($_POST['db_name']);
                    $_POST['db_pfix'] = $conn->quote($_POST['db_pfix']);
                    $_POST['db_file'] = $conn->quote($_POST['db_file']);
                    $_POST['cache_type'] = $conn->quote($_POST['cache_type']);
                    $_POST['redis_host'] = $conn->quote($_POST['redis_host']);
                    $_POST['redis_pass'] = $conn->quote($_POST['redis_pass']);
                    $_POST['cache_path'] = $conn->quote($_POST['cache_path']);
                    $_POST['api_token'] = $conn->quote($_POST['api_token']);
                    // Create config file
                    file_put_contents(
                        sprintf('%s/../config.php', ROOT),
                        <<<EOF
<?php
// 数据库配置
define('DB_TYPE', {$_POST['db_type']});
define('DB_HOST', {$_POST['db_host']});
define('DB_PORT', {$_POST['db_port']});
define('DB_USER', {$_POST['db_user']});
define('DB_PASS', {$_POST['db_pass']});
define('DB_NAME', {$_POST['db_name']});
define('DB_PFIX', {$_POST['db_pfix']});
define('DB_FILE', {$_POST['db_file']});

// 缓存配置
define('CACHE_TYPE', {$_POST['cache_type']});
define('REDIS_HOST', {$_POST['redis_host']});
define('REDIS_PORT', {$_POST['redis_port']});
define('REDIS_PASS', {$_POST['redis_pass']});
define('CACHE_PATH', {$_POST['cache_path']});

define('API_TOKEN', {$_POST['api_token']});
define('CONFIG_ON_INVALID', false); // Cookie 失效后是否提示重新输入
EOF
                    );
                    // Create install lock file
                    file_put_contents(sprintf('%s/../data/install.lock', ROOT), '');
                    // Redirect
                    die($this->getErrorTemplate('安装成功', '安装成功，请点击刷新，然后使用您设置的用户名和密码登录。'));
                    break;
                default:
                    die($this->getErrorTemplate('未知操作', '未知的操作。'));
            }
            exit;
        }
    }
}

SESSION_START();
$comments = new BiliComments();

if (!$comments->checkInstall()) {
    $comments->doInstall();
}

require_once(ROOT . '/../config.php');

$comments->init();

if (!isset($_SESSION['user']) && (!isset($_POST['token']) || $_POST['token'] !== API_TOKEN)) {
    // Http basic auth
    if (!isset($_POST['username']) || !isset($_POST['password'])) {
        $comments->basicAuth();
    }
    $username = $_POST['username'];
    $password = $_POST['password'];
    if (!preg_match('/^[a-zA-Z0-9_]{2,16}$/', $username)) {
        $comments->basicAuth();
    }
    $conn = $comments->getDatabase();
    $stmt = $conn->prepare(sprintf('SELECT `id`, `username`, `password`, `salt` FROM `%s` WHERE `username` = ?', $comments->getTableName('users')));
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password . $user['salt'], $user['password'])) {
        $comments->basicAuth();
    }
    $_SESSION['user'] = $user;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['user']);
    session_destroy();
    die($comments->getErrorTemplate('已退出', '您已退出登录。<script>setTimeout(function() { window.location.href = window.location.href.replace(/(\\?logout)$/, \'\'); }, 2000);</script>'));
    exit;
}

if (isset($_GET['action']) && is_string($_GET['action'])) {
    switch ($_GET['action']) {
        case 'updateCookie':
            if (!isset($_POST['cookie'])) {
                exit(json_encode(['success' => false, 'message' => '请填写所有必要的信息。']));
            }
            $oldCookie = $comments->getConvar('cookie');
            $newCookie = $_POST['cookie'];
            $oldCookieArray = $comments->parseCookie($oldCookie);
            $newCookieArray = $comments->parseCookie($newCookie);
            $cookie = array_merge($oldCookieArray, $newCookieArray);
            $buildCookie = [];
            foreach ($cookie as $key => $value) {
                $buildCookie[] = sprintf('%s=%s', $key, $value);
            }
            $cookie = implode('; ', $buildCookie);
            $result = $comments->validCookie($cookie);
            if (!$result['success']) {
                exit(json_encode(['success' => false, 'message' => 'Cookie 验证失败 | ' . $cookie . ' | ' . $result['message']]));
            }
            $comments->setConvar('cookie', $cookie);
            exit(json_encode(['success' => true, 'message' => 'Cookie 已更新。']));
            break;
        case 'updateConvar':
            if (!isset($_POST['key']) || !isset($_POST['value'])) {
                die($comments->getErrorTemplate('错误', '请填写所有必要的信息。'));
            }
            if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $_POST['key'])) {
                die($comments->getErrorTemplate('错误', '键名不符合规范。'));
            }
            if ($_POST['key'] == 'cookie') {
                $result = $comments->validCookie($_POST['value']);
                if (!$result['success']) {
                    die($comments->getErrorTemplate('错误', 'Cookie 验证失败 | ' . $_POST['value'] . ' | ' . $result['message']));
                }
            }
            $comments->setConvar($_POST['key'], $_POST['value']);
            header('Location: /');
            exit;
        case 'fetchBlacklist':
            $url = 'https://api.bilibili.com/x/relation/blacks';
            $response = $comments->httpRequest($url, 'GET', [], [
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3",
                "Referer: https://www.bilibili.com/",
            ], $comments->getConvar('cookie'));
            if ($response) {
                $data = json_decode($response['data'], true) ?: [];
                $blackList = [];
                if ($data && $data['data'] && $data['data']['list']) {
                    foreach ($data['data']['list'] as $blackItem) {
                        $blackList[] = $blackItem['mid'];
                    }
                }
                echo implode("\n", $blackList);
            }
            break;
        case 'fetchDeleted':
            $deleted = [];
            $stmt = $comments->getDatabase()->query(sprintf('SELECT * FROM `%s` ORDER BY `time` DESC LIMIT 10', $comments->getTableName('deleted')));
            $result = $stmt->fetchAll();
            foreach ($result as $comment) {
                $bvNumber = $comments->bilibili->av2bv($comment['oid']);
                $deleted[] = [
                    'username' => htmlspecialchars($comment['username']),
                    'oid'      => $bvNumber,
                    'message'  => htmlspecialchars($comment['message']),
                    'match'    => $comment['match'],
                    'time'     => $comments->readableTime($comment['time']),
                ];
            }
            exit(json_encode($deleted));
        case 'fetchConvar':
            if (!isset($_GET['key'])) {
                die($comments->getErrorTemplate('错误', '请填写所有必要的信息。'));
            }
            if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $_GET['key'])) {
                die($comments->getErrorTemplate('错误', '键名不符合规范。'));
            }
            $defaultValue = isset($_GET['default']) ? $_GET['default'] : '';
            $multiple = isset($_GET['multiple']) ? $_GET['multiple'] : false;
            $value = $comments->getConvar($_GET['key'], $defaultValue);
            if ($multiple) {
                $value = json_decode($value, true);
                $value = is_array($value) ? implode("\n", $value) : '';
            }
            exit(json_encode(['value' => $value]));
        case 'fetchInfo':
            $last = $comments->getCacheValue('btl_last_check');
            $interval = $comments->getConvar('interval', 30);
            $lastText = $last ? date('Y-m-d H:i:s', $last) : '从未';
            $health = 'success';
            if ($last && time() - $last > $interval * 4) {
                $health = 'danger';
            } elseif ($last && time() - $last > $interval * 2) {
                $health = 'warning';
            }
            $info = [
                'login'  => $_SESSION['user']['username'],
                'health' => $health,
                'last'   => $lastText,
            ];
            exit(json_encode($info));
        default:
            die($comments->getErrorTemplate('错误', '未知操作。'));
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bilibili 评论管理工具</title>
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/css/bootstrap.min.css">
    <link rel="stylesheet" async href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <style>
        body {
            background-color: #f1f1f1;
        }

        .card {
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1), 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .sub-heading {
            height: 0 !important;
            border-top: 1px solid #e9f1f1 !important;
            text-align: center !important;
            margin-top: 32px !important;
            margin-bottom: 40px !important;
        }

        .sub-heading span {
            display: inline-block;
            position: relative;
            padding: 0 17px;
            top: -13px;
            font-size: 16px;
            color: #058;
            background-color: #fff;
        }

        .secure {
            font-family: monospace;
            font-size: 14px;
            background-color: #f8f9fa;
            filter: blur(5px);
            transition: all 0.2s;
            word-break: break-all;
        }

        .secure:focus,
        .secure:hover {
            filter: none;
        }

        .title {
            margin-bottom: 0px;
            font-weight: bold;
        }

        .title small {
            margin-left: 10px;
            font-size: 14px;
        }

        .title .badge {
            margin-left: 10px;
        }

        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(240, 240, 240, 0.8);
            backdrop-filter: blur(15px);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .loading .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        .text-no-wrap {
            white-space: nowrap;
        }

        @media screen and (max-width: 1200px) {
            .container {
                max-width: 100% !important;
                padding: 0px;
                margin: 0;
            }

            .main-content {
                margin-top: 0px !important;
                margin-bottom: 0px !important;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="loading">
            <div class="spinner-border text-muted" role="status">
                <span class="sr-only">Loading...</span>
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card mt-5 mb-5 main-content">
                    <div class="card-header">
                        <h5 class="title">Bilibili 评论管理工具 <small>已登录 <span class="info-login"></span> (<a href="?logout">退出</a>) <span class="badge info-health">上次检查</span> <span class="info-last"></span></small></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-sm-12">
                                <p>欢迎使用 Bilibili 评论管理工具，您可以在这里对系统设置进行修改。</p>
                                <div class="sub-heading"><span>Cookie 配置</span></div>
                                <p>请输入您的 Bilibili Cookie，用于评论操作。</p>
                                <textarea class="form-control convar secure" rows="5" data-convar-key="cookie" data-convar-type="string" data-convar-default="" placeholder="Cookie" style="margin-bottom: 16px;"></textarea>
                            </div>
                            <div class="col-sm-6">
                                <div class="sub-heading"><span>屏蔽设置</span></div>
                                <p>要屏蔽的用户名或 UID，每行一条 (<a href="javascript:void(0);" onclick="fetchBlacklist()">导入黑名单</a>)</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="blacklist_users" data-convar-type="array" data-convar-default="[]" data-convar-multiplelines="1" style="margin-bottom: 16px;"></textarea>
                                <p>要屏蔽的关键词，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="blacklist_words" data-convar-type="array" data-convar-default="[]" data-convar-multiplelines="1" style="margin-bottom: 16px;"></textarea>
                                <p>正则表达式屏蔽评论，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="blacklist_regex" data-convar-type="array" data-convar-default="[]" data-convar-multiplelines="1" style="margin-bottom: 16px;"></textarea>
                            </div>
                            <div class="col-sm-6">
                                <div class="sub-heading"><span>监控设置</span></div>
                                <p>白名单用户名或 UID，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="whitelist_users" data-convar-type="array" data-convar-default="[]" data-convar-multiplelines="1" style="margin-bottom: 16px;"></textarea>
                                <p>视频 BV 号黑/白名单，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="check_lists" data-convar-type="array" data-convar-default="[]" data-convar-multiplelines="1" style="margin-bottom: 16px;"></textarea>
                                <p>视频监控模式</p>
                                <select class="form-control convar" data-convar-key="check_mode" data-convar-default="0" style="margin-bottom: 16px;">
                                    <option value="0">监控所有视频</option>
                                    <option value="1">仅监控指定视频（白名单）</option>
                                    <option value="2">不监控指定视频（黑名单）</option>
                                </select>
                                <div class="row">
                                    <div class="col-sm-6">
                                        <p>评论监控间隔时间（秒）</p>
                                        <input type="number" class="form-control convar" data-convar-key="interval" placeholder="10" data-convar-default="30" style="margin-bottom: 16px;" suggest-min="20" suggest-max="60" suggest-text="不建议低于 20 秒或高于 60 秒，过低的延迟可能会触发 B 站防火墙，过高的延迟可能会造成评论读取不及时。">
                                    </div>
                                    <div class="col-sm-6">
                                        <p>每次读取评论数量</p>
                                        <input type="number" class="form-control convar" data-convar-key="req_ps" placeholder="20" data-convar-default="20" style="margin-bottom: 16px;" suggest-min="10" suggest-max="50" suggest-text="不建议低于 10 条或高于 50 条，过低的数量可能会导致评论读取不及时，过高的数量可能会触发 B 站防火墙。">
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12">
                                <div class="sub-heading"><span>最近删除的评论</span></div>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th nowrap>用户</th>
                                                <th nowrap>视频</th>
                                                <th nowrap>类型</th>
                                                <th nowrap>时间</th>
                                                <th nowrap>评论</th>
                                            </tr>
                                        </thead>
                                        <tbody class="deleted-comments">
                                            <tr>
                                                <td colspan="5" class="text-center">正在加载...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-center text-muted">
                        <small>&copy; <span class="copy-text"></span> | Powered by <a href="https://github.com/ZeroDream-CN/bilibili-ctl-web" target="_blank">Bilibili CTL Web</a></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script async src="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/js/bootstrap.min.js"></script>
<script async src="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.6.0/js/all.min.js"></script>
<script async src="https://cdn.bootcdn.net/ajax/libs/sweetalert2/11.12.4/sweetalert2.all.min.js"></script>
<script>
    $(document).ready(function() {
        SetCopyText();
        fetchInfo();
        loadConvars();
        fetchDeleted();
        $('.loading').fadeOut();
        $('.convar').on('change', function() {
            var key = $(this).data('convar-key');
            var cType = $(this).data('convar-type');
            var value = $(this).val();
            if (cType === 'array') {
                value = value.split('\n').filter(function(item) {
                    return item.trim() !== '';
                });
                value = JSON.stringify(value);
            }
            var suggestMin = $(this).attr('suggest-min');
            var suggestMax = $(this).attr('suggest-max');
            var suggestText = $(this).attr('suggest-text');
            if (suggestMin && suggestMax && (value < suggestMin || value > suggestMax)) {
                Swal.fire({
                    icon: 'warning',
                    title: '警告',
                    text: suggestText,
                    showConfirmButton: true,
                    showCancelButton: true,
                    confirmButtonText: '继续',
                    cancelButtonText: '取消',
                }).then(function(result) {
                    if (result.isConfirmed) {
                        updateConvar(key, value);
                    }
                });
            } else {
                updateConvar(key, value);
            }
        });
        setInterval(() => {
            fetchInfo();
            fetchDeleted();
        }, 10000);
    });

    function SetCopyText() {
        var date = new Date();
        var year = date.getFullYear();
        var host = window.location.host;
        $('.copy-text').text(year + ' ' + host);
    }

    function loadConvars() {
        $('.convar').each(function() {
            var key = $(this).data('convar-key');
            var defaultValue = $(this).data('convar-default');
            var multipleLines = $(this).data('convar-multiplelines');
            fetchConvar(key, defaultValue, multipleLines);
        });
    }

    function fetchConvar(key, defaultValue, multipleLines) {
        $.get('?action=fetchConvar', {
            key: key,
            default: defaultValue,
            multiple: multipleLines
        }, function(data) {
            data = JSON.parse(data);
            $('.convar[data-convar-key="' + key + '"]').val(data.value);
        });
    }

    function fetchInfo() {
        $.get('?action=fetchInfo', function(data) {
            data = JSON.parse(data);
            $('.info-login').text(data.login);
            $('.info-health').text('上次检查').removeClass('badge-success badge-danger badge-warning').addClass('badge-' + data.health);
            $('.info-last').text(data.last);
        });
    }

    function fetchDeleted() {
        $.get('?action=fetchDeleted', function(data) {
            var matches = {
                users: '用户',
                words: '关键词',
                regex: '正则表达式'
            };
            var html = '';
            data = JSON.parse(data);
            data.forEach(function(comment) {
                var bvNumber = comment.oid.replace('av', 'BV');
                html += '<tr>';
                html += '<td nowrap>' + comment.username + '</td>';
                html += '<td nowrap><a href="https://www.bilibili.com/video/' + bvNumber + '" target="_blank">' + bvNumber + '</a></td>';
                html += '<td nowrap>' + (matches[comment.match] || comment.match) + '</td>';
                html += '<td nowrap>' + comment.time + '</td>';
                html += '<td>' + comment.message + '</td>';
                html += '</tr>';
            });
            if (data.length === 0) {
                html += '<tr><td colspan="5" class="text-center">暂无数据</td></tr>';
            }
            $('.deleted-comments').html(html);
        });
    }

    function fetchBlacklist() {
        Swal.fire({
            title: '确认导入',
            text: '导入黑名单将会覆盖当前设置，是否继续？',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '继续',
            cancelButtonText: '取消',
        }).then(function(result) {
            if (result.isConfirmed) {
                $.get('?action=fetchBlacklist', function(data) {
                    $('textarea[data-convar-key="blacklist_users"]').val(data);
                    $('textarea[data-convar-key="blacklist_words"]').trigger('change');
                });
            }
        });
    }

    function updateConvar(key, value) {
        $.post('?action=updateConvar', {
            key: key,
            value: value
        }, function() {
            Swal.fire({
                icon: 'success',
                title: '设置已保存',
                showConfirmButton: true,
            });
        });
    }
</script>

</html>