import { z } from "zod";

const usernameRegex = /^[A-Za-z0-9_]{3,32}$/;

export const RegisterSchema = z
  .object({
    username: z.string().regex(usernameRegex, "3-32 chars, letters/digits/underscores."),
    email: z.string().email("Enter a valid email."),
    password: z.string().min(10, "At least 10 characters."),
    password_confirmation: z.string(),
  })
  .refine((d) => d.password === d.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords do not match.",
  });

export type RegisterInput = z.infer<typeof RegisterSchema>;

export const LoginSchema = z.object({
  username: z.string().min(1, "Required."),
  password: z.string().min(1, "Required."),
});

export type LoginInput = z.infer<typeof LoginSchema>;

export const ClaimUsernameSchema = z.object({
  username: z.string().regex(usernameRegex, "3-32 chars, letters/digits/underscores."),
});
