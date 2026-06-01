'use client';

import React, { useState, useEffect, useTransition } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  LayoutDashboard, PhoneCall, ClipboardList, Users, CreditCard, HeartHandshake,
  Tag, Stethoscope, Settings, LogOut, Menu, X, ExternalLink, ShieldCheck,
} from 'lucide-react';
import { logoutAction } from '@/app/actions/auth';

const NAV = [
  { label: 'Overview', href: '/admin/dashboard', icon: LayoutDashboard },
  { label: 'Leads', href: '/admin/leads', icon: PhoneCall },
  { label: 'Evaluations', href: '/admin/evaluations', icon: ClipboardList },
  { label: 'Parents', href: '/admin/parents', icon: Users },
  { label: 'Payments', href: '/admin/payments', icon: CreditCard },
  { label: 'Partners', href: '/admin/partners', icon: HeartHandshake },
  { label: 'Pricing', href: '/admin/pricing', icon: Tag },
  { label: 'Specialists', href: '/admin/specialists', icon: Stethoscope },
  { label: 'Settings', href: '/admin/settings', icon: Settings },
];

export default function AdminShell({
  children,
  adminName,
  adminEmail,
}: {
  children: React.ReactNode;
  adminName: string;
  adminEmail: string;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [logoutOpen, setLogoutOpen] = useState(false);
  const [loggingOut, startLogout] = useTransition();

  const pageTitle = NAV.find((n) => pathname?.startsWith(n.href))?.label || 'Admin';

  useEffect(() => { setDrawerOpen(false); }, [pathname]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') { setLogoutOpen(false); setDrawerOpen(false); } };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const confirmLogout = () =>
    startLogout(async () => {
      try { await logoutAction(); } catch { /* ignore */ }
      setLogoutOpen(false);
      router.push('/');
      router.refresh();
    });

  const SidebarInner = ({ onNav }: { onNav?: () => void }) => (
    <>
      <Link href="/admin/dashboard" className="es-admin-brand" onClick={onNav}>
        <span className="es-admin-brand-badge"><ShieldCheck size={17} /></span>
        <span style={{ lineHeight: 1.15 }}>
          <span style={{ display: 'block', fontWeight: 800, color: '#fff', fontSize: 14 }}>Empower Students</span>
         
        </span>
      </Link>

      <nav className="es-admin-nav">
        {NAV.map((n) => {
          const Icon = n.icon;
          const isActive = pathname?.startsWith(n.href);
          return (
            <Link key={n.href} href={n.href} onClick={onNav} className={`es-nav-link${isActive ? ' active' : ''}`}>
              <Icon size={18} /> {n.label}
            </Link>
          );
        })}
      </nav>

      <div className="es-admin-nav-foot">
        <a href="/" className="es-nav-link"><ExternalLink size={18} /> View Site</a>
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
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button className="es-admin-hamburger" onClick={() => setDrawerOpen(true)} aria-label="Open menu">
              <Menu size={20} />
            </button>
            <div>
              <h1 style={{ fontSize: 18, fontWeight: 800, letterSpacing: '-0.01em' }} className="text-slate-800 dark:text-slate-100">{pageTitle}</h1>
              <p style={{ fontSize: 11 }} className="text-slate-400">Empower Students · Admin Console</p>
            </div>
          </div>
          <div style={{ fontSize: 13, fontWeight: 600 }} className="text-slate-500">{adminName}</div>
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
              Are you sure you want to log out of the admin console? You&apos;ll need to sign in again to continue.
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
