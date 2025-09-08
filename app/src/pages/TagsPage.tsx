import { useState } from 'react';
import { api } from '../api/client';

interface TagEntry { tag: string; count: number; keys: string[] }

export default function TagsPage() {
  const [tagQuery, setTagQuery] = useState('');
  const [currentTag, setCurrentTag] = useState<string|null>(null);
  const [keys, setKeys] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string|null>(null);
  const [cache, setCache] = useState<Record<string,string[]>>({});

  async function loadTag(tag: string) {
    if(!tag) return;
    setLoading(true); setError(null); setCurrentTag(tag);
    try {
      if (cache[tag]) { setKeys(cache[tag]); return; }
      const r = await api.get(`/keys-by-tag`, { params: { tag, limit: 500 }});
      const list: string[] = r.data.keys || [];
      setCache(c => ({...c, [tag]: list}));
      setKeys(list);
    } catch(e:any) { setError(e?.response?.data?.error || e.message); }
    finally { setLoading(false); }
  }

  function onSubmit(e:React.FormEvent) { e.preventDefault(); loadTag(tagQuery.trim()); }

  return (
    <div className="space-y-4">
      <form onSubmit={onSubmit} className="flex gap-2 items-end">
        <div className="flex-1">
          <label className="block text-xs font-semibold mb-1">Tag</label>
          <input value={tagQuery} onChange={e=>setTagQuery(e.target.value)} placeholder="enter tag" className="w-full border rounded px-2 py-1 bg-transparent" />
        </div>
        <button type="submit" className="px-3 py-1 bg-blue-600 text-white rounded disabled:opacity-50" disabled={!tagQuery || loading}>{loading ? 'Loading…':'Load'}</button>
      </form>
      {error && <div className="text-red-600 text-xs">Error: {error}</div>}
      {currentTag && (
        <div className="space-y-2">
          <div className="text-sm font-semibold">Tag: <span className="font-mono">{currentTag}</span> ({keys.length} keys)</div>
          <div className="border rounded max-h-96 overflow-auto text-xs divide-y">
            {keys.map(k => (
              <div key={k} className="px-2 py-1 font-mono truncate">{k}</div>
            ))}
            {(!loading && keys.length===0) && <div className="px-2 py-4 text-center text-gray-500">No keys</div>}
          </div>
        </div>
      )}
    </div>
  );
}
