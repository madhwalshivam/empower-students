import React from 'react';

// A single, reusable loading skeleton used by every route's loading.tsx so the
// app never shows a blank <main> while a server page awaits its data/auth. Each
// route picks the `variant` that matches its real layout, so the shimmer roughly
// mirrors what's about to appear. Pure markup (no client JS) — renders instantly.

type Variant = 'dashboard' | 'detail' | 'table' | 'list' | 'form' | 'generic';

const SHIMMER = 'es-skeleton-box';

function Box({
  h,
  w = '100%',
  r = 10,
  style,
}: {
  h: number | string;
  w?: number | string;
  r?: number;
  style?: React.CSSProperties;
}) {
  return <div className={SHIMMER} style={{ height: h, width: w, borderRadius: r, ...style }} />;
}

function StatCards({ count = 3 }: { count?: number }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: `repeat(auto-fit, minmax(220px, 1fr))`, gap: 16 }}>
      {Array.from({ length: count }).map((_, i) => (
        <div
          key={i}
          className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-100 dark:border-slate-800 p-5 animate-pulse"
          style={{ display: 'flex', flexDirection: 'column', gap: 12, animationDelay: `${i * 90}ms` }}
        >
          <Box h={12} w="55%" r={6} />
          <Box h={26} w="40%" r={8} />
        </div>
      ))}
    </div>
  );
}

function Card({ children, style }: { children?: React.ReactNode; style?: React.CSSProperties }) {
  return (
    <div
      className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-100 dark:border-slate-800 p-6 animate-pulse"
      style={style}
    >
      {children}
    </div>
  );
}

function TableCard({ rows = 5 }: { rows?: number }) {
  return (
    <Card style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <Box h={16} w="35%" r={6} />
      <div style={{ height: 1, background: 'var(--surface-border)', opacity: 0.6 }} />
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <Box h={36} w={36} r={999} />
          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 7 }}>
            <Box h={11} w="45%" r={6} />
            <Box h={10} w="30%" r={6} />
          </div>
          <Box h={12} w={60} r={6} />
        </div>
      ))}
    </Card>
  );
}

export default function PageSkeleton({ variant = 'generic' }: { variant?: Variant }) {
  if (variant === 'form') {
    return (
      <div className="w-full flex flex-col items-center justify-center px-4" style={{ minHeight: '55vh' }}>
        <div className="card-premium max-w-2xl w-full mx-auto border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xl rounded-3xl p-6 sm:p-8 animate-pulse">
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12, marginBottom: 24 }}>
            <Box h={48} w={48} r={999} />
            <Box h={24} w={200} r={8} />
            <Box h={12} w={280} r={6} style={{ maxWidth: '80%' }} />
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
            {[0, 1, 2].map((i) => (
              <div key={i} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                <Box h={11} w={120} r={6} />
                <Box h={46} r={12} />
              </div>
            ))}
            <Box h={48} r={12} style={{ marginTop: 6 }} />
          </div>
        </div>
      </div>
    );
  }

  if (variant === 'list') {
    return (
      <div style={{ maxWidth: 1100, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 24 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Box h={30} w={260} r={8} style={{ maxWidth: '70%' }} />
          <Box h={14} w={380} r={6} style={{ maxWidth: '85%' }} />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 20 }}>
          {Array.from({ length: 6 }).map((_, i) => (
            <Card key={i} style={{ display: 'flex', flexDirection: 'column', gap: 12, animationDelay: `${i * 80}ms` }}>
              <Box h={140} r={14} />
              <Box h={16} w="60%" r={6} />
              <Box h={11} w="90%" r={6} />
              <Box h={11} w="75%" r={6} />
            </Card>
          ))}
        </div>
      </div>
    );
  }

  if (variant === 'detail') {
    return (
      <div style={{ maxWidth: 900, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 24 }}>
        <Box h={14} w={120} r={6} />
        <Card style={{ display: 'flex', alignItems: 'center', gap: 18 }}>
          <Box h={64} w={64} r={999} />
          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 10 }}>
            <Box h={22} w="45%" r={8} />
            <Box h={13} w="60%" r={6} />
          </div>
        </Card>
        {[0, 1].map((i) => (
          <Card key={i} style={{ display: 'flex', flexDirection: 'column', gap: 14, animationDelay: `${i * 120}ms` }}>
            <Box h={16} w="30%" r={6} />
            <Box h={12} w="95%" r={6} />
            <Box h={12} w="85%" r={6} />
            <Box h={12} w="70%" r={6} />
          </Card>
        ))}
      </div>
    );
  }

  if (variant === 'table') {
    return (
      <div style={{ maxWidth: 1100, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 24 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Box h={30} w={240} r={8} style={{ maxWidth: '60%' }} />
          <Box h={14} w={340} r={6} style={{ maxWidth: '80%' }} />
        </div>
        <StatCards count={3} />
        <TableCard rows={6} />
      </div>
    );
  }

  if (variant === 'dashboard') {
    return (
      <div style={{ maxWidth: 1100, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 28 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Box h={30} w={240} r={8} style={{ maxWidth: '60%' }} />
          <Box h={14} w={200} r={6} style={{ maxWidth: '50%' }} />
        </div>
        <StatCards count={3} />
        <Card style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <Box h={18} w="35%" r={6} />
          <Box h={120} r={14} />
        </Card>
      </div>
    );
  }

  // generic
  return (
    <div style={{ maxWidth: 1000, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 20 }}>
      <Box h={30} w={280} r={8} style={{ maxWidth: '60%' }} />
      <Box h={14} w={420} r={6} style={{ maxWidth: '85%' }} />
      <Card style={{ display: 'flex', flexDirection: 'column', gap: 14, marginTop: 8 }}>
        <Box h={16} w="40%" r={6} />
        <Box h={12} w="95%" r={6} />
        <Box h={12} w="80%" r={6} />
        <Box h={12} w="88%" r={6} />
      </Card>
    </div>
  );
}
