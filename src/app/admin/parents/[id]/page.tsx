import React from 'react';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ArrowLeft, Coins, Baby, Crown, Wallet } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { Card, Badge, StatCard, EmptyState, inr, fmtDateTime, fmtDate } from '@/components/admin/ui';
import GiveCreditsForm from './GiveCreditsForm';

export const dynamic = 'force-dynamic';

export default async function AdminParentDetailPage({ params }: { params: Promise<{ id: string }> }) {
  await requireAdminUser();
  const { id } = await params;
  const db = createAdminClient();

  const { data: parent } = await db.from('parents').select('*').eq('id', id).maybeSingle();
  if (!parent) notFound();

  const [{ data: children }, { data: ledger }, { data: payments }] = await Promise.all([
    db.from('children').select('*').eq('parent_id', id).order('created_at', { ascending: false }),
    db.from('wallet_ledger').select('*').eq('parent_id', id).order('created_at', { ascending: false }).limit(20),
    db.from('payment_orders').select('amount, status').eq('parent_id', id),
  ]);

  const kids = children || [];
  const childIds = kids.map((c) => c.id);
  let assessments: any[] = [];
  if (childIds.length) {
    const { data } = await db.from('assessments').select('*').in('child_id', childIds).order('created_at', { ascending: false });
    assessments = data || [];
  }
  const revenue = (payments || []).filter((p) => p.status === 'success').reduce((s, p) => s + (p.amount || 0), 0);
  const childName = (cid: number) => kids.find((k) => k.id === cid)?.name || 'Child';

  return (
    <div className="space-y-6 animate-fade-in">
      <Link href="/admin/parents" className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-indigo-600">
        <ArrowLeft size={15} /> Back to Parents
      </Link>

      <Card className="p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <span className="w-14 h-14 rounded-2xl bg-indigo-50 dark:bg-indigo-950/30 text-indigo-700 dark:text-indigo-300 font-extrabold text-xl flex items-center justify-center">
            {(parent.name || 'P').charAt(0).toUpperCase()}
          </span>
          <div>
            <h1 className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
              {parent.name || '—'} {!!parent.is_vip && <Badge variant="pending"><Crown size={10} className="inline" /> VIP</Badge>}
            </h1>
            <p className="text-sm text-slate-500">{parent.whatsapp || '—'}{parent.email ? ` · ${parent.email}` : ''}{parent.city ? ` · ${parent.city}` : ''}</p>
            <p className="text-xs text-slate-400 mt-0.5">Joined {fmtDate(parent.created_at)} · Last login {fmtDate(parent.last_login)}</p>
          </div>
        </div>
        {!!parent.is_blocked && <Badge variant="failed">Blocked</Badge>}
      </Card>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard icon={Coins} label="Wallet Credits" value={parent.credits || 0} accent="indigo" />
        <StatCard icon={Baby} label="Children" value={kids.length} accent="sky" />
        <StatCard icon={Wallet} label="Revenue" value={inr(revenue)} accent="emerald" />
        <StatCard icon={Coins} label="Assessments" value={assessments.length} accent="violet" />
      </div>

      <GiveCreditsForm parentId={parent.id} current={parent.credits || 0} />

      <div className="grid lg:grid-cols-2 gap-6">
        <Card className="p-6">
          <h2 className="font-bold text-slate-800 dark:text-slate-100 mb-4">Children &amp; assessments</h2>
          {kids.length === 0 ? (
            <EmptyState icon={Baby} title="No children" desc="This parent hasn't added a child yet." />
          ) : (
            <div className="space-y-4">
              {kids.map((c) => {
                const cAss = assessments.filter((a) => a.child_id === c.id);
                return (
                  <div key={c.id} className="border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
                    <div className="flex items-center justify-between">
                      <div className="font-bold text-slate-800 dark:text-slate-100">{c.name}</div>
                      <span className="text-xs text-slate-400">{c.gender || '—'}{c.dob ? ` · ${fmtDate(c.dob)}` : ''}</span>
                    </div>
                    {cAss.length > 0 ? (
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {cAss.map((a) => (
                          <span key={a.id} className="text-[11px] bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 px-2 py-1 rounded-lg">
                            {a.module} {a.score != null ? `· ${a.score}` : ''}
                          </span>
                        ))}
                      </div>
                    ) : (
                      <p className="text-xs text-slate-400 mt-1.5">No assessments yet.</p>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </Card>

        <Card className="p-6">
          <h2 className="font-bold text-slate-800 dark:text-slate-100 mb-4">Wallet ledger</h2>
          {(ledger || []).length === 0 ? (
            <EmptyState icon={Wallet} title="No transactions" desc="Credit and debit history will appear here." />
          ) : (
            <div className="divide-y divide-slate-100 dark:divide-slate-800">
              {ledger!.map((l) => (
                <div key={l.id} className="py-2.5 flex items-center justify-between">
                  <div>
                    <div className="text-sm font-semibold text-slate-700 dark:text-slate-200">{l.reason || l.service_key}</div>
                    <div className="text-xs text-slate-400">{fmtDateTime(l.created_at)}</div>
                  </div>
                  <span className={`font-bold text-sm ${(l.amount || 0) >= 0 ? 'text-emerald-600' : 'text-rose-500'}`}>
                    {(l.amount || 0) >= 0 ? '+' : ''}{l.amount}
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
