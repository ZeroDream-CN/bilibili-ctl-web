// ==UserScript==
// @name         Bilibili 评论管理工具自动更新 Cookie
// @namespace    https://github.com/ZeroDream-CN/bilibili-ctl-web/
// @version      1.0.1
// @description  这个脚本可以在您访问创作者中心的时候自动更新 Cookie 到评论管理工具中。
// @author       Akkariin
// @match        https://member.bilibili.com/*
// @icon         https://www.google.com/s2/favicons?sz=64&domain=bilibili.com
// @grant        GM_cookie
// @grant        GM_xmlhttpRequest
// @connect      member.bilibili.com
// ==/UserScript==

(function() {
    'use strict';

    function GetData(key, defaultValue) {
        return localStorage.getItem(key) || defaultValue;
    }

    function SetData(key, value) {
        localStorage.setItem(key, value);
    }

    function GetLastUpdate() {
        return GetData('bctl_lastUpdate', 0);
    }

    function SetLastUpdate(value) {
        SetData('bctl_lastUpdate', value);
    }

    function ShowServerDialog(cb) {
        var currentServer = GetData('bctl_server', '');
        Swal.fire({
            title: '配置服务器',
            html: '请输入您的 Bililbili 评论管理工具服务器地址<br>示例地址：<code>http://bctl.example.com/</code><br>本地部署请填：<code>http://localhost:12380/</code>',
            input: 'text',
            inputValue: currentServer,
            inputAttributes: {
                autocapitalize: 'off'
            },
            showCancelButton: true,
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            showLoaderOnConfirm: true,
            preConfirm: (server) => {
                return new Promise((resolve) => {
                    if (!server) {
                        Swal.showValidationMessage('服务器地址不能为空');
                        resolve();
                    }
                    if (!server.startsWith('http://') && !server.startsWith('https://')) {
                        Swal.showValidationMessage('服务器地址必须以 http:// 或 https:// 开头');
                        resolve();
                    }
                    if (!server.endsWith('/')) {
                        server += '/';
                    }
                    resolve(server);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                // SetData('bctl_server', result.value);
                cb(result.value);
            }
        });
    }

    function ShowTokenDialog(cb) {
        var currentToken = GetData('bctl_token', '');
        Swal.fire({
            title: '配置 Token',
            html: '请输入您的 Bililbili 评论管理工具 Token<br>Token 可以在您的 config.php 文件中找到',
            input: 'text',
            inputValue: currentToken,
            inputAttributes: {
                autocapitalize: 'off'
            },
            showCancelButton: true,
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            showLoaderOnConfirm: true,
            preConfirm: (token) => {
                return new Promise((resolve) => {
                    if (!token) {
                        Swal.showValidationMessage('Token 不能为空');
                        resolve();
                    }
                    resolve(token);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                // SetData('bctl_token', result.value);
                cb(result.value);
            }
        });
    }

    function CheckInstall() {
        const currentServer = GetData('bctl_server', false);
        const currentToken = GetData('bctl_token', false);
        if (!currentServer || !currentToken) {
            var tmpServer = false;
            var tmpToken = false;
            ShowServerDialog((server) => {
                tmpServer = server;
                ShowTokenDialog((token) => {
                    tmpToken = token;
                    CheckServer(tmpServer, tmpToken).then((result) => {
                        if (result) {
                            SetData('bctl_server', tmpServer);
                            SetData('bctl_token', tmpToken);
                            Swal.fire({
                                icon: 'success',
                                title: '配置成功',
                                text: 'Bilibili 评论管理工具配置成功，请刷新页面以生效',
                                showConfirmButton: true,
                                confirmButtonText: '确定',
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: '配置失败',
                                text: 'Token 验证失败，请检查服务器地址和 Token 是否正确',
                                showConfirmButton: true,
                                confirmButtonText: '确定',
                            });
                        }
                    });
                });
            });
            return false;
        }
        return true;
    }

    function CreateSideBarItem(icon, name, onClick, subMenu) {
        var subMenuElements = [];
        if (subMenu) {
            for (let i = 0; i < subMenu.length; i++) {
                var subMenuElement = $(`<div class="bcc-nav-slider-item__wrap group-item">
                    <div class="bcc-nav-slider-item__content">
                        <span class="router-item" data-reporter-id="17">
                            <span class="menu-title">
                                <span>${subMenu[i].name}</span>
                            </span>
                        </span>
                    </div>
                </div>`);
                if (subMenu[i].onClick) {
                    subMenuElement.click(subMenu[i].onClick);
                }
                subMenuElements.push(subMenuElement);
            }
        }
        var routerClass = subMenu ? 'router_wrap' : 'router-item';
        var element = $(`<div class="bcc-nav-slider-sub-menu__wrap bar-item">
            <div class="bcc-nav-slider-sub-menu">
                <span class="${routerClass}" data-reporter-id="21">
                    <i class="menu-icon bcc-iconfont bcc-icon-${icon}"></i>
                    <span class="menu-title">
                        <span>${name}</span>
                    </span>
                    ${subMenu ? '<i class="bcc-iconfont arrow-iconfont bcc-icon-ic_drop-down"></i>' : ''}
                </span>
            </div>
            <div class="bcc-nav-slider-sub-menu__group" style="height: 0px; overflow: hidden; transition: 0.3s !important;">
        </div>`);
        if (!subMenu) {
            if (onClick) {
                element.click(onClick);
            }
        } else {
            element.find('.bcc-nav-slider-sub-menu').click(() => {
                $(element).parent().find('.bcc-nav-slider-sub-menu .router-item').removeClass('active');
                $(element).parent().find('.bcc-nav-slider-sub-menu .router_wrap').removeClass('active');
                $(element).parent().find('.bcc-nav-slider-sub-menu__group .router-item').removeClass('active');
                $(element).parent().find('.bcc-nav-slider-item__wrap').removeClass('is-active');
                var shouldOpen = !element.find('.bcc-nav-slider-sub-menu__group').hasClass('active');
                if (shouldOpen) {
                    element.find('.bcc-nav-slider-sub-menu .router_wrap').addClass('active');
                    element.find('.router_wrap .bcc-icon-ic_drop-down').attr('class', 'bcc-iconfont arrow-iconfont bcc-icon-ic_pull');
                    element.find('.bcc-nav-slider-sub-menu__group').addClass('active');
                    setTimeout(() => {
                        var items = $(element).find('.bcc-nav-slider-sub-menu__group .group-item').length;
                        var height = $(element).find('.bcc-nav-slider-sub-menu__group .group-item').height() * items;
                        element.find('.bcc-nav-slider-sub-menu__group').css('height', height + 'px');
                    }, 10);
                } else {
                    element.find('.bcc-nav-slider-sub-menu .router_wrap').removeClass('active');
                    element.find('.router_wrap .bcc-icon-ic_pull').attr('class', 'bcc-iconfont arrow-iconfont bcc-icon-ic_drop-down');
                    element.find('.bcc-nav-slider-sub-menu__group').removeClass('active');
                    setTimeout(() => {
                        element.find('.bcc-nav-slider-sub-menu__group').css('height', '0px');
                    }, 10);
                }
            });
            for (let i = 0; i < subMenuElements.length; i++) {
                element.find('.bcc-nav-slider-sub-menu__group').append(subMenuElements[i]);
            }
        }
        $('#slider-bar-wrap .bccNavSliderMenu-wrap').append(element);
    }

    function InitSideBar() {
        CreateSideBarItem('gexinghuapeizhi2x', '评论监控', null, [{
            name: '设置服务器',
            onClick: () => {
                var tmpServer = false;
                var tmpToken = false;
                ShowServerDialog((server) => {
                    tmpServer = server;
                    ShowTokenDialog((token) => {
                        tmpToken = token;
                        CheckServer(tmpServer, tmpToken).then((result) => {
                            if (result) {
                                SetData('bctl_server', tmpServer);
                                SetData('bctl_token', tmpToken);
                                Swal.fire({
                                    icon: 'success',
                                    title: '配置成功',
                                    text: 'Bilibili 评论管理工具配置成功，请刷新页面以生效',
                                    showConfirmButton: true,
                                    confirmButtonText: '确定',
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: '配置失败',
                                    text: 'Token 验证失败，请检查服务器地址和 Token 是否正确',
                                    showConfirmButton: true,
                                    confirmButtonText: '确定',
                                });
                            }
                        });
                    });
                });
            }
        }, {
            name: '刷新 Cookie',
            onClick: () => {
                RefreshCookie(true);
            }
        }, {
            name: '关于本插件',
            onClick: () => {
                Swal.fire({
                    title: '关于本插件',
                    html: '本插件会定时将您最新的 Cookie 推送到 Bilibili 评论管理工具，以实现实时监控评论内容。<br><br>作者：Akkariin<br>GitHub：<a href="https://github.com/ZeroDream-CN/bilibili-ctl-web/" target="_blank">bilibili-ctl-web</a><br>版本：1.0.1',
                    showConfirmButton: true,
                    confirmButtonText: '确定',
                });
            }
        }]);
    }

    function InitSwal(cb) {
        if (typeof Swal === 'undefined') {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
            script.onload = () => {
                InitSwal(cb);
            };
            document.head.appendChild(script);
            // Swal style fix
            var style = document.createElement('style');
            style.innerHTML = '.swal2-popup { font-size: 1.4em !important; }';
            document.head.appendChild(style);
            return;
        }
        if (cb) {
            cb();
        }
    }

    async function CheckServer(server, token) {
        const cookies = await GetCookie();
        let cookie = '';
        var findSessData = false;
        for (let i = 0; i < cookies.length; i++) {
            let encoded = encodeURIComponent(cookies[i].value);
            cookie += `${cookies[i].name}=${encoded}`;
            if (i < cookies.length - 1) {
                cookie += '; ';
            }
            if (cookies[i].name === 'SESSDATA') {
                findSessData = true;
            }
        }
        if (!findSessData) {
            console.error('未找到 SESSDATA Cookie');
            if (useAlert) {
                Swal.fire({
                    icon: 'error',
                    title: '检查失败',
                    text: '未读取到 SESSDATA，请在 Tampermonkey 设置 > 安全 > 允许脚本访问 Cookie 中选择 “All”',
                    showConfirmButton: true,
                    confirmButtonText: '确定',
                });
            }
            return;
        }
        return new Promise((resolve, reject) => {
            GM_xmlhttpRequest({
                method: 'POST',
                url: `${server}?action=updateCookie`,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                data: `token=${token}&cookie=${cookie}`,
                onload: function(response) {
                    try {
                        var json = JSON.parse(response.responseText);
                        if (json.success) {
                            resolve(true);
                        } else {
                            resolve(false);
                        }
                    } catch (e) {
                        console.error('Token 检查失败：', response.responseText, e);
                        resolve(false);
                    }
                },
                onerror: function(err) {
                    console.error('Token 检查失败：网络错误', err);
                    resolve(false);
                }
            });
        });
    }

    async function RefreshCookie(useAlert, checkCached) {
        const cookies = await GetCookie();
        let cookie = '';
        var findSessData = false;
        for (let i = 0; i < cookies.length; i++) {
            let encoded = encodeURIComponent(cookies[i].value);
            cookie += `${cookies[i].name}=${encoded}`;
            if (i < cookies.length - 1) {
                cookie += '; ';
            }
            if (cookies[i].name === 'SESSDATA') {
                findSessData = true;
            }
        }
        if (!findSessData) {
            console.error('未找到 SESSDATA Cookie');
            if (useAlert) {
                Swal.fire({
                    icon: 'error',
                    title: '更新失败',
                    text: '未读取到 SESSDATA，请在 Tampermonkey 设置 > 安全 > 允许脚本访问 Cookie 中选择 “All”',
                    showConfirmButton: true,
                    confirmButtonText: '确定',
                });
            }
            return;
        }
        if (checkCached) {
            if (cookie === GetData('bctl_cookie', '')) {
                console.log('Cookie 未发生变化');
                if (useAlert) {
                    Swal.fire({
                        icon: 'info',
                        title: '更新失败',
                        text: 'Cookie 未发生变化',
                        showConfirmButton: true,
                        confirmButtonText: '确定',
                    });
                }
                return;
            }
        }
        const cachedCookie = GetData('bctl_cookie', '');
        var server = GetData('bctl_server', false);
        var token = GetData('bctl_token', false);
        if (server !== false && token !== false) {
            GM_xmlhttpRequest({
                method: 'POST',
                url: `${server}?action=updateCookie`,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                data: `cookie=${cookie}&token=${token}`,
                onload: function(response) {
                    try {
                        var json = JSON.parse(response.responseText);
                        if (json.success) {
                            console.log('Cookie 更新成功');
                            if (useAlert) {
                                Swal.fire({
                                    icon: 'success',
                                    title: '更新成功',
                                    text: '已将最新的 Cookie 推送到远程服务器',
                                    showConfirmButton: true,
                                    confirmButtonText: '确定',
                                });
                            }
                            const now = new Date().getTime();
                            SetLastUpdate(now);
                            SetData('bctl_cookie', cookie);
                        } else {
                            console.error('Cookie 更新失败：', json.message);
                            if (useAlert) {
                                Swal.fire({
                                    icon: 'error',
                                    title: '更新失败',
                                    text: `Cookie 更新失败：${json.message}`,
                                    showConfirmButton: true,
                                    confirmButtonText: '确定',
                                });
                            }
                        }
                    } catch (e) {
                        console.error('Cookie 更新失败：', response.responseText, e);
                        if (useAlert) {
                            Swal.fire({
                                icon: 'error',
                                title: '更新失败',
                                text: `Cookie 更新失败：${e.message} (${response.responseText})`,
                                showConfirmButton: true,
                                confirmButtonText: '确定',
                            });
                        }
                    }
                },
                onerror: function(err) {
                    console.error('Cookie 更新失败：网络错误', err);
                    if (useAlert) {
                        var translateErr = '网络错误';
                        if (err.error.includes('This domain is not a part of the @connect list')) {
                            translateErr = '跨域错误，请在 Tampermonkey 设置 &gt; 安全 &gt; @connect 模式中选择 “已禁用”';
                        }
                        Swal.fire({
                            icon: 'error',
                            title: '更新失败',
                            html: translateErr,
                            showConfirmButton: true,
                            confirmButtonText: '确定',
                        });
                    }
                }
            });
        } else {
            console.error('server 或 token 未设置');
            if (useAlert) {
                Swal.fire({
                    icon: 'error',
                    title: '更新失败',
                    text: 'server 或 token 未设置',
                    showConfirmButton: true,
                    confirmButtonText: '确定',
                });
            }
        }
    }

    async function GetCookie() {
        return new Promise((resolve, reject) => {
            GM_cookie.list({ url: "https://member.bilibili.com/" }, function(cookies, error) {
                if (error) {
                    reject(error);
                } else {
                    resolve(cookies);
                }
            });
        });
    }

    async function Init() {
        var tmpInterval = setInterval(async () => {
            if ($('#slider-bar-wrap .bccNavSliderMenu-wrap').length > 0) {
                clearInterval(tmpInterval);
                InitSideBar();
                InitSwal(async () => {
                    if (!CheckInstall()) {
                        console.log('未安装 Bilibili 评论管理工具');
                        return;
                    }
                    setInterval(async () => {
                        const updateInterval = 1 * 60 * 60 * 1000;
                        const now = new Date().getTime();
                        const lastUpdate = GetLastUpdate();
                        if (now - lastUpdate > updateInterval) {
                            await RefreshCookie(false, true);
                        }
                    }, 1 * 60 * 1000);
                });
            }
        });
    }

    $(document).ready(() => {
        Init();
    });
})();