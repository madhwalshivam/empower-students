'use server';

import { createClient } from '@/lib/supabase/server';
import { orderReportViaWallet, orderReportViaReferral } from '@/lib/referrals';
import { revalidatePath } from 'next/cache';

export async function orderReportViaWalletAction(childId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { ok: false, error: 'Authentication required' };
  }

  try {
    const res = await orderReportViaWallet(user.id, childId);
    revalidatePath(`/child/${childId}`);
    revalidatePath(`/report/${childId}`);

    if (res.status === 'ordered') {
      return { ok: true, message: 'Report ordered successfully using wallet credits.' };
    } else if (res.status === 'already_ordered') {
      return { ok: true, message: 'Expert report has already been ordered.' };
    } else {
      return { ok: false, error: 'Insufficient credits in wallet. Please top up.' };
    }
  } catch (err: any) {
    return { ok: false, error: err.message || 'An unexpected error occurred.' };
  }
}

export async function orderReportViaReferralAction(childId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { ok: false, error: 'Authentication required' };
  }

  try {
    const ok = await orderReportViaReferral(user.id, childId);
    revalidatePath(`/child/${childId}`);
    revalidatePath(`/report/${childId}`);

    if (ok) {
      return { ok: true, message: 'Report ordered successfully using referral invites.' };
    } else {
      return { ok: false, error: 'You do not qualify for a free report. Please refer more friends.' };
    }
  } catch (err: any) {
    return { ok: false, error: err.message || 'An unexpected error occurred.' };
  }
}
