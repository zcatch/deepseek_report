import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

// dev 时把 /api 代理到本地 PHP 后端（php -S 127.0.0.1:8000 -t site）
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 3210,
    proxy: {
      "/api": "http://127.0.0.1:8000",
    },
  },
  build: {
    outDir: "dist",
    chunkSizeWarningLimit: 1500,
  },
});
