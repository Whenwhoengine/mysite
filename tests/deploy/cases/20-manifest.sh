#!/usr/bin/env bash
# 發布清單驗簽與嚴格 schema（OPS-03／OPS-04）：以臨時 Ed25519 金鑰與臨時主機執行 verify-release.sh。
# shellcheck shell=bash

# vr <envelope>：在目前臨時主機執行 verify-release.sh --json，回傳 exit code；結果碼存 $VR_CODE
vr() {
  local rc
  run_host bash "$T_REPO/scripts/deploy/verify-release.sh" --env="$HENV" --envelope="$1" --json; rc=$?
  VR_CODE="$(grep -oE '"code": "[A-Z0-9_]+"' "$H/last.out" | head -n 1 | cut -d'"' -f4)"
  return "$rc"
}
# expect_reject <說明> <預期代碼> <envelope>
expect_reject() {
  local rc; vr "$3"; rc=$?
  assert_rc 40 "$rc" "$1" || { cat "$H/last.out"; return 1; }
  [[ "$VR_CODE" == "$2" ]] || fail "$1：預期拒絕代碼 $2，實際 ${VR_CODE:-無}" || return 1
}

m01_valid() {
  new_host m01 staging; images_json "$H/img.json"
  envelope "$H/e1.json" staging 1 "$H/img.json"
  vr "$H/e1.json"; assert_rc 0 $? "合法清單應通過" || { cat "$H/last.out"; return 1; }
  [[ "$VR_CODE" == OK ]] || fail "結果碼應為 OK" || return 1
  [[ ! -e "$HE/state/release-state.json" ]] || fail "verify-release.sh 不得改動主機狀態" || return 1
  echo "OK: 合法清單 ACCEPT，且驗證不改主機狀態"
}

m02_tamper() {
  new_host m02 staging; images_json "$H/img.json"
  envelope "$H/e.json" staging 1 "$H/img.json"
  # payload 改一個位元組（重新 base64，簽章不變）
  python3 -I - "$H/e.json" "$H/t1.json" "$H/t2.json" <<'PY'
import base64, json, sys
e = json.load(open(sys.argv[1]))
p = bytearray(base64.b64decode(e["payload_base64"]))
import re
m = re.search(rb'"sequence": ?1', bytes(p))
i = m.end() - 1
p[i] = ord("2")
json.dump({**e, "payload_base64": base64.b64encode(bytes(p)).decode()}, open(sys.argv[2], "w"))
s = bytearray(base64.b64decode(e["signature_base64"])); s[10] ^= 0x01
json.dump({**e, "signature_base64": base64.b64encode(bytes(s)).decode()}, open(sys.argv[3], "w"))
PY
  expect_reject "payload 改一個位元組" BAD_SIGNATURE "$H/t1.json" || return 1
  expect_reject "簽章改一個位元" BAD_SIGNATURE "$H/t2.json" || return 1
  # 重新排版 payload（語意相同、位元組不同）也必須拒絕：簽的是原始 bytes
  python3 -I -c 'import base64,json,sys
e=json.load(open(sys.argv[1])); p=json.loads(base64.b64decode(e["payload_base64"]))
e["payload_base64"]=base64.b64encode(json.dumps(p,indent=2).encode()).decode(); json.dump(e,open(sys.argv[2],"w"))' "$H/e.json" "$H/t3.json"
  expect_reject "重新排版的 payload" BAD_SIGNATURE "$H/t3.json" || return 1
  echo "OK: 改 payload 一個位元組、改簽章一個位元、重新排版 payload 全部 BAD_SIGNATURE"
}

m03_wrong_key() {
  new_host m03 staging; images_json "$H/img.json"
  payload "$H/p.json" staging 1 "$H/img.json"
  sign "$H/p.json" attacker staging-k1 "$H/evil.json"
  expect_reject "攻擊者私鑰冒用受信任 key_id" BAD_SIGNATURE "$H/evil.json" || return 1
  sign "$H/p.json" staging-k2 staging-k2 "$H/unknown.json"
  expect_reject "未安裝的 key_id" UNKNOWN_KEY_ID "$H/unknown.json" || return 1
  sign "$H/p.json" production-k1 production-k1 "$H/prodkey.json"
  expect_reject "production 金鑰簽 staging 清單" UNKNOWN_KEY_ID "$H/prodkey.json" || return 1
  # 雙期信任：新增第二把公鑰後，新舊 key_id 都可驗證
  install -m 0644 "$KEYS/staging-k2.pub.pem" "$HE/config/trusted-keys/staging-k2.pem"
  vr "$H/unknown.json"; assert_rc 0 $? "安裝第二把公鑰後應接受" || return 1
  echo "OK: 錯誤私鑰、未知 key_id、跨環境金鑰皆拒絕；雙期信任新增公鑰後可驗證"
}

