import React from 'react';
import Link from 'next/link';
import { PhoneCall, Inbox } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card, EmptyState } from '@/components/admin/ui';
import LeadsList from './LeadsList';

export const dynamic = 'force-dynamic';

const TABS = [
  { key: 'all', label: 'All' },
  { key: 'new', label: 'New' },
  { key: 'contacted', label: 'Contacted' },
  { key: 'booked', label: 'Booked' },
  { key: 'converted', label: 'Converted' },
  { key: 'lost', label: 'Lost' },
  { key: 'spam', label: 'Spam' },
];

export default async function AdminLeadsPage({ searchParams }: { searchParams: Promise<{ filter?: string }> }) {
  await requireAdminUser();
  const { filter } = await searchParams;
  const active = TABS.some((t) => t.key === filter) ? filter! : 'all';
  const db = createAdminClient();

  // Graceful: the leads table may not exist yet on a fresh install.
  let leads: any[] = [];
  try {
    const { data, error } = await db.from('leads').select('*').order('id', { ascending: false }).limit(500);
    if (!error) leads = data || [];
  } catch { /* table absent */ }

  const counts: Record<string, number> = { all: leads.length };
  TABS.forEach((t) => { if (t.key !== 'all') counts[t.key] = leads.filter((l) => (l.status || 'new') === t.key).length; });
  const rows = active === 'all' ? leads : leads.filter((l) => (l.status || 'new') === active);

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
        <LeadsList leads={rows} />
      )}

      <p className="text-xs text-slate-400 text-center">Email alerts go to <strong>drpankajjha@gmail.com</strong> · showing latest 500 records · total: {leads.length}</p>
    </div>
  );
}
