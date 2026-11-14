import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Port 5181 is this project's own Vite port (docs/herd/profile.json), so
// it can run beside other Herd sites on the default 5173.
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
        host: '127.0.0.1',
        port: 5181,
        strictPort: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
