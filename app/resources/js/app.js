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

// ---- 內容工作包：影音播放器與圖片失效狀態（漸進增強；JS 失敗時仍有連結與替代文字）----

const YOUTUBE_EMBED_PREFIX = 'https://www.youtube-nocookie.com/embed/';

/** YouTube：點擊後才建立 iframe（不預先連線第三方）。 */
function initYoutubePlayers() {
    document.querySelectorAll('[data-youtube-player]').forEach((player) => {
        const start = player.querySelector('[data-youtube-start]');
        const src = player.dataset.embedSrc || '';
        if (!start || !src.startsWith(YOUTUBE_EMBED_PREFIX)) return;
        start.addEventListener('click', (event) => {
            // 保留以新分頁開啟等修飾鍵行為
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            const frame = player.querySelector('.player__frame');
            if (!frame) return;
            const iframe = document.createElement('iframe');
            iframe.src = src;
            iframe.title = `YouTube 影片播放器：${player.dataset.title || ''}`;
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
            iframe.allowFullscreen = true;
            iframe.referrerPolicy = 'strict-origin-when-cross-origin';
            iframe.className = 'player__iframe';
            frame.replaceChildren(iframe);
            player.classList.add('is-loaded');
            iframe.focus();
        });
    });
}

function markImageBroken(img) {
    const frame = img.closest('.media-frame');
    if (frame) frame.classList.add('is-broken');
}

function markAudioBroken(el) {
    const player = el.closest('[data-audio-player]');
    if (!player) return;
    player.classList.add('is-broken');
    const message = player.querySelector('[data-audio-error]');
    if (message) message.hidden = false;
}

/** 圖片、音訊載入失敗：顯示明確的失效狀態（error 事件不冒泡，以捕獲階段監聽）。 */
function initMediaFallbacks() {
    document.addEventListener('error', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (target.matches('img[data-media-img]')) markImageBroken(target);
        if (target.matches('[data-audio-player] audio, [data-audio-player] source')) markAudioBroken(target);
    }, true);
    // 腳本執行前就已失敗的圖片
    document.querySelectorAll('img[data-media-img]').forEach((img) => {
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) markImageBroken(img);
    });
    document.querySelectorAll('[data-audio-player] audio').forEach((audio) => {
        if (audio.error) markAudioBroken(audio);
    });
}

initYoutubePlayers();
initMediaFallbacks();
