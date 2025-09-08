import { useEffect, useState } from 'react';
import { api } from '../api/client';
import { useAuthStore } from '../store/auth';
import { TagDistribution } from '../components/TagDistribution';

interface Stats { hits:number; misses:number; puts:number; invalidations:number; hit_ratio:number; items?:number; bytes?:number; tags?:number }

export default function DashboardPage() {
  const [stats,setStats] = useState<Stats|null>(null);
  const [err,setErr] = useState<string|null>(null);
  
  const token = useAuthStore(s=>s.token);
  useEffect(()=>{
    let active=true;
    const fetchStats=async()=>{
      try {
        const r=await api.get('/stats');
        let data = r.data as any;
        // Backward compatibility: older server versions may omit extended fields (items/bytes/tags)
        if((data && typeof data==='object') && (data.hits!==undefined) && (data.tags===undefined) && token){
          try {
            const sr = await api.post('/search', { limit: 1000 });
            const keys: any[] = sr.data?.keys || [];
            const tagSet = new Set<string>();
            for(const k of keys){
              if(Array.isArray(k.tags)) for(const t of k.tags) tagSet.add(t);
            }
            data = { ...data, tags: tagSet.size };
          } catch(_) { /* ignore fallback errors */ }
        }
        if(active) setStats(data);
      } catch(e:any){ if(active) setErr(e.message); }
    };
    fetchStats();
    const id=setInterval(fetchStats,4000);
    return ()=>{active=false;clearInterval(id);};
  },[token]);

  function ring(value:number, total:number, color:string){
    const pct = total>0? value/total:0;
    const circ = 2*Math.PI*54;
    return (
      <div className="relative w-32 h-32">
        <svg className="w-32 h-32 -rotate-90">
          <circle cx="64" cy="64" r="54" stroke="#e5e7eb" strokeWidth="12" fill="none" />
          <circle cx="64" cy="64" r="54" stroke={color} strokeWidth="12" fill="none" strokeDasharray={`${pct*circ} ${circ}`} strokeLinecap="round" />
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center">
          <div className="text-lg font-semibold tabular-nums">{value}</div>
          <div className="text-[11px] text-gray-500">{(pct*100).toFixed(1)}%</div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="bg-white rounded-xl p-6 shadow-sm">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg font-semibold text-gray-800">Cache Overview</h2>
          <span className="text-xs text-gray-500">live stats</span>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-6 place-items-center">
          {stats && ring(stats.hits, (stats.hits+stats.misses)||1, '#10b981')}
          {stats && ring(stats.misses, (stats.hits+stats.misses)||1, '#ef4444')}
          {stats && ring(stats.items||0, (stats.items||1), '#6366f1')}
          {stats && ring(stats.tags||0, (stats.tags||1), '#f59e0b')}
          {!stats && <div className="col-span-4 text-sm text-gray-500">Loading...</div>}
        </div>
        <div className="mt-6 flex flex-wrap gap-6 text-xs text-gray-600">
          <div>Hit Ratio <span className="font-semibold text-gray-800">{stats? (stats.hit_ratio*100).toFixed(2)+'%':'—'}</span></div>
          <div>Writes <span className="font-semibold text-gray-800">{stats?.puts ?? '—'}</span></div>
          <div>Invalidations <span className="font-semibold text-gray-800">{stats?.invalidations ?? '—'}</span></div>
          <div>Bytes <span className="font-semibold text-gray-800">{stats? ((stats.bytes||0)/1024).toFixed(1)+' KB':'—'}</span></div>
        </div>
      </div>

      <div className="grid grid-cols-3 gap-6">
        <div className="col-span-2 bg-white rounded-xl shadow-sm p-6">
          <h3 className="text-lg font-semibold text-gray-800 mb-4">Cache Dynamics</h3>
          <div className="grid grid-cols-2 gap-6 text-sm">
            <div className="p-4 rounded-lg bg-gradient-to-br from-brand-teal/10 to-brand-teal/5">
              <div className="text-xs uppercase tracking-wide text-gray-500 mb-1">Hit Ratio</div>
              <div className="text-3xl font-semibold text-gray-800">{stats? (stats.hit_ratio*100).toFixed(1)+'%':'—'}</div>
              <div className="mt-2 text-xs text-gray-500">{stats? stats.hits+" hits / "+stats.misses+" misses":""}</div>
            </div>
            <div className="p-4 rounded-lg bg-gradient-to-br from-brand-purple/10 to-brand-purple/5">
              <div className="text-xs uppercase tracking-wide text-gray-500 mb-1">Writes</div>
              <div className="text-3xl font-semibold text-gray-800">{stats?.puts ?? '—'}</div>
              <div className="mt-2 text-xs text-gray-500">Invalidations {stats?.invalidations ?? '—'}</div>
            </div>
            <div className="p-4 rounded-lg bg-gradient-to-br from-brand-blue/10 to-brand-blue/5">
              <div className="text-xs uppercase tracking-wide text-gray-500 mb-1">Items</div>
              <div className="text-3xl font-semibold text-gray-800">{stats?.items ?? '—'}</div>
              <div className="mt-2 text-xs text-gray-500">Bytes {(stats?.bytes||0)/1024? ((stats?.bytes||0)/1024).toFixed(1)+' KB':'—'}</div>
            </div>
            <div className="p-4 rounded-lg bg-gradient-to-br from-brand-amber/10 to-brand-amber/5">
              <div className="text-xs uppercase tracking-wide text-gray-500 mb-1">Tags</div>
              <div className="text-3xl font-semibold text-gray-800">{stats?.tags !== undefined ? stats.tags : '—'}</div>
              <div className="mt-2 text-xs text-gray-500">Avg/Tag {stats && stats.tags? ( (stats.items||0)/Math.max(1,stats.tags)).toFixed(1):'—'}</div>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-xl p-6 shadow-sm flex flex-col items-center justify-center">
          <h3 className="text-sm font-medium text-gray-600 mb-2">Overall Utilization</h3>
          {stats && ring(stats.hits, (stats.hits+stats.misses)||1, '#0ea5e9')}
          <div className="mt-2 text-xs text-gray-500">Total Requests {stats? stats.hits+stats.misses:'—'}</div>
        </div>
      </div>

      <div className="bg-white rounded-xl shadow-sm p-6">
        <h3 className="text-lg font-semibold text-gray-800 mb-4">Tag Distribution (Top)</h3>
        <TagDistribution />
      </div>
    </div>
  );
}