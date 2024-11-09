// ==UserScript==
// @name         Bilibili 评论管理工具自动更新 Cookie
// @namespace    https://github.com/ZeroDream-CN/bilibili-ctl-web/
// @version      1.0.0
// @description  这个脚本可以在您访问创作者中心的时候自动更新 Cookie 到评论管理工具中。
// @author       Akkariin
// @match        https://member.bilibili.com/*
// @icon         https://www.google.com/s2/favicons?sz=64&domain=bilibili.com
// @grant        GM_cookie
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
        var server = prompt('请输入您的 Bililbili 评论管理工具服务器地址', '');
        if (!server) {
            return;
        }
        if (!server.startsWith('http://') && !server.startsWith('https://')) {
            alert('服务器地址必须以 http:// 或 https:// 开头');
            return;
        }
        if (!server.endsWith('/')) {
            server += '/';
        }
        SetData('bctl_server', server);
        cb(server);
    }

    function ShowTokenDialog(cb) {
        var token = prompt('请输入您的 Bililbili 评论管理工具 Token', '');
        if (!token) {
            return;
        }
        SetData('bctl_token', token);
        cb(token);
    }

    function CheckInstall() {
        const server = GetData('bctl_server', false);
        const token  = GetData('bctl_token', false);
        if (!server || !token) {
            ShowServerDialog((server) => {
                ShowTokenDialog((token) => {
                    if (CheckInstall()) {
                        alert('Bilibili 评论管理工具配置成功，请刷新页面以生效');
                    }
                });
            });
            return false;
        }
        return true;
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
        if (!CheckInstall()) {
            console.log('未安装 Bilibili 评论管理工具');
            return;
        }
        const updateInterval = 1 * 60 * 60 * 1000;
        const now            = new Date().getTime();
        const lastUpdate     = GetLastUpdate();
        const cookies        = await GetCookie();
        let   cookie         = '';
        for (let i = 0; i < cookies.length; i++) {
            cookie += `${cookies[i].name}=${cookies[i].value}; `;
        }
        const cachedCookie = GetData('bctl_cookie', '');
        if (now - lastUpdate > updateInterval || cookie !== cachedCookie) {
            var server = GetData('bctl_server', false);
            var token  = GetData('bctl_token', false);
            if (server !== false && token !== false) {
                var ajaxObj = $.ajax({
                    url  : `${server}?action=updateCookie`,
                    type : 'POST',
                    async: true,
                    data : {
                        cookie: cookie,
                        token : token
                    },
                    success: function() {
                        var json = JSON.parse(ajaxObj.responseText);
                        if (json.success) {
                            console.log('Cookie 更新成功');
                            SetLastUpdate(now);
                        } else {
                            console.error('Cookie 更新失败：', json.message);
                        }
                    },
                    error: function() {
                        console.error('Cookie 更新失败：网络错误');
                    }
                });
            } else {
                console.error('server 或 token 未设置');
            }
        } else {
            console.log('距离上次更新 Cookie 不足 24 小时');
        }
    }

    $(document).ready(() => {
        Init();
    });
})();
