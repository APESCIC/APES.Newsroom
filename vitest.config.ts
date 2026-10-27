import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        setupFiles: ['./resources/js/test/setup.ts'],
        exclude: ['**/node_modules/**', 'tests/e2e/**'],
        testTimeout: 15_000,
    },
});
