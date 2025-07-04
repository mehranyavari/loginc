import {defineConfig} from 'vite';
import solidPlugin from 'vite-plugin-solid';

export default defineConfig({
    plugins: [solidPlugin()],
    server: {
        port: 3000,
    },
    build: {
        target: 'es2015',
        sourcemap: false,
        minify: true,
        rollupOptions: {
            output: {
                format: 'iife',
                entryFileNames: 'loginc.js',
            }
        }
    },
    optimizeDeps: {
        exclude: ['js-big-decimal']
    }
});
