# AGENTS.md

本文件为 AI 编程助手提供本仓库的协作指引。

## 项目概述

**Dcat Admin** 是一个基于 Laravel 的后台系统构建工具，基于 [laravel-admin](https://www.laravel-admin.org/) 二次开发。本仓库（`gzxy/dcat-admin`）是 [jqhph/dcat-admin](https://github.com/jqhph/dcat-admin)（原 `dcat/laravel-admin` 包）的维护分支：上游已停止更新，本分支继续维护，并通过 composer `replace` 声明兼容依赖 `dcat/laravel-admin` 的扩展。它通过很少的代码即可构建出功能完善的后台系统，内置用户管理、RBAC 权限、菜单管理、数据表格（Grid）、数据表单（Form）、树状页面（Tree）、代码生成器等组件。

- PHP `>=7.1`，兼容 Laravel `5.5 ~ 13.x`
- 主分支：`2.0`
- 命名空间：`Dcat\Admin\` → `src/`（PSR-4）

## 仓库结构

```
src/                  核心源码（Dcat\Admin 命名空间）
  Admin.php           核心门面类，全局入口
  AdminServiceProvider.php  Laravel 服务提供者
  Application.php     多应用支持
  Grid/  Form/  Show/ Tree/   四大核心构建器（表格、表单、详情、树）
  Layout/             页面布局（Content、Row、Column 等）
  Widgets/            页面组件（图表、卡片、Tab、弹窗等）
  Actions/            行操作、批量操作、工具操作
  Http/               路由、控制器、中间件（Auth、Permission、Pjax 等）
  Models/             内置模型（管理员、角色、权限、菜单等）
  Repositories/       数据仓库层（松耦合数据源设计，可切换非 Eloquent 数据源）
  Extend/             扩展（插件）系统
  Scaffold/           可视化代码生成器
  Console/            artisan 命令（admin:install、admin:make、扩展管理等）
  Support/            辅助类与 helpers.php（通过 composer files 自动加载）
config/admin.php      默认配置（发布到宿主项目）
database/migrations/  内置数据表迁移
resources/
  assets/             前端源码（SCSS / JS，用 webpack 构建）
  dist/               构建产物（发布到宿主的静态资源，勿手改）
  views/              Blade 视图
  lang/               多语言
tests/                测试（见下文）
docs/                 issue 模板等文档
```

## 常用命令

```bash
# PHP 依赖
composer install

# 静态分析
composer phpstan          # vendor/bin/phpstan analyse

# 测试（配置文件为 phpunit.dusk.xml，含 Feature 与 Browser 两个 suite）
vendor/bin/phpunit -c phpunit.dusk.xml --testsuite Feature
php artisan dusk          # 浏览器测试需在完整 Laravel 宿主项目中运行

# 前端构建（laravel-mix + webpack，产物输出到 resources/dist）
npm install
npm run dev               # 开发构建
npm run watch             # 监听构建
npm run prod              # 生产构建
```

## 测试约定

- 本包是**库**，不能直接运行：Feature/Dusk 测试需要一个真实的 Laravel 应用。CI 有两个工作流，均用 matrix 覆盖 Laravel 5.5 ~ 13（每个版本配一个代表性 PHP 版本，MySQL 5.7 以 service 容器提供）：
  - `.github/workflows/feature.yml`：跑 `vendor/bin/phpunit -c phpunit.dusk.xml --testsuite Feature`
  - `.github/workflows/dusk.yml`：跑 `php artisan dusk` 浏览器测试
  两者共同的安装流程：
  1. `composer create-project laravel/laravel laravel-tests "<版本>"`
  2. `sh tests/bin/install-dep.sh`（把本仓库拷贝进宿主项目并注册为 path 依赖；旧骨架走 `admin:composer-config` artisan stub，Laravel 11+ 精简骨架走 `composer config repositories.dcat-admin`）
  3. `sh tests/bin/install-admin.sh`（`admin:publish --force`、`admin:install`、拷贝测试路由与配置、从 chrome-for-testing 下载与当前 Chrome 匹配的 chromedriver——旧版 Dusk 内置下载源已失效）
  4. 仅 Dusk 需要 `sh tests/bin/start.sh`（启动 `artisan serve`）
- 测试基类：`tests/TestCase.php`（单元/Feature）、`tests/DuskTestCase.php`（浏览器）
- 测试辅助模型/控制器/路由在 `tests/Models`、`tests/Controllers`、`tests/routes.php`
- 测试数据库为 MySQL（CI 用 5.7），连接配置见 `tests/resources/config`
- 新增代码应优先补 Feature 测试（`tests/Feature`）；涉及前端交互的补 Dusk 测试（`tests/Browser`）

## 代码规范

- 代码风格：**Laravel preset**（`.styleci.yml`，StyleCI 自动检查），即 PSR-2 基础上按字母序排列 import 等规则
- PHP 需兼容 `>=7.1`：不要使用 7.4+ 语法（箭头函数、属性类型、空合并赋值等）
- 同时兼容 Laravel 5.5 ~ 13.x：避免使用高版本 Laravel 才有的 API，或先判断版本
- 新增功能应遵循既有的"构建器 + 数据仓库（Repository）"分层：页面构建逻辑与数据操作解耦
- `resources/dist/` 是前端构建产物，不要手工编辑；改前端应修改 `resources/assets/` 后执行 `npm run dev/prod`
- 修改面向用户的配置时，同步检查 `config/admin.php` 中的注释与默认值

## 关键设计约定

- 入口为 `Dcat\Admin\Admin` 类与 `admin` 辅助函数；大量功能通过 `Section` 机制（类似 WordPress 的 filter）开放扩展点
- 静态资源按需加载：组件用 `Admin::requireAssets` / 静态资源注册机制声明依赖，避免整页加载
- 扩展（插件）体系在 `src/Extend/`，扩展命令在 `src/Console/Extension*Command.php`
- 多应用支持见 `src/Application.php` 与 `admin:app` 命令
- Grid/Form/Show 的数据源默认是 Eloquent，但通过 `src/Repositories/` 抽象可替换（接口在 `src/Contracts/`）
