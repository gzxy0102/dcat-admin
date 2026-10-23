
<div align="center">
    <img src="resources/assets/images/logo.png" height="80"> 
</div>
<br>

<p align="center">
    <a href="https://github.com/gzxy0102/dcat-admin/actions">
        <img src="https://github.com/gzxy0102/dcat-admin/workflows/Laravel%20Dusk/badge.svg" alt="Dusk Tests">
    </a>
    <a href="https://github.com/gzxy0102/dcat-admin/actions">
        <img src="https://github.com/gzxy0102/dcat-admin/workflows/Feature%20Tests/badge.svg" alt="Feature Tests">
    </a>
    <a href="https://packagist.org/packages/gzxy/dcat-admin" ><img src="https://poser.pugx.org/gzxy/dcat-admin/v/stable" /></a>
    <a href="https://packagist.org/packages/gzxy/dcat-admin"><img src="https://img.shields.io/packagist/dt/gzxy/dcat-admin.svg?color=" /></a>
    <a><img src="https://img.shields.io/badge/php-7.1+-59a9f8.svg?style=flat" /></a>
    <a><img src="https://img.shields.io/badge/laravel-5.5~13-59a9f8.svg?style=flat" ></a>
</p>

> 本仓库是 [jqhph/dcat-admin](https://github.com/jqhph/dcat-admin)（原 `dcat/laravel-admin` 包）的维护分支。上游已停止更新，本分支在其基础上继续维护，并以 `gzxy/dcat-admin` 包名发布，同时通过 `replace` 声明兼容依赖 `dcat/laravel-admin` 的扩展。

<p align=""><code>Dcat Admin</code>是一个基于<a href="https://www.laravel-admin.org/" target="_blank">laravel-admin</a>二次开发而成的后台系统构建工具，只需很少的代码即可快速构建出一个功能完善的高颜值后台系统。内置丰富的后台常用组件，开箱即用，让开发者告别冗杂的HTML代码，对后端开发者非常友好。</p>


- [中文文档](https://gzxy0102.github.io/dcat-admin-docs/)
- [Demo源码（上游）](https://github.com/jqhph/dcat-admin-demo)
- [扩展](#)


![](https://cdn.learnku.com/uploads/images/202101/28/38389/YLmL7PLqH7.png!large)


### 功能特性

- [x] 简洁优雅、灵活可扩展的API
- [x] 用户管理
- [x] RBAC权限管理，支持无限极权限节点
- [x] 菜单管理
- [x] 使用pjax构建无刷新页面，支持**按需加载**静态资源，可以无限扩展组件而不影响整体性能
- [x] 松耦合的页面构建与数据操作设计，可轻松切换数据源
- [x] 自定义页面
- [x] 自定义主题配色
- [x] 多主题切换功能，内置多种主题色
- [x] 可轻松构建无菜单栏的独立页面（如可用于构建弹窗选择器等功能）
- [x] 插件功能
- [x] 可视化代码生成器，可根据数据表一键生成增删改查页面
- [x] 数据表格构建工具，内置丰富的表格常用功能（如组合表头、数据导出、搜索、快捷创建、批量操作等）
- [x] 树状表格功能构建工具，支持分页和点击加载
- [x] 数据表单构建工具，内置丰富的表单类型，支持表单异步提交
- [x] 分步表单构建工具
- [x] 弹窗表单构建工具
- [x] 数据详情页构建工具
- [x] 无限层级树状页面构建工具，支持用拖拽的方式实现数据的层级、排序等操作
- [x] 内置丰富的常用页面组件（如图表、数据统计卡片、下拉菜单、Tab卡片、提示工具等）
- [x] `Section`功能（类似`Wordpress`的`Filter`和`blade`模板的`section`标签）
- [x] 异步文件上传表单，支持分块多线程上传
- [x] 多应用


### 环境
 - PHP >= 7.1.0
 - Laravel 5.5.0 ~ 13.*
 - Fileinfo PHP Extension

### 安装

首先需要安装`laravel`框架，如已安装可以跳过此步骤。如果您是第一次使用`laravel`，请务必先阅读文档 [安装 《Laravel中文文档》](https://learnku.com/docs/laravel) ！
```bash
composer create-project --prefer-dist laravel/laravel 项目名称
```

安装完`laravel`之后需要修改`.env`文件，设置数据库连接设置正确

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dcat-admin
DB_USERNAME=root
DB_PASSWORD=
```

安装`dcat-admin`


```
cd {项目名称}

composer require gzxy/dcat-admin
```

> **从 `dcat/laravel-admin` 迁移**：先 `composer remove dcat/laravel-admin`，再 `composer require gzxy/dcat-admin` 即可。包内命名空间（`Dcat\Admin`）与配置完全不变，且本包通过 `replace` 声明兼容依赖 `dcat/laravel-admin` 的扩展，无需修改业务代码。

然后运行下面的命令来发布资源：

```
php artisan admin:publish
```

在该命令会生成配置文件`config/admin.php`，可以在里面修改安装的地址、数据库连接、以及表名，建议都是用默认配置不修改。

然后运行下面的命令完成安装：

> 执行这一步命令可能会报以下错误`Specified key was too long ... 767 bytes`，如果出现这个报错，请在`app/Providers/AppServiceProvider.php`文件的`boot`方法中加上代码`\Schema::defaultStringLength(191);`，然后删除掉数据库中的所有数据表，再重新运行一遍`php artisan admin:install`命令即可。

```
php artisan admin:install
```

上述步骤操作完成之后就可以配置`web`服务了，**注意需要把`web`目录指向`public`目录**！如果用的是`nginx`，还需要在配置中加上伪静态配置
```dotenv
location / {
	try_files $uri $uri/ /index.php?$query_string;
}
```

启动服务后，在浏览器打开 `http://localhost/admin`，使用用户名 `admin` 和密码 `admin`登陆。


<a name="extensions"></a>
### 扩展

| 扩展                                        | 描述                              | dcat-admin 版本                             |
| ------------------------------------------------ | ---------------------------------------- |---------------------------------------- |
| [mosiboom/dcat-iframe-tab](https://github.com/mosiboom/dcat-iframe-tab)    | IFRAME TAB标签切换 | 2.x |
| [super-eggs/dcat-distpicker](https://github.com/super-eggs/dcat-distpicker)    | 省市区联动 | 2.x |
| [ueditor](https://github.com/jqhph/dcat-admin-ueditor) | 百度在线编辑器     | 1.x |
| [grid-sortable](https://github.com/jqhph/dcat-admin-grid-sortable) | 表格拖曳排序工具      | 1.x |


### 鸣谢
`Dcat Admin` 基于以下组件:

+ [Laravel](https://laravel.com/)
+ [Laravel Admin](https://www.laravel-admin.org/)
+ [AdminLTE3](https://github.com/ColorlibHQ/AdminLTE)
+ [bootstrap4](https://getbootstrap.com/)
+ [jQuery3](https://jquery.com/)
+ [Eonasdan Datetimepicker](https://github.com/Eonasdan/bootstrap-datetimepicker/)
+ [font-awesome](http://fontawesome.io)
+ [jquery-form](https://github.com/jquery-form/form)
+ [moment](http://momentjs.com/)
+ [webuploader](http://fex.baidu.com/webuploader/)
+ [jquery-pjax](https://github.com/defunkt/jquery-pjax)
+ [Nestable](http://dbushell.github.io/Nestable/)
+ [toastr](http://codeseven.github.io/toastr/)
+ [editor-md](https://github.com/pandao/editor.md)
+ [fontawesome-iconpicker](https://github.com/itsjavi/fontawesome-iconpicker)
+ [layer弹出层](http://layer.layui.com/)
+ [char.js](https://www.chartjs.org)
+ [nprogress](https://ricostacruz.com/nprogress/)
+ [bootstrap-validator](https://github.com/1000hz/bootstrap-validator)
+ [Google map](https://www.google.com/maps)
+ [Tencent map](http://lbs.qq.com/)

### Contributors

感谢所有为本项目做出贡献的人（含上游 [jqhph/dcat-admin](https://github.com/jqhph/dcat-admin) 的贡献者）。

<a href="https://github.com/gzxy0102/dcat-admin/graphs/contributors"><img src="https://contrib.rocks/image?repo=gzxy0102/dcat-admin" /></a>

### License
------------
`dcat-admin` is licensed under [The MIT License (MIT)](LICENSE).
