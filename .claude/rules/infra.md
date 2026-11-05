# infra/ 與 scripts/ 規則
- 版本、digest、hash 只寫入 `infra/versions.env` 並在 `docs/版本查核.md` 記來源。
- 映像只由 `scripts/lib/build.sh` 建置；Compose 只引用映像。
- 腳本必須有 `--help`、參數驗證、有界 timeout，遵守 exit code 契約（0/10/20/21/30/40/50）。
- 禁止 `down -v`、全域 prune、`migrate:fresh` 用於 staging／production；不在 up／session-start 藏 reset。
