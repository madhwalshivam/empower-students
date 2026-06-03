import React from 'react';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import EvalClient from './EvalClient';

export const dynamic = 'force-dynamic';

interface PageProps {
  params: Promise<{ childId: string; moduleKey: string }>;
}

export default async function EvalPage({ params }: PageProps) {
  const resolvedParams = await params;
  const childId = Number(resolvedParams.childId);
  const moduleKey = resolvedParams.moduleKey;

  if (isNaN(childId) || !moduleKey) {
    redirect('/dashboard');
  }

  const supabase = await createClient();

  // Validate session
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    redirect('/login');
  }

  // Load child profile and check ownership (service-role read scoped to this user)
  const db = createAdminClient();
  const { data: child, error: childErr } = await db
    .from('children')
    .select('name')
    .eq('id', childId)
    .eq('parent_id', user.id)
    .maybeSingle();

  if (childErr || !child) {
    redirect('/dashboard');
  }

  // Cost + balance, so the parent sees the price BEFORE starting the module.
  const { data: priceRow } = await db
    .from('service_prices')
    .select('price, is_active')
    .eq('service_key', moduleKey)
    .maybeSingle();
  const { data: parent } = await db
    .from('parents')
    .select('credits')
    .eq('id', user.id)
    .maybeSingle();

  const price = priceRow?.price ?? 0;
  const balance = parent?.credits ?? 0;

  // Is there an in-progress session to RESUME? (started within the last 24h)
  // If so, the intro shows "Resume" and won't show a deduction note — resuming
  // is free (already paid) and continues from the same pending question.
  const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  const { data: openSession } = await db
    .from('child_eval_sessions')
    .select('id')
    .eq('child_id', childId)
    .eq('module', moduleKey)
    .eq('status', 'in_progress')
    .gt('started_at', oneDayAgo)
    .limit(1)
    .maybeSingle();

  return (
    <EvalClient
      childId={childId}
      moduleKey={moduleKey}
      childName={child.name}
      price={price}
      balance={balance}
      resuming={!!openSession}
    />
  );
}
