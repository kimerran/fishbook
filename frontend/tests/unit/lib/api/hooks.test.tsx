import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, expect, test, vi, beforeEach } from "vitest";
import type { ReactNode } from "react";

import {
  useLoginMutation,
  useLogoutMutation,
  useMeQuery,
  useRegisterMutation,
} from "@/lib/api/hooks";

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const USER = { id: 1, username: "alice", email: "a@b.co", is_admin: false };

beforeEach(() => {
  vi.unstubAllGlobals();
});

describe("auth hooks", () => {
  test("useMeQuery returns null on 401", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response(null, { status: 401 })),
    );

    const { result } = renderHook(() => useMeQuery(), { wrapper });
    await waitFor(() => expect(result.current.isPending).toBe(false));
    expect(result.current.data).toBeNull();
  });

  test("useMeQuery returns the user on 200", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify({ user: USER }), {
            status: 200,
            headers: { "content-type": "application/json" },
          }),
      ),
    );

    const { result } = renderHook(() => useMeQuery(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual(USER);
  });

  test("useRegisterMutation posts to proxy then set-cookie", async () => {
    const fetchMock = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url.includes("/api/proxy/auth/register")) {
        return new Response(JSON.stringify({ user: USER, token: "abc" }), {
          status: 201,
          headers: { "content-type": "application/json" },
        });
      }
      return new Response(null, { status: 204 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const { result } = renderHook(() => useRegisterMutation(), { wrapper });
    const user = await result.current.mutateAsync({
      username: "alice",
      email: "a@b.co",
      password: "pw-strong-pass",
      password_confirmation: "pw-strong-pass",
    });
    expect(user).toEqual(USER);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  test("useLoginMutation posts to proxy then set-cookie", async () => {
    const fetchMock = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url.includes("/api/proxy/auth/login")) {
        return new Response(JSON.stringify({ user: USER, token: "abc" }), {
          status: 200,
          headers: { "content-type": "application/json" },
        });
      }
      return new Response(null, { status: 204 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const { result } = renderHook(() => useLoginMutation(), { wrapper });
    const user = await result.current.mutateAsync({
      username: "alice",
      password: "pw-strong-pass",
    });
    expect(user).toEqual(USER);
  });

  test("useLogoutMutation clears cookie then calls backend logout", async () => {
    const calls: string[] = [];
    const fetchMock = vi.fn(async (input: string | URL | Request) => {
      calls.push(String(input));
      return new Response(null, { status: 204 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const { result } = renderHook(() => useLogoutMutation(), { wrapper });
    await result.current.mutateAsync();
    expect(calls[0]).toContain("/api/auth/clear-cookie");
    expect(calls[1]).toContain("/api/proxy/auth/logout");
  });

  test("useRegisterMutation surfaces errors when the backend rejects", async () => {
    const fetchMock = vi.fn(
      async () =>
        new Response(JSON.stringify({ message: "bad" }), {
          status: 422,
          headers: { "content-type": "application/json" },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const { result } = renderHook(() => useRegisterMutation(), { wrapper });
    await expect(
      result.current.mutateAsync({
        username: "x",
        email: "x@y.co",
        password: "pw-strong-pass",
        password_confirmation: "pw-strong-pass",
      }),
    ).rejects.toThrow();
  });
});
