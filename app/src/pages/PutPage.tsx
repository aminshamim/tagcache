import { useState } from 'react';
import { api } from '../api/client';

export default function PutPage() {
  const [key, setKey] = useState('');
  const [value, setValue] = useState('{}');
  const [tags, setTags] = useState('');
  const [ttl, setTtl] = useState('60000');
  const [msg, setMsg] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);

  async function submit() {
    setMsg(null); setErr(null);
    try {
      JSON.parse(value); // validate
    } catch(e:any) { setErr('Invalid JSON'); return; }
    try {
  await api.put(`/keys/${encodeURIComponent(key)}`, { value: JSON.parse(value), ttl_ms: Number(ttl), tags: tags.split(/[,\s]+/).filter(Boolean) });
  setMsg('Stored OK');
    } catch(e:any) { setErr(e?.response?.data?.error || e.message); }
  }

  return (
    <div className="space-y-4 max-w-xl">
      <div>
        <label className="block text-xs font-semibold mb-1">Key</label>
        <input className="w-full border rounded px-2 py-1 bg-transparent" value={key} onChange={e=>setKey(e.target.value)} />
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-semibold mb-1">TTL (ms)</label>
          <input className="w-full border rounded px-2 py-1 bg-transparent" value={ttl} onChange={e=>setTtl(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs font-semibold mb-1">Tags (space/comma)</label>
          <input className="w-full border rounded px-2 py-1 bg-transparent" value={tags} onChange={e=>setTags(e.target.value)} />
        </div>
      </div>
      <div>
        <label className="block text-xs font-semibold mb-1">Value (JSON)</label>
        <textarea rows={10} className="w-full border rounded px-2 py-1 font-mono text-xs bg-transparent" value={value} onChange={e=>setValue(e.target.value)} />
      </div>
      <div className="flex gap-2 items-center">
        <button onClick={submit} className="px-3 py-1 rounded bg-green-600 text-white">Put</button>
        {msg && <span className="text-green-600 text-xs">{msg}</span>}
        {err && <span className="text-red-600 text-xs">{err}</span>}
      </div>
    </div>
  );
}
