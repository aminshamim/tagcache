import axios from 'axios';

// Using any cast to satisfy TS in this scaffold; real project should extend ImportMetaEnv interface.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let BASE_URL = (import.meta as any).env.VITE_CACHE_API_BASE as string | undefined;
if (!BASE_URL) {
  // default to current origin (proxy in dev handles forwarding)
  BASE_URL = window.location.origin;
}

export const api = axios.create({
  baseURL: BASE_URL,
  timeout: 10000,
  headers: { 'Accept': 'application/json' }
});

// Simple auth token in-memory (non-persistent)
let authToken: string | null = null;
export function setAuthToken(t: string | null) { authToken = t; }

api.interceptors.request.use(cfg => {
  if (authToken) {
    cfg.headers = cfg.headers || {};
    cfg.headers['Authorization'] = `Bearer ${authToken}`;
  }
  return cfg;
});

api.interceptors.response.use(r => r, err => {
  if (err.response && err.response.status === 401) {
    // TODO: broadcast logout event
  }
  return Promise.reject(err);
});
