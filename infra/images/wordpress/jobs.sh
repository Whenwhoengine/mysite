#!/usr/bin/env bash
# wp-jobs：零訪客也會執行的 WordPress 排程與內容事件投遞（不依賴訪客觸發 WP-Cron）。
# 每輪：到期 cron 事件 → outbox 投遞 → 對帳（每分鐘）；flock 防重入，心跳檔供健康檢查。
set -Euo pipefail

interval="${WP_JOBS_INTERVAL_SECONDS:-30}"     # 暫定值：每 30 秒一輪
heartbeat_dir="/tmp/mysite-wp-jobs"
mkdir -p "$heartbeat_dir"
lock="$heartbeat_dir/jobs.lock"

log() {
  printf '{"timestamp":"%s","service":"wp-jobs","environment":"%s","level":"%s","event":"%s"}\n' \
    "$(date -u +%FT%TZ)" "${WP_ENVIRONMENT_TYPE:-unknown}" "$1" "$2"
}

wait_ready() {
  local tries=0
  until wp --skip-plugins --skip-themes core is-installed >/dev/null 2>&1; do
    tries=$((tries + 1))
    if (( tries > 120 )); then log error wp_not_installed_timeout; return 1; fi
    sleep 5
  done
}

wait_ready || exit 20
log info wp_jobs_started

trap 'log info wp_jobs_stopping; exit 0' TERM INT

while true; do
  (
    flock -n 9 || { log warn wp_jobs_overlap_skipped; exit 0; }
    rc=0
    timeout 120 wp cron event run --due-now --quiet || { rc=$?; log error cron_run_failed; }
    # 排程補救：WP 的 cron 陣列整包讀寫，排程與執行同時發生時新事件可能遺失；補排缺少的事件、補發已到期內容（WP-07）
    timeout 120 wp site-content schedule sweep --quiet || { rc=$?; log error schedule_sweep_failed; }
    timeout 120 wp site-content outbox deliver --quiet || { rc=$?; log error outbox_deliver_failed; }
    now=$(date +%s)
    if (( now - $(cat "$heartbeat_dir/last_reconcile" 2>/dev/null || echo 0) >= 60 )); then
      timeout 120 wp site-content reconcile --quiet && date +%s > "$heartbeat_dir/last_reconcile" || { rc=$?; log error reconcile_failed; }
    fi
    if (( rc == 0 )); then date +%s > "$heartbeat_dir/heartbeat"; fi
  ) 9>"$lock"
  sleep "$interval" &
  wait $!
done
