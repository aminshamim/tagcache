import { useEffect, useState } from 'react';
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
  const [fakeLoading, setFakeLoading] = useState(false);

  // Reset value when type changes
  useEffect(() => {
    const next = mode === 'json' ? '{}'
      : mode === 'text' ? ''
      : mode === 'number' ? '0'
      : 'false';
    setValue(next);
    setMsg(null);
    setErr(null);
  }, [mode]);

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
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="px-2 py-1 text-xs rounded border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50"
              title="Fetch sample data from online"
              disabled={fakeLoading}
              onClick={async () => {
                setErr(null); setMsg(null); setFakeLoading(true);
                // Reset fields before filling with fake data
                setKey('');
                setTags('');
                setValue('');
                const ctrl = new AbortController();
                const timeoutMs = 15000;
                const timeout = setTimeout(() => ctrl.abort(), timeoutMs);
                try {
                  if (mode === 'json') {
                    const id = Math.floor(Math.random() * 100) + 1;
                    try {
                      const r = await fetch(`https://jsonplaceholder.typicode.com/posts/${id}?_=${Date.now()}` as string, { signal: ctrl.signal });
                      if (!r.ok) throw new Error(`HTTP ${r.status}`);
                      const data = await r.json();
                      setValue(JSON.stringify(data, null, 2));
                      setKey(`post-${id}`);
                      setTags('post, sample');
                    } catch (e:any) {
                      try {
                        const r2 = await fetch(`https://dummyjson.com/posts/${(id % 150) + 1}?_=${Date.now()}`, { signal: ctrl.signal });
                        if (!r2.ok) throw new Error(`HTTP ${r2.status}`);
                        const data2 = await r2.json();
                        setValue(JSON.stringify(data2, null, 2));
                        setKey(`post-${id}`);
                        setTags('post, sample');
                      } catch (e2:any) {
                        // Final local fallback
                        const local = { id, title: `Sample post ${id}`, body: 'Lorem ipsum dolor sit amet.', userId: 1, tags: ['sample','post'] };
                        setValue(JSON.stringify(local, null, 2));
                        setKey(`post-${id}`);
                        setTags('post, sample');
                        // If initial error was an abort, surface a friendly message
                        if (e?.name === 'AbortError' || /aborted/i.test(String(e?.message))) {
                          setMsg('Request timed out; used a local sample.');
                        }
                      }
                    }
                  } else if (mode === 'text') {
                    try {
                      const r = await fetch('https://baconipsum.com/api/?type=meat-and-filler&paras=1&format=text', { signal: ctrl.signal });
                      const txt = await r.text();
                      setValue(txt.trim());
                    } catch {
                      const r2 = await fetch('https://loripsum.net/api/1/short/plaintext', { signal: ctrl.signal });
                      const txt2 = await r2.text();
                      setValue(txt2.trim());
                    }
                    setKey(`lorem-${Date.now()}`);
                    setTags('lorem, sample');
                  } else if (mode === 'number') {
                    try {
                      const r = await fetch('https://www.randomnumberapi.com/api/v1.0/random?min=1&max=1000000&count=1', { signal: ctrl.signal });
                      const arr = await r.json();
                      const n = Array.isArray(arr) ? arr[0] : Math.floor(Math.random() * 1000000) + 1;
                      setValue(String(n));
                      setKey(`num-${n}`);
                      setTags('number, sample');
                    } catch {
                      const n = Math.floor(Math.random() * 1000000) + 1;
                      setValue(String(n));
                      setKey(`num-${n}`);
                      setTags('number, sample');
                    }
                  } else { // boolean
                    try {
                      const r = await fetch('https://yesno.wtf/api', { signal: ctrl.signal });
                      const data = await r.json();
                      const b = (data?.answer === 'yes');
                      setValue(b ? 'true' : 'false');
                      setKey(`flag-${b ? 'yes' : 'no'}`);
                      setTags('boolean, sample');
                    } catch {
                      const b = Math.random() > 0.5;
                      setValue(b ? 'true' : 'false');
                      setKey(`flag-${b ? 'yes' : 'no'}`);
                      setTags('boolean, sample');
                    }
                  }
                } catch (e:any) {
                  if (e?.name === 'AbortError' || /aborted/i.test(String(e?.message))) {
                    setErr('Timed out fetching fake data');
                  } else {
                    setErr(e?.message || 'Failed to fetch fake data');
                  }
                } finally {
                  clearTimeout(timeout);
                  setFakeLoading(false);
                }
              }}
            >{fakeLoading ? 'Generating…' : 'Fake data'}</button>
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
