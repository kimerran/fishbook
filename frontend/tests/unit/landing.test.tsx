import { render, screen } from "@testing-library/react";
import { expect, test } from "vitest";
import Landing from "@/app/page";

test("renders the brand tagline as the page H1", () => {
  render(<Landing />);
  expect(
    screen.getByRole("heading", { level: 1, name: /Your Zen Sanctuary, Powered by Code/i }),
  ).toBeInTheDocument();
});

test("renders sign-in and create-account links", () => {
  render(<Landing />);
  expect(screen.getByRole("link", { name: /sign in/i })).toHaveAttribute(
    "href",
    "/login",
  );
  expect(screen.getByRole("link", { name: /create account/i })).toHaveAttribute(
    "href",
    "/register",
  );
});
