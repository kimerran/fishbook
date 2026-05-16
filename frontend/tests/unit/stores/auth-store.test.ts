import { describe, expect, test, beforeEach } from "vitest";
import { useAuthStore } from "@/stores/auth-store";

beforeEach(() => useAuthStore.getState().clear());

describe("useAuthStore", () => {
  test("starts null", () => {
    expect(useAuthStore.getState().user).toBeNull();
  });
  test("set / clear", () => {
    useAuthStore.getState().set({ id: 1, username: "a", email: "a@b.co", is_admin: false });
    expect(useAuthStore.getState().user?.username).toBe("a");
    useAuthStore.getState().clear();
    expect(useAuthStore.getState().user).toBeNull();
  });
});
