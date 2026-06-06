'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { weekLabel } from '@/lib/tracker/week-utils';

/** Returns all checks for the given child for a specific week key */
export async function getTrackerChecks(
  childId: number,
  weekKey: string,
): Promise<{ ok: boolean; checks?: Record<string, boolean>; error?: string }> {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return { ok: false, error: 'auth' };

  const db = createAdminClient();

  const { data, error } = await db
    .from('tracker_checks')
    .select('habit_idx, day_idx, checked')
    .eq('parent_id', user.id)
    .eq('child_id', childId)
    .eq('week_key', weekKey)
    .eq('checked', true);

  if (error) {
    // table may not exist yet — return empty object gracefully
    return { ok: true, checks: {} };
  }

  const checks: Record<string, boolean> = {};
  for (const row of data || []) {
    checks[`${row.habit_idx}-${row.day_idx}`] = true;
  }
  return { ok: true, checks };
}

/** Upserts (toggle) a single check cell */
export async function toggleTrackerCheck(
  childId: number,
  weekKey: string,
  habitIdx: number,
  dayIdx: number,
  checked: boolean,
): Promise<{ ok: boolean; error?: string }> {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return { ok: false, error: 'auth' };

  const db = createAdminClient();

  const { error } = await db.from('tracker_checks').upsert(
    {
      parent_id: user.id,
      child_id: childId,
      week_key: weekKey,
      habit_idx: habitIdx,
      day_idx: dayIdx,
      checked,
      checked_at: new Date().toISOString(),
    },
    { onConflict: 'parent_id,child_id,week_key,habit_idx,day_idx' },
  );

  if (error) {
    console.error('[tracker] toggle error:', error.message);
    return { ok: false, error: error.message };
  }
  return { ok: true };
}

/** Returns check data for ALL weeks that have at least one check for this child */
export async function getTrackerHistory(
  childId: number,
): Promise<{ ok: boolean; history?: { weekKey: string; label: string; total: number; checked: number }[]; error?: string }> {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return { ok: false, error: 'auth' };

  const db = createAdminClient();
  const { data, error } = await db
    .from('tracker_checks')
    .select('week_key, habit_idx, day_idx')
    .eq('parent_id', user.id)
    .eq('child_id', childId)
    .eq('checked', true)
    .order('week_key', { ascending: false });

  if (error) return { ok: true, history: [] };

  // Group by week_key
  const byWeek: Record<string, number> = {};
  for (const row of data || []) {
    byWeek[row.week_key] = (byWeek[row.week_key] || 0) + 1;
  }

  const history = Object.entries(byWeek)
    .sort(([a], [b]) => b.localeCompare(a))
    .map(([wk, cnt]) => ({
      weekKey: wk,
      label: weekLabel(wk),
      total: 35,
      checked: cnt,
    }));

  return { ok: true, history };
}
