"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { AuthUser } from "@/stores/auth-store";

type ApiError = Error & { status?: number; body?: unknown };

async function jsonOrThrow(res: Response) {
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    const err = new Error("Request failed") as ApiError;
    err.status = res.status;
    err.body = body;
    throw err;
  }
  return res.json();
}

async function postJson(path: string, body: unknown) {
  return jsonOrThrow(
    await fetch(path, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    }),
  );
}

export function useMeQuery() {
  return useQuery({
    queryKey: ["auth", "me"],
    queryFn: async () => {
      const res = await fetch("/api/proxy/auth/me");
      if (res.status === 401) return null;
      return (await jsonOrThrow(res)).user as AuthUser;
    },
  });
}

export function useRegisterMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: {
      username: string;
      email: string;
      password: string;
      password_confirmation: string;
    }) => {
      const { user, token } = await postJson("/api/proxy/auth/register", input);
      await postJson("/api/auth/set-cookie", { token, user });
      return user as AuthUser;
    },
    onSuccess: (user) => qc.setQueryData(["auth", "me"], user),
  });
}

export function useLoginMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { username: string; password: string }) => {
      const { user, token } = await postJson("/api/proxy/auth/login", input);
      await postJson("/api/auth/set-cookie", { token, user });
      return user as AuthUser;
    },
    onSuccess: (user) => qc.setQueryData(["auth", "me"], user),
  });
}

export function useLogoutMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      await postJson("/api/auth/clear-cookie", {});
      await fetch("/api/proxy/auth/logout", { method: "POST" });
    },
    onSuccess: () => qc.removeQueries({ queryKey: ["auth"] }),
  });
}
