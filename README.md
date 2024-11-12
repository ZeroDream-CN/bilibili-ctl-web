# Bilibili Comments Tool Web
Bilibili 评论管理工具，帮助你自动清理不想要的评论内容，支持关键字、黑名单以及正则表达式匹配。

![image](https://github.com/user-attachments/assets/86cef016-236e-402f-b590-67d43db229af)

## 项目简介
如果你遇到过 B 站的黑名单数量不够用，屏蔽功能不够智能，或者你想屏蔽某些评论但是又不想屏蔽某个人，那么这个工具将可以很方便的帮助你完成这个工作。它通过不断读取评论列表，当发现有评论符合你设定的规则时，就会自动删除该评论，对于拥有大量粉丝的 UP 主来说将是一个非常方便的功能，你不再需要手动去清理那些让人血压飙升的评论，一切都交由这个工具来完成。

## 功能特性
* 按指定用户名或 UID 屏蔽评论
* 根据关键字屏蔽评论
* 使用正则表达式匹配评论
* 忽略指定用户评论（白名单）
* 一键从 Bilibili 导入黑名单
* 仅监控/不监控指定视频
* 可配置的监控时间

## 如何使用

### 一键懒人包（Windows）
首先你需要下载 [Bilibili-Ctl-Windows](https://github.com/ZeroDream-CN/bilibili-ctl-web/releases/download/1.0.1/biblibili_ctl_windows.zip) 并解压到你的电脑上，然后运行 `start.cmd` 即可。

> 如果出现 VCRUNTIME140_1.dll 丢失的情况，请下载 [Visual C++ 运行时](https://aka.ms/vs/17/release/vc_redist.x64.exe) 并安装。

### 手动搭建
首先你需要准备以下环境：

* PHP 7 或更高版本
* PDO MySQL 或 PDO SQLite 扩展
* Swoole 扩展或 Swoole Cli 工具
* PHP Redis 扩展（可选）
* Redis（可选）
* MySQL（可选）

然后，将本项目克隆到你的网站根目录并设置权限：

```bash
cd /data/wwwroot/your-web-site.com/
git clone https://github.com/ZeroDream-CN/bilibili-ctl-web.git .
chown -R www:www *
```

修改 Nginx 或 Apache 配置文件，将网站根目录指向到 `public` 目录，然后重启 Nginx 或 Apache 服务。

<details>
    <summary><b><em>Nginx 配置示例</em></b></summary>
    <hr>

以下是配置文件示例（以 `your-domain.com` 域名为例）：
```nginx
server {
  listen 80;
  listen 443 ssl http2;
  ssl_certificate /usr/local/nginx/conf/ssl/fullchain.crt;
  ssl_certificate_key /usr/local/nginx/conf/ssl/private.key;
  ssl_protocols TLSv1.1 TLSv1.2 TLSv1.3;
  ssl_ciphers TLS13-AES-256-GCM-SHA384:TLS13-CHACHA20-POLY1305-SHA256:TLS13-AES-128-GCM-SHA256:TLS13-AES-128-CCM-8-SHA256:TLS13-AES-128-CCM-SHA256:EECDH+CHACHA20:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:EECDH+3DES:RSA+3DES:!MD5;
  ssl_prefer_server_ciphers on;
  ssl_session_timeout 10m;
  ssl_session_cache builtin:1000 shared:SSL:10m;
  ssl_buffer_size 1400;
  add_header Strict-Transport-Security max-age=15768000;
  add_header Access-Control-Allow-Origin '*';
  ssl_stapling off;
  ssl_stapling_verify off;
  server_name your-domain.com;
  index index.html index.htm index.php;
  root /data/wwwroot/your-domain.com/public;
  location ~ [^/]\.php(/|$) {
    fastcgi_pass unix:/dev/shm/php-cgi.sock;
    fastcgi_index index.php;
    include fastcgi.conf;
  }
  location ~ /\.tpl {
    deny all;
  }
}
```
</details>

接着，访问你的网站，按照页面上的提示，输入数据库、缓存以及管理员信息，然后进行安装。

安装完成后，使用 systemd 或 screen 等其他方式在后台运行 `php daemon.php` 即可。

<details>
    <summary><b><em>Systemd 示例</em></b></summary>
    <hr>

以下是 Systemd 文件示例，以 `/etc/systemd/system/bilibili-ctl.service` 为例：
```ini
[Unit]
Description=Bilibili Comments Tool Service
After=network.target

[Service]
WorkingDirectory=/data/wwwroot/your-domain.com/
ExecStart=/usr/local/php/bin/php daemon.php
Restart=always
RestartSec=5s

[Install]
WantedBy=multi-user.target
```
</details>

## 获取 Cookie
该工具需要配合您的 Bilibili Cookie 才能正常使用，请参阅以下教程获取 Cookie。

<details>
    <summary><b><em>Cookie 获取方法</em></b></summary>
    <hr>
  
第一次启动软件会提示你输入 cookie，这里推荐使用 Chrome 谷歌浏览器或者其他 Chromium 系浏览器。

打开 [Bilibili 创作中心](https://member.bilibili.com/platform/comment/article)，打开之后按下 F12 打开浏览器控制台，然后转到 “网络” 或者 “Network”，接着刷新一下网页。

![image](https://user-images.githubusercontent.com/34357771/137756642-19f9a28e-0e5c-4820-9327-b6577e128d51.png)

然后点击第一个请求 article，此时右侧会出现请求的详细信息，找到 “请求标头” 或者 “Request Header”，将 “cookie:” 后面的内容复制（也就是截图中红框的部分）

![image](https://user-images.githubusercontent.com/34357771/137757549-273a9b9b-8859-4581-a34f-b8372e9f859a.png)

复制完之后返回到工具，在输入框内粘贴即可。

</details>

## Cookie 自动更新
由于 B 站更新了 Cookie 刷新算法，所以 Cookie 每隔一段时间就会失效，因此这里提供了一个[自动更新脚本](/bctl.user.js)，可以在你访问创作者中心的时候自动更新 Cookie，需要搭配 [Tampermonkey](https://www.tampermonkey.net/)（篡改猴）浏览器插件使用。

请注意，由于 Bilibili 的 SESSDATA 设置了 HttpOnly 属性，所以无法通过 JavaScript 直接获取，因此您需要在 Tampermonkey 的设置中，将 **安全** > **允许脚本访问 Cookie** 设置为 `All`（全部）。同时将 **@connect 模式** 设置为 `已禁用`。

脚本安装完成后，您在首次访问创作者中心时，会弹出输入框提示您输入 Bilibili-Ctl-Web 的网站页面地址以及 API Token。API Token 可以在 config.php 中找到。如果你使用的是一键懒人包，那么默认的网站地址是 `http://localhost:12380/`。

## 开源协议
本软件使用 GPL v3 协议开放源代码，任何人可以在遵循开源协议的情况下对本软件进行修改和使用。
