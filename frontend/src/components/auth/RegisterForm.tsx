"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { RegisterSchema, type RegisterInput } from "@/lib/auth/schemas";
import { useRegisterMutation } from "@/lib/api/hooks";

export default function RegisterForm({ onSuccess }: { onSuccess: () => void }) {
  const mutation = useRegisterMutation();
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<RegisterInput>({ resolver: zodResolver(RegisterSchema) });

  const onSubmit = handleSubmit(async (values) => {
    try {
      await mutation.mutateAsync(values);
      onSuccess();
    } catch {
      /* surfaced through mutation.isError */
    }
  });

  return (
    <form onSubmit={onSubmit} className="glass-md rounded-xl p-8 w-full max-w-md space-y-4">
      <div>
        <label className="label-caps text-on-surface-variant">Username</label>
        <input
          {...register("username")}
          className="w-full mt-1 px-3 py-2 rounded-lg bg-surface-container-low text-on-surface"
          autoComplete="username"
        />
        {errors.username && <p className="text-error text-sm mt-1">{errors.username.message}</p>}
      </div>

      <div>
        <label className="label-caps text-on-surface-variant">Email</label>
        <input
          type="email"
          {...register("email")}
          className="w-full mt-1 px-3 py-2 rounded-lg bg-surface-container-low"
          autoComplete="email"
        />
        {errors.email && <p className="text-error text-sm mt-1">{errors.email.message}</p>}
      </div>

      <div>
        <label className="label-caps text-on-surface-variant">Password</label>
        <input
          type="password"
          {...register("password")}
          className="w-full mt-1 px-3 py-2 rounded-lg bg-surface-container-low"
          autoComplete="new-password"
        />
        {errors.password && <p className="text-error text-sm mt-1">{errors.password.message}</p>}
      </div>

      <div>
        <label className="label-caps text-on-surface-variant">Confirm password</label>
        <input
          type="password"
          {...register("password_confirmation")}
          className="w-full mt-1 px-3 py-2 rounded-lg bg-surface-container-low"
          autoComplete="new-password"
        />
        {errors.password_confirmation && (
          <p className="text-error text-sm mt-1">{errors.password_confirmation.message}</p>
        )}
      </div>

      {mutation.isError && (
        <p className="text-error text-sm">
          Could not create account. Please check your details.
        </p>
      )}

      <button
        type="submit"
        disabled={isSubmitting || mutation.isPending}
        className="w-full rounded-full bg-primary text-on-primary py-3 label-caps disabled:opacity-50"
      >
        Create account
      </button>
    </form>
  );
}
