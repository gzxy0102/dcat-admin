#!/usr/bin/env bash

set -e

# 旧骨架（Laravel 5.5 ~ 10）通过 artisan stub 注册 admin:composer-config 命令，
# 并在启动时设置 Schema::defaultStringLength(191) 兼容 MySQL 5.7 索引长度；
# Laravel 11+ 精简骨架没有 Console Kernel，直接用 composer config 注册 path 仓库。
if [ -f ./laravel-tests/app/Console/Kernel.php ]; then
    cp -f ./tests/resources/stubs/artisan ./laravel-tests/
    cp -f ./tests/resources/stubs/ComposerConfigCommand.php ./laravel-tests/app/
fi

mkdir ./laravel-tests/dcat-admin
cp -rf ./config ./laravel-tests/dcat-admin
cp -rf ./database ./laravel-tests/dcat-admin
cp -rf ./resources ./laravel-tests/dcat-admin
cp -rf ./src ./laravel-tests/dcat-admin
cp -rf ./tests ./laravel-tests/dcat-admin
cp -f ./composer.json ./laravel-tests/dcat-admin
rm -rf ./laravel-tests/tests
cp -rf ./tests ./laravel-tests/tests
cp -f ./phpunit.dusk.xml ./laravel-tests
cp -f ./.env.testing ./laravel-tests/.env

cd ./laravel-tests

# Laravel 11+ 将 CACHE_DRIVER 改名为 CACHE_STORE（默认 database，需要数据表，这里固定为 file）
grep -q '^CACHE_STORE=' .env || echo "CACHE_STORE=file" >> .env

if [ -f ./app/Console/Kernel.php ]; then
    php artisan admin:composer-config
else
    composer config repositories.dcat-admin '{"type":"path","url":"./dcat-admin"}'
fi

composer require gzxy/dcat-admin:*@dev
composer require "laravel/dusk:*" --dev
