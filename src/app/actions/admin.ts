'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { revalidatePath } from 'next/cache';

interface AddSpecialistInput {
  name: string;
  role: string;
  qualifications: string;
  bio: string;
  photo: string;
  orderNo: number;
}

export async function addSpecialistAction(input: AddSpecialistInput) {
  try {
    const supabase = await createClient();

    // Verify Admin Role using user token
    const { data: { user } } = await supabase.auth.getUser();
    if (!user || user.user_metadata?.role !== 'admin') {
      return { ok: false, error: 'Unauthorized.' };
    }

    if (!input.name.trim() || !input.role.trim()) {
      return { ok: false, error: 'Name and Role are required.' };
    }

    // Use admin client to perform the database insert (bypasses user RLS limits securely on server)
    const supabaseAdmin = createAdminClient();
    const { data, error } = await supabaseAdmin
      .from('specialists')
      .insert({
        name: input.name.trim(),
        role: input.role.trim(),
        qualifications: input.qualifications.trim() || null,
        bio: input.bio.trim() || null,
        photo: input.photo.trim() || null,
        order_no: input.orderNo || 100,
        active: true
      })
      .select('*')
      .single();

    if (error) {
      console.error('Add specialist error:', error);
      return { ok: false, error: error.message };
    }

    revalidatePath('/');
    revalidatePath('/specialists');
    revalidatePath('/admin/dashboard');

    return { ok: true, data };
  } catch (err: any) {
    return { ok: false, error: err.message || 'An unexpected error occurred.' };
  }
}

export async function toggleSpecialistAction(id: number, active: boolean) {
  try {
    const supabase = await createClient();

    // Verify Admin Role using user token
    const { data: { user } } = await supabase.auth.getUser();
    if (!user || user.user_metadata?.role !== 'admin') {
      return { ok: false, error: 'Unauthorized.' };
    }

    // Use admin client to update specialists (bypasses RLS limits securely on server)
    const supabaseAdmin = createAdminClient();
    const { error } = await supabaseAdmin
      .from('specialists')
      .update({ active })
      .eq('id', id);

    if (error) {
      console.error('Toggle specialist error:', error);
      return { ok: false, error: error.message };
    }

    revalidatePath('/');
    revalidatePath('/specialists');
    revalidatePath('/admin/dashboard');

    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err.message || 'An unexpected error occurred.' };
  }
}

async function requireAdmin() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user || user.user_metadata?.role !== 'admin') return null;
  return user;
}

export async function approvePartnerApplicationAction(id: number) {
  try {
    if (!(await requireAdmin())) return { ok: false, error: 'Unauthorized.' };
    const db = createAdminClient();

    const { data: app } = await db
      .from('partner_applications')
      .select('*')
      .eq('id', id)
      .maybeSingle();

    if (app) {
      const waDigits = String(app.whatsapp || '').replace(/\D/g, '');
      const namePart = String(app.name || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 4);
      let code = `${namePart}${waDigits.slice(-4)}`;
      const { count } = await db
        .from('partners')
        .select('*', { count: 'exact', head: true })
        .eq('referral_code', code);
      if (count && count > 0) code += Math.floor(10 + Math.random() * 90);

      await db.from('partners').insert({
        name: app.clinic || app.name,
        contact_name: app.name,
        whatsapp: waDigits,
        phone: waDigits,
        city: app.city || '',
        referral_code: code,
        status: 'pending',
      });
    }

    await db.from('partner_applications').update({ status: 'approved' }).eq('id', id);
    revalidatePath('/admin/dashboard');
    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Failed to approve.' };
  }
}

export async function rejectPartnerApplicationAction(id: number) {
  try {
    if (!(await requireAdmin())) return { ok: false, error: 'Unauthorized.' };
    const db = createAdminClient();
    await db.from('partner_applications').update({ status: 'rejected' }).eq('id', id);
    revalidatePath('/admin/dashboard');
    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Failed to reject.' };
  }
}

export async function deleteSpecialistAction(id: number) {
  try {
    const supabase = await createClient();

    // Verify Admin Role using user token
    const { data: { user } } = await supabase.auth.getUser();
    if (!user || user.user_metadata?.role !== 'admin') {
      return { ok: false, error: 'Unauthorized.' };
    }

    // Use admin client to delete specialists (bypasses RLS limits securely on server)
    const supabaseAdmin = createAdminClient();
    const { error } = await supabaseAdmin
      .from('specialists')
      .delete()
      .eq('id', id);

    if (error) {
      console.error('Delete specialist error:', error);
      return { ok: false, error: error.message };
    }

    revalidatePath('/');
    revalidatePath('/specialists');
    revalidatePath('/admin/dashboard');

    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err.message || 'An unexpected error occurred.' };
  }
}

