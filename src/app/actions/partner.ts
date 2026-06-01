'use server';

import { createAdminClient } from '@/lib/supabase/admin';
import { createClient as createServerSupabase } from '@/lib/supabase/server';

interface FamilyRegistrationData {
  parentName: string;
  parentPhone: string;
  parentEmail: string;
  childName: string;
  childDob: string;
  childGender?: string;
  childSchool?: string;
  childClass?: string;
  childMotherTongue?: string;
  childDiagnosis?: string;
}

export async function partnerAddFamilyAction(
  data: FamilyRegistrationData
): Promise<{ ok: boolean; error?: string; message?: string }> {
  const supabase = await createServerSupabase();
  const { data: { user } } = await supabase.auth.getUser();

  if (!user || user.user_metadata?.role !== 'partner') {
    return { ok: false, error: 'Unauthorized. Partner login required.' };
  }

  const partnerId = user.id;

  // Validate inputs
  const pwa = data.parentPhone.trim().replace(/\D/g, '');
  const parentName = data.parentName.trim();
  const childName = data.childName.trim();
  const childDob = data.childDob.trim();

  if (!parentName || pwa.length < 10) {
    return { ok: false, error: 'Parent name and a valid WhatsApp number (at least 10 digits) are required.' };
  }
  if (!childName || !childDob) {
    return { ok: false, error: "Child's name and date of birth are required." };
  }

  const parentEmail = data.parentEmail.trim().toLowerCase();
  const supabaseAdmin = createAdminClient();

  try {
    let parentUuid: string | null = null;
    let parentExists = false;

    // 1. Check if email already exists in Auth
    if (parentEmail) {
      const { data: userList } = await supabaseAdmin.auth.admin.listUsers();
      const existingAuthUser = userList?.users.find(u => u.email === parentEmail);
      if (existingAuthUser) {
        parentUuid = existingAuthUser.id;
        parentExists = true;
      }
    }

    // 2. If parent doesn't exist in auth, create them
    if (!parentUuid) {
      const { data: authUser, error: authError } = await supabaseAdmin.auth.admin.createUser({
        email: parentEmail || `parent-${pwa}@empowerstudents.in`,
        phone: pwa.startsWith('+') ? pwa : `+91${pwa}`,
        email_confirm: true,
        phone_confirm: true,
        password: `welcome${pwa.substring(pwa.length - 4)}`, // Default password like welcome5678
        user_metadata: {
          name: parentName,
          phone: pwa,
          role: 'parent'
        }
      });

      if (authError || !authUser.user) {
        console.error('Failed to create parent in auth:', authError);
        return { ok: false, error: `Failed to register parent account: ${authError?.message || 'Unknown error'}` };
      }

      parentUuid = authUser.user.id;
    }

    // 3. Look up or insert profile in public.parents
    const { data: existingParent } = await supabaseAdmin
      .from('parents')
      .select('id, partner_id')
      .eq('id', parentUuid)
      .maybeSingle();

    if (existingParent) {
      // Attribute to partner if they don't have one
      if (!existingParent.partner_id) {
        await supabaseAdmin
          .from('parents')
          .update({ partner_id: partnerId })
          .eq('id', parentUuid);
      }
    } else {
      // Create new profile linked to partner
      await supabaseAdmin.from('parents').insert({
        id: parentUuid,
        partner_id: partnerId,
        whatsapp: pwa.startsWith('+') ? pwa : `+91${pwa}`,
        name: parentName,
        email: parentEmail || null,
        credits: 100, // free signup credits
      });

      // Insert ledger row
      await supabaseAdmin.from('wallet_ledger').insert({
        parent_id: parentUuid,
        amount: 100,
        balance_after: 100,
        service_key: 'signup_bonus',
        reason: 'Free signup credits',
      });
    }

    // 4. Create child record
    const { error: childError } = await supabaseAdmin.from('children').insert({
      parent_id: parentUuid,
      name: childName,
      gender: data.childGender || null,
      dob: childDob,
      school: data.childSchool || null,
      class_grade: data.childClass || null,
      mother_tongue: data.childMotherTongue || null,
      diagnosis: data.childDiagnosis || null,
    });

    if (childError) {
      console.error('Failed to insert child:', childError);
      return { ok: false, error: `Parent registered but failed to add child details: ${childError.message}` };
    }

    return {
      ok: true,
      message: `Successfully registered child ${childName} under parent ${parentName}.`
    };
  } catch (err: any) {
    console.error('Add family transaction exception:', err);
    return { ok: false, error: err.message || 'An unexpected error occurred.' };
  }
}
