import React from 'react';
import { ClipboardList, FileText } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card, StatCard, Badge, EmptyState, inr, fmtDateTime } from '@/components/admin/ui';

export const dynamic = 'force-dynamic';

export default async function AdminEvaluationsPage({ searchParams }: { searchParams: Promise<{ q?: string; status?: string }> }) {
  await requireAdminUser();
  const { q, status } = await searchParams;
  const db = createAdminClient();

  // Graceful: parent_reflect_sessions may not exist yet.
  let sessions: any[] = [];
  try {
    const { data, error } = await db
      .from('parent_reflect_sessions')
      .select('*, parent:parents(name, whatsapp)')
      .order('id', { ascending: false })
      .limit(200);
    if (!error) sessions = data || [];
  } catch { /* table absent */ }

  let rows = sessions;
  if (status && status !== 'all') rows = rows.filter((s) => s.status === status);
  if (q) {
    const ql = q.toLowerCase();
    rows = rows.filter((s) => [s.parent?.name, s.parent?.whatsapp].some((v) => String(v || '').toLowerCase().includes(ql)));
  }

  const completed = sessions.filter((s) => s.status === 'completed').length;
  const hasPdf = sessions.filter((s) => s.parent_summary_md).length;
  const refunded = sessions.filter((s) => s.status === 'refunded').length;
  const followed = sessions.filter((s) => s.admin_follow_up_by).length;

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={ClipboardList} title="Evaluations" subtitle="Parent reflection sessions — newest first, up to 200." />

      {/* Filters */}
      <Card className="p-4">
        <form method="GET" className="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
          <div className="sm:col-span-2">
            <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Parent name / WhatsApp</label>
            <input name="q" defaultValue={q || ''} placeholder="Jyoti or +9198…" className="w-full mt-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" />
          </div>
          <div>
            <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Status</label>
            <select name="status" defaultValue={status || 'all'} className="w-full mt-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
              <option value="all">All</option>
              <option value="completed">Completed</option>
              <option value="in_progress">In progress</option>
              <option value="abandoned">Abandoned</option>
              <option value="refunded">Refunded</option>
            </select>
          </div>
          <button type="submit" className="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-xl border-0 cursor-pointer">Apply</button>
        </form>
      </Card>

      {/* Stat cards */}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <StatCard label="Total shown" value={rows.length} accent="slate" />
        <StatCard label="Completed" value={completed} accent="emerald" />
        <StatCard label="Has PDF" value={hasPdf} accent="violet" />
        <StatCard label="Refunded" value={refunded} accent="amber" />
        <StatCard label="Followed-up" value={followed} accent="sky" />
      </div>

      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={ClipboardList} title="No reflection sessions yet" desc="Parent reflection evaluations (₹499 each) will be listed here once parents complete them." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs uppercase text-slate-400 text-left border-b border-slate-100 dark:border-slate-800">
                <tr>
                  <th className="px-5 py-3.5 font-bold">#</th>
                  <th className="px-3 py-3.5 font-bold">Parent</th>
                  <th className="px-3 py-3.5 font-bold">Started</th>
                  <th className="px-3 py-3.5 font-bold">Status</th>
                  <th className="px-3 py-3.5 font-bold text-center">Phase</th>
                  <th className="px-3 py-3.5 font-bold text-right">Cost</th>
                  <th className="px-5 py-3.5 font-bold">Report</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {rows.map((s) => (
                  <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <td className="px-5 py-3.5 text-slate-400">#{s.id}</td>
                    <td className="px-3 py-3.5">
                      <div className="font-semibold text-slate-700 dark:text-slate-200">{s.parent?.name || '—'}</div>
                      <div className="text-xs text-slate-400">{s.parent?.whatsapp || ''}</div>
                    </td>
                    <td className="px-3 py-3.5 text-xs text-slate-500">{fmtDateTime(s.started_at)}</td>
                    <td className="px-3 py-3.5"><Badge variant={s.status}>{s.status}</Badge></td>
                    <td className="px-3 py-3.5 text-center text-slate-600 dark:text-slate-300">{s.current_phase || 1}/10</td>
                    <td className="px-3 py-3.5 text-right font-semibold">{inr(s.cost_paid || 0)}</td>
                    <td className="px-5 py-3.5">
                      {s.parent_summary_md ? (
                        <span className="inline-flex items-center gap-1 text-emerald-600 text-xs font-bold"><FileText size={13} /> Ready</span>
                      ) : <span className="text-slate-300">—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