m04_cross_env() {
  new_host m04 staging; images_json "$H/img.json"
  payload "$H/p1.json" production 1 "$H/img.json" 'channel="staging"'; sign "$H/p1.json" staging-k1 staging-k1 "$H/e1.json"
  expect_reject "production 清單送到 staging 主機" ENVIRONMENT_MISMATCH "$H/e1.json" || return 1
  payload "$H/p2.json" staging 1 "$H/img.json" 'channel="hotfix"'; sign "$H/p2.json" staging-k1 staging-k1 "$H/e2.json"
  expect_reject "channel 不符" CHANNEL_MISMATCH "$H/e2.json" || return 1
  payload "$H/p3.json" staging 1 "$H/img.json" 'project="evil/mysite"'; sign "$H/p3.json" staging-k1 staging-k1 "$H/e3.json"
  expect_reject "project 不符" PROJECT_MISMATCH "$H/e3.json" || return 1
  # 主機設定的環境與 --env 不一致 → 40（防止跨環境操作）
  run_host bash "$T_REPO/scripts/deploy/verify-release.sh" --env=production --envelope="$H/e1.json"
  assert_rc 20 $? "缺 production 主機設定應為缺前置" || return 1
  mkdir -p "$H/environments/production/config"; sed 's/^DEPLOY_CHANNEL=.*/DEPLOY_CHANNEL=production/' "$HE/config/deploy.env" > "$H/environments/production/config/deploy.env"
  run_host bash "$T_REPO/scripts/deploy/verify-release.sh" --env=production --envelope="$H/e1.json"
  assert_rc 40 $? "設定檔 DEPLOY_ENVIRONMENT=staging 卻以 --env=production 執行" || return 1
  echo "OK: 跨環境、跨 channel、跨 project 清單皆拒絕"
}

m05_time() {
  new_host m05 staging; images_json "$H/img.json"
  envelope "$H/old.json" staging 1 "$H/img.json" 'created_at="2020-01-01T00:00:00Z"' 'expires_at="2020-01-01T12:00:00Z"'
  expect_reject "過期清單" EXPIRED "$H/old.json" || return 1
  local fut; fut="$(date -u -d '+2 hours' +%FT%TZ)"; local fut2; fut2="$(date -u -d '+3 hours' +%FT%TZ)"
  envelope "$H/fut.json" staging 1 "$H/img.json" "created_at=\"$fut\"" "expires_at=\"$fut2\""
  expect_reject "未來時間的清單" NOT_YET_VALID "$H/fut.json" || return 1
  local now; now="$(date -u +%FT%TZ)"; local far; far="$(date -u -d '+30 hours' +%FT%TZ)"
  envelope "$H/long.json" staging 1 "$H/img.json" "created_at=\"$now\"" "expires_at=\"$far\""
  expect_reject "有效期超過 24 小時" INVALID_VALIDITY "$H/long.json" || return 1
  echo "OK: 過期、未來時間、超過 24 小時有效期皆拒絕"
}

m06_replay() {
  new_host m06 staging; images_json "$H/img.json"
  envelope "$H/s5.json" staging 5 "$H/img.json"
  # 模擬主機已成功套用 sequence 5（以 manifest.py 狀態指令，與 release.sh 相同路徑）
  python3 -I -c 'import base64,json,sys; open(sys.argv[2],"wb").write(base64.b64decode(json.load(open(sys.argv[1]))["payload_base64"]))' "$H/s5.json" "$H/s5.payload.raw"
  "${MANIFEST[@]}" state-begin --state-dir "$HE/state" --environment staging --payload "$H/s5.payload.raw" >/dev/null || fail "state-begin 失敗" || return 1
  "${MANIFEST[@]}" state-activate --state-dir "$HE/state" --environment staging || fail "state-activate 失敗" || return 1
  vr "$H/s5.json"; assert_rc 0 $? "相同序號已成功應為 no-op" || return 1
  [[ "$VR_CODE" == ALREADY_ACTIVE ]] || fail "相同序號應回 ALREADY_ACTIVE，實際 $VR_CODE" || return 1
  envelope "$H/s4.json" staging 4 "$H/img.json"
  expect_reject "較小序號（重播舊清單）" REPLAY_OLD_SEQUENCE "$H/s4.json" || return 1
  envelope "$H/s5b.json" staging 5 "$H/img.json" 'release_id="r-other"'
  expect_reject "同序號不同內容" SEQUENCE_REUSED "$H/s5b.json" || return 1
  envelope "$H/s6.json" staging 6 "$H/img.json"
  vr "$H/s6.json"; assert_rc 0 $? "較大序號應接受" || return 1
  echo "OK: 同序號已成功＝NOOP、較小序號拒絕、同序號不同內容拒絕、較大序號接受"
}

