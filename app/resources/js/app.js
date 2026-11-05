// mysite 前臺漸進增強：JS 失敗時正文與連結仍可使用（SSR 為主）。
document.documentElement.classList.add('js');

function initNavToggle() {
    const nav = document.querySelector('[data-site-nav]');
    const toggle = document.querySelector('[data-site-nav-toggle]');
    if (!nav || !toggle) return;
    nav.dataset.open = 'false';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
        const open = nav.dataset.open !== 'true';
        nav.dataset.open = String(open);
        toggle.setAttribute('aria-expanded', String(open));
    });
}

initNavToggle();
