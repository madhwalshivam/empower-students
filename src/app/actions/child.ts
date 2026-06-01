'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';

export async function addChildAction(formData: {
  name: string;
  dob: string;
  gender: string;
  school?: string;
  class_grade?: string;
  mother_tongue?: string;
  languages?: string;
  diagnosis?: string;
  notes?: string;
}): Promise<{ ok: boolean; error?: string; childId?: number }> {
  const supabase = await createClient();

  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { ok: false, error: 'Authentication required' };
  }

  if (!formData.name || !formData.name.trim()) {
    return { ok: false, error: "Child's name is required." };
  }

  if (!formData.dob || !formData.dob.trim()) {
    return { ok: false, error: 'Date of birth is required.' };
  }

  const supabaseAdmin = createAdminClient();

  const { data: child, error: insertErr } = await supabaseAdmin
    .from('children')
    .insert({
      parent_id: user.id,
      name: formData.name.trim(),
      dob: formData.dob.trim(),
      gender: formData.gender || null,
      school: formData.school?.trim() || null,
      class_grade: formData.class_grade?.trim() || null,
      mother_tongue: formData.mother_tongue?.trim() || null,
      languages: formData.languages?.trim() || null,
      diagnosis: formData.diagnosis?.trim() || null,
      notes: formData.notes?.trim() || null,
    })
    .select('id')
    .single();

  if (insertErr || !child) {
    console.error('Child insertion error:', insertErr);
    return { ok: false, error: insertErr?.message || 'Failed to save child profile.' };
  }

  return { ok: true, childId: Number(child.id) };
}

export async function resetEvaluationAction(childId: number): Promise<{ ok: boolean; error?: string }> {
  const supabase = await createClient();

  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { ok: false, error: 'Authentication required' };
  }

  const supabaseAdmin = createAdminClient();

  // Verify ownership
  const { data: child, error: childErr } = await supabaseAdmin
    .from('children')
    .select('id')
    .eq('id', childId)
    .eq('parent_id', user.id)
    .maybeSingle();

  if (childErr || !child) {
    return { ok: false, error: 'Child not found or does not belong to you.' };
  }

  // Wipes all assessments, sessions and turns associated with the child
  const { error: delAssessmentsErr } = await supabaseAdmin
    .from('assessments')
    .delete()
    .eq('child_id', childId);

  if (delAssessmentsErr) {
    return { ok: false, error: `Failed to clear assessments: ${delAssessmentsErr.message}` };
  }

  // Find all sessions to delete their turns first due to DB foreign key constraints
  const { data: sessions } = await supabaseAdmin
    .from('child_eval_sessions')
    .select('id')
    .eq('child_id', childId);

  if (sessions && sessions.length > 0) {
    const sessionIds = sessions.map(s => s.id);

    await supabaseAdmin
      .from('child_eval_turns')
      .delete()
      .in('session_id', sessionIds);

    await supabaseAdmin
      .from('child_eval_sessions')
      .delete()
      .in('id', sessionIds);
  }

  return { ok: true };
}