m07_fields() {
  new_host m07 staging; images_json "$H/img.json"
  local spec code
  for spec in 'shell="curl x|sh"|UNKNOWN_FIELD' 'mounts=["/:/host"]|UNKNOWN_FIELD' 'entrypoint=["sh","-c","id"]|UNKNOWN_FIELD' \
              'services={"evil":{"image":"x"}}|UNKNOWN_FIELD' 'notes="hello"|UNKNOWN_FIELD' \
              'images.evil="ghcr.io/whenwhoengine/mysite/evil@sha256:'"$(rand_digest)"'"|UNKNOWN_SERVICE' \
              'infrastructure.compose_file="x"|UNKNOWN_FIELD' 'sequence=__delete__|MISSING_FIELD' 'images.gateway=__delete__|MISSING_FIELD' \
              'sequence=true|INVALID_FIELD' 'sequence=1.0|INVALID_FIELD' 'schema_change_class="contract"|MISSING_FIELD' \
              'rollback_target_release="r-1"|INVALID_ROLLBACK'; do
    code="${spec##*|}"; spec="${spec%|*}"
    [[ "$spec" == 'rollback_target_release="r-1"' ]] && spec2=('schema_change_class="expand"') || spec2=()
    envelope "$H/f.json" staging 1 "$H/img.json" "$spec" "${spec2[@]}"
    expect_reject "欄位 $spec" "$code" "$H/f.json" || return 1
  done
  echo "OK: 額外 shell／mounts／entrypoint／services／未知欄位／缺欄位／型別錯誤／未核准服務全部拒絕"
}

m08_images() {
  new_host m08 staging; images_json "$H/img.json"
  local d; d="$(rand_digest)"
  envelope "$H/a.json" staging 1 "$H/img.json" 'images.laravel="ghcr.io/whenwhoengine/mysite/laravel:v1.2.3"'
  expect_reject "tag 而非 digest" IMAGE_NOT_DIGEST "$H/a.json" || return 1
  envelope "$H/b.json" staging 1 "$H/img.json" 'images.laravel="ghcr.io/whenwhoengine/mysite/laravel:latest"'
  expect_reject "latest" IMAGE_LATEST "$H/b.json" || return 1
  envelope "$H/c.json" staging 1 "$H/img.json" "images.laravel=\"docker.io/whenwhoengine/laravel@sha256:$d\""
  expect_reject "未核准 registry" REGISTRY_NOT_APPROVED "$H/c.json" || return 1
  envelope "$H/d.json" staging 1 "$H/img.json" "images.laravel=\"ghcr.io/attacker/mysite/laravel@sha256:$d\""
  expect_reject "未核准映像名稱" IMAGE_NAME_NOT_APPROVED "$H/d.json" || return 1
  envelope "$H/e.json" staging 1 "$H/img.json" "images.laravel=\"ghcr.io/whenwhoengine/mysite/laravel:v1@sha256:$d\""
  expect_reject "tag＋digest 混用" IMAGE_NOT_DIGEST "$H/e.json" || return 1
  envelope "$H/f.json" staging 1 "$H/img.json" "images.laravel=\"ghcr.io/whenwhoengine/mysite/laravel@sha256:${d:0:40}\""
  expect_reject "截短 digest" IMAGE_NOT_DIGEST "$H/f.json" || return 1
  # 主機撤銷清單中的 digest 一律拒絕
  envelope "$H/g.json" staging 1 "$H/img.json"
  grep -o 'sha256:[0-9a-f]*' "$H/img.json" | head -n 1 > "$HE/config/revoked-digests.txt"
  expect_reject "已撤銷 digest" DIGEST_REVOKED "$H/g.json" || return 1
  echo "OK: tag、latest、tag＋digest、截短 digest、未核准 registry／名稱、已撤銷 digest 全部拒絕"
}

