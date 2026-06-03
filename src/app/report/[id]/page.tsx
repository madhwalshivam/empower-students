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

  // Load per-module detailed reports from the adaptive engine sessions
  const { data: evalSessions } = await db
    .from('child_eval_sessions')
    .select('id, module, overall_score, report_json, completed_at')
    .eq('child_id', childId)
    .eq('status', 'completed')
    .order('completed_at', { ascending: false });

  const seenModules = new Set<string>();
  const moduleReports: any[] = [];
  for (const s of evalSessions || []) {
    if (!s.module || seenModules.has(s.module)) continue; // keep latest per module
    let report: any = null;
    try { report = s.report_json ? JSON.parse(s.report_json) : null; } catch { /* skip */ }
    if (!report) continue;
    seenModules.add(s.module);
    moduleReports.push({
      id: s.id,
      module: s.module,
      score: s.overall_score,
      completed_at: s.completed_at,
      report,
    });
  }

  // Load expert report status
  const { data: expertOrder } = await db
    .from('expert_report_orders')
    .select('*')
    .eq('parent_id', user.id)
    .eq('child_id', childId)
    .order('id', { ascending: false })
    .maybeSingle();

  // Load parent details (for credits + form prefill)
  const { data: parent } = await db
    .from('parents')
    .select('credits, name, whatsapp')
    .eq('id', user.id)
    .single();

  // Load referral stats
  const referralStats = await getReferralStats(user.id);

  return (
    <ReportClient
      child={child}
      assessments={assessments || []}
      moduleReports={moduleReports}
      expertOrder={expertOrder || null}
      referralStats={referralStats}
      parentCredits={parent?.credits || 0}
      parentName={parent?.name || ''}
      parentPhone={parent?.whatsapp || ''}
    />
  );
}
