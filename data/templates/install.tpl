<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bilibili 评论管理工具</title>
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/css/bootstrap.min.css">
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

        .database-mysql, .database-sqlite, .cache-redis, .cache-file {
            display: flex;
            flex-wrap: wrap;
            width: 100%;
            padding: 0 -15px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card mt-5 mb-5">
                    <div class="card-header">
                        <h5 style="margin-bottom: 0px;font-weight: bold;">Bilibili 评论管理工具</h5>
                    </div>
                    <div class="card-body">
                        <p>欢迎使用 Bilibili 评论管理工具，首次使用请先安装。</p>
                        <form action="." method="post" id="installForm" class="row">
                            <input type="hidden" name="action" value="install">
                            <div class="col-sm-12">
                                <div class="sub-heading">
                                    <span>数据库设置</span>
                                </div>
                                <div class="form-group">
                                    <label for="db_type">数据库类型</label>
                                    <select class="form-control" id="db_type" name="db_type" required>
                                        <option value="sqlite" selected>SQLite</option>
                                        <option value="mysql">MySQL</option>
                                    </select>
                                </div>
                            </div>
                            <div class="database-mysql" style="display: none;">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label for="db_host">数据库地址</label>
                                        <input type="text" class="form-control" id="db_host" name="db_host"
                                            placeholder="localhost" required value="{{db_host}}">
                                    </div>
                                    <div class="form-group">
                                        <label for="db_port">数据库端口</label>
                                        <input type="number" class="form-control" id="db_port" name="db_port"
                                            placeholder="3306" required min="1" max="65535" value="{{db_port}}">
                                    </div>
                                    <div class="form-group">
                                        <label for="db_name">数据库名称</label>
                                        <input type="text" class="form-control" id="db_name" name="db_name"
                                            placeholder="bilibili_ctl" required value="{{db_name}}">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label for="db_user">数据库用户名</label>
                                        <input type="text" class="form-control" id="db_user" name="db_user"
                                            placeholder="root" required value="{{db_user}}">
                                    </div>
                                    <div class="form-group">
                                        <label for="db_pass">数据库密码</label>
                                        <input type="password" class="form-control" id="db_pass" name="db_pass"
                                            placeholder="password" required value="{{db_pass}}">
                                    </div>
                                    <div class="form-group">
                                        <label for="db_pfix">数据表前缀 (可选)</label>
                                        <input type="text" class="form-control" id="db_pfix" name="db_pfix"
                                            placeholder="btl_" value="{{db_pfix}}">
                                    </div>
                                </div>
                            </div>
                            <div class="database-sqlite">
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label for="db_file">数据库文件</label>
                                        <input type="text" class="form-control" id="db_file" name="db_file"
                                            placeholder="bilibili_ctl.db" required value="{{db_file}}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12">
                                <div class="sub-heading">
                                    <span>缓存设置</span>
                                </div>
                                <div class="form-group">
                                    <label for="cache_type">缓存类型</label>
                                    <select class="form-control" id="cache_type" name="cache_type" required>
                                        <option value="file" selected>文件</option>
                                        <option value="redis">Redis</option>
                                    </select>
                                </div>
                            </div>
                            <div class="cache-redis" style="display: none;">
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label for="redis_host">Redis 地址</label>
                                        <input type="text" class="form-control" id="redis_host" name="redis_host"
                                            placeholder="localhost" required value="{{redis_host}}">
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label for="redis_port">Redis 端口</label>
                                        <input type="number" class="form-control" id="redis_port" name="redis_port"
                                            placeholder="6379" required min="1" max="65535" value="{{redis_port}}">
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label for="redis_pass">Redis 密码 (可选)</label>
                                        <input type="password" class="form-control" id="redis_pass" name="redis_pass"
                                            placeholder="password" value="{{redis_pass}}">
                                    </div>
                                </div>
                            </div>
                            <div class="cache-file">
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label for="cache_path">缓存目录</label>
                                        <input type="text" class="form-control" id="cache_path" name="cache_path"
                                            placeholder="cache" required value="{{cache_path}}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12">
                                <div class="sub-heading">
                                    <span>管理员设置</span>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label for="admin_user">管理员用户名</label>
                                    <input type="text" class="form-control" id="admin_user" name="admin_user"
                                        placeholder="admin" required value="{{admin_user}}">
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label for="admin_pass">管理员密码</label>
                                    <input type="password" class="form-control" id="admin_pass" name="admin_pass"
                                        placeholder="password" required value="{{admin_pass}}">
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label for="api_token">API 秘钥</label>
                                    <input type="text" class="form-control" id="api_token" name="api_token"
                                        placeholder="token" required value="{{api_token}}">
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer">
                        <a href="javascript:installForm.submit();"><button
                                class="btn btn-primary btn-sm btn-block">开始安装</button></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/js/bootstrap.min.js"></script>
<script>
    $(function () {
        $('#db_type').change(function () {
            if ($(this).val() === 'mysql') {
                $('.database-mysql').show();
                $('.database-sqlite').hide();
            } else {
                $('.database-mysql').hide();
                $('.database-sqlite').show();
            }
        });
        $('#cache_type').change(function () {
            if ($(this).val() === 'redis') {
                $('.cache-redis').show();
                $('.cache-file').hide();
            } else {
                $('.cache-redis').hide();
                $('.cache-file').show();
            }
        });
    });
</script>

</html>
