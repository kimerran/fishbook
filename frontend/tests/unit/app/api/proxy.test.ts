// @vitest-environment node
import { describe, expect, test, vi, beforeEach } from "vitest";

const ironSessionMock = vi.fn();
vi.mock("iron-session", () => ({
  getIronSession: (...args: unknown[]) => ironSessionMock(...args),
}));

vi.mock("next/headers", () => ({
  cookies: vi.fn(async () => ({})),
}));

beforeEach(() => {
  process.env.BACKEND_INTERNAL_URL = "http://backend:8000/api/v1";
});

describe("ALL /api/proxy/[...path]", () => {
  test("injects Authorization: Bearer when token is present", async () => {
    ironSessionMock.mockResolvedValue({ token: "tk-123", user: { username: "x" } });

    const fetchMock = vi.fn(
      // eslint-disable-next-line @typescript-eslint/no-unused-vars
      async (_url: string | URL | Request, _init?: RequestInit) =>
        new Response(JSON.stringify({ ok: true }), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const { GET } = await import("@/app/api/proxy/[...path]/route");
    const req = new Request("http://localhost:3000/api/proxy/auth/me", { method: "GET" });
    const res = await GET(req, { params: Promise.resolve({ path: ["auth", "me"] }) });

    expect(res.status).toBe(200);
    expect(fetchMock).toHaveBeenCalled();
    const call = fetchMock.mock.calls[0];
    expect(call).toBeDefined();
    const [calledUrl, calledInit] = call!;
    expect(String(calledUrl)).toBe("http://backend:8000/api/v1/auth/me");
    expect(calledInit?.headers).toMatchObject({
      Authorization: "Bearer tk-123",
    });
  });

  test("forwards without Authorization when no token", async () => {
    ironSessionMock.mockResolvedValue({});
    const fetchMock = vi.fn(
      // eslint-disable-next-line @typescript-eslint/no-unused-vars
      async (_url: string | URL | Request, _init?: RequestInit) =>
        new Response("ok", { status: 200 }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const { GET } = await import("@/app/api/proxy/[...path]/route");
    const req = new Request("http://localhost:3000/api/proxy/health");
    await GET(req, { params: Promise.resolve({ path: ["health"] }) });

    const call = fetchMock.mock.calls[0];
    expect(call).toBeDefined();
    const [, calledInit] = call!;
    expect(calledInit?.headers).not.toHaveProperty("Authorization");
  });
});
