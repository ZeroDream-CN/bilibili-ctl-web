<?php
define('ROOT', dirname(__FILE__));

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
        $result = $this->redis->get($key);
        if ($result) {
            return $result;
        }
        $stmt = $this->conn->prepare(sprintf('SELECT `value` FROM `%s` WHERE `key` = ?', $this->getTableName('convars')));
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $result = $result ? $result['value'] : $default;
        $this->redis->set("btl_convar_{$key}", $result);
        $this->redis->expire("btl_convar_{$key}", 86400);
        return $result;
    }

    public function setConvar(string $key, string $value): void
    {
        $stmt = $this->conn->prepare(sprintf('INSERT INTO `%s` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?', $this->getTableName('convars')));
        $stmt->execute([$key, $value, $value]);
        $this->redis->set("btl_convar_{$key}", $value);
        $this->redis->expire("btl_convar_{$key}", 86400);
    }

    public function refreshConvars(): void
    {
        $stmt = $this->conn->query(sprintf('SELECT `key`, `value` FROM `%s`', $this->getTableName('convars')));
        $convars = $stmt->fetchAll();
        foreach ($convars as $convar) {
            $this->redis->set("btl_convar_{$convar['key']}", $convar['value']);
            $this->redis->expire("btl_convar_{$convar['key']}", 86400);
        }
    }

    /* Cookies */

    public function validCookie(string $cookie): bool
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
        return $data && $data['code'] === 0;
    }

    public function parseCookie(string $cookie): array
    {
        $cookie = explode(';', $cookie);
        $cookie = array_reduce($cookie, function ($carry, $item) {
            $exp = explode('=', $item);
            $carry[trim($exp[0])] = trim($exp[1]);
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
        $error = curl_error($curl);
        curl_close($curl);
        return ['data' => $response, 'error' => $error];
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
        $this->conn = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', DB_HOST, DB_PORT, DB_NAME), DB_USER, DB_PASS);
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->conn->exec('SET NAMES utf8mb4');
    }

    private function connectRedis(): void
    {
        $this->redis = new Redis();
        $this->redis->connect(REDIS_HOST, REDIS_PORT);
        if (REDIS_PASS && !empty(REDIS_PASS)) {
            $this->redis->auth(REDIS_PASS);
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

    public function doInstall(): void
    {
        if ($this->checkInstall()) {
            die($this->getErrorTemplate('重复安装', '当前系统已进行过安装，如需重新安装请删除 data/install.lock 文件。'));
        }

        if (!isset($_POST['action']) || !is_string($_POST['action'])) {
            $vars = [
                'db_host' => isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost',
                'db_port' => isset($_POST['db_port']) ? $_POST['db_port'] : 3306,
                'db_user' => isset($_POST['db_user']) ? $_POST['db_user'] : '',
                'db_pass' => isset($_POST['db_pass']) ? $_POST['db_pass'] : '',
                'db_name' => isset($_POST['db_name']) ? $_POST['db_name'] : 'bilibili_ctl',
                'db_pfix' => isset($_POST['db_pfix']) ? $_POST['db_pfix'] : '',
                'redis_host' => isset($_POST['redis_host']) ? $_POST['redis_host'] : '',
                'redis_port' => isset($_POST['redis_port']) ? $_POST['redis_port'] : 6379,
                'redis_pass' => isset($_POST['redis_pass']) ? $_POST['redis_pass'] : '',
                'admin_user' => isset($_POST['admin_user']) ? $_POST['admin_user'] : 'admin',
                'admin_pass' => isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '',
                'api_token' => isset($_POST['api_token']) ? $_POST['api_token'] : sha1(uniqid()),
            ];
            die($this->getTemplate('install', $vars));
        } else {
            switch ($_POST['action']) {
                case 'install':
                    $checkList = ['db_host', 'db_port', 'db_user', 'db_pass', 'db_name', 'redis_host', 'redis_port', 'admin_user', 'admin_pass', 'api_token'];
                    foreach ($checkList as $key) {
                        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                            die($this->getErrorTemplate('安装失败', '请填写所有必要的信息。'));
                        }
                    }
                    // Check username
                    if (!preg_match('/^[a-zA-Z0-9_]{2,16}$/', $_POST['admin_user'])) {
                        die($this->getErrorTemplate('安装失败', '用户名不符合规范 (2-16 位字母、数字或下划线)。'));
                    }
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
                    $stmt = $conn->prepare($installSQL);
                    $stmt->execute();
                    // Insert admin user
                    $salt = uniqid();
                    $password = password_hash($_POST['admin_pass'] . $salt, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare(sprintf('INSERT INTO `%s` (`username`, `password`, `salt`, `time`) VALUES (?, ?, ?, ?)', $_POST['db_pfix'] . 'users'));
                    $stmt->execute([$_POST['admin_user'], $password, $salt, time()]);
                    // Escape strings
                    $_POST['db_host'] = $conn->quote($_POST['db_host']);
                    $_POST['db_user'] = $conn->quote($_POST['db_user']);
                    $_POST['db_pass'] = $conn->quote($_POST['db_pass']);
                    $_POST['db_name'] = $conn->quote($_POST['db_name']);
                    $_POST['db_pfix'] = $conn->quote($_POST['db_pfix']);
                    $_POST['redis_host'] = $conn->quote($_POST['redis_host']);
                    $_POST['redis_pass'] = $conn->quote($_POST['redis_pass']);
                    $_POST['api_token'] = $conn->quote($_POST['api_token']);
                    // Create config file
                    file_put_contents(
                        sprintf('%s/../config.php', ROOT),
                        <<<EOF
<?php
define('DB_HOST', {$_POST['db_host']});
define('DB_PORT', {$_POST['db_port']});
define('DB_USER', {$_POST['db_user']});
define('DB_PASS', {$_POST['db_pass']});
define('DB_NAME', {$_POST['db_name']});
define('DB_PFIX', {$_POST['db_pfix']});

define('REDIS_HOST', {$_POST['redis_host']});
define('REDIS_PORT', {$_POST['redis_port']});
define('REDIS_PASS', {$_POST['redis_pass']});

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
            if (!$comments->validCookie($cookie)) {
                exit(json_encode(['success' => false, 'message' => 'Cookie 验证失败 | ' . $cookie]));
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
            if ($_POST['key'] == 'cookie' && !$comments->validCookie($_POST['value'])) {
                die($comments->getErrorTemplate('错误', 'Cookie 无效。'));
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
        default:
            die($comments->getErrorTemplate('错误', '未知操作。'));
    }
    exit;
}

$last = $comments->redis->get('btl_last_check');
$interval = $comments->getConvar('interval', 30);
$lastText = $last ? date('Y-m-d H:i:s', $last) : '从未';
$health = 'success';
if ($last && time() - $last > $interval * 4) {
    $health = 'danger';
} elseif ($last && time() - $last > $interval * 2) {
    $health = 'warning';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bilibili 评论管理工具</title>
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <style>
        body {
            background-color: #f1f1f1;
        }

        .card {
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1), 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .sub-heading {
            width: calc(100% - 16px);
            height: 0 !important;
            border-top: 1px solid #e9f1f1 !important;
            text-align: center !important;
            margin-top: 32px !important;
            margin-bottom: 40px !important;
            margin-left: 7px;
        }

        .sub-heading span {
            display: inline-block;
            position: relative;
            padding: 0 17px;
            top: -11px;
            font-size: 16px;
            color: #058;
            background-color: #fff;
        }

        .secure {
            font-family: monospace;
            font-size: 14px;
            background-color: #f8f9fa;
            filter: blur(10px);
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
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card mt-5 mb-5">
                    <div class="card-header">
                        <h5 class="title">Bilibili 评论管理工具 <small>已登录 <?php echo htmlspecialchars($_SESSION['user']['username']); ?> (<a href="?logout">退出</a>) <span class="badge badge-<?php echo $health; ?>">上次检查</span> <?php echo $lastText; ?></small></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-sm-12">
                                <p>欢迎使用 Bilibili 评论管理工具，您可以在这里对系统设置进行修改。</p>
                                <div class="sub-heading"><span>Cookie 配置</span></div>
                                <p>请输入您的 Bilibili Cookie，用于评论操作。</p>
                                <textarea class="form-control convar secure" rows="5" data-convar-key="cookie" data-convar-type="string" placeholder="Cookie" style="margin-bottom: 16px;"><?php echo htmlspecialchars($comments->getConvar('cookie')); ?></textarea>
                            </div>
                            <div class="col-sm-6">
                                <div class="sub-heading"><span>黑名单设置</span></div>
                                <p>要屏蔽的用户名或 UID，每行一条 (<a href="javascript:void(0);" onclick="fetchBlacklist()">导入黑名单</a>)</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="blacklist_users" data-convar-type="array" style="margin-bottom: 16px;"><?php echo htmlspecialchars(implode("\n", json_decode($comments->getConvar('blacklist_users', '[]')) ?: [])); ?></textarea>
                                <p>要屏蔽的关键词，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="blacklist_words" data-convar-type="array" style="margin-bottom: 16px;"><?php echo htmlspecialchars(implode("\n", json_decode($comments->getConvar('blacklist_words', '[]')) ?: [])); ?></textarea>
                                <p>正则表达式匹配评论，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="blacklist_regex" data-convar-type="array" style="margin-bottom: 16px;"><?php echo htmlspecialchars(implode("\n", json_decode($comments->getConvar('blacklist_regex', '[]')) ?: [])); ?></textarea>
                            </div>
                            <div class="col-sm-6">
                                <div class="sub-heading"><span>监控设置</span></div>
                                <p>白名单用户名或 UID，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="whitelist_users" data-convar-type="array" style="margin-bottom: 16px;"><?php echo htmlspecialchars(implode("\n", json_decode($comments->getConvar('whitelist_users', '[]')) ?: [])); ?></textarea>
                                <p>要监控的视频 BV 号，每行一条</p>
                                <textarea class="form-control convar" rows="5" data-convar-key="check_lists" data-convar-type="array" style="margin-bottom: 16px;"><?php echo htmlspecialchars(implode("\n", json_decode($comments->getConvar('check_lists', '[]')) ?: [])); ?></textarea>
                                <p>视频监控模式</p>
                                <select class="form-control convar" data-convar-key="check_mode" style="margin-bottom: 16px;">
                                    <option value="0" <?php echo $comments->getConvar('check_mode') === '0' ? 'selected' : ''; ?>>监控所有视频</option>
                                    <option value="1" <?php echo $comments->getConvar('check_mode') === '1' ? 'selected' : ''; ?>>仅监控指定视频</option>
                                    <option value="2" <?php echo $comments->getConvar('check_mode') === '2' ? 'selected' : ''; ?>>不监控指定视频</option>
                                </select>
                                <p>评论监控间隔时间（秒）</p>
                                <input type="number" class="form-control convar" data-convar-key="interval" placeholder="10" value="<?php echo htmlspecialchars($comments->getConvar('interval', '30')); ?>" style="margin-bottom: 16px;">
                            </div>
                            <div class="col-sm-12">
                                <div class="sub-heading"><span>最近删除的评论</span></div>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>用户名</th>
                                            <th>视频</th>
                                            <th>评论</th>
                                            <th>类型</th>
                                            <th>时间</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $stmt = $comments->getDatabase()->query(sprintf('SELECT * FROM `%s` ORDER BY `time` DESC LIMIT 10', $comments->getTableName('deleted')));
                                        $result = $stmt->fetchAll();
                                        foreach ($result as $comment) {
                                            $bvNumber = $comments->bilibili->av2bv($comment['oid']);
                                            $matchs = ['users' => '用户', 'words' => '关键词', 'regex' => '正则表达式'];
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars($comment['username']) . '</td>';
                                            echo '<td><a href="https://www.bilibili.com/video/' . $bvNumber . '" target="_blank">' . $bvNumber . '</a></td>';
                                            echo '<td>' . htmlspecialchars($comment['message']) . '</td>';
                                            echo '<td>' . ($matchs[$comment['match']] ?: $comment['match']) . '</td>';
                                            echo '<td>' . date('Y-m-d H:i:s', $comment['time']) . '</td>';
                                            echo '</tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-center text-muted">
                        <small>&copy; <?php echo sprintf('%d %s', date('Y'), $_SERVER['HTTP_HOST']); ?> | Powered by <a href="https://github.com/ZeroDream-CN/bilibili-ctl-web" target="_blank">Bilibili CTL Web</a></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/js/bootstrap.min.js"></script>
<script src="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.6.0/js/all.min.js"></script>
<script src="https://cdn.bootcdn.net/ajax/libs/sweetalert2/11.12.4/sweetalert2.all.min.js"></script>
<script>
    $(document).ready(function() {
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
            $.post('?action=updateConvar', {
                key: key,
                value: value
            }, function() {
                Swal.fire({
                    icon: 'success',
                    title: '设置已保存',
                    showConfirmButton: false,
                    timer: 1500,
                });
            });
        });
    });

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
                });
            }
        });
    }
</script>

</html>
