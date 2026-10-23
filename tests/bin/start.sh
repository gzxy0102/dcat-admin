#!/usr/bin/env bash

cd ./laravel-tests
sudo chmod -R 0755 ./vendor/laravel/dusk/bin/
php artisan serve --port=8300 > /dev/null 2>&1 &
