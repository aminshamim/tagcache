import { ReactNode } from 'react';

interface MetricCardProps { title: string; value: ReactNode; sub?: ReactNode; className?: string }
export function MetricCard({ title, value, sub, className="" }: MetricCardProps) {
  return (
    <div className={`metric-card hover-lift animate-fade-in ${className}`}>
      <div className="text-[10px] uppercase tracking-wide font-semibold text-ink/70 mb-1">{title}</div>
      <div className="text-2xl font-bold text-ink mb-1">{value}</div>
      {sub && <div className="text-[11px] text-ink/60">{sub}</div>}
    </div>
  );
}
