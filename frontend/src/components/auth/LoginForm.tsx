"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { LoginSchema, type LoginInput } from "@/lib/auth/schemas";
import { useLoginMutation } from "@/lib/api/hooks";

export default function LoginForm({ onSuccess }: { onSuccess: () => void }) {
  const mutation = useLoginMutation();
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginInput>({ resolver: zodResolver(LoginSchema) });

  const onSubmit = handleSubmit(async (values) => {
    try {
      await mutation.mutateAsync(values);
      onSuccess();
    } catch {
      /* swallow; banner shown via isError */
    }
  });

  return (
    <form onSubmit={onSubmit} className="glass-md rounded-xl p-8 w-full max-w-md space-y-4">
      <div>
        <label className="label-caps text-on-surface-variant">Username</label>
        <input
          {...register("username")}
          className="w-full mt-1 px-3 py-2 rounded-lg bg-surface-container-low"
          autoComplete="username"
        />
        {errors.username && <p className="text-error text-sm mt-1">{errors.username.message}</p>}
      </div>
      <div>
        <label className="label-caps text-on-surface-variant">Password</label>
        <input
          type="password"
          {...register("password")}
          className="w-full mt-1 px-3 py-2 rounded-lg bg-surface-container-low"
          autoComplete="current-password"
        />
        {errors.password && <p className="text-error text-sm mt-1">{errors.password.message}</p>}
      </div>

      {mutation.isError && (
        <p className="text-error text-sm">Invalid username or password.</p>
      )}

      <button
        type="submit"
        disabled={isSubmitting || mutation.isPending}
        className="w-full rounded-full bg-primary text-on-primary py-3 label-caps disabled:opacity-50"
      >
        Sign in
      </button>
    </form>
  );
}
