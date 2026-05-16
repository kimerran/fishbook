import { describe, expect, test } from "vitest";
import { RegisterSchema, LoginSchema } from "@/lib/auth/schemas";

describe("RegisterSchema", () => {
  test("accepts a valid payload", () => {
    const r = RegisterSchema.safeParse({
      username: "alice_99",
      email: "a@b.co",
      password: "long-enough-pw",
      password_confirmation: "long-enough-pw",
    });
    expect(r.success).toBe(true);
  });

  test("rejects mismatched confirmation", () => {
    const r = RegisterSchema.safeParse({
      username: "alice_99",
      email: "a@b.co",
      password: "long-enough-pw",
      password_confirmation: "different",
    });
    expect(r.success).toBe(false);
  });

  test("rejects short password", () => {
    const r = RegisterSchema.safeParse({
      username: "alice_99",
      email: "a@b.co",
      password: "short",
      password_confirmation: "short",
    });
    expect(r.success).toBe(false);
  });

  test("rejects bad username regex", () => {
    const r = RegisterSchema.safeParse({
      username: "spaces here",
      email: "a@b.co",
      password: "long-enough-pw",
      password_confirmation: "long-enough-pw",
    });
    expect(r.success).toBe(false);
  });
});

describe("LoginSchema", () => {
  test("rejects empty fields", () => {
    expect(LoginSchema.safeParse({ username: "", password: "" }).success).toBe(false);
  });
});
