# 缺陷：gateway 以過期 IP 轉送，主站流量誤入 WordPress（2026-11-05，開發環境）

- **等級**：SEV3（僅開發環境發現；未上線、無資料外洩。若發生在正式環境屬 SEV2：路由錯誤＋可能把主站請求送進 CMS）
- **現象**：重建 app-web／wp-web 後，經 gateway 存取 `/preview/exchange` 得到 WordPress 的 301（`Expires: 1984…`、轉向主站首頁），直連 app-web 則正確回 403。
- **原因**：Nginx 只在啟動時解析 `proxy_pass http://app-web:8080`、`fastcgi_pass app-php:9000` 的主機名稱；容器重建後 IP 改變，舊 IP 被重新分配給另一服務，gateway 仍把流量送往舊 IP。
- **修正**：gateway、app-web、wp-web 全部改用 `resolver 127.0.0.11 valid=10s` 與變數形式的 `proxy_pass`／`fastcgi_pass`，每 10 秒重新解析。
- **驗證**：連續兩次強制重建 app-web 與 wp-web 後，主站預覽交換仍回 403（Laravel）、CMS 健康檢查回 200；doctor 全部通過。
- **一併修正**：Nginx 預設 `main` 存取紀錄會記錄查詢字串（含預覽票券）。gateway、app-web、wp-web 改在每個 server 區塊使用只記 `$uri` 的 JSON 格式；實測帶假票券的請求在三層紀錄皆不出現票券值。
