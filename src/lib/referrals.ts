import { createAdminClient } from '@/lib/supabase/admin';

export function generateReferralCode(): string {
  const chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  let code = '';
  for (let i = 0; i < 8; i++) {
    code += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return code;
}

export async function getOrCreateReferralCode(parentId: string): Promise<string> {
  const supabase = createAdminClient();

  const { data: parent } = await supabase
    .from('parents')
    .select('referral_code')
    .eq('id', parentId)
    .single();

  if (parent?.referral_code) {
    return parent.referral_code;
  }

  const newCode = generateReferralCode();
  await supabase
    .from('parents')
    .update({ referral_code: newCode })
    .eq('id', parentId);

  return newCode;
}

export async function getReferralStats(parentId: string) {
  const supabase = createAdminClient();

  const { data: referrals } = await supabase
    .from('referrals')
    .select('*')
    .eq('referrer_parent_id', parentId);

  const list = referrals || [];
  const completedCount = list.filter((r) => r.child_eval_completed).length;

  return {
    signups: list.length,
    completed_evals: completedCount,
    needed: 2,
    qualifies_free: completedCount >= 2,
  };
}

export async function orderReportViaWallet(parentId: string, childId: number): Promise<{
  status: 'ordered' | 'already_ordered' | 'insufficient';
  needed: number;
  balance: number;
}> {
  const supabase = createAdminClient();

  // Check if already ordered or completed
  const { data: existing } = await supabase
    .from('expert_report_orders')
    .select('id, status')
    .eq('parent_id', parentId)
    .eq('child_id', childId)
    .maybeSingle();

  if (existing && (existing.status === 'pending' || existing.status === 'delivered')) {
    const { data: parent } = await supabase.from('parents').select('credits').eq('id', parentId).single();
    return {
      status: 'already_ordered',
      needed: 0,
      balance: parent?.credits || 0,
    };
  }

  const price = 1000; // Expert report price: 1000 credits
  const { data: parent } = await supabase.from('parents').select('credits').eq('id', parentId).single();
  const balance = parent?.credits || 0;

  if (balance < price) {
    return {
      status: 'insufficient',
      needed: price,
      balance,
    };
  }

  const nextCredits = balance - price;

  // Deduct credits
  await supabase
    .from('parents')
    .update({ credits: nextCredits })
    .eq('id', parentId);

  // Insert Expert Report Order
  const { data: order } = await supabase
    .from('expert_report_orders')
    .insert({
      parent_id: parentId,
      child_id: childId,
      source: 'paid',
      amount_paid: price,
      status: 'pending',
    })
    .select('id')
    .single();

  if (order) {
    // Record ledger transaction
    await supabase.from('wallet_ledger').insert({
      parent_id: parentId,
      amount: -price,
      balance_after: nextCredits,
      service_key: 'expert_report',
      ref_id: order.id,
      reason: `Expert Report Order for child ID ${childId}`,
    });
  }

  return {
    status: 'ordered',
    needed: price,
    balance: nextCredits,
  };
}

export async function orderReportViaReferral(parentId: string, childId: number): Promise<boolean> {
  const supabase = createAdminClient();

  const stats = await getReferralStats(parentId);
  if (!stats.qualifies_free) {
    return false;
  }

  // Insert Expert Report Order with source referral
  await supabase
    .from('expert_report_orders')
    .insert({
      parent_id: parentId,
      child_id: childId,
      source: 'referral',
      amount_paid: 0,
      status: 'pending',
    });

  return true;
}
