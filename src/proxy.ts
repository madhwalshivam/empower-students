import { type NextRequest } from 'next/server';
import { updateSession } from '@/lib/supabase/middleware';

export async function proxy(request: NextRequest) {
  return await updateSession(request);
}

export const config = {
  // Run on every page (including public ones like the home page) so a stale
  // Supabase refresh token gets cleared no matter where the visitor lands —
  // otherwise the browser client reads the dead cookie and logs the
  // "Invalid Refresh Token: Refresh Token Not Found" error in the dev overlay.
  //
  // This does NOT reintroduce the public-page lag the narrow matcher fixed:
  // `updateSession` returns immediately (no Supabase round-trip) unless an
  // `sb-…-auth-token` cookie is actually present, so logged-out visitors pay
  // nothing. Static assets and Next internals are excluded below.
  matcher: [
    '/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico|css|js)$).*)',
  ],
};
