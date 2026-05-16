"use client";

import { useRouter } from "next/navigation";
import RegisterForm from "@/components/auth/RegisterForm";
import GoogleButton from "@/components/auth/GoogleButton";

export default function RegisterPage() {
  const router = useRouter();
  return (
    <main className="min-h-screen flex flex-col items-center justify-center gap-6 px-4">
      <h1 className="text-headline-lg font-light text-on-surface">Create your account</h1>
      <RegisterForm onSuccess={() => router.push("/fish")} />
      <GoogleButton />
    </main>
  );
}
