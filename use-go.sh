#!/bin/bash
# Goへ切り替え: nginx→127.0.0.1:8080, isu-go起動。事前に go-port ブランチで `cd webapp/golang && go build -o app` 推奨。
set -e
sudo cp /etc/nginx/sites-available/isucon.conf.go /etc/nginx/sites-available/isucon.conf
sudo nginx -t
sudo systemctl enable isu-go >/dev/null 2>&1 || true
sudo systemctl restart isu-go
sudo systemctl reload nginx
echo "[switched to GO] nginx→:8080 / isu-go=$(systemctl is-active isu-go) / php-fpm=$(systemctl is-active php8.3-fpm)"
