import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { useSelectionStore } from '../store/selection';
import { useCacheStore } from '../store/cache';

interface SearchResultRow { key: string; ttl_ms?: number; tags?: string[] }

export default function SearchPage() {
  const navigate = useNavigate();
  const selectKey = useSelectionStore(s=>s.selectKey);
  const { flushCounter } = useCacheStore();
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [results, setResults] = useState<SearchResultRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [hasSearched, setHasSearched] = useState(false);
  const [hasMore, setHasMore] = useState(true);
  const [offset, setOffset] = useState(0);
  const [allData, setAllData] = useState<SearchResultRow[]>([]); // Store all data for infinite scroll

  const INITIAL_LIMIT = 50;
  const LOAD_MORE_LIMIT = 25;

  async function loadLatestData(isInitial = true) {
    if (isInitial) {
      setLoading(true);
      setOffset(0);
    } else {
      setLoadingMore(true);
    }
    setError(null);
    
    try {
      // Load cache entries with pagination
      const resp = await api.post('/search', { 
        limit: isInitial ? INITIAL_LIMIT : LOAD_MORE_LIMIT,
        offset: isInitial ? 0 : offset
      });
      const keys = resp.data.keys || [];
      
      // Normalize to SearchResultRow shape
      const normalized: SearchResultRow[] = keys
        .map((item: any) => ({
          key: typeof item === 'string' ? item : item.key,
          ttl_ms: typeof item === 'string' ? undefined : item.ttl_ms,
          tags: typeof item === 'string' ? [] : (item.tags || []),
          created_ms: typeof item === 'string' ? undefined : item.created_ms
        }))
        .sort((a: any, b: any) => (b.created_ms || 0) - (a.created_ms || 0));
      
      if (isInitial) {
        setAllData(normalized);
        setResults(normalized);
        setOffset(INITIAL_LIMIT);
      } else {
        const newData = [...allData, ...normalized];
        setAllData(newData);
        setResults(newData);
        setOffset(prev => prev + LOAD_MORE_LIMIT);
      }
      
      // Check if we have more data
      setHasMore(keys.length === (isInitial ? INITIAL_LIMIT : LOAD_MORE_LIMIT));
      
    } catch (e: any) {
      setError(e?.response?.data?.error || e.message);
    } finally { 
      setLoading(false);
      setLoadingMore(false);
    }
  }

  async function runSearch() {
    if (!query.trim()) {
      // If empty query, just show all latest data
      onClear();
      return;
    }
    
    setLoading(true); setError(null); setHasSearched(true);
    setOffset(0); setHasMore(false); // Disable infinite scroll for search results
    
    try {
      const resp = await api.post('/search', { q: query, limit: 1000 });
      const searchResults = resp.data.keys || [];
      setResults(searchResults);
      setAllData(searchResults);
    } catch (e: any) {
      setError(e?.response?.data?.error || e.message);
    } finally { setLoading(false); }
  }

  function loadMore() {
    if (!loadingMore && hasMore && !hasSearched) {
      loadLatestData(false);
    }
  }

  function onClear() {
    setQuery('');
    setHasSearched(false);
    setResults([]);
    setAllData([]);
    setError(null);
    setHasMore(true);
    setOffset(0);
    // Load latest data (similar to initial load)
    loadLatestData(true);
  }

  // Load initial data when component mounts
  useEffect(() => {
    loadLatestData(true);
  }, []);

  // Infinite scroll effect with throttling
  useEffect(() => {
    let ticking = false;
    
    const handleScroll = (e: Event) => {
      if (ticking) return;
      
      ticking = true;
      requestAnimationFrame(() => {
        if (loading || loadingMore || !hasMore || hasSearched) {
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
        if (loading || loadingMore || !hasMore || hasSearched) {
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
  }, [loading, loadingMore, hasMore, hasSearched]);

  // Listen for flush events and reload data
  useEffect(() => {
    if (flushCounter > 0) {
      setResults([]);
      setAllData([]);
      setError(null);
      setHasSearched(false);
      setHasMore(true);
      setOffset(0);
      setQuery(''); // Also clear the search query
      // Reload latest data after flush
      loadLatestData(true);
    }
  }, [flushCounter]);

  return (
    <div className="space-y-4">
      <div className="flex gap-2 items-end">
        <div className="flex-1">
          <label className="block text-xs uppercase font-semibold mb-1">Query</label>
          <input 
            value={query} 
            onChange={e=>setQuery(e.target.value)} 
            placeholder="Search by key" 
            className="w-full border rounded px-2 py-1 bg-transparent"
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                runSearch();
              }
            }}
          />
        </div>
        <button disabled={loading} onClick={runSearch} className="px-3 py-1 rounded bg-blue-600 text-white disabled:opacity-50">
          {loading ? 'Searching…' : 'Search'}
        </button>
        <button onClick={onClear} className="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">
          Clear
        </button>
      </div>
      {error && <div className="text-red-600 text-sm">Error: {error}</div>}
      
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <div className="text-sm font-semibold">
            {hasSearched && query ? (
              <>Search Results for: <span className="font-mono">"{query}"</span> ({results.length} rows)</>
            ) : (
              <>Latest Cache Entries ({results.length} rows)</>
            )}
          </div>
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
            {results.map(r => (
              <tr key={r.key} data-key-row={r.key} className="border-b last:border-none hover:bg-gray-50 cursor-pointer" onClick={()=>selectKey(r.key, (k)=>{ setResults(prev=>prev.filter(x=>x.key!==k)); })}>
                <td className="py-1 pr-2 font-mono text-xs">{r.key}</td>
                <td className="py-1 pr-2 text-xs">{r.ttl_ms ?? ''}</td>
                <td className="py-1 pr-2">{r.tags?.map(t => (
                  <button
                    key={t}
                    type="button"
                    onClick={(e)=>{ e.stopPropagation(); navigate(`/tags?tags=${encodeURIComponent(t)}`); }}
                    className="inline-flex items-center rounded-full border border-brand-teal/30 bg-white text-brand-teal px-1.5 py-0.5 mr-1 mb-1 text-[10px] shadow-sm hover:bg-brand-teal/10"
                  >{t}</button>
                ))}</td>
              </tr>
            ))}
            {!loading && results.length === 0 && (
              <tr>
                <td colSpan={3} className="py-4 text-center text-xs text-gray-500">
                  {hasSearched && query ? 'No search results found' : 'No cache entries'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        
        {/* Infinite scroll loading indicator */}
        {!hasSearched && loadingMore && (
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
    </div>
  );
}
