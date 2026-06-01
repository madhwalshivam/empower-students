'use server';

import crypto from 'crypto';
import { createAdminClient } from '@/lib/supabase/admin';
import { createClient as createServerSupabase } from '@/lib/supabase/server';
import { sendOtpMessage, generateOtpCode, normalizePhone } from '@/lib/twilio/otp';

const OTP_TTL_MINUTES = 5;
const RESEND_LIMIT_SECONDS = 30;

export async function sendOtpAction(
  name: string,
  phoneRaw: string
): Promise<{ ok: boolean; error?: string; step?: string; code?: string }> {
  if (!name || name.trim().length < 2) {
    return { ok: false, error: 'Please enter your name.' };
  }

  const phone = normalizePhone(phoneRaw);
  if (!/^\+\d{10,15}$/.test(phone)) {
    return { ok: false, error: 'Please enter a valid WhatsApp number with country code.' };
  }

  const supabase = createAdminClient();

  // Rate limit check
  const { data: lastOtp, error: rateError } = await supabase
    .from('otps')
    .select('sent_at')
    .eq('whatsapp', phone)
    .order('sent_at', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (lastOtp) {
    const elapsed = (Date.now() - new Date(lastOtp.sent_at).getTime()) / 1000;
    if (elapsed < RESEND_LIMIT_SECONDS) {
      return { ok: false, error: 'Please wait a few seconds before requesting another OTP.' };
    }
  }

  const code = generateOtpCode();
  // We hash using SHA256 for database safety
  const codeHash = crypto.createHash('sha256').update(code).digest('hex');

  const now = new Date();
  const expiresAt = new Date(now.getTime() + OTP_TTL_MINUTES * 60 * 1000).toISOString();

  // Save OTP record
  const { error: dbError } = await supabase
    .from('otps')
    .insert({
      whatsapp: phone,
      code_hash: codeHash,
      expires_at: expiresAt,
      attempts: 0,
    });

  if (dbError) {
    console.error('Database OTP insert error:', dbError);
    return { ok: false, error: 'Failed to generate OTP. Please try again.' };
  }

  // Send twilio WhatsApp message
  const verifyUrl = process.env.NEXT_PUBLIC_VERIFY_URL || 'https://empowerstudents.in';
  const twilioResult = await sendOtpMessage(phone, code, verifyUrl);

  const demoMode = process.env.OTP_MODE === 'demo';

  if (!twilioResult.ok && !demoMode) {
    return {
      ok: false,
      error: `Failed to send OTP on WhatsApp. Details: ${twilioResult.message}`,
    };
  }

  return {
    ok: true,
    step: 'otp',
    // Return code in demo mode so the UI can print it out for testing
    code: demoMode ? code : undefined,
  };
}

export async function verifyOtpAction(
  phoneRaw: string,
  name: string,
  enteredCode: string
): Promise<{ ok: boolean; error?: string }> {
  const phone = normalizePhone(phoneRaw);
  const codeHash = crypto.createHash('sha256').update(enteredCode.trim()).digest('hex');

  const supabaseAdmin = createAdminClient();

  // Find latest active OTP
  const { data: otp, error: otpError } = await supabaseAdmin
    .from('otps')
    .select('*')
    .eq('whatsapp', phone)
    .is('used_at', null)
    .order('sent_at', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (otpError || !otp) {
    return { ok: false, error: 'No active OTP found. Please request a new one.' };
  }

  if (new Date(otp.expires_at).getTime() < Date.now()) {
    return { ok: false, error: 'OTP expired. Please request a new one.' };
  }

  if (otp.code_hash !== codeHash) {
    // Increment attempts
    await supabaseAdmin
      .from('otps')
      .update({ attempts: otp.attempts + 1 })
      .eq('id', otp.id);

    if (otp.attempts >= 4) {
      return { ok: false, error: 'Too many wrong attempts. Please request a new OTP.' };
    }
    return { ok: false, error: 'Invalid OTP. Please try again.' };
  }

  // Mark OTP as used
  await supabaseAdmin
    .from('otps')
    .update({ used_at: new Date().toISOString() })
    .eq('id', otp.id);

  // Generate deterministic password for Supabase Auth
  const pepper = process.env.OTP_PASSWORD_PEPPER || 'empower-students-otp-pepper-secure-random';
  const password = crypto
    .createHmac('sha256', pepper)
    .update(phone)
    .digest('hex');

  const email = `${phone.replace('+', '')}@whatsapp.empowerstudents.in`;

  // Look up if user exists in auth
  const { data: userList, error: listError } = await supabaseAdmin.auth.admin.listUsers();
  const existingUser = userList?.users.find(u => u.email === email);

  let userId: string;

  if (existingUser) {
    userId = existingUser.id;
    // Ensure parent record exists in DB
    const { data: parentRecord } = await supabaseAdmin
      .from('parents')
      .select('id')
      .eq('id', userId)
      .maybeSingle();

    if (!parentRecord) {
      await supabaseAdmin.from('parents').insert({
        id: userId,
        whatsapp: phone,
        name: name,
        credits: 100, // 100 free credits
      });

      // Insert ledger entry
      await supabaseAdmin.from('wallet_ledger').insert({
        parent_id: userId,
        amount: 100,
        balance_after: 100,
        service_key: 'signup_bonus',
        reason: 'Free signup credits',
      });
    } else {
      // Update name if it was empty
      await supabaseAdmin
        .from('parents')
        .update({ name })
        .eq('id', userId)
        .is('name', null);
    }
  } else {
    // Register new user in Supabase Auth
    const { data: newUser, error: createError } = await supabaseAdmin.auth.admin.createUser({
      email,
      password,
      email_confirm: true,
      user_metadata: { name, whatsapp: phone },
    });

    if (createError || !newUser.user) {
      console.error('Auth create user error:', createError);
      return { ok: false, error: 'Registration failed. Please contact support.' };
    }

    userId = newUser.user.id;

    // Create parent record
    const { error: parentInsertErr } = await supabaseAdmin.from('parents').insert({
      id: userId,
      whatsapp: phone,
      name: name,
      credits: 100, // 100 free credits
    });

    if (parentInsertErr) {
      console.error('Parent profile insert error:', parentInsertErr);
    }

    // Insert wallet ledger transaction entry
    await supabaseAdmin.from('wallet_ledger').insert({
      parent_id: userId,
      amount: 100,
      balance_after: 100,
      service_key: 'signup_bonus',
      reason: 'Free signup credits',
    });
  }

  // Log in using standard auth client to set sessions & cookies
  const supabase = await createServerSupabase();
  const { data: sessionData, error: loginError } = await supabase.auth.signInWithPassword({
    email,
    password,
  });

  if (loginError) {
    console.error('Auth signin error:', loginError);
    return { ok: false, error: 'Login session establishment failed.' };
  }

  return { ok: true };
}

export async function logoutAction(): Promise<{ ok: boolean }> {
  const supabase = await createServerSupabase();
  await supabase.auth.signOut();
  return { ok: true };
}
