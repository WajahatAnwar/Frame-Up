import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

export default defineConfig({
  build: {
    outDir: fileURLToPath(new URL('./public/frame-up-preview', import.meta.url)),
    emptyOutDir: false,
    copyPublicDir: false,
    lib: {
      entry: fileURLToPath(new URL('./resources/js/StorefrontPreview/preview-webgl.js', import.meta.url)),
      formats: ['es'],
      fileName: () => 'preview-webgl.js',
    },
    minify: true,
  },
});
