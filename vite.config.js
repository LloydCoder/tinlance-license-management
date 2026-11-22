import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        emptyOutDir: true,
        outDir: 'public/build',
        rollupOptions: {
            input: {
                passkeys: 'resources/js/passkeys.js',
                'membership-invitations': 'resources/js/membership-invitations.js',
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
