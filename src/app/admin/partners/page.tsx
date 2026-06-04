import React from 'react';
import Link from 'next/link';
import { headers } from 'next/headers';
import { HeartHandshake, Users, UserCheck, Clock, MessageSquare } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card, StatCard, Badge, EmptyState, inr } from '@/components/admin/ui';
import Pagination, { parsePage } from '@/components/Pagination';
import CopyLinkButton from '../../partner/dashboard/CopyLinkButton';
import PartnerRowActions from './PartnerRowActions';
import PartnerPayoutButton from './PartnerPayoutButton';
import { IndianRupee } from 'lucide-react';

export const dynamic = 'force-dynamic';

const PAGE_SIZE = 20;

const TABS = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'pending', label: 'Pending' },
];

export default async function AdminPartnersPage({ searchParams }: { searchParams: Promise<{ filter?: string; page?: string }> }) {
  await requireAdminUser();
  const { filter, page: pageRaw } = await searchParams;
  const active = TABS.some((t) => t.key === filter) ? filter! : 'all';
  const page = parsePage(pageRaw);
  const db = createAdminClient();

  // Counts (HEAD only) + total owed (one tiny column) + just the current page of
  // partners. Per-partner earnings are then scoped to the visible partners.
  const fromIdx = (page - 1) * PAGE_SIZE;
  let listQuery = db.from('partners').select('*', { count: 'exact' }).order('created_at', { ascending: false });
  if (active !== 'all') listQuery = listQuery.eq('status', active);

  const [totalRes, activeRes, pendingRes, refRes, pendingAmts, listRes] = await Promise.all([
    db.from('partners').select('*', { count: 'exact', head: true }),
    db.from('partners').select('*', { count: 'exact', head: true }).eq('status', 'active'),
    db.from('partners').select('*', { count: 'exact', head: true }).eq('status', 'pending'),
    db.from('parents').select('*', { count: 'exact', head: true }).not('partner_id', 'is', null),
    db.from('partner_payouts').select('partner_amount').eq('status', 'pending'),
    listQuery.range(fromIdx, fromIdx + PAGE_SIZE - 1),
  ]);

  const totalPartners = totalRes.count || 0;
  const activeCount = activeRes.count || 0;
  const pendingCount = pendingRes.count || 0;
  const refCount = refRes.count || 0;
  const totalOwed = (pendingAmts.data || []).reduce((s, p) => s + (p.partner_amount || 0), 0);

  const rows = listRes.data || [];
  const listTotal = listRes.count || 0;

  // Per-partner commission tallies — only for the partners shown on this page.
  const pendingByPartner: Record<string, number> = {};
  const paidByPartner: Record<string, number> = {};
  const partnerIds = rows.map((p) => p.id);
  if (partnerIds.length) {
    const { data: payouts } = await db
      .from('partner_payouts')
      .select('partner_id, partner_amount, status')
      .in('partner_id', partnerIds);
    (payouts || []).forEach((p: any) => {
      const amt = p.partner_amount || 0;
      if (p.status === 'paid') paidByPartner[p.partner_id] = (paidByPartner[p.partner_id] || 0) + amt;
      else if (p.status === 'pending') pendingByPartner[p.partner_id] = (pendingByPartner[p.partner_id] || 0) + amt;
    });
  }

  const hdrs = await headers();
  const host = hdrs.get('x-forwarded-host') || hdrs.get('host') || 'localhost:3000';
  const proto = hdrs.get('x-forwarded-proto') || (host.includes('localhost') ? 'http' : 'https');
  const origin = `${proto}://${host}`;

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={HeartHandshake} title="Partners" subtitle="Tutors, centres and therapy partners — referrals and revenue share." />

      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <StatCard icon={Users} label="Total partners" value={totalPartners} accent="indigo" />
        <StatCard icon={UserCheck} label="Active" value={activeCount} accent="emerald" />
        <StatCard icon={Clock} label="Pending" value={pendingCount} accent="amber" />
        <StatCard icon={Users} label="Referred parents" value={refCount} accent="violet" />
        <StatCard icon={IndianRupee} label="Owed to partners" value={inr(totalOwed)} accent="amber" hint="unpaid commission" />
      </div>

      <div className="flex flex-wrap gap-2 mb-2">
        {TABS.map((t) => (
          <Link
            key={t.key}
            href={t.key === 'all' ? '/admin/partners' : `/admin/partners?filter=${t.key}`}
            className={`es-tab ${active === t.key ? 'active' : ''}`}
          >
            {t.label}
          </Link>
        ))}
      </div>

      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={HeartHandshake} title="No partners" desc="Approved partner applications and manually-added centres will appear here." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs uppercase text-slate-400 text-left border-b border-slate-100 dark:border-slate-800">
                <tr>
                  <th className="px-5 py-3.5 font-bold">Partner</th>
                  <th className="px-3 py-3.5 font-bold">Code</th>
                  <th className="px-3 py-3.5 font-bold">Area</th>
                  <th className="px-3 py-3.5 font-bold">Status</th>
                  <th className="px-3 py-3.5 font-bold">Earnings</th>
                  <th className="px-5 py-3.5 font-bold text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {rows.map((p) => {
                  const wa = String(p.whatsapp || p.phone || '').replace(/\D/g, '');
                  return (
                    <tr key={p.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                      <td className="px-5 py-3.5">
                        <div className="font-bold text-slate-800 dark:text-slate-100">{p.name || '—'}</div>
                        <div className="text-xs text-slate-400">{p.contact_name || '—'}</div>
                      </td>
                      <td className="px-3 py-3.5"><span className="font-mono text-xs bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded">{p.referral_code || '—'}</span></td>
                      <td className="px-3 py-3.5 text-slate-500">{p.city || '—'}</td>
                      <td className="px-3 py-3.5"><Badge variant={p.status || 'pending'}>{p.status || 'pending'}</Badge></td>
                      <td className="px-3 py-3.5">
                        {pendingByPartner[p.id] > 0 ? (
                          <div className="text-xs font-bold text-amber-600">{inr(pendingByPartner[p.id])} <span className="font-medium text-slate-400">pending</span></div>
                        ) : (
                          <div className="text-xs text-slate-400">—</div>
                        )}
                        {paidByPartner[p.id] > 0 && (
                          <div className="text-[11px] text-slate-400 mt-0.5">{inr(paidByPartner[p.id])} paid</div>
                        )}
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex items-center justify-end gap-3">
                          <PartnerPayoutButton partnerId={p.id} pending={pendingByPartner[p.id] || 0} />
                          {wa && (
                            <a href={`https://wa.me/${wa}`} target="_blank" rel="noopener noreferrer" className="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 no-underline">
                              <MessageSquare size={12} /> WhatsApp
                            </a>
                          )}
                          {p.referral_code && <CopyLinkButton link={`${origin}/r/${p.referral_code}`} />}
                          <PartnerRowActions partnerId={p.id} currentStatus={p.status || 'pending'} />
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {rows.length > 0 && (
        <Pagination
          page={page}
          total={listTotal}
          pageSize={PAGE_SIZE}
          basePath="/admin/partners"
          params={{ filter: active === 'all' ? undefined : active }}
        />
      )}
    </div>
  );
}
