'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { calcAgeYears } from '@/lib/evaluations/engine';
import { orderReportViaWallet, orderReportViaReferral } from '@/lib/referrals';
import { revalidatePath } from 'next/cache';

/**
 * Parent requests a detailed expert report via a form. We record it as a LEAD so
 * it shows up in the admin panel (/admin/leads) for the clinical team to follow up.
 */
export async function requestExpertReportAction(payload: {
  childId: number;
  parentName: string;
  phone: string;
  concern?: string;
  message?: string;
  preferredTime?: string;
}) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { ok: false, error: 'Authentication required' };
  }

  const name = (payload.parentName || '').trim();
  const phone = (payload.phone || '').trim();
  if (!name) return { ok: false, error: 'Please enter your name.' };
  if (phone.replace(/\D/g, '').length < 8) return { ok: false, error: 'Please enter a valid phone number.' };

  const admin = createAdminClient();

  // Verify the child belongs to this parent and pull a little context
  const { data: child } = await admin
    .from('children')
    .select('name, dob, class_grade')
    .eq('id', payload.childId)
    .eq('parent_id', user.id)
    .maybeSingle();

  if (!child) return { ok: false, error: 'Child profile not found.' };

  const age = child.dob ? calcAgeYears(child.dob) : null;

  const notesParts = [
    `Detailed expert report request for "${child.name}"${age ? `, age ${age}` : ''}${child.class_grade ? `, Grade ${child.class_grade}` : ''}.`,
    payload.preferredTime ? `Preferred call time: ${payload.preferredTime}.` : '',
    payload.message ? `Parent note: ${payload.message}` : '',
    `Submitted by parent account: ${user.email || user.id}.`,
  ].filter(Boolean);

  try {
    const { error } = await admin.from('leads').insert({
      parent_name: name,
      phone,
      child_age: age != null ? String(age) : '',
      concern: payload.concern || 'detailed_report',
      source: 'expert_report',
      status: 'new',
      notes: notesParts.join('\n'),
    });

    if (error) {
      // The leads table may not exist yet on this DB. Don't lose the request —
      // fall back to an expert_report_orders pending record so the parent's
      // submission still goes through (and the report page shows "in progress").
      const missingTable = /leads/i.test(error.message) && /(schema cache|does not exist|find the table)/i.test(error.message);
      if (missingTable) {
        console.error('[requestExpertReportAction] leads table missing — run create_leads_table.sql. Falling back to expert_report_orders.');
        await admin.from('expert_report_orders').insert({
          parent_id: user.id,
          child_id: payload.childId,
          source: 'expert_report_form',
          amount_paid: 0,
          status: 'pending',
        });
        revalidatePath(`/report/${payload.childId}`);
        return { ok: true, message: 'Request received! Our clinical team will contact you within 24 hours.' };
      }
      return { ok: false, error: error.message };
    }
  } catch (e: any) {
    return { ok: false, error: e.message || 'Could not submit your request. Please try again.' };
  }

  revalidatePath('/admin/leads');
  return { ok: true, message: 'Request received! Our clinical team will contact you within 24 hours.' };
}

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
