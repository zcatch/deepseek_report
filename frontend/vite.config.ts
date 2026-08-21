import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

// dev 时把 /api 代理到后端；build 后由 server.ts 直接托管 dist
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      "/api": "http://localhost:3210",
    },
  },
  build: {
    outDir: "dist",
    chunkSizeWarningLimit: 1500,
  },
});
