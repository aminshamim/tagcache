import { create } from 'zustand';

interface AuthState {
  token: string | null;
  username: string | null;
  login: (username: string, token: string) => void;
  logout: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  token: null,
  username: null,
  login: (username, token) => set({ username, token }),
  logout: () => set({ username: null, token: null })
}));
