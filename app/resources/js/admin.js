// Laravel 管理後臺腳本（漸進增強）。由後臺工作包擴充。
document.documentElement.classList.add('js');

// 危險操作（停用帳號、取消訂單、停用 2FA）送出前再確認一次；JS 失效時表單照常運作（伺服器端仍有授權與狀態檢查）。
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const message = form.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }
    // 避免重複點擊；真正的重送防護仍由伺服器端冪等鍵與狀態機負責。
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.setAttribute('aria-disabled', 'true');
        window.setTimeout(() => button.removeAttribute('aria-disabled'), 3000);
    });
});
