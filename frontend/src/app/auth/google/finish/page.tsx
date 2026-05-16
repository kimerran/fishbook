"use client";

import { Suspense, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";

function GoogleFinishInner() {
  const router = useRouter();
  const sp = useSearchParams();

  useEffect(() => {
    const token = sp.get("token");
    const isNew = sp.get("new") === "1";
    if (!token) {
      router.replace("/login");
      return;
    }

    (async () => {
      // 1) Seal a temporary cookie so the proxy can authenticate /me.
      await fetch("/api/auth/set-cookie", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          token,
          user: { id: 0, username: "_", email: "_", is_admin: false },
        }),
      });
      // 2) Drop the token from the URL.
      window.history.replaceState({}, "", "/auth/google/finish");
      // 3) Pull canonical user.
      const meRes = await fetch("/api/proxy/auth/me");
      if (!meRes.ok) {
        router.replace("/login");
        return;
      }
      const { user } = await meRes.json();
      // 4) Reseal cookie with the real user payload.
      await fetch("/api/auth/set-cookie", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ token, user }),
      });

      router.replace(isNew ? "/onboarding/username" : "/fish");
    })();
  }, [sp, router]);

  return (
    <main className="min-h-screen flex items-center justify-center">
      <p className="label-caps text-on-surface-variant">Signing you in...</p>
    </main>
  );
}

export default function GoogleFinish() {
  return (
    <Suspense
      fallback={
        <main className="min-h-screen flex items-center justify-center">
          <p className="label-caps text-on-surface-variant">Signing you in...</p>
        </main>
      }
    >
      <GoogleFinishInner />
    </Suspense>
  );
}
