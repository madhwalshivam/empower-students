import React from 'react';
import Link from 'next/link';
import { headers } from 'next/headers';
import { HeartHandshake, Users, UserCheck, Clock, MessageSquare } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card, StatCard, Badge, EmptyState } from '@/components/admin/ui';
import CopyLinkButton from '../../partner/dashboard/CopyLinkButton';

export const dynamic = 'force-dynamic';

const TABS = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'pending', label: 'Pending' },
];

export default async function AdminPartnersPage({ searchParams }: { searchParams: Promise<{ filter?: string }> }) {
  await requireAdminUser();
  const { filter } = await searchParams;
  const active = TABS.some((t) => t.key === filter) ? filter! : 'all';
  const db = createAdminClient();

  const [{ data: partners }, { data: refParents }] = await Promise.all([
    db.from('partners').select('*').order('created_at', { ascending: false }),
    db.from('parents').select('id').not('partner_id', 'is', null),
  ]);

  const all = partners || [];
  const activeCount = all.filter((p) => p.status === 'active').length;
  const pendingCount = all.filter((p) => p.status === 'pending').length;
  const rows = active === 'all' ? all : all.filter((p) => p.status === active);

  const hdrs = await headers();
  const host = hdrs.get('x-forwarded-host') || hdrs.get('host') || 'localhost:3000';
  const proto = hdrs.get('x-forwarded-proto') || (host.includes('localhost') ? 'http' : 'https');
  const origin = `${proto}://${host}`;

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={HeartHandshake} title="Partners" subtitle="Tutors, centres and therapy partners — referrals and revenue share." />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard icon={Users} label="Total partners" value={all.length} accent="indigo" />
        <StatCard icon={UserCheck} label="Active" value={activeCount} accent="emerald" />
        <StatCard icon={Clock} label="Pending" value={pendingCount} accent="amber" />
        <StatCard icon={Users} label="Referred parents" value={(refParents || []).length} accent="violet" />
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
                      <td className="px-5 py-3.5">
                        <div className="flex items-center justify-end gap-2">
                          {wa && (
                            <a href={`https://wa.me/${wa}`} target="_blank" rel="noopener noreferrer" className="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 no-underline">
                              <MessageSquare size={12} /> WhatsApp
                            </a>
                          )}
                          {p.referral_code && <CopyLinkButton link={`${origin}/r/${p.referral_code}`} />}
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
    </div>
  );
}
