import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  test: {
    environment: "happy-dom",
    globals: false,
    setupFiles: ["./tests/setup.ts"],
    include: ["tests/**/*.test.{ts,tsx}", "src/**/*.test.{ts,tsx}"],
    coverage: {
      provider: "v8",
      reporter: ["text", "lcov"],
      include: ["src/**/*.{ts,tsx}"],
      exclude: [
        "src/lib/api-client/**",
        "src/**/*.test.{ts,tsx}",
        "src/middleware.ts",
        "src/app/**/layout.tsx",
        "src/app/**/page.tsx",
        "src/app/api/health/**",
      ],
      thresholds: { statements: 70, lines: 70, functions: 70, branches: 60 },
    },
  },
});
