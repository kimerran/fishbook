"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { ClaimUsernameSchema } from "@/lib/auth/schemas";

type FormValues = { username: string };

export default function OnboardingUsername() {
  const router = useRouter();
  const mutation = useMutation({
    mutationFn: async (input: FormValues) => {
      const res = await fetch("/api/proxy/auth/claim-username", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(input),
      });
      if (!res.ok) throw new Error("nope");
    },
    onSuccess: () => router.push("/fish"),
  });

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(ClaimUsernameSchema),
  });

  return (
    <main className="min-h-screen flex flex-col items-center justify-center gap-6 px-4">
      <h1 className="text-headline-lg font-light">Pick a username</h1>
      <form
        onSubmit={handleSubmit((v) => mutation.mutate(v))}
        className="glass-md rounded-xl p-8 w-full max-w-md space-y-4"
      >
        <input
          {...register("username")}
          className="w-full px-3 py-2 rounded-lg bg-surface-container-low"
        />
        {errors.username && <p className="text-error text-sm">{errors.username.message}</p>}
        {mutation.isError && (
          <p className="text-error text-sm">That username is taken or invalid.</p>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="w-full rounded-full bg-primary text-on-primary py-3 label-caps disabled:opacity-50"
        >
          Save
        </button>
      </form>
    </main>
  );
}
