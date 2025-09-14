import { Routes, Route, NavLink, useNavigate, Navigate } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { api, setAuthToken, flushAll } from '../api/client';
import { useAuthStore } from '../store/auth';
import { useCacheStore } from '../store/cache';
import SearchPage from './SearchPage';
import PutPage from './PutPage';
import TagsPage from './TagsPage';
import EventsPage from './EventsPage';
import DashboardPage from './DashboardPage';
import SettingsPage from './SettingsPage';
import { RightPanel } from '../components/RightPanel';

export default function App() {
  const { token, login, logout, username } = useAuthStore();
  const { triggerFlush } = useCacheStore();
  const [u,setU] = useState('');
  const [p,setP] = useState('');
  const [err,setErr] = useState<string|null>(null);
  const [showFlushModal, setShowFlushModal] = useState(false);
  const [isFlushingAll, setIsFlushingAll] = useState(false);

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

  async function handleFlushAll() {
    setIsFlushingAll(true);
    try {
      const result = await flushAll();
      if (result.success) {
        // Trigger cache flush event to notify all components
        triggerFlush();
        console.log(`Successfully flushed ${result.count || 0} entries`);
      }
    } catch (error: any) {
      console.error('Failed to flush cache:', error);
      setErr(error?.response?.data?.error || error.message);
    } finally {
      setIsFlushingAll(false);
      setShowFlushModal(false);
    }
  }

  const navigate = useNavigate();

  // Keyboard shortcuts
  useEffect(()=>{
    function onKey(e:KeyboardEvent){
      if(e.key==='/' && !e.metaKey){ e.preventDefault(); const el=document.getElementById('global-search'); el?.focus(); }
      if(e.key==='g'){ (window as any)._gKeyTime=Date.now(); }
  // removed 'g'+'s' shortcut to /stats (page no longer exists)
      if(e.key==='i'){ navigate('/put'); }
    }
    window.addEventListener('keydown', onKey); return ()=>window.removeEventListener('keydown', onKey);
  },[navigate]);

  // Sync persisted token to API client on load/changes
  useEffect(()=>{ if(token){ setAuthToken(token); } },[token]);

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
  <button onClick={()=>navigate('/')} className="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center overflow-hidden group" aria-label="Tag Cache Home">
          <img src="/logo.png" alt="Tag Cache" className="w-10 h-10 object-contain transition-transform group-hover:scale-110" />
        </button>
        <nav className="flex flex-col gap-4">
          <NavLink to="/" end className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" fill="currentColor"/></svg>
          </NavLink>
          <NavLink to="/search" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="currentColor"/></svg>
          </NavLink>
          {/* stats nav removed */}
          <NavLink to="/tags" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z" fill="currentColor"/></svg>
          </NavLink>
          <NavLink to="/put" className={({isActive}) => `w-12 h-12 flex items-center justify-center rounded-xl ${isActive ? 'bg-white/20': 'hover:bg-white/10'} transition-colors`}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" fill="currentColor"/></svg>
          </NavLink>
        </nav>
        <div className="mt-auto flex flex-col items-center gap-4">
          <div className="w-10 h-10 bg-white/20 rounded-full overflow-hidden flex items-center justify-center text-xs font-medium uppercase tracking-wide">
            {username ? username.slice(0,2) : 'TC'}
          </div>
          <button
            onClick={doLogout}
            title="Logout"
            aria-label="Logout"
            className="w-12 h-12 flex items-center justify-center rounded-xl hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/40 transition-colors group"
          >
            <svg className="w-5 h-5 text-white/80 group-hover:text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
          </button>
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
            <button
              onClick={() => setShowFlushModal(true)}
              disabled={isFlushingAll}
              className="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white rounded-lg text-sm font-medium transition-colors"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <polyline points="3 6 5 6 21 6"></polyline>
                <path d="m19 6-1 14c0 1-1 2-2 2H8c-1 0-2-1-2-2L5 6"></path>
                <path d="m10 11 0 6"></path>
                <path d="m14 11 0 6"></path>
                <path d="M5 6l1-3h12l1 3"></path>
              </svg>
              {isFlushingAll ? 'Flushing...' : 'Flush All'}
            </button>
          </div>
        </header>
        <main className="flex-1 bg-gray-50 overflow-hidden flex">
          <div className="flex-1 p-6 overflow-auto">
            <Routes>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/search" element={<SearchPage />} />
              <Route path="/dashboard" element={<Navigate to="/" replace />} />
              <Route path="/put" element={<PutPage />} />
              <Route path="/tags" element={<TagsPage />} />
              {/* /stats route removed */}
              <Route path="/events" element={<EventsPage />} />
              <Route path="/settings" element={<SettingsPage />} />
            </Routes>
          </div>
          <div className="w-80 bg-white border-l border-gray-200 p-6 overflow-auto">
            <RightPanel />
          </div>
        </main>
      </div>

      {/* Flush All Confirmation Modal */}
      {showFlushModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="text-red-600">
                  <path d="m3 3 18 18"></path>
                  <path d="M6 6v10c0 1 1 2 2 2h8c1 0 2-1 2-2V6"></path>
                  <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                </svg>
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Flush All Cache</h3>
                <p className="text-sm text-gray-500">This action cannot be undone</p>
              </div>
            </div>
            <p className="text-gray-700 mb-6">
              Are you sure you want to flush all cache entries? This will permanently remove all stored data from the cache.
            </p>
            <div className="flex gap-3 justify-end">
              <button
                onClick={() => setShowFlushModal(false)}
                disabled={isFlushingAll}
                className="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 disabled:bg-gray-50 rounded-lg font-medium transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleFlushAll}
                disabled={isFlushingAll}
                className="px-4 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white rounded-lg font-medium transition-colors flex items-center gap-2"
              >
                {isFlushingAll && (
                  <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none"></circle>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                )}
                {isFlushingAll ? 'Flushing...' : 'Yes, Flush All'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
