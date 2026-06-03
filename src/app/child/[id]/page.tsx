import React from 'react';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import ChildDetailClient from './ChildDetailClient';

export const dynamic = 'force-dynamic';

interface PageProps {
  params: Promise<{ id: string }>;
}

export default async function ChildDetailPage({ params }: PageProps) {
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

  // Reads use the service-role client (RLS bypass) scoped to this user's own id.
  const db = createAdminClient();

  // Load child details and verify parent ownership
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
    .eq('status', 'completed');

  // Modules with an in-progress session (started in the last 24h) → show "Resume"
  const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  const { data: openSessions } = await db
    .from('child_eval_sessions')
    .select('module')
    .eq('child_id', childId)
    .eq('status', 'in_progress')
    .gt('started_at', oneDayAgo);
  const inProgressModules = Array.from(new Set((openSessions || []).map((s: any) => s.module)));

  // Load parent details for credit balance
  const { data: parent } = await db
    .from('parents')
    .select('credits')
    .eq('id', user.id)
    .single();

  // Load care pack
  const { data: carePack } = await db
    .from('care_packs')
    .select('*')
    .eq('child_id', childId)
    .eq('parent_id', user.id)
    .maybeSingle();

  return (
    <ChildDetailClient
      child={child}
      assessments={assessments || []}
      carePack={carePack || null}
      parentCredits={parent?.credits || 0}
      inProgressModules={inProgressModules}
    />
  );
}
