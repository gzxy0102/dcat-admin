#!/usr/bin/env bash

set -e

cd ./laravel-tests
php artisan admin:publish --force
php artisan admin:install
php artisan migrate:rollback
cp -f ./tests/routes.php ./app/Admin/
cp -rf ./tests/resources/config ./config/

# 旧版 Dusk 内置的 chromedriver 下载源（chromedriver.storage.googleapis.com）已失效，
# 统一改为从 chrome-for-testing 下载与当前 Chrome 主版本匹配的 chromedriver。
CHROME_MAJOR=$(google-chrome --version | sed 's/[^0-9.]//g' | cut -d. -f1)
DRIVER_URL=$(php -r '
    $data = json_decode(file_get_contents("https://googlechromelabs.github.io/chrome-for-testing/latest-versions-per-milestone-with-downloads.json"), true);
    foreach ($data["milestones"][$argv[1]]["downloads"]["chromedriver"] as $download) {
        if ($download["platform"] === "linux64") {
            echo $download["url"];
        }
    }
' "$CHROME_MAJOR")
curl -sSL "$DRIVER_URL" -o /tmp/chromedriver.zip
unzip -o /tmp/chromedriver.zip -d /tmp/chromedriver
mkdir -p ./vendor/laravel/dusk/bin
cp -f /tmp/chromedriver/chromedriver-linux64/chromedriver ./vendor/laravel/dusk/bin/chromedriver-linux
chmod 0755 ./vendor/laravel/dusk/bin/chromedriver-linux
