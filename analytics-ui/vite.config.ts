import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "node:path";

// Builds a single-bundle island that gets mounted by admin/live-analytics.php.
// Output goes to ../assets/live-analytics/ (under nginx-served /assets/).
export default defineConfig({
  plugins: [react()],
  base: "/assets/live-analytics/",
  build: {
    outDir: resolve(__dirname, "../assets/live-analytics"),
    emptyOutDir: true,
    sourcemap: false,
    rollupOptions: {
      output: {
        entryFileNames: "main.[hash].js",
        chunkFileNames: "chunk.[name].[hash].js",
        assetFileNames: "asset.[name].[hash][extname]",
      },
    },
    manifest: "manifest.json",
  },
});
