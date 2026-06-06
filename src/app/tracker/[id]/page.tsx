import React from 'react';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { ArrowLeft, BarChart2, Flag } from 'lucide-react';
import { getCarePackContent } from '@/app/actions/carepack';

export const dynamic = 'force-dynamic';

export default async function TrackerPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const childId = Number(id);
  if (isNaN(childId)) redirect('/dashboard');

  const res = await getCarePackContent(childId);
  if (!res.ok) {
    if (res.error === 'auth') redirect('/login');
    redirect(`/child/${childId}`);
  }

  const child = res.child;
  const tracker = res.content!.tracker;
  const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  return (
    <div style={{ maxWidth: 860, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 24 }}>
      <Link href={`/child/${childId}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, color: '#6366f1', textDecoration: 'none' }}>
        <ArrowLeft size={15} /> Back to {child.name}'s Profile
      </Link>

      <div style={{ background: 'linear-gradient(135deg, #059669 0%, #0d9488 100%)', borderRadius: 24, padding: '28px 32px', color: '#fff', boxShadow: '0 12px 40px rgba(5,150,105,0.3)' }}>
        <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '0.1em', textTransform: 'uppercase', opacity: 0.85, marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
          <BarChart2 size={13} /> Daily Tracker
        </div>
        <h1 style={{ margin: '0 0 10px', fontSize: 26, fontWeight: 900, letterSpacing: '-0.02em' }}>{child.name}'s Daily Tracker</h1>
        <p style={{ margin: 0, fontSize: 14, lineHeight: 1.7, opacity: 0.92, maxWidth: 620 }}>{tracker.intro}</p>
      </div>

      {/* Habit checklist grid */}
      <div style={{ background: '#fff', border: '1px solid #f1f5f9', borderRadius: 20, padding: '8px', boxShadow: '0 2px 12px rgba(0,0,0,0.04)', overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 560 }}>
          <thead>
            <tr>
              <th style={{ textAlign: 'left', padding: '14px 16px', fontSize: 12, fontWeight: 800, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Daily Habit</th>
              {days.map((d) => (
                <th key={d} style={{ padding: '14px 8px', fontSize: 12, fontWeight: 800, color: '#64748b', textAlign: 'center' }}>{d}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {tracker.daily_habits?.map((h, i) => (
              <tr key={i} style={{ borderTop: '1px solid #f1f5f9' }}>
                <td style={{ padding: '14px 16px' }}>
                  <div style={{ fontSize: 13.5, fontWeight: 700, color: '#1e293b' }}>{h.habit}</div>
                  <div style={{ fontSize: 11.5, color: '#94a3b8', marginTop: 2 }}>{h.why}</div>
                </td>
                {days.map((d) => (
                  <td key={d} style={{ padding: '14px 8px', textAlign: 'center' }}>
                    <span style={{ display: 'inline-block', width: 22, height: 22, borderRadius: 7, border: '2px solid #e2e8f0' }} />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p style={{ margin: '-8px 4px 0', fontSize: 12, color: '#94a3b8' }}>Print this page or tick the boxes with {child.name} each day. A fresh week starts every Monday.</p>

      {/* Weekly milestones */}
      {tracker.weekly_milestones?.length > 0 && (
        <section>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 18, fontWeight: 900, color: '#1e293b', margin: '0 0 14px' }}>
            <Flag size={19} color="#059669" /> Weekly Milestones
          </h2>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {tracker.weekly_milestones.map((m, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 14, background: '#fff', border: '1px solid #f1f5f9', borderRadius: 16, padding: '14px 18px' }}>
                <span style={{ width: 32, height: 32, borderRadius: 10, background: '#ecfdf5', color: '#059669', fontWeight: 900, fontSize: 13, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                  {i + 1}
                </span>
                <span style={{ fontSize: 13.5, color: '#475569', lineHeight: 1.5 }}>{m}</span>
              </div>
            ))}
          </div>
        </section>
      )}

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
        <Link href={`/growth-plan/${childId}`} style={{ flex: 1, minWidth: 200, textAlign: 'center', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: '13px', fontWeight: 800, fontSize: 14, color: '#4f46e5', textDecoration: 'none' }}>
          ← Back to Growth Plan
        </Link>
        <Link href={`/course/${childId}`} style={{ flex: 1, minWidth: 200, textAlign: 'center', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: '13px', fontWeight: 800, fontSize: 14, color: '#4f46e5', textDecoration: 'none' }}>
          Open Personal Course →
        </Link>
      </div>
    </div>
  );
}
