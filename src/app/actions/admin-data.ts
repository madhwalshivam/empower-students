'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { creditOrderIfPaid } from '@/lib/cashfree/client';
import { revalidatePath } from 'next/cache';

async function ensureAdmin() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user || user.user_metadata?.role !== 'admin') return null;
  return user;
}

/** Leads — update status / notes (no-op gracefully if the leads table is absent). */
export async function updateLeadStatusAction(id: number, status: string, notes?: string) {
  try {
    if (!(await ensureAdmin())) return { ok: false, error: 'Unauthorized.' };
    const db = createAdminClient();
    const patch: any = { status };
    if (notes !== undefined) patch.notes = notes;
    const { error } = await db.from('leads').update(patch).eq('id', id);
    if (error) return { ok: false, error: error.message };
    revalidatePath('/admin/leads');
    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Failed to update lead.' };
  }
}

/** Payments — re-verify a Cashfree order and credit if paid. Idempotent. */
export async function reverifyPaymentAction(orderId: string) {
  try {
    if (!(await ensureAdmin())) return { ok: false, error: 'Unauthorized.' };
    const result = await creditOrderIfPaid(orderId);
    revalidatePath('/admin/payments');
    return { ok: true, status: result.status, newBalance: result.newBalance, error: result.error };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Verification failed.' };
  }
}

/** Pricing — bulk save credit cost + active flag for services. */
export async function savePricingAction(rows: { service_key: string; price: number; is_active: boolean }[]) {
  try {
    if (!(await ensureAdmin())) return { ok: false, error: 'Unauthorized.' };
    const db = createAdminClient();
    let updated = 0;
    for (const r of rows) {
      const price = Math.max(0, Math.round(Number(r.price) || 0));
      const { error } = await db
        .from('service_prices')
        .update({ price, is_active: !!r.is_active })
        .eq('service_key', r.service_key);
      if (!error) updated++;
    }
    revalidatePath('/admin/pricing');
    return { ok: true, updated };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Failed to save pricing.' };
  }
}

/** Settings — change the admin account password. */
export async function changeAdminPasswordAction(newPassword: string) {
  try {
    const user = await ensureAdmin();
    if (!user) return { ok: false, error: 'Unauthorized.' };
    const pw = (newPassword || '').trim();
    if (pw.length < 8) return { ok: false, error: 'Password must be at least 8 characters.' };
    const db = createAdminClient();
    const { error } = await db.auth.admin.updateUserById(user.id, { password: pw });
    if (error) return { ok: false, error: error.message };
    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Failed to update password.' };
  }
}
