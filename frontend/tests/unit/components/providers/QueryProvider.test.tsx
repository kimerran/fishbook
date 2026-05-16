import { render, screen } from "@testing-library/react";
import { describe, expect, test } from "vitest";
import QueryProvider from "@/components/providers/QueryProvider";

describe("QueryProvider", () => {
  test("renders children inside a QueryClientProvider", () => {
    render(
      <QueryProvider>
        <span>hello</span>
      </QueryProvider>,
    );
    expect(screen.getByText("hello")).toBeInTheDocument();
  });
});
