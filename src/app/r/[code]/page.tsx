import { redirect } from 'next/navigation';
import { cookies } from 'next/headers';
import { createAdminClient } from '@/lib/supabase/admin';

interface PageProps {
  params: Promise<{ code: string }>;
}

export default async function ReferralRedirectPage({ params }: PageProps) {
  const resolvedParams = await params;
  const referralCode = resolvedParams.code?.toUpperCase();

  if (referralCode) {
    const supabase = createAdminClient();
    const cookieStore = await cookies();

    // 1. Look up referrer parent to check if code is valid
    const { data: parent } = await supabase
      .from('parents')
      .select('id')
      .eq('referral_code', referralCode)
      .maybeSingle();

    if (parent) {
      // Store parent referral details in cookies for 30 days
      cookieStore.set('referred_by_code', referralCode, {
        maxAge: 30 * 24 * 60 * 60, // 30 days
        path: '/',
      });
      cookieStore.set('referred_by_parent_id', parent.id, {
        maxAge: 30 * 24 * 60 * 60, // 30 days
        path: '/',
      });
    } else {
      // 2. Look up referrer partner to check if code is valid
      const { data: partner } = await supabase
        .from('partners')
        .select('id')
        .eq('referral_code', referralCode)
        .maybeSingle();

      if (partner) {
        // Store partner referral details in cookies for 30 days
        cookieStore.set('referred_by_code', referralCode, {
          maxAge: 30 * 24 * 60 * 60, // 30 days
          path: '/',
        });
        cookieStore.set('referred_by_partner_id', partner.id, {
          maxAge: 30 * 24 * 60 * 60, // 30 days
          path: '/',
        });
      }
    }
  }

  // Redirect to register/login
  redirect('/signup');
}
