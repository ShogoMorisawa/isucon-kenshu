#!/bin/bash
# PHPへ戻す: nginx→fastcgi9000, php-fpm起動, isu-go停止。事前に `git checkout main` でindex.phpを最良版に。
set -e
sudo cp /etc/nginx/sites-available/isucon.conf.php /etc/nginx/sites-available/isucon.conf
sudo nginx -t
sudo systemctl enable php8.3-fpm >/dev/null 2>&1 || true
sudo systemctl restart php8.3-fpm
sudo systemctl disable isu-go >/dev/null 2>&1 || true
sudo systemctl stop isu-go || true
sudo systemctl reload nginx
echo "[switched to PHP] nginx→fastcgi9000 / php-fpm=$(systemctl is-active php8.3-fpm) / isu-go=$(systemctl is-active isu-go)"
