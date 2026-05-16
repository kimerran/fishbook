"use client";

export default function GoogleButton() {
  if (process.env.NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED !== "true") return null;
  return (
    // The proxy route ends in a 302 to Google. Next/Link would soft-navigate,
    // which can't follow a cross-origin server redirect — use a plain <a>.
    // eslint-disable-next-line @next/next/no-html-link-for-pages
    <a
      href="/api/proxy/auth/google/redirect"
      className="glass-sm rounded-full px-6 py-3 label-caps text-on-surface w-full max-w-md text-center block"
    >
      Continue with Google
    </a>
  );
}
