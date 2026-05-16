// @vitest-environment node
import { describe, expect, test, vi } from "vitest";

vi.mock("iron-session", () => ({
  getIronSession: vi.fn(async () => ({
    save: vi.fn(),
    destroy: vi.fn(),
    token: undefined,
    user: undefined,
  })),
}));

vi.mock("next/headers", () => ({
  cookies: vi.fn(async () => ({})),
}));

describe("POST /api/auth/set-cookie", () => {
  test("rejects cross-origin requests", async () => {
    process.env.NEXT_PUBLIC_APP_URL = "http://localhost:3000";
    const { POST } = await import("@/app/api/auth/set-cookie/route");
    const req = new Request("http://localhost:3000/api/auth/set-cookie", {
      method: "POST",
      headers: { origin: "http://evil.example", "content-type": "application/json" },
      body: JSON.stringify({ token: "t", user: {} }),
    });
    const res = await POST(req);
    expect(res.status).toBe(403);
  });

  test("accepts same-origin POST and 204s", async () => {
    process.env.NEXT_PUBLIC_APP_URL = "http://localhost:3000";
    const { POST } = await import("@/app/api/auth/set-cookie/route");
    const req = new Request("http://localhost:3000/api/auth/set-cookie", {
      method: "POST",
      headers: { origin: "http://localhost:3000", "content-type": "application/json" },
      body: JSON.stringify({
        token: "t",
        user: { id: 1, username: "a", email: "a@b.co", is_admin: false },
      }),
    });
    const res = await POST(req);
    expect(res.status).toBe(204);
  });
});
