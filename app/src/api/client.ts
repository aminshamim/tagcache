import axios from 'axios';
// Lazy import helpers to avoid circular deps (dynamic require style)
type EventAdder = (e:{type:string; detail:string; ts:number})=>void;
let addEvent: EventAdder | null = null;
export function __bindAddEvent(fn:EventAdder){ addEvent = fn; }

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

// Types
export interface KeyEntry { key: string; size: number; ttl: number | null; tags: string[]; created_ms?: number }

// Convenience helpers (expand as needed)
export async function listKeys(): Promise<KeyEntry[]> {
  const r = await api.get('/keys');
  if(Array.isArray(r.data)) { return r.data as KeyEntry[]; }
  if(r.data && Array.isArray(r.data.keys)) return r.data.keys as KeyEntry[];
  return [];
}

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

api.interceptors.response.use(r => {
  try {
    if(addEvent) {
      const m = r.config.method?.toUpperCase();
      if(m && ['PUT','POST','DELETE'].includes(m)) {
        const path = r.config.url || '';
        addEvent({ type: m, detail: path, ts: Date.now() });
      }
    }
  } catch(_) {}
  return r;
}, err => {
  if (err.response && err.response.status === 401) {
    // TODO: broadcast logout event
  }
  return Promise.reject(err);
});
