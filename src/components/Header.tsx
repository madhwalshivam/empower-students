'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { createClient } from '@/lib/supabase/client';
import { useTranslation } from './I18nContext';
import { logoutAction } from '@/app/actions/auth';
import { Coins, LogOut, LayoutDashboard, Menu, X } from 'lucide-react';

export default function Header() {
  const { t, language, setLanguage } = useTranslation();
  const router = useRouter();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [user, setUser] = useState<any>(null);
  const [profile, setProfile] = useState<any>(null);

  useEffect(() => {
    const supabase = createClient();

    const fetchSession = async () => {
      const { data: { session } } = await supabase.auth.getSession();
      if (session) {
        setUser(session.user);
        const { data: parent } = await supabase
          .from('parents')
          .select('*')
          .eq('id', session.user.id)
          .maybeSingle();
        if (parent) {
          setProfile(parent);
        }
      } else {
        setUser(null);
        setProfile(null);
      }
    };

    fetchSession();

    const { data: { subscription } } = supabase.auth.onAuthStateChange(
      async (event, session) => {
        if (session) {
          setUser(session.user);
          const { data: parent } = await supabase
            .from('parents')
            .select('*')
            .eq('id', session.user.id)
            .maybeSingle();
          if (parent) {
            setProfile(parent);
          }
        } else {
          setUser(null);
          setProfile(null);
        }
      }
    );

    return () => {
      subscription.unsubscribe();
    };
  }, []);

  const handleLogout = async () => {
    await logoutAction();
    setUser(null);
    setProfile(null);
    router.push('/');
    router.refresh();
  };

  return (
    <header className="es-header sticky top-0 z-30 w-full bg-white dark:bg-slate-900 border-b border-slate-100 dark:border-slate-800">
      <div className="container max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        {/* Brand Logo */}
        <Link href="/" className="flex items-center gap-2.5 group">
          <img
            src="/logo-small.png"
            alt="Empower Students Logo"
            className="h-9 w-9 object-contain transition-transform group-hover:scale-105"
          />
          <span className="font-extrabold text-lg sm:text-xl tracking-tight text-slate-800 dark:text-slate-100">
            Empower Students
          </span>
        </Link>

        {/* Desktop Navigation */}
        <nav className="hidden md:flex items-center gap-6 text-sm font-semibold">
          <Link href="/" className="text-slate-500 dark:text-slate-300 hover:text-indigo-600 transition-colors">
            {t('nav.home')}
          </Link>
          <Link href="/specialists" className="text-slate-500 dark:text-slate-300 hover:text-indigo-600 transition-colors">
            {t('nav.panel')}
          </Link>
          <Link href="/about" className="text-slate-500 dark:text-slate-300 hover:text-indigo-600 transition-colors">
            {t('nav.about')}
          </Link>

          {/* Language Toggle */}
          <div className="lang-toggle inline-flex items-center rounded-full p-0.5 text-xs font-bold border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-800">
            <button
              onClick={() => setLanguage('en')}
              className={`px-3 py-1 rounded-full transition-all ${language === 'en' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 bg-transparent border-none'}`}
            >
              EN
            </button>
            <button
              onClick={() => setLanguage('hi')}
              className={`px-3 py-1 rounded-full transition-all ${language === 'hi' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 bg-transparent border-none'}`}
            >
              हिं
            </button>
          </div>

          {/* Auth Sections */}
          {user ? (
            <>
              <Link href="/wallet" className="es-credits-pill inline-flex items-center gap-1.5 text-xs rounded-full px-4 py-2 border border-indigo-100 dark:border-slate-800 bg-indigo-50 dark:bg-slate-800 text-indigo-700 dark:text-indigo-400 hover:scale-105 transition-transform font-bold">
                <Coins size={14} /> <span>{profile?.credits || 0} {t('nav.cr')}</span>
              </Link>
              <Link href={user?.user_metadata?.role === 'admin' ? '/admin/dashboard' : (user?.user_metadata?.role === 'partner' ? '/partner/dashboard' : '/dashboard')} className="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400 hover:text-indigo-850 font-bold transition-colors">
                <LayoutDashboard size={14} /> {t('nav.dashboard')}
              </Link>
              <button
                onClick={handleLogout}
                className="inline-flex items-center gap-1 text-slate-500 hover:text-rose-650 transition-colors font-semibold border-none bg-transparent cursor-pointer"
              >
                <LogOut size={14} /> {t('nav.logout')}
              </button>
            </>
          ) : (
            <>
              <Link
                href="/signup"
                className="text-indigo-600 dark:text-indigo-400 px-4 py-2 rounded-xl font-bold hover:bg-indigo-50 transition-all text-xs border border-indigo-200"
              >
                Sign Up
              </Link>
              <Link
                href="/login"
                className="bg-indigo-600 text-white px-5 py-2 rounded-xl font-bold hover:shadow-lg hover:scale-105 transition-all text-xs"
              >
                {t('nav.login')}
              </Link>
            </>
          )}
        </nav>

        {/* Mobile menu trigger */}
        <div className="md:hidden flex items-center gap-2">
          {user && (
            <Link href="/wallet" className="es-credits-pill inline-flex items-center gap-1 text-xs rounded-full px-2.5 py-1.5 border border-indigo-100 bg-indigo-50 text-indigo-750 font-bold">
              <Coins size={12} /> {profile?.credits || 0}
            </Link>
          )}
          <button
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg focus:outline-none border-none bg-transparent"
            aria-label="Toggle menu"
          >
            {mobileMenuOpen ? <X size={20} /> : <Menu size={20} />}
          </button>
        </div>
      </div>

      {/* Mobile Drawer */}
      {mobileMenuOpen && (
        <div className="md:hidden w-full border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 py-4 flex flex-col gap-4 animate-fade-in shadow-lg">
          <Link
            href="/"
            onClick={() => setMobileMenuOpen(false)}
            className="text-slate-700 dark:text-slate-300 font-semibold py-1 border-b border-slate-50 dark:border-slate-800"
          >
            {t('nav.home')}
          </Link>
          <Link
            href="/specialists"
            onClick={() => setMobileMenuOpen(false)}
            className="text-slate-700 dark:text-slate-300 font-semibold py-1 border-b border-slate-50 dark:border-slate-800"
          >
            {t('nav.panel')}
          </Link>
          <Link
            href="/about"
            onClick={() => setMobileMenuOpen(false)}
            className="text-slate-700 dark:text-slate-300 font-semibold py-1 border-b border-slate-50 dark:border-slate-800"
          >
            {t('nav.about')}
          </Link>

          {/* Mobile Language Switcher */}
          <div className="flex items-center justify-between py-1 border-b border-slate-50 dark:border-slate-800">
            <span className="text-slate-500 font-semibold text-sm">Language</span>
            <div className="lang-toggle inline-flex items-center rounded-full p-0.5 text-xs font-bold border border-slate-200 bg-slate-100">
              <button
                onClick={() => setLanguage('en')}
                className={`px-3 py-1 rounded-full transition-all ${language === 'en' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 bg-transparent border-none'}`}
              >
                EN
              </button>
              <button
                onClick={() => setLanguage('hi')}
                className={`px-3 py-1 rounded-full transition-all ${language === 'hi' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 bg-transparent border-none'}`}
              >
                हिं
              </button>
            </div>
          </div>

          {user ? (
            <>
              <Link
                href={user?.user_metadata?.role === 'admin' ? '/admin/dashboard' : (user?.user_metadata?.role === 'partner' ? '/partner/dashboard' : '/dashboard')}
                onClick={() => setMobileMenuOpen(false)}
                className="text-indigo-600 font-bold py-1 border-b border-slate-50 dark:border-slate-800"
              >
                {t('nav.dashboard')}
              </Link>
              <Link
                href="/wallet"
                onClick={() => setMobileMenuOpen(false)}
                className="text-indigo-750 font-bold py-1 border-b border-slate-50 dark:border-slate-800 flex items-center gap-1"
              >
                <Coins size={14} /> Wallet ({profile?.credits || 0} cr)
              </Link>
              <button
                onClick={() => {
                  setMobileMenuOpen(false);
                  handleLogout();
                }}
                className="text-rose-600 font-semibold text-left py-1 border-none bg-transparent cursor-pointer flex items-center gap-1"
              >
                <LogOut size={14} /> {t('nav.logout')}
              </button>
            </>
          ) : (
            <>
              <Link
                href="/signup"
                onClick={() => setMobileMenuOpen(false)}
                className="text-indigo-600 text-center py-2.5 rounded-xl font-bold border border-indigo-200"
              >
                Sign Up
              </Link>
              <Link
                href="/login"
                onClick={() => setMobileMenuOpen(false)}
                className="bg-indigo-600 text-white text-center py-2.5 rounded-xl font-bold shadow-md"
              >
                {t('nav.login')}
              </Link>
            </>
          )}
        </div>
      )}
    </header>
  );
}