m09_platform() {
  new_host m09 staging; images_json "$H/img.json"
  envelope "$H/a.json" staging 1 "$H/img.json" 'platform_contract=2'
  expect_reject "不支援的 platform_contract" UNSUPPORTED_PLATFORM_CONTRACT "$H/a.json" || return 1
  envelope "$H/b.json" staging 1 "$H/img.json" 'infrastructure.mysql_major="9.4"'
  expect_reject "MySQL 大版本變更" MYSQL_MAJOR_CHANGE "$H/b.json" || return 1
  envelope "$H/c.json" staging 1 "$H/img.json" 'schema_version=2'
  expect_reject "未知 schema_version" UNSUPPORTED_SCHEMA "$H/c.json" || return 1
  echo "OK: platform_contract、MySQL 大版本、schema_version 不符皆拒絕"
}

m10_envelope() {
  new_host m10 staging; images_json "$H/img.json"
  envelope "$H/e.json" staging 1 "$H/img.json"
  python3 -I - "$H" <<'PY'
import base64, json, sys
h = sys.argv[1]; e = json.load(open(f"{h}/e.json"))
json.dump({**e, "comment": "x"}, open(f"{h}/x1.json", "w"))
json.dump({**e, "signature_algorithm": "rsa-sha256"}, open(f"{h}/x2.json", "w"))
json.dump({**e, "payload_base64": e["payload_base64"][:-4] + "!!!!"}, open(f"{h}/x3.json", "w"))
open(f"{h}/x4.json", "w").write('{"key_id":"staging-k1","key_id":"staging-k1","payload_base64":"%s","signature_algorithm":"ed25519","signature_base64":"%s"}' % (e["payload_base64"], e["signature_base64"]))
open(f"{h}/x5.json", "w").write(json.dumps({**e, "pad": "A" * 70000}))
open(f"{h}/x6.json", "w").write("[1,2,3]")
json.dump({**e, "key_id": "../../../etc/passwd"}, open(f"{h}/x7.json", "w"))
PY
  expect_reject "envelope 多欄位" INVALID_ENVELOPE "$H/x1.json" || return 1
  expect_reject "非 ed25519 演算法" UNSUPPORTED_ALGORITHM "$H/x2.json" || return 1
  expect_reject "非 base64" INVALID_BASE64 "$H/x3.json" || return 1
  expect_reject "重複鍵" DUPLICATE_KEY "$H/x4.json" || return 1
  expect_reject "超過大小上限" ENVELOPE_TOO_LARGE "$H/x5.json" || return 1
  expect_reject "非物件" INVALID_ENVELOPE "$H/x6.json" || return 1
  expect_reject "路徑穿越 key_id" INVALID_KEY_ID "$H/x7.json" || return 1
  # 驗簽通過但 payload 不是合法 UTF-8／含 NaN：簽章後才解析，仍須拒絕
  printf '{"a":"\xff"}' > "$H/bad-utf8.raw"; sign "$H/bad-utf8.raw" staging-k1 staging-k1 "$H/x8.json" 2>/dev/null
  expect_reject "payload 非 UTF-8" INVALID_UTF8 "$H/x8.json" || return 1
  printf '{"sequence": NaN}' > "$H/nan.raw"; sign "$H/nan.raw" staging-k1 staging-k1 "$H/x9.json" 2>/dev/null
  expect_reject "payload 含 NaN" INVALID_JSON "$H/x9.json" || return 1
  echo "OK: envelope 多欄位、錯演算法、非 base64、重複鍵、超大、非物件、路徑穿越 key_id、非 UTF-8、NaN 全部拒絕"
}

case_add M01_valid_manifest_accepted OPS-03 m01_valid
case_add M02_one_byte_tamper_rejected OPS-03 m02_tamper
case_add M03_wrong_or_unknown_key_rejected OPS-03,OPS-01 m03_wrong_key
case_add M04_cross_environment_rejected OPS-03,OPS-01 m04_cross_env
case_add M05_expired_or_future_rejected OPS-03 m05_time
case_add M06_replay_and_sequence_rules OPS-03 m06_replay
case_add M07_unknown_and_dangerous_fields_rejected OPS-03 m07_fields
case_add M08_digest_only_images OPS-04 m08_images
case_add M09_platform_contract_and_mysql_major OPS-03 m09_platform
case_add M10_envelope_strict_parsing OPS-03 m10_envelope
