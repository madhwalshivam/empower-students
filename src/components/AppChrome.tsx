'use client';

import React, { useState, useEffect } from 'react';
import { usePathname } from 'next/navigation';
import { createClient } from '@/lib/supabase/client';
import Header from './Header';
import Footer from './Footer';
import UserShell from './UserShell';

// Strictly-protected routes: the server component for each of these already
// redirects unauthenticated visitors to /login, so if one renders at all the
// user IS authenticated. We therefore show the app shell (sidebar) IMMEDIATELY
// for these — no client-side auth gate, which is what used to get stuck on
// "Loading portal…" whenever the browser session check hung.
const PROTECTED_PREFIXES = [
  '/dashboard',
  '/wallet',
  '/partner',
  '/child',
  '/eval',
  '/eval-speech',
  '/parent-reflect',
];

// Dual routes: public AND in-app. Logged-in users get the shell; visitors get
// the public header. These need the client auth check — but it's bounded so it
// can never hang.
const DUAL_ROUTES = ['/specialists', '/about'];

export default function AppChrome({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const isAdmin = pathname?.startsWith('/admin');

  // null = unknown yet, true/false once resolved.
  const [isAuthenticated, setIsAuthenticated] = useState<boolean | null>(null);

  useEffect(() => {
    const supabase = createClient();
    let settled = false;
    const resolve = (v: boolean) => { settled = true; setIsAuthenticated(v); };

    // getSession() reads the locally-stored session; normally instant. Wrap it
    // so a rejection (e.g. a bad refresh token) can never leave us hanging.
    supabase.auth
      .getSession()
      .then(({ data }) => resolve(!!data.session))
      .catch(() => resolve(false));

    // Hard safety net: never wait more than 2.5s for the auth check.
    const timer = setTimeout(() => { if (!settled) setIsAuthenticated(false); }, 2500);

    const { data: { subscription } } = supabase.auth.onAuthStateChange((_event, session) => {
      setIsAuthenticated(!!session);
    });

    return () => {
      clearTimeout(timer);
      subscription.unsubscribe();
    };
  }, []);

  if (isAdmin) return <>{children}</>;

  const isProtected = PROTECTED_PREFIXES.some((r) => pathname?.startsWith(r));
  const isDual = DUAL_ROUTES.includes(pathname || '');

  // Protected → app shell straight away. The page's own loading.tsx skeleton
  // covers any data-fetch wait; there's no blank and nothing to get stuck on.
  if (isProtected) {
    return <UserShell>{children}</UserShell>;
  }

  if (isDual) {
    // Brief, bounded wait before we know whether to show the app shell or the
    // public chrome — avoids flashing the wrong one. Capped by the 2.5s timeout.
    if (isAuthenticated === null) {
      return (
        <div className="min-h-screen bg-slate-50 flex flex-col items-center justify-center text-slate-500 font-medium">
          <div className="es-spinner mb-3" style={{ width: 28, height: 28, borderTopColor: '#4f46e5' }} />
          <span>Loading…</span>
        </div>
      );
    }
    if (isAuthenticated) {
      return <UserShell>{children}</UserShell>;
    }
  }

  return (
    <>
      <Header />
      <main className="flex-grow max-w-7xl mx-auto px-4 py-8 sm:py-12 w-full">{children}</main>
      <Footer />
    </>
  );
}
