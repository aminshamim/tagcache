import { useState } from 'react';
import { api } from '../api/client';

interface Item { key: string; created_ms?: number; ttl_ms?: number; tags?: string[] }

export default function TagsPage() {
  const [tagQuery, setTagQuery] = useState('');
  const [currentFilter, setCurrentFilter] = useState<string>('All');
  const [items, setItems] = useState<Item[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string|null>(null);
  const [cache, setCache] = useState<Record<string,Item[]>>({});
  function parseTags(input:string){
    return input.split(',').map(s=>s.trim()).filter(Boolean);
  }

  async function loadData(raw:string) {
    setLoading(true); setError(null);
    const tags = parseTags(raw);
    const sig = tags.length? tags.slice().sort().join(',') : '__ALL__';
    setCurrentFilter(tags.length? tags.join(', ') : 'All');
    try {
      if (cache[sig]) { setItems(cache[sig]); return; }
      let list: any[] = [];
      if(tags.length===0){
        const r = await api.post('/search', { limit: 2000 });
        list = r.data?.keys || [];
      } else {
        const r = await api.post('/search', { limit: 2000, tags });
        list = r.data?.keys || [];
      }
      // Normalize to Item shape
  const norm: Item[] = list.map((it:any)=> typeof it === 'string' ? ({ key: it }) : ({ key: it.key, created_ms: it.created_ms, ttl_ms: it.ttl_ms, tags: it.tags }));
      // Order by latest created_ms desc, fallback by key desc for stability
      norm.sort((a,b)=> (b.created_ms||0) - (a.created_ms||0) || (b.key > a.key ? 1 : -1));
      setCache(c => ({...c, [sig]: norm}));
      setItems(norm);
    } catch(e:any) { setError(e?.response?.data?.error || e.message); }
    finally { setLoading(false); }
  }

  function onSubmit(e:React.FormEvent) { e.preventDefault(); loadData(tagQuery); }

  return (
    <div className="space-y-4">
      <form onSubmit={onSubmit} className="flex gap-2 items-end">
        <div className="flex-1">
          <label className="block text-xs font-semibold mb-1">Tag</label>
          <input value={tagQuery} onChange={e=>setTagQuery(e.target.value)} placeholder="tag or tag1,tag2" className="w-full border rounded px-2 py-1 bg-transparent" />
        </div>
        <button type="submit" className="px-3 py-1 bg-blue-600 text-white rounded disabled:opacity-50" disabled={loading}>{loading ? 'Loading…':'Load'}</button>
        <button type="button" onClick={()=>loadData('')} className="px-3 py-1 bg-gray-200 text-gray-800 rounded">Load All</button>
      </form>
      {error && <div className="text-red-600 text-xs">Error: {error}</div>}
      {(
        <div className="space-y-2">
          <div className="text-sm font-semibold">Filter: <span className="font-mono">{currentFilter}</span> ({items.length} rows)</div>
          <table className="w-full text-sm border-collapse">
            <thead>
              <tr className="text-left border-b">
                <th className="py-1 pr-2">Key</th>
                <th className="py-1 pr-2">TTL</th>
                <th className="py-1 pr-2">Tags</th>
              </tr>
            </thead>
            <tbody>
              {items.map(it => (
                <tr key={it.key} className="border-b last:border-none">
                  <td className="py-1 pr-2 font-mono text-xs">{it.key}</td>
                  <td className="py-1 pr-2 text-xs">{it.ttl_ms ?? ''}</td>
                  <td className="py-1 pr-2">{it.tags?.map(t => <span key={t} className="inline-block bg-gray-200 dark:bg-gray-700 rounded px-1 mr-1 mb-1 text-[10px]">{t}</span>)}</td>
                </tr>
              ))}
              {!loading && items.length === 0 && <tr><td colSpan={3} className="py-4 text-center text-xs text-gray-500">No results</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
