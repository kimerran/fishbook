import { render, screen } from "@testing-library/react";
import { describe, expect, test, afterEach } from "vitest";
import GoogleButton from "@/components/auth/GoogleButton";

const ORIG = process.env.NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED;

afterEach(() => {
  process.env.NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED = ORIG;
});

describe("GoogleButton", () => {
  test("renders nothing when the flag is off", () => {
    process.env.NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED = "false";
    const { container } = render(<GoogleButton />);
    expect(container).toBeEmptyDOMElement();
  });

  test("renders a link to the proxy when the flag is on", () => {
    process.env.NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED = "true";
    render(<GoogleButton />);
    const link = screen.getByRole("link", { name: /continue with google/i });
    expect(link).toHaveAttribute("href", "/api/proxy/auth/google/redirect");
  });
});
