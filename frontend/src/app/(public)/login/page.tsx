"use client";

import { useRouter } from "next/navigation";
import LoginForm from "@/components/auth/LoginForm";
import GoogleButton from "@/components/auth/GoogleButton";

export default function LoginPage() {
  const router = useRouter();
  return (
    <main className="min-h-screen flex flex-col items-center justify-center gap-6 px-4">
      <h1 className="text-headline-lg font-light text-on-surface">Sign in</h1>
      <LoginForm onSuccess={() => router.push("/fish")} />
      <GoogleButton />
    </main>
  );
}
