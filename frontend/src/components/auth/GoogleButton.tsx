"use client";

export default function GoogleButton() {
  if (process.env.NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED !== "true") return null;
  return (
    <a
      href="/api/proxy/auth/google/redirect"
      className="glass-sm rounded-full px-6 py-3 label-caps text-on-surface w-full max-w-md text-center block"
    >
      Continue with Google
    </a>
  );
}
