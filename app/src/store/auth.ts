import { create } from 'zustand';

interface AuthState {
  token: string | null;
  username: string | null;
  login: (username: string, token: string) => void;
  logout: () => void;
}

// LocalStorage keys
const KEY_TOKEN = 'tc_auth_token_v1';
const KEY_USER = 'tc_auth_user_v1';

function loadPersisted(): { token: string | null; username: string | null } {
  try {
    const t = localStorage.getItem(KEY_TOKEN);
    const u = localStorage.getItem(KEY_USER);
    if(t && u) return { token: t, username: u };
    return { token: t, username: u };
  } catch { return { token: null, username: null }; }
}

export const useAuthStore = create<AuthState>((set) => {
  const persisted = loadPersisted();
  return {
    token: persisted.token,
    username: persisted.username,
    login: (username, token) => {
      try { localStorage.setItem(KEY_TOKEN, token); localStorage.setItem(KEY_USER, username); } catch {}
      set({ username, token });
    },
    logout: () => {
      try { localStorage.removeItem(KEY_TOKEN); localStorage.removeItem(KEY_USER); } catch {}
      set({ username: null, token: null });
    }
  };
});
