'use server';

import { cookies } from 'next/headers';
import { createAdminClient } from '@/lib/supabase/admin';
import { createClient as createServerSupabase } from '@/lib/supabase/server';

/**
 * Sign up a new user (Parent or Partner).
 * Creates the user in Supabase Auth and populates public.parents or public.partners.
 */
export async function signUpAction(
  name: string,
  email: string,
  phone: string,
  password: string,
  role: 'parent' | 'partner' = 'parent',
  additionalFields?: {
    referralCode?: string;
    city?: string;
    upiId?: string;
  }
): Promise<{ ok: boolean; error?: string }> {
  // Validate inputs
  if (!name || name.trim().length < 2) {
    return { ok: false, error: 'Please enter your name (at least 2 characters).' };
  }

  const trimmedEmail = email.trim().toLowerCase();
  if (!trimmedEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
    return { ok: false, error: 'Please enter a valid email address.' };
  }

  const trimmedPhone = phone.trim();
  if (!trimmedPhone || trimmedPhone.length < 10) {
    return { ok: false, error: 'Please enter a valid phone number.' };
  }

  if (!password || password.length < 6) {
    return { ok: false, error: 'Password must be at least 6 characters long.' };
  }

  const supabaseAdmin = createAdminClient();

  // Check if email already exists
  const { data: userList } = await supabaseAdmin.auth.admin.listUsers();
  const existingUser = userList?.users.find(u => u.email === trimmedEmail);

  if (existingUser) {
    return { ok: false, error: 'This email is already registered. Please login instead.' };
  }

  // Partner-specific validations
  let uppercaseCode = '';
  if (role === 'partner') {
    const code = additionalFields?.referralCode?.trim() || '';
    if (!code || code.length < 3) {
      return { ok: false, error: 'Referral code must be at least 3 characters long.' };
    }
    uppercaseCode = code.toUpperCase();

    // Check if referral code is already taken in partners table
    const { data: existingPartner } = await supabaseAdmin
      .from('partners')
      .select('id')
      .eq('referral_code', uppercaseCode)
      .maybeSingle();

    if (existingPartner) {
      return { ok: false, error: 'This referral code is already taken. Please choose another.' };
    }
  }

  // Create user in Supabase Auth
  const { data: newUser, error: createError } = await supabaseAdmin.auth.admin.createUser({
    email: trimmedEmail,
    password: password,
    email_confirm: true,
    user_metadata: {
      name: name.trim(),
      phone: trimmedPhone,
      role: role,
    },
  });

  if (createError || !newUser.user) {
    console.error('Auth create user error:', createError);
    return { ok: false, error: 'Registration failed. Please try again.' };
  }

  const userId = newUser.user.id;

  if (role === 'partner') {
    // Create partner record
    const { error: partnerInsertErr } = await supabaseAdmin.from('partners').insert({
      id: userId,
      name: name.trim(),
      email: trimmedEmail,
      phone: trimmedPhone,
      whatsapp: trimmedPhone.startsWith('+') ? trimmedPhone : `+91${trimmedPhone}`,
      city: additionalFields?.city?.trim() || null,
      referral_code: uppercaseCode,
      upi_id: additionalFields?.upiId?.trim() || null,
      revenue_share: 0.30, // 30% revenue share default
      status: 'pending'
    });

    if (partnerInsertErr) {
      console.error('Partner profile insert error:', partnerInsertErr);
    }
  } else {
    // Resolve the referrer. A code the parent TYPED on the form wins; otherwise
    // fall back to the cookie set when they arrived via a /r/<code> link. This is
    // what makes the bare code usable even without the link.
    const cookieStore = await cookies();
    let referredByPartnerId = cookieStore.get('referred_by_partner_id')?.value || null;
    let referredByParentId = cookieStore.get('referred_by_parent_id')?.value || null;

    const typedCode = additionalFields?.referralCode?.trim().toUpperCase();
    if (typedCode) {
      // A code can belong to a partner or to a referring parent — check both.
      const { data: refPartner } = await supabaseAdmin
        .from('partners')
        .select('id')
        .eq('referral_code', typedCode)
        .maybeSingle();

      if (refPartner) {
        referredByPartnerId = refPartner.id;
        referredByParentId = null;
      } else {
        const { data: refParent } = await supabaseAdmin
          .from('parents')
          .select('id')
          .eq('referral_code', typedCode)
          .maybeSingle();
        if (refParent) {
          referredByParentId = refParent.id;
        }
      }
    }

    // Create parent record
    const { error: parentInsertErr } = await supabaseAdmin.from('parents').insert({
      id: userId,
      partner_id: referredByPartnerId,
      whatsapp: trimmedPhone.startsWith('+') ? trimmedPhone : `+91${trimmedPhone}`,
      name: name.trim(),
      email: trimmedEmail,
      credits: 100, // 100 free signup credits
    });

    if (parentInsertErr) {
      console.error('Parent profile insert error:', parentInsertErr);
    }

    // Insert wallet ledger bonus
    await supabaseAdmin.from('wallet_ledger').insert({
      parent_id: userId,
      amount: 100,
      balance_after: 100,
      service_key: 'signup_bonus',
      reason: 'Free signup credits',
    });

    // Record parent referral if referred by parent
    if (referredByParentId) {
      const { error: referralErr } = await supabaseAdmin.from('referrals').insert({
        referrer_parent_id: referredByParentId,
        referred_phone: trimmedPhone,
        referred_email: trimmedEmail,
        child_eval_completed: false
      });
      if (referralErr) {
        console.error('Referral record insert error:', referralErr);
      }
    }
  }

  return { ok: true };
}

