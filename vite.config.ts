import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// The Docker node container sets VITE_DEV_HOST and VITE_PORT; host runs keep Vite's defaults.
const devHost = process.env.VITE_DEV_HOST;
const devPort = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        host: devHost ? '0.0.0.0' : undefined,
        port: devPort,
        strictPort: Boolean(devHost),
        origin: devHost ? `http://${devHost}:${devPort}` : undefined,
        hmr: devHost ? { host: devHost, port: devPort } : undefined,
        cors: devHost ? true : undefined,
        watch: {
            // File events from a Windows bind mount do not reach the container, so poll there.
            usePolling: Boolean(devHost),
            interval: 300,
            ignored: devHost
                ? ['**/storage/**', '**/vendor/**', '**/database/*.sqlite*']
                : ['**/storage/framework/views/**'],
        },
    },
});
