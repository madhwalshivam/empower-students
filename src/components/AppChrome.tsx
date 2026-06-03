'use client';

import React, { useState, useEffect } from 'react';
import { usePathname } from 'next/navigation';
import { createClient } from '@/lib/supabase/client';
import Header from './Header';
import Footer from './Footer';
import UserShell from './UserShell';

const APP_ROUTES = [
  '/dashboard',
  '/wallet',
  '/partner',
  '/child',
  '/eval',
  '/eval-speech',
  '/parent-reflect',
];

export default function AppChrome({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const isAdmin = pathname?.startsWith('/admin');

  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const supabase = createClient();

    const checkSession = async () => {
      const { data: { session } } = await supabase.auth.getSession();
      setIsAuthenticated(!!session);
      setLoading(false);
    };

    checkSession();

    const { data: { subscription } } = supabase.auth.onAuthStateChange((event, session) => {
      setIsAuthenticated(!!session);
      setLoading(false);
    });

    return () => {
      subscription.unsubscribe();
    };
  }, []);

  if (isAdmin) return <>{children}</>;

  const isAppRoute = APP_ROUTES.some((route) => pathname?.startsWith(route)) ||
                     pathname === '/specialists' ||
                     pathname === '/about';

  if (isAppRoute && loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col items-center justify-center text-slate-500 font-medium">
        <div className="es-spinner mb-3" style={{ width: 28, height: 28, borderTopColor: '#4f46e5' }} />
        <span>Loading portal...</span>
      </div>
    );
  }

  if (isAuthenticated && isAppRoute) {
    return <UserShell>{children}</UserShell>;
  }

  return (
    <>
      <Header />
      <main className="flex-grow max-w-7xl mx-auto px-4 py-8 sm:py-12 w-full">{children}</main>
      <Footer />
    </>
  );
}
