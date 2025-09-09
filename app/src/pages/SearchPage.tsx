import { useState } from 'react';
import { api } from '../api/client';

interface SearchResultRow { key: string; ttl_ms?: number; tags?: string[] }

export default function SearchPage() {
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(false);
  const [results, setResults] = useState<SearchResultRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  async function runSearch() {
    setLoading(true); setError(null);
    try {
      // Placeholder: server lacks /search; simulate local filter over last results
  const resp = await api.post('/search', { q: query });
  setResults(resp.data.keys || []);
    } catch (e: any) {
      setError(e?.response?.data?.error || e.message);
    } finally { setLoading(false); }
  }

  return (
    <div className="space-y-4">
      <div className="flex gap-2 items-end">
        <div className="flex-1">
          <label className="block text-xs uppercase font-semibold mb-1">Query</label>
          <input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Search by key" className="w-full border rounded px-2 py-1 bg-transparent" />
        </div>
        <button disabled={loading} onClick={runSearch} className="px-3 py-1 rounded bg-blue-600 text-white disabled:opacity-50">{loading? 'Searching…':'Search'}</button>
      </div>
      {error && <div className="text-red-600 text-sm">Error: {error}</div>}
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
            <tr key={r.key} className="border-b last:border-none">
              <td className="py-1 pr-2 font-mono text-xs">{r.key}</td>
              <td className="py-1 pr-2 text-xs">{r.ttl_ms ?? ''}</td>
              <td className="py-1 pr-2">{r.tags?.map(t => <span key={t} className="inline-block bg-gray-200 dark:bg-gray-700 rounded px-1 mr-1 mb-1 text-[10px]">{t}</span>)}</td>
            </tr>
          ))}
          {!loading && results.length === 0 && <tr><td colSpan={3} className="py-4 text-center text-xs text-gray-500">No results</td></tr>}
        </tbody>
      </table>
    </div>
  );
}
