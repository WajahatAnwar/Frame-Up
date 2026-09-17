import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = new URL(env.APP_URL || 'http://localhost:8000');
    const isHttps = appUrl.protocol === 'https:';

    return {
        plugins: [
            laravel({
                input: ['resources/js/app.jsx'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            react(),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: appUrl.origin,
            cors: {
                origin: appUrl.origin,
            },
            hmr: isHttps
                ? {
                      protocol: 'wss',
                      host: appUrl.hostname,
                      clientPort: 443,
                      path: '/vite-hmr',
                  }
                : true,
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
