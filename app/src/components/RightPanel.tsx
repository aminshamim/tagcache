import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import CodeMirror from '@uiw/react-codemirror';
import { json as jsonLang } from '@codemirror/lang-json';
import { Minimize2, Maximize2 } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { deleteKey, getKey, listKeys } from '../api/client';
import { useAuthStore } from '../store/auth';
import { useSelectionStore } from '../store/selection';

export function RightPanel() {
  const token = useAuthStore(s=>s.token);
  const { selectedKey, clear, triggerInvalidated } = useSelectionStore();
  const hasSelection = !!selectedKey;
  const [copied, setCopied] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const navigate = useNavigate();
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

  // Selected key details
  const sel = useQuery({
    queryKey: ['key-detail', selectedKey, !!token],
    queryFn: async ()=>{
      if(!selectedKey) throw new Error('no selection');
      return await getKey(selectedKey);
    },
    enabled: !!token && !!selectedKey,
    staleTime: 0,
    retry: 1
  });

  const now = Date.now();
  return (
    <div className="space-y-8">
      {hasSelection && (
        <div className="transition-all duration-300">
          <div className="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <div className="px-3 py-2 bg-gradient-to-r from-brand-primary/10 to-brand-teal/10 border-b border-gray-200">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold text-gray-800">Key Details</h3>
                <button className="text-xs text-gray-600 hover:text-gray-800" onClick={()=>clear()} aria-label="Close details">Close</button>
              </div>
            </div>
            <div className="p-3 space-y-3">
              {sel.isLoading && <div className="text-xs text-gray-500">Loading…</div>}
              {sel.error && <div className="text-xs text-red-600">Error loading key</div>}
              {sel.data && (
                <>
                  <div>
                    <div className="text-[10px] uppercase text-gray-500">Key</div>
                    <div className="font-mono text-xs break-all text-gray-900">{sel.data.key}</div>
                  </div>
                  <div className="text-xs space-y-1">
                    <div className="flex items-center justify-between">
                      <div className="text-[10px] uppercase text-gray-500">TTL</div>
                      <div className={`font-mono whitespace-nowrap ml-3 ${sel.data.ttl_ms===null ? 'text-gray-600' : 'text-emerald-700'}`}>
                        {sel.data.ttl_ms === null ? '∞' : `${Math.floor((sel.data.ttl_ms||0)/1000)}s`}
                      </div>
                    </div>
                    {sel.data.created_ms && (
                      <div className="flex items-center justify-between">
                        <div className="text-[10px] uppercase text-gray-500">Created</div>
                        <div className="font-mono whitespace-nowrap ml-3 text-gray-600">{new Date(sel.data.created_ms).toLocaleString()}</div>
                      </div>
                    )}
                  </div>
                  <div>
                    <div className="text-[10px] uppercase text-gray-500 mb-1">Tags</div>
                    {sel.data.tags && sel.data.tags.length>0 ? (
                      <div className="flex flex-wrap gap-1">
                          {sel.data.tags.map(t=> (
                            <button key={t} type="button" onClick={(e)=>{ e.stopPropagation(); navigate(`/tags?tags=${encodeURIComponent(t)}`); }} className="inline-flex items-center rounded-full border border-brand-teal/30 bg-white text-brand-teal px-1.5 py-0.5 text-[10px] shadow-sm hover:bg-brand-teal/10">{t}</button>
                          ))}
                      </div>
                    ) : (
                      <div className="text-xs text-gray-400">—</div>
                    )}
                  </div>
                  <div className="relative">
                    <div className="flex items-center justify-between mb-1">
                      <div className="text-[10px] uppercase text-gray-500">Value</div>
                      {/* type/size badges + controls */}
                      {(() => {
                        const raw = sel.data!.value as any;
                        let isJson = false;
                        let pretty = '';
                        let min = '';
                        if (typeof raw === 'string') {
                          try { const parsed = JSON.parse(raw); pretty = JSON.stringify(parsed, null, 2); min = JSON.stringify(parsed); isJson = true; } catch { pretty = raw; min = raw; }
                        } else { pretty = JSON.stringify(raw, null, 2); min = JSON.stringify(raw); isJson = true; }
                        const text = collapsed ? min : pretty;
                        const size = new Blob([text]).size;
                        return (
                          <div className="flex items-center gap-2">
                            <span className={`px-1.5 py-0.5 rounded-full border text-[10px] ${isJson ? 'border-emerald-300 text-emerald-700 bg-emerald-50' : 'border-gray-300 text-gray-600 bg-gray-50'}`}>{isJson ? 'JSON' : 'Text'}</span>
                            <span className="px-1.5 py-0.5 rounded-full border border-gray-200 text-[10px] text-gray-600 bg-white">{size} B</span>
                            <div className="h-3 w-px bg-gray-200 mx-1" />
                            <button
                              type="button"
                              onClick={()=>setCollapsed(true)}
                              className={`h-6 w-6 inline-flex items-center justify-center rounded border ${collapsed ? 'bg-gray-200 text-gray-800' : 'bg-white text-gray-700 hover:bg-gray-100'} border-gray-300`}
                              title="Collapse all"
                              aria-label="Collapse all"
                            >
                              <Minimize2 className="h-3.5 w-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={()=>setCollapsed(false)}
                              className={`h-6 w-6 inline-flex items-center justify-center rounded border ${!collapsed ? 'bg-gray-200 text-gray-800' : 'bg-white text-gray-700 hover:bg-gray-100'} border-gray-300`}
                              title="Expand all"
                              aria-label="Expand all"
                            >
                              <Maximize2 className="h-3.5 w-3.5" />
                            </button>
                          </div>
                        );
                      })()}
                    </div>
                    {/* Copy button */}
                    <button
                      onClick={async()=>{
                        try {
                          const raw = sel.data!.value as any;
                          let pretty = '';
                          let min = '';
                          if (typeof raw === 'string') { try { const parsed = JSON.parse(raw); pretty = JSON.stringify(parsed, null, 2); min = JSON.stringify(parsed); } catch { pretty = raw; min = raw; } }
                          else { pretty = JSON.stringify(raw, null, 2); min = JSON.stringify(raw); }
                          const text = collapsed ? min : pretty;
                          await navigator.clipboard.writeText(text);
                          setCopied(true);
                          window.setTimeout(()=>setCopied(false), 1200);
                        } catch {}
                      }}
                      className="absolute right-2 -top-6 text-[10px] px-2 py-0.5 rounded bg-gray-200 hover:bg-gray-300 text-gray-800"
                      aria-label="Copy value"
                    >{copied ? 'Copied' : 'Copy'}</button>
                    {/* Code editor */}
                    {(() => {
                      const raw = sel.data!.value as any;
                      let isJson = false;
                      let pretty = '';
                      let min = '';
                      if (typeof raw === 'string') {
                        try { const parsed = JSON.parse(raw); pretty = JSON.stringify(parsed, null, 2); min = JSON.stringify(parsed); isJson = true; } catch { pretty = raw; min = raw; }
                      } else { pretty = JSON.stringify(raw, null, 2); min = JSON.stringify(raw); isJson = true; }
                      const text = collapsed ? min : pretty;
                      return (
                        <div className="rounded-lg overflow-hidden border border-gray-200">
                          <CodeMirror
                            value={text}
                            height="292px"
                            editable={false}
                            basicSetup={{ lineNumbers: true, highlightActiveLine: false }}
                            className="text-[10px]"
                            extensions={isJson ? [jsonLang()] : []}
                          />
                        </div>
                      );
                    })()}
                  </div>
                </>
              )}
            </div>
            <div className="px-3 py-2 bg-gray-50 border-t border-gray-200 flex items-center justify-end gap-2">
              <button
                onClick={async()=>{
                  if(!selectedKey) return;
                  try {
                    const res = await deleteKey(selectedKey);
                    if(res.ok){ triggerInvalidated(selectedKey); }
                  } catch {}
                }}
                className="px-2.5 py-1 text-xs rounded bg-red-600 text-white hover:bg-red-700"
              >Invalidate</button>
              <button onClick={()=>sel.refetch()} className="px-2.5 py-1 text-xs rounded bg-white border border-gray-300 hover:bg-gray-100">Refresh</button>
            </div>
          </div>
        </div>
      )}
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
                      {k.tags.slice(0,6).map(t=> (
                        <button key={t} type="button" onClick={(e)=>{ e.stopPropagation(); navigate(`/tags?tags=${encodeURIComponent(t)}`); }} className="inline-flex items-center rounded-full border border-brand-teal/30 bg-white text-brand-teal px-1.5 py-0.5 text-[10px] shadow-sm hover:bg-brand-teal/10">{t}</button>
                      ))}
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