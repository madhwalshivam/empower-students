'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { revalidatePath } from 'next/cache';
import { generateCarePack, CARE_PACK_PRICE, type CarePackContent } from '@/lib/carepack/generate';

// The Care Pack "unlocked" flag lives in wallet_ledger (service_key='care_pack',
// ref_id=childId). This is the source of truth, so the feature works even if the
// optional `care_packs` cache table hasn't been created yet. We additionally try
// to cache the generated content in `care_packs` (best-effort) to avoid
// re-generating on every visit.

async function getAuthedParent() {
  const supabase = await createClient();
  const { data: { user }, error } = await supabase.auth.getUser();
  if (error || !user) return null;
  return user;
}

async function ownsChild(db: ReturnType<typeof createAdminClient>, childId: number, parentId: string) {
  const { data } = await db
    .from('children')
    .select('*')
    .eq('id', childId)
    .eq('parent_id', parentId)
    .maybeSingle();
  return data || null;
}

// Has this parent already unlocked the pack for this child?
export async function isCarePackUnlocked(parentId: string, childId: number): Promise<boolean> {
  const db = createAdminClient();
  const { count } = await db
    .from('wallet_ledger')
    .select('*', { count: 'exact', head: true })
    .eq('parent_id', parentId)
    .eq('service_key', 'care_pack')
    .eq('ref_id', childId);
  return (count || 0) > 0;
}

export async function unlockCarePackAction(
  childId: number
): Promise<{ ok: boolean; error?: string; insufficient?: boolean; need?: number; balance?: number }> {
  try {
    const user = await getAuthedParent();
    if (!user) return { ok: false, error: 'Please log in again.' };

    const db = createAdminClient();
    const child = await ownsChild(db, childId, user.id);
    if (!child) return { ok: false, error: 'Child profile not found.' };

    // Already unlocked? Don't charge twice.
    if (await isCarePackUnlocked(user.id, childId)) {
      revalidatePath(`/child/${childId}`);
      return { ok: true };
    }

    const { data: parent } = await db.from('parents').select('credits').eq('id', user.id).single();
    const balance = parent?.credits || 0;
    if (balance < CARE_PACK_PRICE) {
      return { ok: false, insufficient: true, need: CARE_PACK_PRICE, balance, error: 'Insufficient credits.' };
    }

    const newBalance = balance - CARE_PACK_PRICE;

    // Deduct first, then record the unlock in the ledger (the unlock marker).
    const { error: updErr } = await db.from('parents').update({ credits: newBalance }).eq('id', user.id);
    if (updErr) return { ok: false, error: updErr.message };

    await db.from('wallet_ledger').insert({
      parent_id: user.id,
      amount: -CARE_PACK_PRICE,
      balance_after: newBalance,
      service_key: 'care_pack',
      ref_id: childId,
      reason: `Care Pack unlocked for ${child.name}`,
    });

    // NOTE: we deliberately do NOT generate the AI content here — that Claude
    // call takes 10-30s and would make the "Unlock" button hang. The unlock is
    // instant (credits + ledger only); the personalised content is generated
    // on-demand the first time the parent opens the Growth Plan and cached then
    // (see getCarePackContent).

    revalidatePath(`/child/${childId}`);
    return { ok: true, balance: newBalance };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Could not unlock the Care Pack.' };
  }
}

// Best-effort upsert into the optional cache table. Silently ignores a missing
// table so the feature still works before the migration is applied.
async function cacheCarePack(parentId: string, childId: number, content: CarePackContent) {
  const db = createAdminClient();
  const row = {
    parent_id: parentId,
    child_id: childId,
    status: 'active',
    amount_paid: CARE_PACK_PRICE,
    growth_plan_json: JSON.stringify(content.growth_plan),
    course_json: JSON.stringify(content.course),
    tracker_json: JSON.stringify(content.tracker),
  };
  const { error } = await db.from('care_packs').upsert(row, { onConflict: 'parent_id,child_id' });
  if (error && !/care_packs/i.test(error.message)) {
    console.error('[cacheCarePack] upsert error:', error.message);
  }
}

// Returns the generated content for a child the parent owns and has unlocked.
// Reads the cache first; regenerates (and re-caches) if nothing is stored yet.
export async function getCarePackContent(
  childId: number
): Promise<{ ok: boolean; error?: string; child?: any; content?: CarePackContent }> {
  const user = await getAuthedParent();
  if (!user) return { ok: false, error: 'auth' };

  const db = createAdminClient();
  const child = await ownsChild(db, childId, user.id);
  if (!child) return { ok: false, error: 'not_found' };

  if (!(await isCarePackUnlocked(user.id, childId))) {
    return { ok: false, error: 'locked' };
  }

  // Try the cache.
  try {
    const { data: cached } = await db
      .from('care_packs')
      .select('growth_plan_json, course_json, tracker_json')
      .eq('parent_id', user.id)
      .eq('child_id', childId)
      .maybeSingle();
    if (cached?.growth_plan_json && cached?.course_json && cached?.tracker_json) {
      return {
        ok: true,
        child,
        content: {
          growth_plan: JSON.parse(cached.growth_plan_json),
          course: JSON.parse(cached.course_json),
          tracker: JSON.parse(cached.tracker_json),
        },
      };
    }
  } catch {
    // table missing or parse error — fall through to regenerate
  }

  // Nothing cached → generate on demand and try to cache.
  const { data: assessments } = await db
    .from('assessments')
    .select('*')
    .eq('child_id', childId)
    .eq('status', 'completed');
  const content = await generateCarePack(child, assessments || []);
  cacheCarePack(user.id, childId, content).catch(() => {});
  return { ok: true, child, content };
}
