import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { useSelectionStore } from '../store/selection';

interface Item { key: string; created_ms?: number; ttl_ms?: number; tags?: string[] }

export default function TagsPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const selectKey = useSelectionStore(s=>s.selectKey);
  const [tagQuery, setTagQuery] = useState('');
  const [currentFilter, setCurrentFilter] = useState<string>('All');
  const [activeTags, setActiveTags] = useState<string[]>([]);
  const [items, setItems] = useState<Item[]>([]);
  const [loading, setLoading] = useState(false);
  const [invLoading, setInvLoading] = useState(false);
  const [error, setError] = useState<string|null>(null);
  const [cache, setCache] = useState<Record<string,Item[]>>({});
  // Auto-load ALL when tag filter is cleared (debounced)
  const clearTimer = useRef<number | null>(null);
  function parseTags(input:string){
    return input.split(',').map(s=>s.trim()).filter(Boolean);
  }

  // When tag input is cleared, fetch remaining items automatically
  useEffect(()=>{
    const val = tagQuery.trim();
    if(val.length === 0){
      if(clearTimer.current) { window.clearTimeout(clearTimer.current); }
      clearTimer.current = window.setTimeout(()=>{ loadData(''); }, 300);
    } else if (clearTimer.current) {
      window.clearTimeout(clearTimer.current);
      clearTimer.current = null;
    }
    return ()=>{ if(clearTimer.current){ window.clearTimeout(clearTimer.current); clearTimer.current = null; } };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tagQuery]);

  async function loadData(raw:string) {
    setLoading(true); setError(null);
    const tags = parseTags(raw);
    const sig = tags.length? tags.slice().sort().join(',') : '__ALL__';
    setCurrentFilter(tags.length? tags.join(', ') : 'All');
    setActiveTags(tags);
    try {
      if (cache[sig]) { setItems(cache[sig]); return; }
      let list: any[] = [];
      if(tags.length===0){
        const r = await api.post('/search', { limit: 2000 }, { timeout: 30000 });
        list = r.data?.keys || [];
      } else {
        // Use tag_any to match items that have any of the entered tags
        const r = await api.post('/search', { limit: 2000, tag_any: tags }, { timeout: 30000 });
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

  function onClear(){
    // cancel pending debounce
    if(clearTimer.current){ window.clearTimeout(clearTimer.current); clearTimer.current = null; }
    setTagQuery('');
    setActiveTags([]);
    setCurrentFilter('All');
    // remove URL query if present
    navigate('/tags');
    // load remaining items now
    loadData('');
  }

  // If navigated with ?tags=foo, auto-populate and load; reacts to changes while staying on /tags
  useEffect(()=>{
    const t = searchParams.get('tags') || '';
    setTagQuery(t);
    if(t){
      loadData(t);
    } else {
      // when cleared, show remaining keys
      loadData('');
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  async function onInvalidateAll(){
    if(activeTags.length===0) return; // avoid nuking all
    setInvLoading(true); setError(null);
    try {
      const resp = await api.post('/invalidate/tags', { tags: activeTags, mode: 'any' }, { timeout: 60000 });
      // Optimistically remove matching items from current list
      setItems(prev => prev.filter(it => !it.tags || !it.tags.some(t => activeTags.includes(t))));
      // Invalidate caches that are impacted: current signature, ALL, and any cached signature that intersects with activeTags
      setCache(c => {
        const copy: Record<string, Item[]> = { ...c };
        const sig = activeTags.slice().sort().join(',');
        delete copy[sig];
        delete copy['__ALL__'];
        for(const key of Object.keys(copy)){
          if(key === '__ALL__') continue;
          const tags = key.split(',').map(s=>s.trim()).filter(Boolean);
          if(tags.some(t => activeTags.includes(t))){ delete copy[key]; }
        }
        return copy;
      });
    } catch(e:any) {
      setError(e?.response?.data?.error || e.message);
    } finally { setInvLoading(false); }
  }

  return (
    <div className="space-y-4">
      <form onSubmit={onSubmit} className="flex gap-2 items-end">
        <div className="flex-1">
          <label className="block text-xs font-semibold mb-1">Tag</label>
          <input value={tagQuery} onChange={e=>setTagQuery(e.target.value)} placeholder="tag or tag1,tag2" className="w-full border rounded px-2 py-1 bg-transparent" />
        </div>
  <button type="submit" className="px-3 py-1 bg-blue-600 text-white rounded disabled:opacity-50" disabled={loading}>{loading ? 'Loading…':'Load'}</button>
  <button type="button" onClick={onClear} className="px-3 py-1 bg-gray-200 text-gray-800 rounded">Clear</button>
      </form>
      {error && <div className="text-red-600 text-xs">Error: {error}</div>}
      {(
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <div className="text-sm font-semibold">Filter: <span className="font-mono">{currentFilter}</span> ({items.length} rows)</div>
            <button
              type="button"
              onClick={onInvalidateAll}
              disabled={activeTags.length===0 || invLoading}
              className={`px-3 py-1 rounded text-white text-xs ${activeTags.length===0 ? 'bg-red-400/50 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700'} disabled:opacity-60`}
              title={activeTags.length===0 ? 'Enter tag(s) to enable' : 'Invalidate keys by tag'}
            >{invLoading ? 'Invalidating…' : 'Invalidate by tag'}</button>
          </div>
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
                <tr key={it.key} className="border-b last:border-none hover:bg-gray-50 cursor-pointer" onClick={()=>selectKey(it.key, (k)=>{ setItems(prev=>prev.filter(x=>x.key!==k)); })}>
                  <td className="py-1 pr-2 font-mono text-xs">{it.key}</td>
                  <td className="py-1 pr-2 text-xs">{it.ttl_ms ?? ''}</td>
                  <td className="py-1 pr-2">{it.tags?.map(t => (
                    <button
                      key={t}
                      type="button"
                      onClick={(e)=>{ e.stopPropagation(); navigate(`/tags?tags=${encodeURIComponent(t)}`); }}
                      className="inline-flex items-center rounded-full border border-brand-teal/30 bg-white text-brand-teal px-1.5 py-0.5 mr-1 mb-1 text-[10px] shadow-sm hover:bg-brand-teal/10"
                    >{t}</button>
                  ))}</td>
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
