import { cookies } from "next/headers";
import { getIronSession } from "iron-session";
import { sessionOptions, type SessionData } from "@/lib/session";

type RouteContext = { params: Promise<{ path: string[] }> };

async function handle(req: Request, ctx: RouteContext): Promise<Response> {
  const { path } = await ctx.params;
  const url = new URL(req.url);
  const target =
    (process.env.BACKEND_INTERNAL_URL ?? "http://backend:8000/api/v1").replace(/\/$/, "") +
    "/" +
    path.join("/") +
    url.search;

  const session = await getIronSession<SessionData>(await cookies(), sessionOptions);

  const headers: Record<string, string> = {};
  for (const [k, v] of req.headers.entries()) {
    if (k.toLowerCase() === "cookie") continue;
    if (k.toLowerCase() === "host") continue;
    headers[k] = v;
  }
  if (session.token) headers.Authorization = `Bearer ${session.token}`;

  // Force Accept: application/json. Browser fetch sends `*/*` by default, which causes
  // Laravel to render ValidationException (and other framework exceptions) as a 302
  // redirect (form-validation default) instead of a JSON error response. This proxy is
  // exclusively for /api/v1 JSON traffic, so it's safe to set unconditionally.
  headers["accept"] = "application/json";

  const init: RequestInit = {
    method: req.method,
    headers,
    body: ["GET", "HEAD"].includes(req.method) ? undefined : await req.arrayBuffer(),
    redirect: "manual",
  };

  const upstream = await fetch(target, init);
  const respHeaders = new Headers(upstream.headers);
  respHeaders.delete("set-cookie"); // never forward backend cookies to the browser

  return new Response(upstream.body, {
    status: upstream.status,
    headers: respHeaders,
  });
}

export { handle as GET, handle as POST, handle as PUT, handle as PATCH, handle as DELETE };
