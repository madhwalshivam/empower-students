import React from 'react';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import WalletClient from './WalletClient';
import { parsePage } from '@/components/Pagination';

export const dynamic = 'force-dynamic';

const PAGE_SIZE = 15;

export default async function WalletPage({ searchParams }: { searchParams: Promise<{ page?: string }> }) {
  const { page: pageRaw } = await searchParams;
  const page = parsePage(pageRaw);
  const supabase = await createClient();

  // Validate session
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    redirect('/login');
  }

  // Reads use the service-role client (RLS bypass) scoped to this user's own id,
  // matching the dashboard pattern. Ownership is enforced by the user.id filter.
  const db = createAdminClient();

  // Load parent details
  const { data: parent, error: pErr } = await db
    .from('parents')
    .select('credits')
    .eq('id', user.id)
    .single();

  if (pErr || !parent) {
    redirect('/dashboard');
  }

  // Load only the current page of ledger entries (with an exact total count) so a
  // long transaction history never loads all at once.
  const fromIdx = (page - 1) * PAGE_SIZE;
  const { data: history, count } = await db
    .from('wallet_ledger')
    .select('*', { count: 'exact' })
    .eq('parent_id', user.id)
    .order('created_at', { ascending: false })
    .range(fromIdx, fromIdx + PAGE_SIZE - 1);

  return (
    <React.Suspense fallback={<div className="text-slate-500 text-center py-8">Loading wallet...</div>}>
      <WalletClient
        initialBalance={parent.credits || 0}
        history={history || []}
        page={page}
        total={count || 0}
        pageSize={PAGE_SIZE}
      />
    </React.Suspense>
  );
}