// Mark ALL of a partner's pending commission payouts as paid (i.e. the admin has
// disbursed the money via UPI/bank). Returns how much was settled so the UI can
// confirm it. Idempotent-ish: if nothing is pending it's a no-op.
export async function markPartnerPaidAction(partnerId: string) {
  try {
    if (!(await requireAdmin())) return { ok: false, error: 'Unauthorized.' };
    const db = createAdminClient();

    const { data: pending } = await db
      .from('partner_payouts')
      .select('id, partner_amount')
      .eq('partner_id', partnerId)
      .eq('status', 'pending');

    const rows = pending || [];
    if (rows.length === 0) return { ok: true, count: 0, amount: 0 };

    const amount = rows.reduce((s, r) => s + (r.partner_amount || 0), 0);

    const { error } = await db
      .from('partner_payouts')
      .update({ status: 'paid' })
      .eq('partner_id', partnerId)
      .eq('status', 'pending');

    if (error) {
      console.error('Mark partner paid error:', error);
      return { ok: false, error: error.message };
    }

    revalidatePath('/admin/partners');
    revalidatePath('/admin/dashboard');
    return { ok: true, count: rows.length, amount };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Failed to mark paid.' };
  }
}

// Admin manually adjusts a parent's wallet credits. `amount` is a signed
// delta — positive grants credits, negative deducts them. The change is
// recorded in wallet_ledger (with the admin as `created_by`) so it shows up in
// the parent's transaction history just like a top-up or spend.
export async function adjustParentCreditsAction(
  parentId: string,
  amount: number,
  reason?: string
) {
  try {
    const admin = await requireAdmin();
    if (!admin) return { ok: false, error: 'Unauthorized.' };

    const delta = Math.trunc(Number(amount));
    if (!Number.isFinite(delta) || delta === 0) {
      return { ok: false, error: 'Enter a non-zero credit amount.' };
    }

    const db = createAdminClient();

    const { data: parent, error: readErr } = await db
      .from('parents')
      .select('credits')
      .eq('id', parentId)
      .maybeSingle();

    if (readErr || !parent) {
      return { ok: false, error: 'Parent not found.' };
    }

    const current = parent.credits || 0;
    const newBalance = current + delta;
    if (newBalance < 0) {
      return { ok: false, error: `Cannot deduct ${Math.abs(delta)} — parent only has ${current} credits.` };
    }

    const { error: updErr } = await db
      .from('parents')
      .update({ credits: newBalance })
      .eq('id', parentId);

    if (updErr) {
      console.error('Adjust credits update error:', updErr);
      return { ok: false, error: updErr.message };
    }

    // Best-effort ledger entry — the balance update above is the source of truth.
    await db.from('wallet_ledger').insert({
      parent_id: parentId,
      amount: delta,
      balance_after: newBalance,
      service_key: 'admin_adjustment',
      reason: reason?.trim() || (delta > 0 ? 'Credits added by admin' : 'Credits removed by admin'),
      created_by: admin.email || 'admin',
    });

    revalidatePath(`/admin/parents/${parentId}`);
    revalidatePath('/admin/parents');
    revalidatePath('/admin/dashboard');

    return { ok: true, credits: newBalance };
  } catch (err: any) {
    return { ok: false, error: err.message || 'Failed to adjust credits.' };
  }
}

export async function updatePartnerStatusAction(
  partnerId: string,
  status: 'active' | 'pending' | 'paused' | 'terminated'
) {
  try {
    const supabase = await createClient();

    // Verify Admin Role using user token
    const { data: { user } } = await supabase.auth.getUser();
    if (!user || user.user_metadata?.role !== 'admin') {
      return { ok: false, error: 'Unauthorized.' };
    }

    const supabaseAdmin = createAdminClient();
    const { error } = await supabaseAdmin
      .from('partners')
      .update({ status })
      .eq('id', partnerId);

    if (error) {
      console.error('Update partner status error:', error);
      return { ok: false, error: error.message };
    }

    revalidatePath('/admin/partners');
    revalidatePath('/admin/dashboard');

    return { ok: true };
  } catch (err: any) {
    return { ok: false, error: err.message || 'An unexpected error occurred.' };
  }
}

