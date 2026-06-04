import { NextResponse, type NextRequest } from 'next/server';
import { createAdminClient } from '@/lib/supabase/admin';

// Referral entry point: /r/<CODE>.
//
// This MUST be a Route Handler, not a page. Setting cookies during a Server
// Component (page) render is not allowed in this version of Next and throws —
// which is why the referral link previously "did not open". A Route Handler can
// freely attach cookies to its redirect response, so the link now works: we tag
// the visitor with the referrer and forward them to signup. When they register,
// signup reads `referred_by_partner_id` / `referred_by_parent_id` and attributes
// the new family to that partner/parent.
export async function GET(
  request: NextRequest,
  { params }: { params: Promise<{ code: string }> }
) {
  const { code } = await params;
  const referralCode = code?.toUpperCase();

  // Always end up on signup, with the referral cookies attached to THIS response.
  const response = NextResponse.redirect(new URL('/signup', request.url));

  if (referralCode) {
    const cookieOpts = { maxAge: 30 * 24 * 60 * 60, path: '/' }; // 30 days
    const supabase = createAdminClient();

    // A code can belong to either a referring parent or a partner. Check parents
    // first, then partners — mirroring the original lookup order.
    const { data: parent } = await supabase
      .from('parents')
      .select('id')
      .eq('referral_code', referralCode)
      .maybeSingle();

    if (parent) {
      response.cookies.set('referred_by_code', referralCode, cookieOpts);
      response.cookies.set('referred_by_parent_id', parent.id, cookieOpts);
    } else {
      const { data: partner } = await supabase
        .from('partners')
        .select('id')
        .eq('referral_code', referralCode)
        .maybeSingle();

      if (partner) {
        response.cookies.set('referred_by_code', referralCode, cookieOpts);
        response.cookies.set('referred_by_partner_id', partner.id, cookieOpts);
      }
    }
  }

  return response;
}