/**
 * Login an existing user (Parent, Partner, or Admin).
 */
export async function loginAction(
  email: string,
  password: string
): Promise<{ ok: boolean; error?: string; role?: string; redirectUrl?: string }> {
  const trimmedEmail = email.trim().toLowerCase();
  // Trim surrounding whitespace from the password too — copy/paste commonly
  // appends a trailing space which otherwise fails as "Invalid credentials".
  const trimmedPassword = password.trim();

  if (!trimmedEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
    return { ok: false, error: 'Please enter a valid email address.' };
  }

  if (!trimmedPassword || trimmedPassword.length < 1) {
    return { ok: false, error: 'Please enter your password.' };
  }

  // Autoseed admin if it's the first time trying to log in as admin
  if (trimmedEmail === 'admin@empowerstudents.in' && trimmedPassword === 'empower@2026') {
    await seedAdminAccount();
  }

  const supabase = await createServerSupabase();
  const { data, error: loginError } = await supabase.auth.signInWithPassword({
    email: trimmedEmail,
    password: trimmedPassword,
  });

  if (loginError || !data.user) {
    console.error('Auth login error:', loginError);
    if (loginError?.message?.includes('Invalid login credentials')) {
      return { ok: false, error: 'Invalid email or password. Please try again.' };
    }
    return { ok: false, error: loginError?.message || 'Login failed. Please try again.' };
  }

  const role = data.user.user_metadata?.role || 'parent';
  let redirectUrl = '/dashboard';
  if (role === 'admin') {
    redirectUrl = '/admin/dashboard';
  } else if (role === 'partner') {
    // Check partner approval status
    const supabaseAdmin = createAdminClient();
    const { data: partner, error: partnerErr } = await supabaseAdmin
      .from('partners')
      .select('status')
      .eq('id', data.user.id)
      .maybeSingle();

    if (partnerErr || !partner) {
      await supabase.auth.signOut();
      return { ok: false, error: 'Partner profile not found.' };
    }

    if (partner.status === 'pending') {
      await supabase.auth.signOut();
      return { ok: false, error: 'Your partner registration request is pending approval. You will be able to log in once the admin approves your account.' };
    }

    if (partner.status !== 'active') {
      await supabase.auth.signOut();
      return { ok: false, error: `Your partner account is currently ${partner.status}. Please contact support.` };
    }

    redirectUrl = '/partner/dashboard';
  }

  return { ok: true, role, redirectUrl };
}

/**
 * Helper function to seed the default admin account.
 */
export async function seedAdminAccount(): Promise<boolean> {
  const adminEmail = 'admin@empowerstudents.in';
  const adminPass = 'empower@2026';

  try {
    const supabaseAdmin = createAdminClient();
    const { data: userList } = await supabaseAdmin.auth.admin.listUsers();
    const existing = userList?.users.find(u => u.email === adminEmail);

    if (!existing) {
      console.log('Seeding default admin user...');
      const { error } = await supabaseAdmin.auth.admin.createUser({
        email: adminEmail,
        password: adminPass,
        email_confirm: true,
        user_metadata: {
          name: 'Administrator',
          role: 'admin',
        },
      });

      if (error) {
        console.error('Failed to seed admin:', error.message);
        return false;
      }
      console.log('Admin user seeded successfully!');
    }
    return true;
  } catch (err) {
    console.error('Admin seeding exception:', err);
    return false;
  }
}
