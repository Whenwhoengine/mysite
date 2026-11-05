import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// 原生 CSS／JavaScript；不使用 Tailwind 或外部字型服務（避免額外第三方請求）。
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/admin.css', 'resources/js/admin.js'],
            refresh: true,
        }),
    ],
    build: {
        manifest: 'manifest.json',
        sourcemap: false,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
