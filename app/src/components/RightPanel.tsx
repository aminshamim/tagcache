import { useQuery } from '@tanstack/react-query';
import { listKeys } from '../api/client';
import { useAuthStore } from '../store/auth';

export function RightPanel() {
  const token = useAuthStore(s=>s.token);
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey:['latest-keys', !!token],
    queryFn: async()=> {
      const r = await listKeys();
      return r.slice(0,25);
    },
    enabled: !!token,
    refetchInterval: token ? 5000 : false,
    retry: (failureCount, err:any) => {
      if(err?.response?.status === 401) return false;
      return failureCount < 2;
    }
  });

  const now = Date.now();
  return (
    <div className="space-y-8">
      <div>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-lg font-semibold text-gray-800">Latest Keys</h3>
          <span className="text-xs text-gray-400">auto-refresh</span>
        </div>
        <div className="space-y-2 max-h-72 overflow-auto pr-1">
          {isLoading && <div className="text-xs text-gray-500">Loading...</div>}
          {error && <div className="text-xs text-red-500">Error loading keys <button onClick={()=>refetch()} className="underline">retry</button></div>}
          {data?.map(k => {
            const ageMs = k.created_ms ? now - k.created_ms : 0;
            const age = ageMs < 60000 ? Math.floor(ageMs/1000)+"s" : Math.floor(ageMs/60000)+"m";
            const ttlDisp = k.ttl === null || k.ttl === undefined ? '∞' : k.ttl === 0 ? 'exp' : Math.floor(k.ttl/1000)+'s';
            return (
              <div key={k.key} className="p-2 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-mono text-xs truncate max-w-[140px]" title={k.key}>{k.key}</span>
                  <span className="text-[10px] text-gray-500">{age}{isFetching && ' • …'} • {ttlDisp}</span>
                </div>
                {k.tags.length>0 && (
                  <div className="mt-1 flex flex-wrap gap-1">
                    {k.tags.slice(0,6).map(t=> <span key={t} className="px-1 py-0.5 rounded bg-brand-teal/10 text-brand-teal text-[10px] font-medium">{t}</span>)}
                    {k.tags.length>6 && <span className="text-[10px] text-gray-500">+{k.tags.length-6}</span>}
                  </div>
                )}
              </div>
            );
          })}
          {(!isLoading && !error && data && data.length===0) && <div className="text-xs text-gray-400">No keys</div>}
        </div>
      </div>
      <div>
        <h3 className="text-lg font-semibold text-gray-800 mb-3">TTL & Tags Snapshot</h3>
        <div className="space-y-2 text-xs">
          {data?.slice(0,8).map(k=> (
            <div key={k.key} className="flex items-center justify-between">
              <span className="font-mono truncate max-w-[110px]" title={k.key}>{k.key}</span>
              <span className="text-gray-500">{k.ttl? Math.floor(k.ttl/1000)+'s':'∞'}</span>
              <span className="text-gray-400">{k.tags.length}</span>
            </div>
          ))}
          {!data && <div className="text-gray-400">—</div>}
        </div>
      </div>
    </div>
  );
}