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
