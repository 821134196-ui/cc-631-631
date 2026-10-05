#!/usr/bin/env bash
# 一条命令启动：连锁商户营业时间临时调整与渠道同步系统
# 用法: ./start.sh
set -euo pipefail
cd "$(dirname "$0")"

PHP_VERSION="8.4.23"
TOOLS_DIR="$PWD/.tools"

# ── 1. 准备 PHP（优先系统 PHP，否则下载静态编译版本到 .tools/）──────────────
if command -v php >/dev/null 2>&1 && php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' 2>/dev/null; then
    PHP=php
else
    PHP="$TOOLS_DIR/php"
    if [ ! -x "$PHP" ]; then
        echo "==> 未找到可用的 PHP，下载静态编译 PHP $PHP_VERSION ..."
        mkdir -p "$TOOLS_DIR"
        curl -fsSL "https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-linux-x86_64.tar.gz" \
            | tar xz -C "$TOOLS_DIR"
    fi
fi
echo "==> PHP: $($PHP -v | head -1)"

# ── 2. 准备 Composer ────────────────────────────────────────────────────────
if command -v composer >/dev/null 2>&1; then
    COMPOSER=composer
else
    COMPOSER="$TOOLS_DIR/composer"
    [ -f "$COMPOSER" ] || curl -fsSL -o "$COMPOSER" https://getcomposer.org/download/latest-stable/composer.phar
    chmod +x "$COMPOSER"
    COMPOSER="$PHP $COMPOSER"
fi

# ── 3. 依赖、环境、数据库 ───────────────────────────────────────────────────
[ -d vendor ] || $COMPOSER install --no-interaction --quiet
[ -f .env ] || cp .env.example .env
$PHP artisan key:generate --force --quiet
touch database/database.sqlite
[ -d node_modules ] || npm install --no-audit --no-fund --silent
$PHP artisan migrate --seed --force
[ -f public/build/manifest.json ] || npm run build

# ── 4. 启动：Web + 队列(异步回执) + 调度(到期恢复) ──────────────────────────
PORT="${PORT:-8000}"
echo ""
echo "==> 启动完成，打开 http://localhost:${PORT}"
echo "    官网=即时回执 / 店内屏=首次失败需重试 / 外卖平台=延迟异步回执"
echo "    Ctrl+C 停止全部进程"
echo ""

trap 'kill 0' EXIT
$PHP artisan queue:work --sleep=1 --tries=1 &
$PHP artisan schedule:work &
$PHP artisan serve --port="$PORT" &
wait
