import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { useAuthStore } from '../store/auth';

interface SearchItem { key:string; ttl_ms?:number; tags:string[]; created_ms?:number }

export function TagDistribution(){
  const token = useAuthStore(s=>s.token);
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
  if(!token) return <div className="text-xs text-gray-500">Authenticate to view tags</div>;
  if(isLoading) return <div className="text-xs text-gray-500">Loading tags...</div>;
  if(error) return <div className="text-xs text-red-500">Error loading tags <button onClick={()=>refetch()} className="underline">retry</button></div>;
  const counts: Record<string, number> = {};
  (data||[]).forEach(it=> it.tags.forEach(t=> { counts[t]=(counts[t]||0)+1; }));
  const sorted = Object.entries(counts).sort((a,b)=> b[1]-a[1]).slice(0,12);
  const total = Object.values(counts).reduce((a,b)=>a+b,0)||1;
  return (
    <div className="grid grid-cols-2 gap-3 text-xs">
      {sorted.map(([tag,c])=> {
        const pct = (c/total)*100;
        return (
          <div key={tag} className="flex items-center gap-2">
            <div className="w-14 h-2 rounded bg-gray-200 overflow-hidden">
              <div className="h-full bg-brand-teal" style={{width: pct+'%'}} />
            </div>
            <span className="font-medium text-gray-700 truncate max-w-[80px]" title={tag}>{tag}</span>
            <span className="text-[10px] text-gray-500 tabular-nums">{c}{isFetching && '·'}</span>
          </div>
        );
      })}
      {sorted.length===0 && <div className="col-span-2 text-gray-500">No tags</div>}
    </div>
  );
}
