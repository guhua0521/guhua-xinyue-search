#!/bin/bash
# ============================================================
# 分站域名 Nginx 配置自动生成脚本
# 用法: update-subsite-nginx.sh add <域名> [站点public目录] | remove <域名>
# 由后台开通分站时调用，仅允许合法域名，不影响 pidanss.com 反代
# ============================================================
set -e

ACTION="$1"
DOMAIN="$2"
ROOT="${3:-/var/www/xinyue-search/public}"
NGINX_DIR="/etc/nginx/sites-enabled"

if [ -z "$DOMAIN" ]; then
    echo "域名不能为空"
    exit 1
fi

# 域名合法性校验（仅允许字母数字、点、中划线，且符合域名格式）
if ! echo "$DOMAIN" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$'; then
    echo "域名格式不正确: $DOMAIN"
    exit 1
fi
if [ ${#DOMAIN} -gt 253 ]; then
    echo "域名过长"
    exit 1
fi
case "$ROOT" in
    /var/www/*) ;;
    *) echo "站点目录不合法: $ROOT"; exit 1 ;;
esac

DOMAIN=$(echo "$DOMAIN" | tr 'A-Z' 'a-z')
CONF_FILE="$NGINX_DIR/subsite-$DOMAIN.conf"

gen_conf() {
cat > "$CONF_FILE" <<EOF
# 分站域名自动生成配置: $DOMAIN
server {
    listen 443 ssl;
    server_name $DOMAIN;

    ssl_certificate /etc/letsencrypt/live/pidan.guhua.dpdns.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pidan.guhua.dpdns.org/privkey.pem;

    root $ROOT;
    index index.php index.html;

    client_max_body_size 20m;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120s;
    }

    location /pansou-api/ {
        proxy_pass http://127.0.0.1:8888/;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_read_timeout 60s;
    }

    location / {
        if (!-e \$request_filename) {
            rewrite ^(.*)$ /index.php?s=/\$1 last;
        }
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }
}

server {
    listen 80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}
EOF
}

case "$ACTION" in
    add)
        gen_conf
        ;;
    remove)
        if [ -f "$CONF_FILE" ]; then
            rm -f "$CONF_FILE"
        fi
        ;;
    *)
        echo "无效操作: $ACTION (仅支持 add/remove)"
        exit 1
        ;;
esac

# 测试配置，失败则回滚
if ! nginx -t > /dev/null 2>&1; then
    if [ "$ACTION" = "add" ] && [ -f "$CONF_FILE" ]; then
        rm -f "$CONF_FILE"
    fi
    echo "Nginx配置测试失败，已回滚"
    exit 1
fi

systemctl reload nginx
echo "OK"
