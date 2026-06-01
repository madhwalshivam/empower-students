import React from 'react';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import WalletClient from './WalletClient';

export const dynamic = 'force-dynamic';

export default async function WalletPage() {
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

  // Load wallet history ledger entries
  const { data: history } = await db
    .from('wallet_ledger')
    .select('*')
    .eq('parent_id', user.id)
    .order('created_at', { ascending: false });

  return (
    <React.Suspense fallback={<div className="text-slate-500 text-center py-8">Loading wallet...</div>}>
      <WalletClient
        initialBalance={parent.credits || 0}
        history={history || []}
      />
    </React.Suspense>
  );
}
