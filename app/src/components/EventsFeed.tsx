import { useEventStore } from '../store/events';

export function EventsFeed(){
  const events = useEventStore(s=>s.events);
  return (
    <div className="metric-card h-72 flex flex-col text-xs overflow-auto">
      <div className="font-semibold mb-2">Events</div>
      <ul className="space-y-1">
        {events.map(e=> (
          <li key={e.ts+e.detail} className="flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-brand-teal" />
            <span className="font-mono text-[10px] opacity-60">{new Date(e.ts).toLocaleTimeString()}</span>
            <span className="font-semibold text-ink/70">{e.type}</span>
            <span className="truncate">{e.detail}</span>
          </li>
        ))}
        {events.length===0 && <li className="opacity-60">No events yet</li>}
      </ul>
    </div>
  );
}
