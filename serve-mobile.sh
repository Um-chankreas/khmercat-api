#!/usr/bin/env bash
set -euo pipefail

# Starts the Laravel dev server reachable from your phone on the same
# Wi-Fi, with PHP's upload limits raised so real video uploads work.
#
# Usage: ./serve-mobile.sh [host] [port]
#   Defaults to 192.168.1.235:8000 (matches APP_URL in .env).
#
# Why this script exists instead of just running `php artisan serve`:
#
# 1. `php artisan serve` spawns the actual dev server as a fresh `php -S ...`
#    process and does NOT forward `-d` ini flags to it, and it also drops
#    most environment variables by default — only a small allowlist (see
#    Laravel's ServeCommand::$passthroughVariables) makes it through. On
#    Herd, HERD_PHP_<version>_INI_SCAN_DIR is on that allowlist, so that's
#    the one mechanism that actually reaches the spawned server process.
#
# 2. PHP's built-in server handles ONE request at a time by default. The
#    mobile app continuously streams/scrubs video (every autoplay tick in
#    the feed is a request), so while a video is mid-stream there is zero
#    capacity left to even receive an upload request — it just hangs until
#    the connection times out. `--no-reload` + PHP_CLI_SERVER_WORKERS in
#    .env spins up multiple worker processes so streaming and uploading
#    can actually happen at the same time. Laravel silently ignores
#    PHP_CLI_SERVER_WORKERS without --no-reload, so both are required.

HOST="${1:-192.168.1.235}"
PORT="${2:-8000}"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INI_DIR="$DIR/.dev/php-overrides"

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')"
VAR_NAME="HERD_PHP_${PHP_VERSION}_INI_SCAN_DIR"

# 3. Live updates (comments, notifications) go through Laravel Reverb on
#    port 8080. Without it the app shows "Reconnecting…" and falls back to
#    polling every few seconds. It listens on all interfaces so the phone
#    can reach it at the same IP as the API, and stops with this script.
REVERB_PORT_TO_USE="${REVERB_PORT:-8080}"
echo "Starting Reverb (live updates) on ws://$HOST:$REVERB_PORT_TO_USE"
php artisan reverb:start --host=0.0.0.0 --port="$REVERB_PORT_TO_USE" &
REVERB_PID=$!
trap 'kill "$REVERB_PID" 2>/dev/null || true' EXIT INT TERM

echo "Starting server on http://$HOST:$PORT (upload limits via $VAR_NAME, multi-worker via --no-reload)"
export "$VAR_NAME=$INI_DIR"
php artisan serve --host="$HOST" --port="$PORT" --no-reload
