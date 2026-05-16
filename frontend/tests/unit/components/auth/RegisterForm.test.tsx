import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, expect, test, vi } from "vitest";
import RegisterForm from "@/components/auth/RegisterForm";

const renderForm = () =>
  render(
    <QueryClientProvider client={new QueryClient()}>
      <RegisterForm onSuccess={() => {}} />
    </QueryClientProvider>,
  );

describe("RegisterForm", () => {
  test("shows validation errors and does not call fetch on invalid submit", async () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal("fetch", fetchSpy);
    renderForm();
    fireEvent.click(screen.getByRole("button", { name: /create account/i }));
    await waitFor(() => {
      expect(screen.getByText(/3.{1,3}32 chars/i)).toBeInTheDocument();
    });
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
