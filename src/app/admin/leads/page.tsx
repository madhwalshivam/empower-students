import React from 'react';
import Link from 'next/link';
import { PhoneCall, Inbox } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card, EmptyState } from '@/components/admin/ui';
import Pagination, { parsePage } from '@/components/Pagination';
import LeadsList from './LeadsList';

export const dynamic = 'force-dynamic';

const PAGE_SIZE = 20;

const TABS = [
  { key: 'all', label: 'All' },
  { key: 'new', label: 'New' },
  { key: 'contacted', label: 'Contacted' },
  { key: 'booked', label: 'Booked' },
  { key: 'converted', label: 'Converted' },
  { key: 'lost', label: 'Lost' },
  { key: 'spam', label: 'Spam' },
];

// Apply a status tab to a query. 'new' also matches NULL (the default state).
const applyStatus = (qb: any, key: string) =>
  key === 'all' ? qb : key === 'new' ? qb.or('status.eq.new,status.is.null') : qb.eq('status', key);

export default async function AdminLeadsPage({ searchParams }: { searchParams: Promise<{ filter?: string; page?: string }> }) {
  await requireAdminUser();
  const { filter, page: pageRaw } = await searchParams;
  const active = TABS.some((t) => t.key === filter) ? filter! : 'all';
  const page = parsePage(pageRaw);
  const db = createAdminClient();

  // Per-tab counts are cheap HEAD counts (no rows transferred), and only the
  // current page's rows are fetched via .range() — so the server never loads the
  // whole leads table at once.
  const counts: Record<string, number> = {};
  let rows: any[] = [];
  let total = 0;
  try {
    const countResults = await Promise.all(
      TABS.map((t) => applyStatus(db.from('leads').select('*', { count: 'exact', head: true }), t.key))
    );
    TABS.forEach((t, i) => { counts[t.key] = countResults[i].count || 0; });
    total = counts[active] || 0;

    const fromIdx = (page - 1) * PAGE_SIZE;
    const { data } = await applyStatus(
      db.from('leads').select('*').order('id', { ascending: false }),
      active
    ).range(fromIdx, fromIdx + PAGE_SIZE - 1);
    rows = data || [];
  } catch { /* table absent on a fresh install */ }

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={PhoneCall} title="Leads" subtitle="Free-evaluation form submissions from empowerstudents.in" />

      <div className="flex flex-wrap gap-2 mb-2">
        {TABS.map((t) => (
          <Link
            key={t.key}
            href={t.key === 'all' ? '/admin/leads' : `/admin/leads?filter=${t.key}`}
            className={`es-tab ${active === t.key ? 'active' : ''}`}
          >
            {t.label}
            <span className="es-tab-badge">{counts[t.key] || 0}</span>
          </Link>
        ))}
      </div>

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            icon={Inbox}
            title={active === 'all' ? 'No leads yet' : `No ${active} leads`}
            desc="Free-evaluation form submissions from the website will appear here, ready to contact and convert."
          />
        </Card>
      ) : (
        <>
          <LeadsList leads={rows} />
          <Pagination
            page={page}
            total={total}
            pageSize={PAGE_SIZE}
            basePath="/admin/leads"
            params={{ filter: active === 'all' ? undefined : active }}
          />
        </>
      )}

      <p className="text-xs text-slate-400 text-center">Email alerts go to <strong>drpankajjha@gmail.com</strong> · total: {counts.all || 0}</p>
    </div>
  );
}
