import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { fileURLToPath, URL } from "node:url";

/* Builds the React island as a single IIFE bundle + one stylesheet, emitted
 * straight into the PHP app's asset dir. Not a SPA of its own: index.php stays
 * the shell, router.js stays the router, and this bundle only owns the routes
 * listed in src/routes/index.ts.
 *
 * Fixed filenames (no content hash) because index.php cache-busts every asset
 * with ?v=SPLITTY_ASSET_VER already. */
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { "@": fileURLToPath(new URL("./src", import.meta.url)) },
  },
  build: {
    outDir: fileURLToPath(new URL("../assets/react", import.meta.url)),
    emptyOutDir: true,
    target: "es2022",
    cssCodeSplit: false,
    rollupOptions: {
      input: fileURLToPath(new URL("./src/main.tsx", import.meta.url)),
      output: {
        format: "iife",
        entryFileNames: "cardify-react.js",
        assetFileNames: "cardify-react.[ext]",
        inlineDynamicImports: true,
      },
    },
  },
});
