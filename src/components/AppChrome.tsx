'use client';

import React from 'react';
import { usePathname } from 'next/navigation';
import Header from './Header';
import Footer from './Footer';

/**
 * Renders the public marketing chrome (Header/Footer) for normal pages, but
 * gets out of the way entirely on /admin routes — the admin section provides
 * its own sidebar + header shell.
 */
export default function AppChrome({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const isAdmin = pathname?.startsWith('/admin');

  if (isAdmin) return <>{children}</>;

  return (
    <>
      <Header />
      <main className="flex-grow max-w-7xl mx-auto px-4 py-8 sm:py-12 w-full">{children}</main>
      <Footer />
    </>
  );
}
