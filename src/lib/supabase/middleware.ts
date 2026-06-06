import { createServerClient } from '@supabase/ssr';
import { NextResponse, type NextRequest } from 'next/server';

export async function updateSession(request: NextRequest) {
  let response = NextResponse.next({
    request,
  });

  // Only talk to Supabase Auth when the browser actually carries a session
  // cookie. Logged-out visitors browsing public pages have no `sb-…-auth-token`
  // cookie, so they pay zero network round-trips — preserving the fast public
  // navigation. When a cookie IS present (valid OR stale), we let getUser()
  // below either refresh it or, for a dead/rotated token, clear it.
  const hasAuthCookie = request.cookies
    .getAll()
    .some((c) => c.name.startsWith('sb-') && c.name.includes('auth-token'));

  if (!hasAuthCookie) {
    return response;
  }

  const supabase = createServerClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!,
    {
      cookies: {
        getAll() {
          return request.cookies.getAll();
        },
        setAll(cookiesToSet) {
          cookiesToSet.forEach(({ name, value }) =>
            request.cookies.set(name, value)
          );
          response = NextResponse.next({
            request,
          });
          cookiesToSet.forEach(({ name, value, options }) =>
            response.cookies.set(name, value, options)
          );
        },
      },
    }
  );

  // Refreshes a valid-but-expired session (writing fresh cookies) and, for a
  // dead/rotated refresh token, removes the stale auth cookies via Set-Cookie.
  // Clearing it here — in the same response that delivers the page — means the
  // browser Supabase client never reads the bad token, so it never logs the
  // "Invalid Refresh Token: Refresh Token Not Found" console error. Wrapped so a
  // transient Auth outage can never break a page render.
  try {
    await supabase.auth.getUser();
  } catch {
    // ignore — getUser already clears non-retryable sessions internally
  }

  return response;
}
