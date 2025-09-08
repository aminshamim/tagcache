import { Routes, Route, NavLink, useNavigate } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { api, setAuthToken } from '../api/client';
import { useAuthStore } from '../store/auth';
import SearchPage from './SearchPage';
import PutPage from './PutPage';
import TagsPage from './TagsPage';
import StatsPage from './StatsPage';
import EventsPage from './EventsPage';
import DashboardPage from './DashboardPage';
import SettingsPage from './SettingsPage';
import { RightPanel } from '../components/RightPanel';

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

  const navigate = useNavigate();

  // Keyboard shortcuts
  useEffect(()=>{
    function onKey(e:KeyboardEvent){
      if(e.key==='/' && !e.metaKey){ e.preventDefault(); const el=document.getElementById('global-search'); el?.focus(); }
      if(e.key==='g'){ (window as any)._gKeyTime=Date.now(); }
      if(e.key==='s'){ if((window as any)._gKeyTime && Date.now() - (window as any)._gKeyTime < 600){ navigate('/stats'); } }
      if(e.key==='i'){ navigate('/put'); }
    }
    window.addEventListener('keydown', onKey); return ()=>window.removeEventListener('keydown', onKey);
  },[navigate]);

  if(!token) {
    return (
      <div className="h-full flex items-center justify-center p-4 bg-gray-50">
        <form onSubmit={doLogin} className="bg-white rounded-xl shadow-lg p-8 w-full max-w-sm space-y-5">
          <div className="text-center">
            <div className="w-16 h-16 rounded-xl flex items-center justify-center mx-auto mb-4 bg-gradient-to-br from-brand-primary to-brand-teal">
              <img src="/logo.png" alt="Tag Cache Logo" className="w-12 h-12 object-contain" loading="lazy" />
            </div>
            <h1 className="text-2xl font-bold text-gray-800">Tag Cache</h1>
            <p className="text-sm text-gray-500 mt-1">Sign in to your account</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Username</label>
            <input className="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-primary/20 focus:border-brand-primary" value={u} onChange={e=>setU(e.target.value)} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Password</label>
            <input type="password" className="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-primary/20 focus:border-brand-primary" value={p} onChange={e=>setP(e.target.value)} />
          </div>
          {err && <div className="text-red-600 text-sm bg-red-50 rounded-lg p-3">{err}</div>}
          <button type="submit" className="w-full bg-brand-primary text-white rounded-lg py-3 font-medium hover:bg-brand-primaryDark transition-colors">Sign In</button>
        </form>
      </div>
    );
  }

  return (
    <div className="flex h-full">
      <aside className="w-20 flex flex-col items-center py-6 gap-6 bg-brand-primary text-white">
        <button onClick={()=>navigate('/dashboard')} className="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center overflow-hidden group" aria-label="Tag Cache Home">
          <img src="/logo.png" alt="Tag Cache" className="w-10 h-10 object-contain transition-transform group-hover:scale-110" />
        </button>
        <nav className="flex flex-col gap-4">
          <NavLink to="/dashboard" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" fill="currentColor"/></svg>
          </NavLink>
          <NavLink to="/" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="currentColor"/></svg>
          </NavLink>
          <NavLink to="/stats" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z" fill="currentColor"/></svg>
          </NavLink>
          <NavLink to="/tags" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z" fill="currentColor"/></svg>
          </NavLink>
          <NavLink to="/put" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" fill="currentColor"/></svg>
          </NavLink>
        </nav>
        <div className="mt-auto flex flex-col items-center gap-4">
          <button className="w-12 h-12 flex items-center justify-center rounded-xl hover:bg-white/10 transition-colors relative">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="currentColor"/></svg>
            <span className="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
          </button>
          <div className="w-10 h-10 bg-white/20 rounded-full overflow-hidden">
            <div className="w-full h-full bg-gradient-to-br from-purple-400 to-pink-400"></div>
          </div>
        </div>
      </aside>
      <div className="flex-1 flex flex-col">
        <header className="h-16 flex items-center justify-between px-6 bg-white border-b border-gray-200">
          <h1 className="text-2xl font-semibold text-gray-800">Tag Cache</h1>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2 text-sm text-gray-600">
              <span className="text-xs text-gray-500">External</span>
              <button className="px-3 py-1 text-xs bg-gray-100 rounded-full flex items-center gap-1">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" fill="currentColor"/></svg>
                Shared
              </button>
            </div>
            <div className="relative">
              <input placeholder="Search" className="pl-8 pr-4 py-2 bg-gray-50 rounded-lg text-sm w-64 focus:outline-none focus:ring-2 focus:ring-brand-primary/20" />
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" className="absolute left-2.5 top-2.5 text-gray-400"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="currentColor"/></svg>
            </div>
          </div>
        </header>
        <main className="flex-1 bg-gray-50 overflow-hidden flex">
          <div className="flex-1 p-6 overflow-auto">
            <Routes>
              <Route path="/" element={<SearchPage />} />
              <Route path="/dashboard" element={<DashboardPage />} />
              <Route path="/put" element={<PutPage />} />
              <Route path="/tags" element={<TagsPage />} />
              <Route path="/stats" element={<StatsPage />} />
              <Route path="/events" element={<EventsPage />} />
              <Route path="/settings" element={<SettingsPage />} />
            </Routes>
          </div>
          <div className="w-80 bg-white border-l border-gray-200 p-6 overflow-auto">
            <RightPanel />
          </div>
        </main>
      </div>
    </div>
  );
}
