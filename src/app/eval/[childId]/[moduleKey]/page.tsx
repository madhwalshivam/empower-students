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

  return (
    <EvalClient
      childId={childId}
      moduleKey={moduleKey}
      childName={child.name}
    />
  );
}
