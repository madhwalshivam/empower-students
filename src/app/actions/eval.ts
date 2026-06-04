'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import {
  ceStartSession,
  ceGenerateNextQuestion,
  ceSubmitAnswer,
  ceFinaliseSession
} from '@/lib/evaluations/engine';

export async function startSessionAction(childId: number, moduleKey: string, lang?: string) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  const admin = createAdminClient();

  // Verify ownership (service-role read scoped to this user's id)
  const { data: child } = await admin
    .from('children')
    .select('id')
    .eq('id', childId)
    .eq('parent_id', user.id)
    .maybeSingle();

  if (!child) {
    throw new Error('Unauthorized or child profile not found');
  }

  // Look up the credit cost for this module (admin-configurable via service_prices)
  const { data: priceRow } = await admin
    .from('service_prices')
    .select('price, is_active')
    .eq('service_key', moduleKey)
    .maybeSingle();

  // If module is disabled in admin pricing, block it
  if (priceRow && priceRow.is_active === false) {
    return { ok: false, error: 'unavailable', message: 'This module is currently unavailable.' };
  }

  const price = priceRow?.price ?? 0;

  // Read current balance
  const { data: parent } = await admin
    .from('parents')
    .select('credits')
    .eq('id', user.id)
    .single();
  const balance = parent?.credits || 0;

  // Create (or resume) the session.
  const { sessionId, created } = await ceStartSession(childId, moduleKey);

  // Decide whether to charge using the LEDGER as the source of truth: a session
  // is charged at most once, but ANY session that hasn't actually been paid for
  // yet (e.g. an old in-progress session left over from before, which would
  // otherwise resume for free) still gets charged. This is what makes credits
  // reliably go down when a module is started.
  let alreadyCharged = false;
  if (price > 0 && !created) {
    const { data: led } = await admin
      .from('wallet_ledger')
      .select('id')
      .eq('parent_id', user.id)
      .eq('service_key', moduleKey)
      .eq('ref_id', sessionId)
      .limit(1)
      .maybeSingle();
    alreadyCharged = !!led;
  }

  const needCharge = price > 0 && !alreadyCharged;

  // Affordability check only matters when we actually need to charge.
  if (needCharge && balance < price) {
    return { ok: false, error: 'insufficient', needed: price, balance };
  }

  let newBalance = balance;
  if (needCharge) {
    const nextCredits = balance - price;

    // Deduct credits — surface a failure instead of silently swallowing it.
    const { error: deductErr } = await admin
      .from('parents')
      .update({ credits: nextCredits })
      .eq('id', user.id);

    if (deductErr) {
      return { ok: false, error: 'charge_failed', message: 'Could not deduct credits. Please try again.' };
    }

    newBalance = nextCredits;

    // Record ledger transaction — this is also what prevents a future re-charge
    // of the same session.
    await admin.from('wallet_ledger').insert({
      parent_id: user.id,
      amount: -price,
      balance_after: nextCredits,
      service_key: moduleKey,
      ref_id: sessionId,
      reason: `${moduleKey} evaluation for child ID ${childId}`,
    });
  }

  // Generate the FIRST question in the SAME round-trip. Previously the client
  // called startSession, waited, then made a second call (with its own auth
  // round-trip) to fetch question 1 — so the parent stared at a spinner through
  // two sequential server hops before anything appeared. Folding the first
  // question in here removes that extra hop entirely.
  const firstQuestion = await ceGenerateNextQuestion(sessionId, lang);

  return { ok: true, sessionId, charged: needCharge ? price : 0, balance: newBalance, firstQuestion };
}

export async function getNextQuestionAction(sessionId: number, lang?: string) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  return await ceGenerateNextQuestion(sessionId, lang);
}

export async function submitAnswerAction(
  sessionId: number,
  answerPayload: { text?: string; choice?: string; response_seconds?: number },
  lang?: string
) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  return await ceSubmitAnswer(sessionId, answerPayload, lang);
}

// Score the current answer AND fetch the next question in a SINGLE server round
// trip. The two steps are inherently sequential (the next question's difficulty
// adapts to whether this answer was right), but doing them in one action removes
// a whole client→server hop and a second auth round-trip per question — the
// overhead that made the gap between questions feel long.
export async function submitAndGetNextAction(
  sessionId: number,
  answerPayload: { text?: string; choice?: string; response_seconds?: number },
  lang?: string
) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  const submit = await ceSubmitAnswer(sessionId, answerPayload, lang);
  if (!submit.ok) {
    return { submit, next: null };
  }

  const next = await ceGenerateNextQuestion(sessionId, lang);
  return { submit, next };
}

export async function finaliseSessionAction(sessionId: number, lang?: string) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    throw new Error('Authentication required');
  }

  return await ceFinaliseSession(sessionId, lang);
}