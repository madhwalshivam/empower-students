import React from 'react';
import Link from 'next/link';
import { Users, Search, ArrowRight, Crown } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card, EmptyState, inr, fmtDate } from '@/components/admin/ui';

export const dynamic = 'force-dynamic';

export default async function AdminParentsPage({ searchParams }: { searchParams: Promise<{ q?: string }> }) {
  await requireAdminUser();
  const { q } = await searchParams;
  const query = (q || '').trim().toLowerCase();
  const db = createAdminClient();

  const [{ data: parents }, { data: children }, { data: assessments }, { data: payments }] = await Promise.all([
    db.from('parents').select('*').order('created_at', { ascending: false }),
    db.from('children').select('id, parent_id'),
    db.from('assessments').select('child_id, status'),
    db.from('payment_orders').select('parent_id, amount, status'),
  ]);

  const childByParent = new Map<string, number>();
  const childToParent = new Map<number, string>();
  (children || []).forEach((c) => {
    childByParent.set(c.parent_id, (childByParent.get(c.parent_id) || 0) + 1);
    childToParent.set(c.id, c.parent_id);
  });

  const doneByParent = new Map<string, number>();
  (assessments || []).forEach((a) => {
    if (a.status === 'completed' || a.status === 'done') {
      const pid = childToParent.get(a.child_id);
      if (pid) doneByParent.set(pid, (doneByParent.get(pid) || 0) + 1);
    }
  });

  const revByParent = new Map<string, number>();
  (payments || []).forEach((p) => {
    if (p.status === 'success') revByParent.set(p.parent_id, (revByParent.get(p.parent_id) || 0) + (p.amount || 0));
  });

  let rows = parents || [];
  if (query) {
    rows = rows.filter((p) =>
      [p.name, p.whatsapp, p.email, p.city].some((v) => String(v || '').toLowerCase().includes(query))
    );
  }

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={Users} title="Parents" subtitle="All registered families — credits, activity and revenue." />

      <Card className="p-3">
        <form method="GET" className="flex items-center gap-2">
          <div className="relative flex-1">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              name="q"
              defaultValue={q || ''}
              placeholder="Search name / phone / email / city"
              className="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
            />
          </div>
          <button type="submit" className="bg-indigo-600 text-white font-bold text-sm px-6 py-2.5 rounded-xl border-0 cursor-pointer hover:bg-indigo-700">
            Search
          </button>
        </form>
      </Card>

      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={Users} title="No parents found" desc={query ? 'Try a different search term.' : 'Registered parents will appear here.'} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs uppercase text-slate-400 text-left border-b border-slate-100 dark:border-slate-800">
                <tr>
                  <th className="px-5 py-3.5 font-bold">Name</th>
                  <th className="px-3 py-3.5 font-bold">WhatsApp</th>
                  <th className="px-3 py-3.5 font-bold text-right">Credits</th>
                  <th className="px-3 py-3.5 font-bold text-right">Kids</th>
                  <th className="px-3 py-3.5 font-bold text-right">Done</th>
                  <th className="px-3 py-3.5 font-bold text-right">Revenue</th>
                  <th className="px-3 py-3.5 font-bold">Last login</th>
                  <th className="px-5 py-3.5" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {rows.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                    <td className="px-5 py-3.5">
                      <div className="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                        {p.name || '—'}
                        {!!p.is_vip && <Crown size={13} className="text-amber-500" />}
                      </div>
                      {p.email && <div className="text-xs text-slate-400">{p.email}</div>}
                    </td>
                    <td className="px-3 py-3.5 text-slate-500 font-medium">{p.whatsapp || '—'}</td>
                    <td className={`px-3 py-3.5 text-right font-bold ${(p.credits || 0) > 0 ? 'text-slate-700 dark:text-slate-200' : 'text-rose-500'}`}>{p.credits || 0}</td>
                    <td className="px-3 py-3.5 text-right text-slate-600 dark:text-slate-300">{childByParent.get(p.id) || 0}</td>
                    <td className="px-3 py-3.5 text-right text-slate-600 dark:text-slate-300">{doneByParent.get(p.id) || 0}</td>
                    <td className="px-3 py-3.5 text-right font-semibold text-emerald-600">{inr(revByParent.get(p.id) || 0)}</td>
                    <td className="px-3 py-3.5 text-xs text-slate-400">{fmtDate(p.last_login || p.created_at)}</td>
                    <td className="px-5 py-3.5 text-right">
                      <Link href={`/admin/parents/${p.id}`} className="text-indigo-600 font-bold text-xs inline-flex items-center gap-1 hover:underline">
                        open <ArrowRight size={12} />
                      </Link>
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
