import React from 'react';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { getReferralStats } from '@/lib/referrals';
import ReportClient from './ReportClient';

export const dynamic = 'force-dynamic';

interface PageProps {
  params: Promise<{ id: string }>;
}

export default async function ReportPage({ params }: PageProps) {
  const resolvedParams = await params;
  const childId = Number(resolvedParams.id);

  if (isNaN(childId)) {
    redirect('/dashboard');
  }

  const supabase = await createClient();

  // Validate session
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    redirect('/login');
  }

  // Reads use the service-role client (RLS bypass) scoped to this user's id.
  const db = createAdminClient();

  // Load child profile and check ownership
  const { data: child, error: childErr } = await db
    .from('children')
    .select('*')
    .eq('id', childId)
    .eq('parent_id', user.id)
    .maybeSingle();

  if (childErr || !child) {
    redirect('/dashboard');
  }

  // Load completed assessments
  const { data: assessments } = await db
    .from('assessments')
    .select('*')
    .eq('child_id', childId)
    .eq('status', 'completed')
    .order('completed_at', { ascending: false });

  // Load expert report status
  const { data: expertOrder } = await db
    .from('expert_report_orders')
    .select('*')
    .eq('parent_id', user.id)
    .eq('child_id', childId)
    .order('id', { ascending: false })
    .maybeSingle();

  // Load parent credits
  const { data: parent } = await db
    .from('parents')
    .select('credits')
    .eq('id', user.id)
    .single();

  // Load referral stats
  const referralStats = await getReferralStats(user.id);

  return (
    <ReportClient
      child={child}
      assessments={assessments || []}
      expertOrder={expertOrder || null}
      referralStats={referralStats}
      parentCredits={parent?.credits || 0}
    />
  );
}
