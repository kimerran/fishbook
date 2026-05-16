import { render, screen } from "@testing-library/react";
import { expect, test } from "vitest";
import Landing from "@/app/page";

test("renders the brand tagline as the page H1", () => {
  render(<Landing />);
  expect(
    screen.getByRole("heading", { level: 1, name: /Your Zen Sanctuary, Powered by Code/i }),
  ).toBeInTheDocument();
});

test("renders disabled sign-in and create-account affordances", () => {
  render(<Landing />);
  const signIn = screen.getByRole("button", { name: /sign in/i });
  const create = screen.getByRole("button", { name: /create account/i });
  expect(signIn).toBeDisabled();
  expect(create).toBeDisabled();
});
