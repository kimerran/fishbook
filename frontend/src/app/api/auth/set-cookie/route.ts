import { cookies } from "next/headers";
import { getIronSession } from "iron-session";
import { sessionOptions, type SessionData } from "@/lib/session";

export async function POST(req: Request): Promise<Response> {
  const origin = req.headers.get("origin") ?? "";
  const allowed = process.env.NEXT_PUBLIC_APP_URL ?? "";
  if (origin !== allowed) {
    return new Response("Forbidden", { status: 403 });
  }

  const body = (await req.json()) as { token?: string; user?: SessionData["user"] };
  if (!body?.token || !body?.user) {
    return new Response("Bad request", { status: 400 });
  }

  const session = await getIronSession<SessionData>(await cookies(), sessionOptions);
  session.token = body.token;
  session.user = body.user;
  await session.save();
  return new Response(null, { status: 204 });
}
