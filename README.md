# Bilibili Comments Tool Web
Bilibili 评论管理工具，帮助你自动清理不想要的评论内容，支持关键字、黑名单以及正则表达式匹配。

## 项目简介
如果你遇到过 B 站的黑名单数量不够用，屏蔽功能不够智能，或者你想屏蔽某些评论但是又不想屏蔽某个人，那么这个工具将可以很方便的帮助你完成这个工作。它通过不断读取评论列表，当发现有评论符合你设定的规则时，就会自动删除该评论，对于拥有大量粉丝的 UP 主来说将是一个非常方便的功能，你不再需要手动去清理那些让人血压飙升的评论，一切都交由这个工具来完成。

## 如何使用
首先你需要准备以下环境：

* PHP 7.3 或更高版本
* Redis
* MySQL

然后，将本项目克隆到你的网站根目录并设置权限：

```bash
cd /data/wwwroot/your-web-site.com/
git clone https://github.com/ZeroDream-CN/bilibili-ctl-web.git .
chown -R www:www *
```

修改 Nginx 或 Apache 配置文件，将网站根目录指向到 `public` 目录，然后重启 Nginx 或 Apache 服务。

接着，访问你的网站，按照提示进行安装。

安装完成后，使用 systemd 或 screen 等其他方式在后台运行 `php daemon.php` 即可。

<details>
    <summary>Cookie 获取方法</summary>
    <hr>
  
第一次启动软件会提示你输入 cookie，这里推荐使用 Chrome 谷歌浏览器或者其他 Chromium 系浏览器。

打开 [Bilibili 创作中心](https://member.bilibili.com/platform/comment/article)，打开之后按下 F12 打开浏览器控制台，然后转到 “网络” 或者 “Network”，接着刷新一下网页。

![image](https://user-images.githubusercontent.com/34357771/137756642-19f9a28e-0e5c-4820-9327-b6577e128d51.png)

然后点击第一个请求 article，此时右侧会出现请求的详细信息，找到 “请求标头” 或者 “Request Header”，将 “cookie:” 后面的内容复制（也就是截图中红框的部分）

![image](https://user-images.githubusercontent.com/34357771/137757549-273a9b9b-8859-4581-a34f-b8372e9f859a.png)

复制完之后返回到软件，按下右键粘贴即可。

</details>

## 开源协议
本软件使用 GPL v3 协议开放源代码，任何人可以在遵循开源协议的情况下对本软件进行修改和使用。
