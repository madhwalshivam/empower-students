'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import {
  ceStartSession,
  ceGenerateNextQuestion,
  ceSubmitAnswer,
  ceFinaliseSession
} from '@/lib/evaluations/engine';

export async function startSessionAction(childId: number, moduleKey: string) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  // Verify ownership (service-role read scoped to this user's id)
  const { data: child } = await createAdminClient()
    .from('children')
    .select('id')
    .eq('id', childId)
    .eq('parent_id', user.id)
    .maybeSingle();

  if (!child) {
    throw new Error('Unauthorized or child profile not found');
  }

  const sessionId = await ceStartSession(childId, moduleKey);
  return { ok: true, sessionId };
}

export async function getNextQuestionAction(sessionId: number) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  return await ceGenerateNextQuestion(sessionId);
}

export async function submitAnswerAction(
  sessionId: number,
  answerPayload: { text?: string; choice?: string; response_seconds?: number }
) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  return await ceSubmitAnswer(sessionId, answerPayload);
}

export async function finaliseSessionAction(sessionId: number) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  return await ceFinaliseSession(sessionId);
}
