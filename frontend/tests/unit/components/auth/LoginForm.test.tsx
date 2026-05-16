import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, expect, test, vi } from "vitest";
import LoginForm from "@/components/auth/LoginForm";

describe("LoginForm", () => {
  test("requires username and password", async () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal("fetch", fetchSpy);
    render(
      <QueryClientProvider client={new QueryClient()}>
        <LoginForm onSuccess={() => {}} />
      </QueryClientProvider>,
    );
    fireEvent.click(screen.getByRole("button", { name: /sign in/i }));
    await waitFor(() => expect(screen.getAllByText(/required/i).length).toBeGreaterThan(0));
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
