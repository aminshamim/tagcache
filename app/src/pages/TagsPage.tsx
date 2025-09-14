import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { useSelectionStore } from '../store/selection';
import { useCacheStore } from '../store/cache';

interface Item { key: string; created_ms?: number; ttl_ms?: number; tags?: string[] }

export default function TagsPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const selectKey = useSelectionStore(s=>s.selectKey);
  const { flushCounter } = useCacheStore();
  const [tagQuery, setTagQuery] = useState('');
  const [currentFilter, setCurrentFilter] = useState<string>('All');
  const [activeTags, setActiveTags] = useState<string[]>([]);
  const [items, setItems] = useState<Item[]>([]);
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [invLoading, setInvLoading] = useState(false);
  const [error, setError] = useState<string|null>(null);
  const [cache, setCache] = useState<Record<string,Item[]>>({});
  const [hasMore, setHasMore] = useState(true);
  const [offset, setOffset] = useState(0);
  const [allData, setAllData] = useState<Record<string, Item[]>>({});
  
  const INITIAL_LIMIT = 50;
  const LOAD_MORE_LIMIT = 25;
  
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

  async function loadData(raw:string, forceReload = false, isLoadMore = false) {
    if (isLoadMore) {
      setLoadingMore(true);
    } else {
      setLoading(true);
      setOffset(0);
    }
    setError(null);
    
    const tags = parseTags(raw);
    const sig = tags.length? tags.slice().sort().join(',') : '__ALL__';
    setCurrentFilter(tags.length? tags.join(', ') : 'All');
    setActiveTags(tags);
    
    try {
      // Check cache for initial load only
      if (!forceReload && !isLoadMore && cache[sig]) { 
        setItems(cache[sig]); 
        setHasMore(cache[sig].length >= INITIAL_LIMIT);
        return; 
      }
      
      let list: any[] = [];
      const currentOffset = isLoadMore ? offset : 0;
      const currentLimit = isLoadMore ? LOAD_MORE_LIMIT : INITIAL_LIMIT;
      
      if(tags.length===0){
        const r = await api.post('/search', { 
          limit: currentLimit, 
          offset: currentOffset 
        }, { timeout: 30000 });
        list = r.data?.keys || [];
      } else {
        const r = await api.post('/search', { 
          limit: currentLimit,
          offset: currentOffset,
          tag_any: tags 
        }, { timeout: 30000 });
        list = r.data?.keys || [];
      }
      
      // Normalize to Item shape
      const norm: Item[] = list.map((it:any)=> typeof it === 'string' ? ({ key: it }) : ({ key: it.key, created_ms: it.created_ms, ttl_ms: it.ttl_ms, tags: it.tags }));
      // Order by latest created_ms desc, fallback by key desc for stability
      norm.sort((a,b)=> (b.created_ms||0) - (a.created_ms||0) || (b.key > a.key ? 1 : -1));
      
      if (isLoadMore) {
        const existingData = allData[sig] || items;
        const newData = [...existingData, ...norm];
        setAllData(prev => ({...prev, [sig]: newData}));
        setItems(newData);
        setOffset(prev => prev + LOAD_MORE_LIMIT);
      } else {
        setAllData(prev => ({...prev, [sig]: norm}));
        setCache(c => ({...c, [sig]: norm}));
        setItems(norm);
        setOffset(INITIAL_LIMIT);
      }
      
      // Check if we have more data
      setHasMore(list.length === currentLimit);
      
    } catch(e:any) { 
      setError(e?.response?.data?.error || e.message); 
    } finally { 
      setLoading(false);
      setLoadingMore(false);
    }
  }

  function onSubmit(e:React.FormEvent) { e.preventDefault(); loadData(tagQuery); }

  function loadMore() {
    if (!loadingMore && hasMore) {
      loadData(tagQuery, false, true);
    }
  }

  function onClear(){
    // cancel pending debounce
    if(clearTimer.current){ window.clearTimeout(clearTimer.current); clearTimer.current = null; }
    setTagQuery('');
    setActiveTags([]);
    setCurrentFilter('All');
    setHasMore(true);
    setOffset(0);
    // remove URL query if present
    navigate('/tags');
    // load remaining items now
    loadData('');
  }

  // If navigated with ?tags=foo, auto-populate and load; reacts to changes while staying on /tags
  useEffect(()=>{
    const t = searchParams.get('tags') || '';
    setTagQuery(t);
    setHasMore(true);
    setOffset(0);
    if(t){
      loadData(t);
    } else {
      // when cleared, show remaining keys
      loadData('');
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  // Infinite scroll effect with throttling
  useEffect(() => {
    let ticking = false;
    
    const handleScroll = (e: Event) => {
      if (ticking) return;
      
      ticking = true;
      requestAnimationFrame(() => {
        if (loading || loadingMore || !hasMore) {
          ticking = false;
          return;
        }
        
        const target = e.target as HTMLElement;
        const scrollTop = target.scrollTop;
        const scrollHeight = target.scrollHeight;
        const clientHeight = target.clientHeight;
        
        // Trigger when within 300px of bottom for better UX
        if (scrollTop + clientHeight >= scrollHeight - 300) {
          loadMore();
        }
        
        ticking = false;
      });
    };

    // Find the scrollable container by traversing up from our component
    let scrollContainer: HTMLElement | null = null;
    
    // Try different approaches to find the scroll container
    const mainElement = document.querySelector('main');
    if (mainElement) {
      const contentDiv = mainElement.querySelector('div.overflow-auto');
      if (contentDiv) {
        scrollContainer = contentDiv as HTMLElement;
      }
    }
    
    // Fallback: find any parent with overflow-auto
    if (!scrollContainer) {
      let parent: HTMLElement | null = document.body;
      while (parent) {
        const style = window.getComputedStyle(parent);
        if (style.overflowY === 'auto' || style.overflow === 'auto') {
          scrollContainer = parent;
          break;
        }
        parent = parent.parentElement;
        if (!parent) break;
      }
    }

    if (scrollContainer) {
      scrollContainer.addEventListener('scroll', handleScroll, { passive: true });
      return () => scrollContainer!.removeEventListener('scroll', handleScroll);
    }
    
    // Final fallback to window scroll
    const handleWindowScroll = () => {
      if (ticking) return;
      
      ticking = true;
      requestAnimationFrame(() => {
        if (loading || loadingMore || !hasMore) {
          ticking = false;
          return;
        }
        
        const scrollTop = document.documentElement.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight;
        const clientHeight = document.documentElement.clientHeight;
        
        if (scrollTop + clientHeight >= scrollHeight - 300) {
          loadMore();
        }
        
        ticking = false;
      });
    };
    
    window.addEventListener('scroll', handleWindowScroll, { passive: true });
    return () => window.removeEventListener('scroll', handleWindowScroll);
  }, [loading, loadingMore, hasMore, tagQuery]);

  // Listen for flush events and reload data
  useEffect(() => {
    if (flushCounter > 0) {
      // Clear all state and force reload
      setCache({});
      setAllData({});
      setItems([]);
      setError(null);
      setHasMore(true);
      setOffset(0);
      
      // Force reload current query bypassing cache
      const currentQuery = tagQuery.trim();
      loadData(currentQuery, true);
    }
  }, [flushCounter]); // Only depend on flushCounter to avoid stale closures

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
                <tr key={it.key} data-key-row={it.key} className="border-b last:border-none hover:bg-gray-50 cursor-pointer" onClick={()=>selectKey(it.key, (k)=>{ setItems(prev=>prev.filter(x=>x.key!==k)); })}>
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
          
          {/* Infinite scroll loading indicator */}
          {loadingMore && (
            <div className="flex justify-center mt-4 py-4">
              <div className="flex items-center gap-2 text-sm text-gray-500">
                <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none"></circle>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading more entries...
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
