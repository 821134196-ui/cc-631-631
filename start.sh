#!/usr/bin/env bash
#
# 一条命令启动：连锁商户营业时间临时调整与渠道同步系统
#
#   bash start.sh            启动（默认 http://127.0.0.1:8000）
#   PORT=8080 bash start.sh  指定端口
#
# 脚本会自动：准备 PHP 环境 → 安装后端依赖 → 构建前端 → 初始化 SQLite → 启动服务。
set -euo pipefail
cd "$(dirname "$0")"

# ── PHP：优先使用仓库内置的静态编译 PHP（无需 root），否则回退到系统 php ──
if [ -x "$PWD/.tools/php" ]; then
    PHP="$PWD/.tools/php"
else
    PHP="$(command -v php || true)"
    [ -n "$PHP" ] || { echo "未找到 PHP，请安装 PHP 8.2+ 或恢复 .tools/php"; exit 1; }
fi

if [ -f "$PWD/.tools/composer.phar" ]; then
    COMPOSER="$PWD/.tools/composer.phar"
elif command -v composer >/dev/null 2>&1; then
    COMPOSER="$(command -v composer)"
else
    echo "未找到 Composer"; exit 1
fi

echo "▶ PHP: $($PHP -v | head -1)"

# ── 环境文件与 SQLite 数据库文件 ──
[ -f .env ] || cp .env.example .env
mkdir -p database
[ -f database/database.sqlite ] || touch database/database.sqlite

# ── 后端依赖 ──
if [ ! -d vendor ]; then
    echo "▶ 安装 PHP 依赖…"
    "$PHP" "$COMPOSER" install --no-interaction --quiet
fi
grep -q '^APP_KEY=base64:.\+' .env 2>/dev/null || "$PHP" artisan key:generate --force --quiet

# ── 前端依赖与构建 ──
if [ ! -d node_modules ]; then
    echo "▶ 安装前端依赖…"
    npm install --silent
fi
if [ ! -f public/build/manifest.json ]; then
    echo "▶ 构建前端…"
    npm run build
fi

# ── 数据库迁移与演示数据 ──
echo "▶ 初始化数据库…"
"$PHP" artisan migrate --seed --force --quiet

PORT="${PORT:-8000}"
echo ""
echo "✔ 系统已启动：  http://127.0.0.1:${PORT}"
echo "  验证场景测试： $PHP artisan test"
echo "  （可选）再开一个终端运行 $PHP artisan schedule:work ，延迟回执与到期恢复将每分钟自动执行"
echo ""
exec "$PHP" artisan serve --host=127.0.0.1 --port="$PORT"
