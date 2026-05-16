import type { SessionOptions } from "iron-session";

export type SessionData = {
  token?: string;
  user?: {
    id: number;
    username: string;
    email: string;
    is_admin: boolean;
  };
};

export const sessionOptions: SessionOptions = {
  password: process.env.SESSION_COOKIE_SECRET ?? "",
  cookieName: process.env.SESSION_COOKIE_NAME ?? "fishbook_session",
  cookieOptions: {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
  },
};
