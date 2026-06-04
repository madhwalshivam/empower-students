import React from 'react';
import Link from 'next/link';
import { CreditCard, TrendingUp, Clock, XCircle, Check } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card, StatCard, Badge, EmptyState, inr, fmtDateTime } from '@/components/admin/ui';
import Pagination, { parsePage } from '@/components/Pagination';
import ReverifyButton from './ReverifyButton';

export const dynamic = 'force-dynamic';

const FILTERS = ['all', 'success', 'pending', 'failed'];
const PAGE_SIZE = 20;

export default async function AdminPaymentsPage({ searchParams }: { searchParams: Promise<{ filter?: string; page?: string }> }) {
  await requireAdminUser();
  const { filter, page: pageRaw } = await searchParams;
  const active = FILTERS.includes(filter || '') ? filter! : 'all';
  const page = parsePage(pageRaw);
  const db = createAdminClient();

  // Counts are HEAD-only; revenue pulls just the `amount` column for successful
  // orders; and only the current page of full rows is fetched via .range().
  const [totalRes, pendingRes, failedRes, successAmts] = await Promise.all([
    db.from('payment_orders').select('*', { count: 'exact', head: true }),
    db.from('payment_orders').select('*', { count: 'exact', head: true }).eq('status', 'pending'),
    db.from('payment_orders').select('*', { count: 'exact', head: true }).eq('status', 'failed'),
    db.from('payment_orders').select('amount').eq('status', 'success'),
  ]);

  const revenue = (successAmts.data || []).reduce((s, o) => s + (o.amount || 0), 0);
  const successCount = (successAmts.data || []).length;
  const pendingCount = pendingRes.count || 0;
  const failedCount = failedRes.count || 0;
  const totalCount = totalRes.count || 0;

  const total =
    active === 'success' ? successCount :
    active === 'pending' ? pendingCount :
    active === 'failed' ? failedCount : totalCount;

  const fromIdx = (page - 1) * PAGE_SIZE;
  let listQuery = db
    .from('payment_orders')
    .select('*, parent:parents(name, whatsapp)')
    .order('created_at', { ascending: false });
  if (active !== 'all') listQuery = listQuery.eq('status', active);
  const { data: orders } = await listQuery.range(fromIdx, fromIdx + PAGE_SIZE - 1);
  const rows = orders || [];

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={CreditCard} title="Payments" subtitle="Cashfree wallet top-ups and order verification." />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard icon={TrendingUp} label="Revenue (success)" value={inr(revenue)} accent="emerald" />
        <StatCard icon={Clock} label="Pending" value={pendingCount} accent="amber" />
        <StatCard icon={XCircle} label="Failed" value={failedCount} accent="rose" />
      </div>

      <Card className="p-3 flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-1.5 flex-wrap">
          <span className="text-xs font-bold text-slate-400 uppercase tracking-wider mr-1">Filter</span>
          {FILTERS.map((f) => (
            <Link
              key={f}
              href={f === 'all' ? '/admin/payments' : `/admin/payments?filter=${f}`}
              className={`px-3 py-1.5 rounded-lg text-sm font-semibold capitalize transition-colors ${
                active === f ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'
              }`}
            >
              {f}
            </Link>
          ))}
        </div>
        <span className="text-xs text-slate-400">{total} total</span>
      </Card>

      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={CreditCard} title="No payments" desc="Wallet top-up orders will appear here once parents pay." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs uppercase text-slate-400 text-left border-b border-slate-100 dark:border-slate-800">
                <tr>
                  <th className="px-5 py-3.5 font-bold">Order</th>
                  <th className="px-3 py-3.5 font-bold">Parent</th>
                  <th className="px-3 py-3.5 font-bold">Amount / Status</th>
                  <th className="px-3 py-3.5 font-bold text-center">Credited</th>
                  <th className="px-3 py-3.5 font-bold">When</th>
                  <th className="px-5 py-3.5 font-bold text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {rows.map((o) => (
                  <tr key={o.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <td className="px-5 py-3.5 font-mono text-xs text-slate-600 dark:text-slate-300">{o.order_id}</td>
                    <td className="px-3 py-3.5">
                      <div className="font-semibold text-slate-700 dark:text-slate-200">{o.parent?.name || '—'}</div>
                      <div className="text-xs text-slate-400">{o.parent?.whatsapp || ''}</div>
                    </td>
                    <td className="px-3 py-3.5">
                      <span className="font-bold text-slate-800 dark:text-slate-100 mr-2">{inr(o.amount)}</span>
                      <Badge variant={o.status}>{o.status}</Badge>
                    </td>
                    <td className="px-3 py-3.5 text-center">
                      {o.credited ? <Check size={16} className="text-emerald-600 inline" /> : <span className="text-slate-300">—</span>}
                    </td>
                    <td className="px-3 py-3.5 text-xs text-slate-400">{fmtDateTime(o.created_at)}</td>
                    <td className="px-5 py-3.5 text-right">
                      {o.status === 'success' && o.credited ? <span className="text-xs text-slate-300">—</span> : <ReverifyButton orderId={o.order_id} />}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {rows.length > 0 && (
        <Pagination
          page={page}
          total={total}
          pageSize={PAGE_SIZE}
          basePath="/admin/payments"
          params={{ filter: active === 'all' ? undefined : active }}
        />
      )}

      <p className="text-xs text-slate-400 text-center">Re-verify hits the Cashfree API to recheck status. Idempotent — if already credited, nothing changes.</p>
    </div>
  );
}
