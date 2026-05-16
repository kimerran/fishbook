import { create } from "zustand";

export type AuthUser = {
  id: number;
  username: string;
  email: string;
  is_admin: boolean;
};

type AuthStore = {
  user: AuthUser | null;
  set: (u: AuthUser) => void;
  clear: () => void;
};

export const useAuthStore = create<AuthStore>((set) => ({
  user: null,
  set: (u) => set({ user: u }),
  clear: () => set({ user: null }),
}));
