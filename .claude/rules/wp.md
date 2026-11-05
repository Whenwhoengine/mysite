# wp/（WordPress 自製程式）規則
- 只修改 `wp/plugins/site-content`、`wp/mu-plugins`、`wp/acf-json`、`wp/config`、`wp/tests`；WordPress 核心與第三方外掛不進 Git。
- REST namespace `site-content/v1`，每個 route 都要有實際檢查的 permission_callback。
- capability 遷移只處理本外掛擁有的能力，不刪其他外掛或管理員權限。
- ACF 只做欄位 UI，不重複註冊 CPT；欄位以 Local JSON 版控。
