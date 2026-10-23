#!/usr/bin/env bash

cd ./laravel-tests
sudo chmod -R 0755 ./vendor/laravel/dusk/bin/
php artisan serve --port=8300 > /dev/null 2>&1 &

# 手动启动 chromedriver 并等待就绪（部分 PHPUnit 版本不会触发 Dusk 的 beforeClass 钩子；
# Dusk 之后尝试重复启动会因端口占用自动退出，不影响使用）
./vendor/laravel/dusk/bin/chromedriver-linux --port=9515 > chromedriver.log 2>&1 &
for i in $(seq 1 30); do
    if curl -s http://localhost:9515/status > /dev/null 2>&1; then
        echo "chromedriver is ready"
        exit 0
    fi
    sleep 1
done
echo "chromedriver failed to start:"
cat chromedriver.log
exit 1
