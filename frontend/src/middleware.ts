import { NextResponse, type NextRequest } from "next/server";

export const config = {
  matcher: ["/fish/:path*", "/onboarding/:path*"],
};

export function middleware(req: NextRequest) {
  // Edge-friendly presence check. The iron-session cookie is HttpOnly and
  // encrypted; we don't decrypt here — we just gate on its presence as a
  // first line of defense. The proxy / backend still gate on the actual
  // token, so a forged-but-not-decryptable cookie can't access protected
  // resources.
  const cookieName = process.env.SESSION_COOKIE_NAME ?? "fishbook_session";
  const cookie = req.cookies.get(cookieName)?.value;
  if (!cookie) {
    const url = req.nextUrl.clone();
    url.pathname = "/login";
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}
