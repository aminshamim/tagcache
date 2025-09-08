import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { useAuthStore } from '../store/auth';
import { useState, useMemo } from 'react';

interface SearchItem { key:string; ttl_ms?:number; tags:string[]; created_ms?:number }

export function TagDistribution(){
  const token = useAuthStore(s=>s.token);
  const [activeTag,setActiveTag] = useState<string|null>(null);
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey:['tag-dist', !!token],
    queryFn: async()=>{ const r = await api.post('/search', { limit: 800, tags: [] }); return r.data.keys as SearchItem[]; },
    enabled: !!token,
    refetchInterval: token ? 7000 : false,
    staleTime: 3000,
    retry: (failureCount, err:any) => {
      if(err?.response?.status === 401) return false;
      return failureCount < 2;
    }
  });
  // IMPORTANT: Hooks (useMemo) must be declared before any early return branches to keep order stable.
  const { cloud, maxCount, minCount } = useMemo(()=>{
    const counts: Record<string, number> = {};
    (data||[]).forEach(it=> it.tags.forEach(t=> { counts[t]=(counts[t]||0)+1; }));
    const entries = Object.entries(counts);
    if(entries.length===0) return { cloud: [] as {tag:string; count:number}[], maxCount:1, minCount:0 };
    entries.sort((a,b)=> b[1]-a[1]);
    return { cloud: entries.slice(0,80).map(([tag,count])=>({tag,count})), maxCount: Math.max(...entries.map(e=>e[1])), minCount: Math.min(...entries.map(e=>e[1])) };
  },[data]);
  if(!token) return <div className="text-xs text-gray-500">Authenticate to view tags</div>;
  if(isLoading) return <div className="text-xs text-gray-500">Loading tags...</div>;
  if(error) return <div className="text-xs text-red-500">Error loading tags <button onClick={()=>refetch()} className="underline">retry</button></div>;

  function scale(count:number){
    if(maxCount===minCount) return 1;
    return 0.4 + 0.6 * ((count - minCount)/(maxCount - minCount)); // 0.4..1.0 scale factor
  }

  return (
    <div className="relative">
      {cloud.length===0 && <div className="text-xs text-gray-500">No tags</div>}
      <div className="flex flex-wrap gap-3 items-center">
        {cloud.map(({tag,count})=>{
          const s = scale(count);
          const size = 12 + s*22; // 12px .. 34px
          const hue = 180 - s*160; // teal->orange
          const sat = 55 + s*35;
          const light = 40 + (1-s)*20;
          const isActive = activeTag===tag;
          return (
            <button
              key={tag}
              onClick={()=> setActiveTag(t=> t===tag? null : tag)}
              title={`${tag} (${count})`}
              className={`transition-all leading-none font-semibold ${isActive?'ring-2 ring-offset-2 ring-brand-primary rounded-md px-1 -m-1':''}`}
              style={{
                fontSize: size,
                color: `hsl(${hue.toFixed(0)}deg ${sat.toFixed(1)}% ${light.toFixed(1)}%)`,
                opacity: isFetching? 0.85 : 1,
                textShadow: '0 1px 2px rgba(0,0,0,0.08)'
              }}
            >{tag}</button>
          );
        })}
      </div>
      {activeTag && <div className="mt-4 p-3 bg-gray-50 rounded-md text-xs text-gray-600 flex items-center gap-3">
        <span className="font-medium">Filter:</span>
        <span className="px-2 py-0.5 rounded bg-white border text-gray-700 font-mono text-[11px]">{activeTag}</span>
        <button onClick={()=>setActiveTag(null)} className="text-blue-600 hover:underline">clear</button>
      </div>}
    </div>
  );
}
