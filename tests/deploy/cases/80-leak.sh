#!/usr/bin/env bash
# 秘密外洩檢查（DAT-02、DAT-05）：掃描前面所有測試的輸出、log、主機紀錄與 jsonl，不得出現任何產生過的秘密或私鑰內容。
# shellcheck shell=bash

l01_no_leak() {
  local vals="$T_ROOT/secret-values.txt" files="$T_ROOT/scan-files.txt" n_vals n_files hits
  : > "$vals"
  # 1. 各臨時主機秘密目錄中的每一行值（含輪替暫存）；只取長度 ≥ 16 的值避免誤判
  find "$T_ROOT/hosts" "$T_ROOT/bh" -path '*/secrets/*' -type f 2>/dev/null | while IFS= read -r f; do
    case "$f" in */ca/ca.crt|*internal_ca_cert|*wp_internal_tls_cert) continue ;; esac
    grep -vE -- '-----(BEGIN|END)' "$f" | sed -E 's/^[A-Z_]+=//; s/^k[0-9]+ ([0-9a-f]+) .*/\1/' | awk 'length($0) >= 16'
  done >> "$vals"
  # 2. 臨時 Ed25519 私鑰本體
  for f in "$KEYS"/*.pem; do [[ "$f" == *.pub.pem ]] && continue; grep -vE -- '-----' "$f" >> "$vals"; done
  sort -u -o "$vals" "$vals"
  n_vals="$(wc -l < "$vals")"
  # 3. 掃描對象：腳本輸出、替身 log、主機事件／備份／輪替／還原紀錄、發布歷史、測試 log、結果 jsonl（排除秘密目錄本身與替身收到的 curl 設定）
  find "$T_ROOT" -type f \( -name '*.out' -o -name '*.log' -o -name '*.jsonl' -o -name '*.json' -o -name 'compose.env*' \) \
    ! -path '*/secrets/*' ! -name 'curl-last-config' ! -path "$T_ROOT/keys/*" > "$files"
  printf '%s\n' "$results" >> "$files"
  n_files="$(wc -l < "$files")"
  (( n_vals > 20 && n_files > 20 )) || { fail "掃描樣本不足（值 ${n_vals}、檔案 ${n_files}）"; return 1; }
  hits="$(xargs -d '\n' grep -lF -f "$vals" < "$files" 2>/dev/null | head -n 5)"
  [[ -z "$hits" ]] || { fail "秘密值出現在：$(printf '%s ' $hits | sed "s#$T_ROOT/##g")"; return 1; }
  ! xargs -d '\n' grep -l -- '-----BEGIN [A-Z ]*PRIVATE KEY-----' < "$files" 2>/dev/null | grep -q . || { fail "輸出中出現私鑰"; return 1; }
  echo "OK: ${n_vals} 個測試秘密值／私鑰行在 ${n_files} 個輸出與紀錄檔中均未出現"
}

case_add L01_no_secret_in_outputs_or_records DAT-02,DAT-05 l01_no_leak
