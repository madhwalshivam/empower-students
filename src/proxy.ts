import { type NextRequest } from 'next/server';
import { updateSession } from '@/lib/supabase/middleware';

export async function proxy(request: NextRequest) {
  return await updateSession(request);
}

export const config = {
  // Only run the session-refresh middleware on routes that actually need an
  // authenticated user. Public pages (home, about, catalogue, login, etc.) no
  // longer pay for a network round-trip to Supabase Auth on every navigation,
  // which is what made browsing feel laggy. Protected pages still validate the
  // user themselves server-side, so auth correctness is unchanged.
  matcher: [
    '/dashboard/:path*',
    '/wallet/:path*',
    '/child/:path*',
    '/eval/:path*',
    '/eval-speech/:path*',
    '/parent-reflect/:path*',
    '/partner/:path*',
    '/admin/:path*',
    '/report/:path*',
  ],
};
