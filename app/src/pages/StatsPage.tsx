import { useEffect, useState } from 'react';
import { api } from '../api/client';

interface Stats { hits: number; misses: number; puts: number; invalidations: number; hit_ratio: number }

export default function StatsPage() {
  const [stats, setStats] = useState<Stats | null>(null);
  const [err, setErr] = useState<string | null>(null);
  useEffect(() => {
    let active = true;
    const fetchStats = async () => {
      try {
        const r = await api.get('/stats');
        if (active) setStats(r.data);
      } catch(e:any) { if (active) setErr(e.message); }
    };
    fetchStats();
    const id = setInterval(fetchStats, 5000);
    return () => { active = false; clearInterval(id); };
  }, []);
  return (
    <div className="space-y-4">
      <h1 className="font-semibold">Stats</h1>
      {err && <div className="text-red-600 text-sm">{err}</div>}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          {Object.entries(stats).map(([k,v]) => (
            <div key={k} className="border rounded p-3 bg-gray-50 dark:bg-gray-800">
              <div className="uppercase text-[10px] tracking-wide text-gray-500 mb-1">{k}</div>
              <div className="text-lg font-mono">{typeof v === 'number' ? v.toFixed( k==='hit_ratio'?2:0) : String(v)}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
