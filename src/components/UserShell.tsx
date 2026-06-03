'use client';

import React, { useState, useEffect, useTransition } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  LayoutDashboard,
  Coins,
  Users,
  BookOpen,
  LogOut,
  Menu,
  X,
  Smile,
  ShieldCheck,
  PlusCircle,
  HeartHandshake
} from 'lucide-react';
import { logoutAction, getUserCreditsAction } from '@/app/actions/auth';
import { useTranslation } from './I18nContext';

export default function UserShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { t, language, setLanguage } = useTranslation();

  const [profile, setProfile] = useState<{ name: string; credits: number; role: 'parent' | 'partner' } | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [logoutOpen, setLogoutOpen] = useState(false);
  const [loggingOut, startLogout] = useTransition();

  const fetchProfile = async () => {
    const res = await getUserCreditsAction();
    if (res.ok) {
      setProfile({
        name: res.name || '',
        credits: res.credits || 0,
        role: (res.role as 'parent' | 'partner') || 'parent',
      });
    }
  };

  useEffect(() => {
    fetchProfile();
  }, [pathname]);

  useEffect(() => {
    const interval = setInterval(fetchProfile, 5000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    setDrawerOpen(false);
  }, [pathname]);

  const confirmLogout = () =>
    startLogout(async () => {
      try {
        await logoutAction();
      } catch {
        /* ignore */
      }
      setLogoutOpen(false);
      router.push('/');
      router.refresh();
    });

  const getPageTitle = () => {
    if (pathname?.startsWith('/dashboard')) return 'Parent Dashboard';
    if (pathname?.startsWith('/wallet')) return 'Wallet & Transactions';
    if (pathname?.startsWith('/specialists')) return 'Clinician Panel';
    if (pathname?.startsWith('/about')) return 'About Us';
    if (pathname?.startsWith('/child/add')) return 'Register New Child';
    if (pathname?.startsWith('/child')) return 'Child Profile';
    if (pathname?.startsWith('/eval-speech')) return 'Speech & Language Evaluation';
    if (pathname?.startsWith('/eval')) return 'Developmental Assessment';
    if (pathname?.startsWith('/parent-reflect')) return 'Parent Reflection';
    if (pathname?.startsWith('/partner/dashboard')) return 'Partner Dashboard';
    if (pathname?.startsWith('/partner/add-family')) return 'Register Referred Family';
    return 'App Portal';
  };

  const parentNav = [
    { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { label: 'Wallet & Topup', href: '/wallet', icon: Coins },
    { label: 'Our Specialists', href: '/specialists', icon: Users },
    { label: 'About Us', href: '/about', icon: BookOpen },
  ];

  const partnerNav = [
    { label: 'Referral Portal', href: '/partner/dashboard', icon: LayoutDashboard },
    { label: 'Register Family', href: '/partner/add-family', icon: PlusCircle },
  ];

  const navItems = profile?.role === 'partner' ? partnerNav : parentNav;

  const SidebarInner = ({ onNav }: { onNav?: () => void }) => (
    <>
      <Link href={profile?.role === 'partner' ? '/partner/dashboard' : '/dashboard'} className="es-admin-brand" onClick={onNav}>
        <span className="es-admin-brand-badge" style={{ background: 'linear-gradient(135deg, #4f46e5, #6366f1)' }}>
          <Smile size={17} />
        </span>
        <span style={{ lineHeight: 1.15 }}>
          <span style={{ display: 'block', fontWeight: 800, color: '#fff', fontSize: 14 }}>Empower Students</span>
          <span style={{ display: 'block', fontSize: 10, color: '#94a3b8', fontWeight: 650 }}>
            {profile?.role === 'partner' ? 'Partner Console' : 'Parent Portal'}
          </span>
        </span>
      </Link>

      <nav className="es-admin-nav">
        {navItems.map((n) => {
          const Icon = n.icon;
          const isActive = pathname === n.href || (n.href !== '/dashboard' && pathname?.startsWith(n.href));
          return (
            <Link key={n.href} href={n.href} onClick={onNav} className={`es-nav-link${isActive ? ' active' : ''}`}>
              <Icon size={18} /> {n.label}
            </Link>
          );
        })}
      </nav>

      <div className="es-admin-nav-foot">
        <a href="/" className="es-nav-link" onClick={onNav}>
          <BookOpen size={18} /> View Homepage
        </a>
        <button onClick={() => { onNav?.(); setLogoutOpen(true); }} className="es-nav-link danger">
          <LogOut size={18} /> Logout
        </button>
      </div>
    </>
  );

  return (
    <div className="es-admin">
      {/* Desktop fixed sidebar */}
      <aside className="es-admin-sidebar">
        <SidebarInner />
      </aside>

      {/* Mobile drawer */}
      {drawerOpen && (
        <>
          <div className="es-admin-overlay animate-fade-in" onClick={() => setDrawerOpen(false)} />
          <aside className="es-admin-drawer animate-slide-in">
            <button
              onClick={() => setDrawerOpen(false)}
              style={{ position: 'absolute', top: 14, right: 12, color: '#94a3b8', background: 'transparent', border: 0, cursor: 'pointer' }}
              aria-label="Close menu"
            >
              <X size={20} />
            </button>
            <SidebarInner onNav={() => setDrawerOpen(false)} />
          </aside>
        </>
      )}

      {/* Main column */}
      <div className="es-admin-main">
        <header className="es-admin-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flex: 1, minWidth: 0 }}>
            <button className="es-admin-hamburger" onClick={() => setDrawerOpen(true)} aria-label="Open menu">
              <Menu size={20} />
            </button>
            <div style={{ minWidth: 0 }}>
              <h1 style={{ fontSize: 18, fontWeight: 800, letterSpacing: '-0.01em', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} className="text-slate-800 dark:text-slate-100">
                {getPageTitle()}
              </h1>
              <p style={{ fontSize: 11, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} className="text-slate-400">
                Empower Students · {profile?.role === 'partner' ? 'Partner Portal' : 'Parent Dashboard'}
              </p>
            </div>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexShrink: 0 }}>
            {/* Language Switcher */}
            <div style={{ flexShrink: 0 }} className="lang-toggle inline-flex items-center rounded-full p-0.5 text-[10px] font-bold border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-800">
              <button
                onClick={() => setLanguage('en')}
                style={{ padding: '2px 8px', border: 0, cursor: 'pointer' }}
                className={`rounded-full transition-all ${language === 'en' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 bg-transparent'}`}
              >
                EN
              </button>
              <button
                onClick={() => setLanguage('hi')}
                style={{ padding: '2px 8px', border: 0, cursor: 'pointer' }}
                className={`rounded-full transition-all ${language === 'hi' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 bg-transparent'}`}
              >
                हिं
              </button>
            </div>

            {/* Credit balance display for parent */}
            {profile?.role === 'parent' && (
              <Link
                href="/wallet"
                style={{ flexShrink: 0, whiteSpace: 'nowrap' }}
                className="es-credits-pill inline-flex items-center gap-1.5 text-xs rounded-full px-4 py-2 border border-indigo-100 dark:border-slate-800 bg-indigo-50 dark:bg-slate-800 text-indigo-700 dark:text-indigo-400 hover:scale-105 transition-transform font-bold no-underline"
              >
                <Coins size={14} /> <span style={{ whiteSpace: 'nowrap' }}>{profile.credits} cr</span>
              </Link>
            )}

            <div style={{ fontSize: 13, fontWeight: 600 }} className="text-slate-500 hidden sm:block">
              {profile?.name || 'User'}
            </div>
          </div>
        </header>

        <div className="es-admin-body">{children}</div>
      </div>

      {/* Logout confirmation modal */}
      {logoutOpen && (
        <div className="es-modal-overlay animate-fade-in" onClick={() => !loggingOut && setLogoutOpen(false)}>
          <div className="es-modal animate-scale-in" onClick={(e) => e.stopPropagation()}>
            <div style={{ width: 56, height: 56, borderRadius: 18, background: '#fef2f2', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 16px' }}>
              <LogOut size={26} className="text-rose-600" />
            </div>
            <h3 style={{ fontSize: 20, fontWeight: 800, textAlign: 'center' }} className="text-slate-800 dark:text-slate-100">Log out?</h3>
            <p style={{ fontSize: 14, textAlign: 'center', marginTop: 6 }} className="text-slate-500">
              Are you sure you want to log out of your account? You&apos;ll need to sign in again to access evaluations and tools.
            </p>
            <div style={{ display: 'flex', gap: 12, marginTop: 24 }}>
              <button
                onClick={() => setLogoutOpen(false)}
                disabled={loggingOut}
                style={{ flex: 1, padding: '10px 0', borderRadius: 12, fontWeight: 700, fontSize: 14, border: 0, cursor: 'pointer', background: '#f1f5f9', color: '#334155' }}
              >
                Cancel
              </button>
              <button
                onClick={confirmLogout}
                disabled={loggingOut}
                style={{ flex: 1, padding: '10px 0', borderRadius: 12, fontWeight: 700, fontSize: 14, border: 0, cursor: 'pointer', background: '#e11d48', color: '#fff', opacity: loggingOut ? 0.6 : 1 }}
              >
                {loggingOut ? 'Logging out…' : 'Logout'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
