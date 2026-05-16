// @vitest-environment node
import { describe, expect, test, vi } from "vitest";

const destroyMock = vi.fn();

vi.mock("iron-session", () => ({
  getIronSession: vi.fn(async () => ({
    destroy: destroyMock,
  })),
}));

vi.mock("next/headers", () => ({
  cookies: vi.fn(async () => ({})),
}));

describe("POST /api/auth/clear-cookie", () => {
  test("destroys the session and 204s", async () => {
    const { POST } = await import("@/app/api/auth/clear-cookie/route");
    const res = await POST();
    expect(res.status).toBe(204);
    expect(destroyMock).toHaveBeenCalled();
  });
});
