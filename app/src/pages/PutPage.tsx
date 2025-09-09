import { useState } from 'react';
import { api } from '../api/client';
import CodeMirror from '@uiw/react-codemirror';
import { json as jsonLang } from '@codemirror/lang-json';

type ValueMode = 'json' | 'text' | 'number' | 'boolean';

export default function PutPage() {
  const [key, setKey] = useState('');
  const [value, setValue] = useState('{}');
  const [mode, setMode] = useState<ValueMode>('json');
  const [tags, setTags] = useState('');
  const [ttl, setTtl] = useState('60000');
  const [msg, setMsg] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);

  async function submit() {
    setMsg(null); setErr(null);
    try {
      if(!key.trim()){ setErr('Key is required'); return; }
      // derive JSON value per mode
      let bodyValue: unknown;
      if(mode==='json'){
        try { bodyValue = JSON.parse(value); }
        catch { setErr('Invalid JSON'); return; }
      } else if(mode==='text'){
        bodyValue = value;
      } else if(mode==='number'){
        const n = Number(value.trim());
        if(Number.isNaN(n)) { setErr('Invalid number'); return; }
        bodyValue = n;
      } else { // boolean
        const v = value.trim().toLowerCase();
        if(v==='true' || v==='1') bodyValue = true;
        else if(v==='false' || v==='0') bodyValue = false;
        else { setErr('Invalid boolean (use true/false)'); return; }
      }
      const ttlNum = ttl ? Number(ttl) : undefined;
      if(ttl && Number.isNaN(Number(ttl))) { setErr('TTL must be a number (ms)'); return; }
      await api.put(`/keys/${encodeURIComponent(key)}`, {
        value: bodyValue as any,
        ttl_ms: ttlNum,
        tags: tags.split(/[\,\s]+/).filter(Boolean)
      });
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
        <div className="flex items-center justify-between mb-1">
          <label className="block text-xs font-semibold">Value</label>
          <div className="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden">
            {(['json','text','number','boolean'] as ValueMode[]).map(m => (
              <button
                key={m}
                type="button"
                onClick={()=>setMode(m)}
                className={`px-2 py-1 text-xs ${mode===m ? 'bg-brand-teal/10 text-brand-teal' : 'bg-white text-gray-700 hover:bg-gray-50'} ${m!=='boolean' ? 'border-r border-gray-300' : ''}`}
              >{m}</button>
            ))}
          </div>
        </div>
        <div className="rounded-lg overflow-hidden border border-gray-200">
          <CodeMirror
            value={value}
            height="220px"
            editable={true}
            onChange={v=>setValue(v)}
            basicSetup={{ lineNumbers: true, highlightActiveLine: false }}
            className="text-sm"
            extensions={mode==='json' ? [jsonLang()] : []}
          />
        </div>
        {mode==='boolean' && (
          <div className="text-[10px] text-gray-500 mt-1">Enter true or false</div>
        )}
      </div>
      <div className="flex gap-2 items-center">
        <button onClick={submit} className="px-3 py-1 rounded bg-green-600 text-white">Put</button>
        {msg && <span className="text-green-600 text-xs">{msg}</span>}
        {err && <span className="text-red-600 text-xs">{err}</span>}
      </div>
    </div>
  );
}
