import { useState } from 'react';
import { api } from '../api/client';
import { setAuthToken } from '../api/client';

export default function SettingsPage(){
  const [rotating,setRotating]=useState(false);
  const [msg,setMsg]=useState<string|null>(null);
  const [err,setErr]=useState<string|null>(null);
  async function rotate(){
    setRotating(true); setMsg(null); setErr(null);
    try { const r = await api.post('/auth/rotate'); setMsg('Rotated: '+r.data.username); } catch(e:any){ setErr(e?.response?.data?.error||e.message); }
    finally { setRotating(false); setAuthToken(null); }
  }
  return <div className="space-y-4">
    <h1 className="text-lg font-semibold">Settings</h1>
    <div className="metric-card space-y-2">
      <div className="text-sm font-medium">Credentials</div>
      <p className="text-xs opacity-70">Rotate server credentials (will require re-login).</p>
      <button onClick={rotate} disabled={rotating} className="btn btn-primary">{rotating? 'Rotating...':'Rotate Credentials'}</button>
      {msg && <div className="text-green-600 text-xs">{msg}</div>}
      {err && <div className="text-red-600 text-xs">{err}</div>}
    </div>
  </div>;
}
