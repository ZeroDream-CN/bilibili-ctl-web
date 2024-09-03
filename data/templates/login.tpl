<!DOCTYPE html>
<html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Bilibili 评论管理工具</title>
        <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/css/bootstrap.min.css">
    </head>
    <body>
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card mt-5 mb-5">
                        <div class="card-header">
                            <h5 style="margin-bottom: 0px;font-weight: bold;">Bilibili 评论管理工具</h5>
                        </div>
                        <div class="card-body">
                            <form action="." method="post" id="loginForm" class="row">
                                <input type="hidden" name="action" value="login">
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label for="username">用户名</label>
                                        <input type="text" class="form-control" id="username" name="username" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="password">密码</label>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="card-footer">
                            <a href="javascript:loginForm.submit();"><button class="btn btn-primary btn-sm btn-block">登录</button></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    <script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.bootcdn.net/ajax/libs/bootstrap/4.5.3/js/bootstrap.min.js"></script>
</html>