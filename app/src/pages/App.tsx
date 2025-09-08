import { Routes, Route, NavLink } from 'react-router-dom';
import { useState } from 'react';
import { api, setAuthToken } from '../api/client';
import { useAuthStore } from '../store/auth';
import SearchPage from './SearchPage';
import PutPage from './PutPage';
import TagsPage from './TagsPage';
import StatsPage from './StatsPage';
import EventsPage from './EventsPage';

export default function App() {
  const { token, login, logout, username } = useAuthStore();
  const [u,setU] = useState('');
  const [p,setP] = useState('');
  const [err,setErr] = useState<string|null>(null);

  async function doLogin(e?:React.FormEvent) {
    e?.preventDefault(); setErr(null);
    try {
      // basic auth header for login, backend also expects JSON body
      const basic = btoa(`${u}:${p}`);
      const resp = await api.post('/auth/login', { username: u, password: p }, { headers: { Authorization: `Basic ${basic}` } });
      const t = resp.data.token;
      setAuthToken(t);
      login(u, t);
    } catch(e:any) { setErr(e?.response?.data?.error || e.message); }
  }

  function doLogout() { setAuthToken(null); logout(); }

  if(!token) {
    return (
      <div className="h-full flex items-center justify-center p-4">
        <form onSubmit={doLogin} className="border rounded p-6 w-full max-w-sm space-y-4 bg-white dark:bg-gray-900">
          <h1 className="text-lg font-semibold">Login</h1>
          <div>
            <label className="block text-xs font-semibold mb-1">Username</label>
            <input className="w-full border rounded px-2 py-1 bg-transparent" value={u} onChange={e=>setU(e.target.value)} />
          </div>
            <div>
            <label className="block text-xs font-semibold mb-1">Password</label>
            <input type="password" className="w-full border rounded px-2 py-1 bg-transparent" value={p} onChange={e=>setP(e.target.value)} />
          </div>
          {err && <div className="text-red-600 text-xs">{err}</div>}
          <button type="submit" className="w-full bg-blue-600 text-white rounded py-1">Login</button>
        </form>
      </div>
    );
  }

  return (
    <div className="flex h-full">
      <aside className="w-56 border-r p-4 space-y-2 text-sm">
        <div className="font-bold mb-4">TagCache</div>
        <nav className="flex flex-col gap-1">
          {[
            ['/', 'Search'],
            ['/put', 'Put/Update'],
            ['/tags', 'Tags'],
            ['/stats', 'Stats'],
            ['/events', 'Events'],
          ].map(([to, label]) => (
            <NavLink key={to} to={to} end className={({isActive}) => `px-2 py-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700 ${isActive ? 'bg-gray-300 dark:bg-gray-600' : ''}`}>{label}</NavLink>
          ))}
        </nav>
        <div className="pt-4 text-xs space-y-1">
          <div className="truncate">{username}</div>
          <button onClick={doLogout} className="text-red-600 underline">Logout</button>
        </div>
      </aside>
      <main className="flex-1 p-4 overflow-auto">
        <Routes>
          <Route path="/" element={<SearchPage />} />
          <Route path="/put" element={<PutPage />} />
          <Route path="/tags" element={<TagsPage />} />
          <Route path="/stats" element={<StatsPage />} />
          <Route path="/events" element={<EventsPage />} />
        </Routes>
      </main>
    </div>
  );
}
