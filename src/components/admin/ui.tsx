import React from 'react';

/** Shared, reusable presentational primitives for the admin console. */

const ACCENT: Record<string, string> = {
  indigo: 'bg-indigo-50 dark:bg-indigo-950/30 text-indigo-600 dark:text-indigo-400',
  sky: 'bg-sky-50 dark:bg-sky-950/30 text-sky-600 dark:text-sky-400',
  rose: 'bg-rose-50 dark:bg-rose-950/30 text-rose-600 dark:text-rose-400',
  violet: 'bg-violet-50 dark:bg-violet-950/30 text-violet-600 dark:text-violet-400',
  amber: 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400',
  emerald: 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400',
  slate: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
};

const BADGE: Record<string, string> = {
  new: 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400',
  contacted: 'bg-sky-50 text-sky-700 dark:bg-sky-950/30 dark:text-sky-400',
  booked: 'bg-violet-50 text-violet-700 dark:bg-violet-950/30 dark:text-violet-400',
  converted: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
  completed: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
  success: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
  active: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
  pending: 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
  in_progress: 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
  abandoned: 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400',
  failed: 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400',
  lost: 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400',
  spam: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
  neutral: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

export function AdminPageHeader({
  icon: Icon, title, subtitle, action,
}: { icon?: any; title: string; subtitle?: string; action?: React.ReactNode }) {
  return (
    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
      <div className="flex items-start gap-3">
        {Icon && (
          <span className="w-11 h-11 rounded-2xl bg-indigo-50 dark:bg-indigo-950/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
            <Icon size={22} />
          </span>
        )}
        <div>
          <h1 className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 tracking-tight">{title}</h1>
          {subtitle && <p className="text-sm text-slate-500 mt-0.5">{subtitle}</p>}
        </div>
      </div>
      {action && <div className="flex items-center gap-2 flex-wrap">{action}</div>}
    </div>
  );
}

export function StatCard({
  icon: Icon, label, value, hint, accent = 'slate', highlight = false,
}: { icon?: any; label: string; value: React.ReactNode; hint?: string; accent?: string; highlight?: boolean }) {
  return (
    <div className={`rounded-2xl border p-5 transition-all hover:shadow-md bg-white dark:bg-slate-900 ${
      highlight ? 'border-indigo-300' : 'border-slate-200 dark:border-slate-800'
    }`}>
      <div className="text-xs uppercase tracking-wider font-bold flex items-center gap-2 text-slate-500">
        {Icon && (
          <span className={`w-7 h-7 rounded-lg flex items-center justify-center ${ACCENT[highlight ? 'indigo' : accent]}`}>
            <Icon size={14} />
          </span>
        )}
        {label}
      </div>
      <div className="text-3xl font-extrabold mt-2 text-slate-800 dark:text-slate-100">{value}</div>
      {hint && <div className="text-xs mt-1 text-slate-400">{hint}</div>}
    </div>
  );
}

export function Badge({ children, variant = 'neutral' }: { children: React.ReactNode; variant?: string }) {
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wide ${BADGE[variant] || BADGE.neutral}`}>
      {children}
    </span>
  );
}

export function Card({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return (
    <div className={`bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-sm ${className}`}>
      {children}
    </div>
  );
}

export function EmptyState({ icon: Icon, title, desc }: { icon?: any; title: string; desc?: string }) {
  return (
    <div className="flex flex-col items-center justify-center text-center py-16 px-4">
      {Icon && (
        <span className="w-16 h-16 rounded-3xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mb-4">
          <Icon size={30} />
        </span>
      )}
      <h3 className="text-lg font-bold text-slate-700 dark:text-slate-200">{title}</h3>
      {desc && <p className="text-sm text-slate-400 max-w-sm mt-1">{desc}</p>}
    </div>
  );
}

export const inr = (n: number) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
export const num = (n: number) => Number(n || 0).toLocaleString('en-IN');
export const fmtDate = (d?: string | null) =>
  d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
export const fmtDateTime = (d?: string | null) =>
  d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
