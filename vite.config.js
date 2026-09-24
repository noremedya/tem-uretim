import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Değişmez kural: hiçbir CDN/harici kaynak yok; her şey Vite ile derlenir veya paketlerden yerel gelir.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/app/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Geliştirme konteynerinde dışarıdan (tablet/telefon) erişilebilsin.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
