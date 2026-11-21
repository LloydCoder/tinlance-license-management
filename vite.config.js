import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        emptyOutDir: true,
        outDir: 'public/build',
        rollupOptions: {
            input: 'resources/js/passkeys.js',
            output: {
                entryFileNames: 'passkeys.js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
