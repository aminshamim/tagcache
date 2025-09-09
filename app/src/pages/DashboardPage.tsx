import { useEffect, useState, useRef } from 'react';
import { api } from '../api/client';
import { useAuthStore } from '../store/auth';
import { TagDistribution } from '../components/TagDistribution';

interface Stats { hits:number; misses:number; puts:number; invalidations:number; hit_ratio:number; items?:number; bytes?:number; tags?:number; shard_count?:number; shard_items?:number[]; shard_bytes?:number[] }

export default function DashboardPage() {
  const [stats,setStats] = useState<Stats|null>(null);
  const [memSeries,setMemSeries] = useState<{ts:number; bytes:number}[]>([]);
  const [err,setErr] = useState<string|null>(null);
  const prevStatsRef = useRef<Stats|null>(null);
  
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
        if(active) {
          setStats(data);
          if(data && typeof data.bytes === 'number') {
            setMemSeries(s => {
              const next = [...s, { ts: Date.now(), bytes: data.bytes }];
              // keep last 60 points (~4 mins if 4s interval)
              return next.slice(-60);
            });
          }
        }
      } catch(e:any){ if(active) setErr(e.message); }
    };
    fetchStats();
    const id=setInterval(fetchStats,4000);
    return ()=>{active=false;clearInterval(id);};
  },[token]);

  // History for line chart (derive from memSeries + stats samples)
  const [history,setHistory] = useState<{ts:number; hits:number; misses:number; puts:number; invalidations:number; items:number; bytes:number; hit_ratio:number}[]>([]);
  useEffect(()=>{
    if(stats){
      setHistory(h=>[...h,{ts:Date.now(), hits:stats.hits, misses:stats.misses, puts:stats.puts, invalidations:stats.invalidations, items:stats.items||0, bytes:stats.bytes||0, hit_ratio:stats.hit_ratio }].slice(-90));
    }
  },[stats]);
  const prevStats = prevStatsRef.current; // snapshot before updating ref below
  useEffect(()=>{ prevStatsRef.current = stats; },[stats]);

  function RatesChart(){
    if(history.length<2) return <div className="h-48 flex items-center justify-center text-sm text-gray-500">Collecting data...</div>;
    const w=720, h=160, pad=6;
    const recent = history;
    // compute per-second rates
    const points = recent.map((p,i)=>{
      if(i===0) return {ts:p.ts, hit_r:0, miss_r:0, put_r:0, inv_r:0, ratio:p.hit_ratio};
      const prev = recent[i-1];
      const dt = (p.ts - prev.ts)/1000 || 1;
      return {
        ts:p.ts,
        hit_r:(p.hits - prev.hits)/dt,
        miss_r:(p.misses - prev.misses)/dt,
        put_r:(p.puts - prev.puts)/dt,
        inv_r:(p.invalidations - prev.invalidations)/dt,
        ratio:p.hit_ratio
      };
    }).slice(1); // drop first zero diff
    const maxRate = Math.max(1, ...points.map(p=>p.hit_r), ...points.map(p=>p.miss_r), ...points.map(p=>p.put_r), ...points.map(p=>p.inv_r));
    const minTs = points[0].ts; const maxTs = points[points.length-1].ts; const span = maxTs-minTs || 1;
    const toXY = (p:any, field:string)=>{
      const x = ((p.ts - minTs)/span)*(w-2*pad)+pad;
      const y = h - ((p[field])/maxRate)*(h-2*pad) - pad;
      return {x,y};
    };
    const buildPath = (field:string)=> points.map((p,i)=>{
      const {x,y}=toXY(p,field); return `${i===0?'M':'L'}${x.toFixed(1)},${y.toFixed(1)}`; }).join(' ');
    const paths = {
      hits: buildPath('hit_r'),
      misses: buildPath('miss_r'),
      puts: buildPath('put_r'),
      inv: buildPath('inv_r')
    };
    const gridLines = 4;
    // Histogram config
    const barH = 64; // height of histogram section
    const barGap = 2;
    const barAreaWidth = w - 2*pad;
    const barCount = points.length;
    const barWidth = Math.max(2, (barAreaWidth / barCount) - barGap);
    const totals = points.map(p=> p.hit_r + p.miss_r + p.put_r + p.inv_r);
    const maxTotal = Math.max(1, ...totals);
    return (
      <div className="relative">
        <svg viewBox={`0 0 ${w} ${h + barH + 30}`} className="w-full" style={{height: 'calc(12rem + 70px)'}}>
          {/* Line chart area */}
          <g>
            {Array.from({length:gridLines+1}).map((_,i)=>{
              const y = (i/gridLines)*h; return <line key={i} x1={0} x2={w} y1={y} y2={y} stroke="#f1f5f9" strokeWidth={1}/>; })}
            <path d={paths.hits} stroke="#10b981" fill="none" strokeWidth={2} />
            <path d={paths.misses} stroke="#ef4444" fill="none" strokeWidth={2} />
            <path d={paths.puts} stroke="#6366f1" fill="none" strokeWidth={2} />
            <path d={paths.inv} stroke="#f59e0b" fill="none" strokeWidth={2} strokeDasharray="4 4" />
          </g>
          {/* Histogram area */}
          <g transform={`translate(0,${h+20})`}>
            <rect x={0} y={0} width={w} height={barH} fill="#f8fafc" />
            {points.map((p,i)=>{
              const total = totals[i];
              const x = pad + i*(barWidth+barGap);
              const scale = total/maxTotal;
              const fullHeight = scale * (barH-4);
              let yCursor = barH-2;
              const seg = (val:number,color:string)=>{
                if(total <= 0 || fullHeight <= 0) return null;
                const hSeg = total > 0 ? (val/total)*fullHeight : 0;
                if(!isFinite(hSeg) || hSeg <= 0) return null;
                yCursor -= hSeg;
                return <rect key={color} x={x} y={yCursor} width={barWidth} height={hSeg} fill={color} rx={1} />;
              };
              const segments = [
                seg(p.hit_r,'#10b981'),
                seg(p.miss_r,'#ef4444'),
                seg(p.put_r,'#6366f1'),
                seg(p.inv_r,'#f59e0b')
              ].filter(Boolean);
              if(segments.length===0){
                // draw a minimal placeholder bar to indicate time slot with zero ops
                return <rect key={p.ts} x={x} y={barH-5} width={barWidth} height={3} fill="#e2e8f0" rx={1} />;
              }
              return <g key={p.ts}>{segments}</g>;
            })}
            {/* axis line */}
            <line x1={0} x2={w} y1={barH-1} y2={barH-1} stroke="#e2e8f0" />
            <text x={pad} y={12} fontSize={10} fill="#64748b">ops/sec histogram (stacked)</text>
            <text x={w-pad} y={12} fontSize={10} textAnchor="end" fill="#64748b">max {maxTotal.toFixed(1)}/s</text>
          </g>
        </svg>
        <div className="flex flex-wrap gap-4 mt-2 text-xs text-gray-600">
          <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-[#10b981]"></span>hits</span>
          <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-[#ef4444]"></span>misses</span>
          <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-[#6366f1]"></span>writes</span>
          <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-[#f59e0b]"></span>invalidations</span>
          <span className="ml-auto text-gray-500">max line {maxRate.toFixed(1)}/s</span>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="bg-white rounded-xl p-6 shadow-sm">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-800">Cache Overview (Rates)</h2>
          <span className="text-xs text-gray-500">live {history.length>0 && 'last '+(history.length*4/60).toFixed(1)+' min'}</span>
        </div>
        <RatesChart />
        <div className="mt-4 flex flex-wrap gap-6 text-xs text-gray-600">
          <div>Hit Ratio <span className="font-semibold text-gray-800">{stats? (stats.hit_ratio*100).toFixed(2)+'%':'—'}</span></div>
          <div>Total Hits <span className="font-semibold text-gray-800">{stats?.hits ?? '—'}</span></div>
            <div>Total Misses <span className="font-semibold text-gray-800">{stats?.misses ?? '—'}</span></div>
          <div>Writes <span className="font-semibold text-gray-800">{stats?.puts ?? '—'}</span></div>
          <div>Invalidations <span className="font-semibold text-gray-800">{stats?.invalidations ?? '—'}</span></div>
          <div>Items <span className="font-semibold text-gray-800">{stats?.items ?? '—'}</span></div>
          <div>Tags <span className="font-semibold text-gray-800">{stats?.tags ?? '—'}</span></div>
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
              <div className="mt-2 text-xs text-gray-600">
                {stats ? (
                  <>
                    <div>Reads <span className="font-medium text-gray-800">{(stats.hits + stats.misses).toLocaleString()}</span></div>
                    <div className="text-gray-500">{stats.hits} hits / {stats.misses} misses</div>
                  </>
                ) : ''}
              </div>
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
        <div className="bg-white rounded-xl p-6 shadow-sm flex flex-col">
          <h3 className="text-sm font-medium text-gray-600 mb-3">Memory Usage</h3>
          <div className="flex-1 relative h-40">
            {memSeries.length>1 ? (
              <svg className="absolute inset-0 w-full h-full">
                {/* grid */}
                <defs>
                  <linearGradient id="memLine" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#0ea5e9" stopOpacity="0.8" />
                    <stop offset="100%" stopColor="#0ea5e9" stopOpacity="0.1" />
                  </linearGradient>
                </defs>
                {(()=>{
                  const pts = memSeries;
                  const max = Math.max(...pts.map(p=>p.bytes),1);
                  const min = Math.min(...pts.map(p=>p.bytes));
                  const range = Math.max(max-min,1);
                  const w = 300; const h = 160;
                  const path = pts.map((p,i)=>{
                    const x = (i/(pts.length-1))*w;
                    const y = h - ((p.bytes-min)/range)*h;
                    return `${i===0?'M':'L'}${x.toFixed(1)},${y.toFixed(1)}`;
                  }).join(' ');
                  return <>
                    <path d={path} fill="none" stroke="#0ea5e9" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                    <path d={path + ` L ${w},${h} L 0,${h} Z`} fill="url(#memLine)" opacity="0.3" />
                  </>;
                })()}
              </svg>
            ) : <div className="text-xs text-gray-400">Collecting data...</div>}
          </div>
          <div className="mt-2 text-xs text-gray-600 flex flex-wrap gap-4">
            <span>Current {(stats?.bytes||0)/1024 ? ((stats?.bytes||0)/1024).toFixed(1)+' KB':'—'}</span>
            {memSeries.length>1 && (
              <>
                <span>Min {Math.min(...memSeries.map(p=>p.bytes))/1024|0} KB</span>
                <span>Max {Math.max(...memSeries.map(p=>p.bytes))/1024|0} KB</span>
              </>
            )}
          </div>
        </div>
      </div>

      <div className="bg-white rounded-xl shadow-sm p-6">
        <h3 className="text-lg font-semibold text-gray-800 mb-4">Tag Distribution (Top)</h3>
        <TagDistribution />
      </div>

      {stats?.shard_count && stats.shard_items && stats.shard_bytes && (
        <div className="bg-white rounded-xl shadow-sm p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-800">Shard Breakdown</h3>
            <span className="text-xs text-gray-500">{stats.shard_count} shards</span>
          </div>
          {(()=>{
            // Precompute maxima for dynamic color scaling
            const maxShardItems = Math.max(...stats.shard_items!);
            const maxShardBytes = Math.max(...stats.shard_bytes!);
            function loadColor(load:number,max:number){
              if(max<=0) return '#94a3b8';
              const r = Math.min(1, load/max);
              // Map load 0..1 to hue 140 (green) -> 25 (orange) -> 0 (red) for heavy
              const hue = r < 0.5 ? 140 - (r*2)*(140-80) : 80 - ((r-0.5)*2*55); // two‑segment curve
              const sat = 70 + r*20; // 70%..90%
              const light = 55 - r*15; // 55%..40%
              return `hsl(${Math.round(hue)}deg ${sat.toFixed(1)}% ${light.toFixed(1)}%)`;
            }
            return null; // just defining helpers in closure scope for the table below
          })()}
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left text-gray-500 border-b"><th className="py-2 pr-4">Shard</th><th className="py-2 pr-4">Items</th><th className="py-2 pr-4">Bytes</th><th className="py-2 pr-4">% Items</th><th className="py-2 pr-4">% Bytes</th></tr>
              </thead>
              <tbody>
                {(()=>{
                  const maxShardItems = Math.max(...stats.shard_items!);
                  const maxShardBytes = Math.max(...stats.shard_bytes!);
                  function loadColor(load:number,max:number){
                    if(max<=0) return '#94a3b8';
                    const r = Math.min(1, load/max);
                    const hue = 140 - r*140; // 140 (green) -> 0 (red)
                    const sat = 65 + r*25; // 65%..90%
                    const light = 52 - r*20; // 52%..32%
                    return `hsl(${hue.toFixed(0)}deg ${sat.toFixed(1)}% ${light.toFixed(1)}%)`;
                  }
                  return stats.shard_items.map((it,i)=>{
                    const bytes = stats.shard_bytes![i];
                    const pctI = stats.items? (it/Math.max(1,stats.items))*100:0;
                    const pctB = stats.bytes? (bytes/Math.max(1,stats.bytes))*100:0;
                    const colorI = loadColor(it, maxShardItems);
                    const colorB = loadColor(bytes, maxShardBytes);
                    const prevIt = prevStats?.shard_items?.[i];
                    const prevBytes = prevStats?.shard_bytes?.[i];
                    const deltaIt = prevIt!==undefined? it - prevIt : 0;
                    const deltaB = prevBytes!==undefined? bytes - prevBytes : 0;
          const deltaBadge = (d:number)=> d===0? null : <span className={`ml-1 inline-block align-middle ${d>0?'text-emerald-600':'text-red-600'}`}>{d>0?'▲':'▼'}</span>;
                    return (
                      <tr key={i} className="border-b last:border-none hover:bg-gray-50 transition-colors">
                        <td className="py-1 pr-4 font-mono">#{i}</td>
            <td className="py-1 pr-4 tabular-nums whitespace-nowrap">{it}{deltaBadge(deltaIt)}</td>
            <td className="py-1 pr-4 tabular-nums whitespace-nowrap">{(bytes/1024).toFixed(1)} KB{deltaBadge(deltaB)}</td>
                        <td className="py-1 pr-4">
                          <div className="w-24 h-2 bg-gray-200 rounded overflow-hidden">
                            <div className="h-full transition-all duration-500" style={{width:pctI+'%', background:colorI}} />
                          </div>
                        </td>
                        <td className="py-1 pr-4">
                          <div className="w-24 h-2 bg-gray-200 rounded overflow-hidden">
                            <div className="h-full transition-all duration-500" style={{width:pctB+'%', background:colorB}} />
                          </div>
                        </td>
                      </tr>
                    );
                  });
                })()}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}