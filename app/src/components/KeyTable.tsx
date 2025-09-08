import { useQuery } from '@tanstack/react-query';
import { listKeys, type KeyEntry } from '../api/client';
import { useState, useMemo } from 'react';
import { hashTagToColor } from '../utils/tagColor';


export function KeyTable(){
  const [page,setPage]=useState(0);
  const [q,setQ]=useState('');
  const { data, isLoading } = useQuery<KeyEntry[]>({
    queryKey:['keys', page, q],
    queryFn: async ()=>{
      const res = await listKeys();
      return res;
    },
    refetchInterval: 5000
  });
  const pageSize=25;
  const filtered = useMemo(()=> (data||[]).filter((k:KeyEntry)=> k.key.includes(q) || k.tags.some((t:string)=>t.includes(q))),[data,q]);
  const paged = filtered.slice(page*pageSize, page*pageSize+pageSize);
  return (
    <div className="metric-card flex flex-col h-[420px]">
      <div className="flex items-center gap-2 mb-2">
        <h3 className="font-semibold">Keys</h3>
        <input value={q} onChange={e=>{setPage(0); setQ(e.target.value);}} placeholder="Search" className="input input-sm ml-auto" />
      </div>
      <div className="overflow-auto border rounded-md border-border flex-1">
        <table className="w-full text-xs">
          <thead className="text-ink/60">
            <tr>
              <th className="text-left p-2">Key</th>
              <th className="text-left p-2">Size</th>
              <th className="text-left p-2">TTL</th>
              <th className="text-left p-2">Tags</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={4} className="p-4 text-center">Loading...</td></tr>}
            {!isLoading && paged.map(k=> (
              <tr key={k.key} className="hover:bg-ink/5">
                <td className="p-2 font-mono text-[11px] max-w-[160px] truncate">{k.key}</td>
                <td className="p-2 tabular-nums">{k.size}</td>
                <td className="p-2 tabular-nums">{k.ttl}</td>
                <td className="p-2">
                  <div className="flex flex-wrap gap-1">
                    {k.tags.map(t=> (
                      <span key={t} className="px-1.5 py-0.5 rounded-md text-[10px] font-medium" style={{background: hashTagToColor(t,0.15), color: hashTagToColor(t,0.9)}}>{t}</span>
                    ))}
                  </div>
                </td>
              </tr>
            ))}
            {!isLoading && paged.length===0 && <tr><td colSpan={4} className="p-4 text-center opacity-60">No keys</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-end gap-2 mt-2 text-xs">
        <span className="opacity-60">{filtered.length} items</span>
        <button disabled={page===0} onClick={()=>setPage(p=>p-1)} className="btn btn-xs" >&lt;</button>
        <button disabled={(page+1)*pageSize>=filtered.length} onClick={()=>setPage(p=>p+1)} className="btn btn-xs" >&gt;</button>
      </div>
    </div>
  );
}
