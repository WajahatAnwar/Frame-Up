#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

for command_name in direnv npm cloudflared; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Missing required command: $command_name" >&2
        exit 1
    fi
done

process_ids=()

cleanup() {
    trap - EXIT INT TERM

    if ((${#process_ids[@]})); then
        echo
        echo "Stopping development services..."
        kill "${process_ids[@]}" 2>/dev/null || true
        wait "${process_ids[@]}" 2>/dev/null || true
    fi
}

trap cleanup EXIT INT TERM

echo "Starting Laravel on http://127.0.0.1:8000..."
direnv exec . php artisan serve \
    --host=127.0.0.1 \
    --port=8000 \
    --no-reload &
process_ids+=("$!")

echo "Starting Vite with tunnel-aware HMR..."
npm run dev &
process_ids+=("$!")

echo "Starting Cloudflare tunnel for https://syedumer.xoarhigh.info..."
cloudflared tunnel run &
process_ids+=("$!")

echo "Development services started. Press Ctrl+C to stop them."

# macOS ships an older Bash without `wait -n`, so monitor each child here.
while true; do
    for process_id in "${process_ids[@]}"; do
        if ! kill -0 "$process_id" 2>/dev/null; then
            wait "$process_id"
            exit $?
        fi
    done

    sleep 1
done
