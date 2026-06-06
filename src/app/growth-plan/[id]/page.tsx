import React from 'react';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { ArrowLeft, Heart, Target, CalendarDays, Lightbulb } from 'lucide-react';
import { getCarePackContent } from '@/app/actions/carepack';

export const dynamic = 'force-dynamic';

export default async function GrowthPlanPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const childId = Number(id);
  if (isNaN(childId)) redirect('/dashboard');

  const res = await getCarePackContent(childId);
  if (!res.ok) {
    // Not logged in, not the owner, or pack not unlocked → back to the child page
    // (which shows the unlock banner). Never render an error.
    if (res.error === 'auth') redirect('/login');
    redirect(`/child/${childId}`);
  }

  const child = res.child;
  const plan = res.content!.growth_plan;

  return (
    <div style={{ maxWidth: 860, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 24 }}>
      <Link href={`/child/${childId}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, color: '#6366f1', textDecoration: 'none' }}>
        <ArrowLeft size={15} /> Back to {child.name}'s Profile
      </Link>

      {/* Hero */}
      <div style={{ background: 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)', borderRadius: 24, padding: '28px 32px', color: '#fff', boxShadow: '0 12px 40px rgba(99,102,241,0.35)' }}>
        <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '0.1em', textTransform: 'uppercase', opacity: 0.85, marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
          <Heart size={13} /> Personalised Growth Plan
        </div>
        <h1 style={{ margin: '0 0 10px', fontSize: 26, fontWeight: 900, letterSpacing: '-0.02em' }}>{child.name}'s 4-Week Growth Plan</h1>
        <p style={{ margin: 0, fontSize: 14, lineHeight: 1.7, opacity: 0.92, maxWidth: 620 }}>{plan.summary}</p>
      </div>

      {/* Focus areas */}
      {plan.focus_areas?.length > 0 && (
        <section>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 18, fontWeight: 900, color: '#1e293b', margin: '0 0 14px' }}>
            <Target size={19} color="#4f46e5" /> Focus Areas
          </h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
            {plan.focus_areas.map((f, i) => (
              <div key={i} style={{ background: '#fff', border: '1px solid #eef2ff', borderRadius: 18, padding: '18px 20px', boxShadow: '0 2px 12px rgba(0,0,0,0.04)' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                  <span style={{ fontWeight: 800, fontSize: 14, color: '#1e293b' }}>{f.area}</span>
                  {f.score != null && (
                    <span style={{ fontSize: 12, fontWeight: 800, color: f.score < 60 ? '#dc2626' : '#059669', background: f.score < 60 ? '#fef2f2' : '#f0fdf4', padding: '2px 8px', borderRadius: 20 }}>
                      {f.score}/100
                    </span>
                  )}
                </div>
                <p style={{ margin: 0, fontSize: 13, color: '#64748b', lineHeight: 1.6 }}>{f.why}</p>
              </div>
            ))}
          </div>
        </section>
      )}

      {/* Weekly plan */}
      <section>
        <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 18, fontWeight: 900, color: '#1e293b', margin: '0 0 14px' }}>
          <CalendarDays size={19} color="#4f46e5" /> Week-by-Week Plan
        </h2>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {plan.weeks?.map((w) => (
            <div key={w.week} style={{ background: '#fff', border: '1px solid #f1f5f9', borderRadius: 20, padding: '20px 24px', boxShadow: '0 2px 12px rgba(0,0,0,0.04)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
                <span style={{ width: 40, height: 40, borderRadius: 12, background: 'linear-gradient(135deg, #4f46e5, #7c3aed)', color: '#fff', fontWeight: 900, fontSize: 15, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                  W{w.week}
                </span>
                <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: '#1e293b' }}>{w.theme}</h3>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 18 }}>
                <div>
                  <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#94a3b8', marginBottom: 8 }}>Goals</div>
                  <ul style={{ margin: 0, paddingLeft: 18, display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {w.goals?.map((g, i) => <li key={i} style={{ fontSize: 13, color: '#475569', lineHeight: 1.5 }}>{g}</li>)}
                  </ul>
                </div>
                <div>
                  <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#94a3b8', marginBottom: 8 }}>Activities</div>
                  <ul style={{ margin: 0, paddingLeft: 18, display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {w.activities?.map((a, i) => <li key={i} style={{ fontSize: 13, color: '#475569', lineHeight: 1.5 }}>{a}</li>)}
                  </ul>
                </div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Parent tips */}
      {plan.parent_tips?.length > 0 && (
        <section style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 20, padding: '22px 26px' }}>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 16, fontWeight: 900, color: '#92400e', margin: '0 0 12px' }}>
            <Lightbulb size={18} /> Tips for You
          </h2>
          <ul style={{ margin: 0, paddingLeft: 18, display: 'flex', flexDirection: 'column', gap: 8 }}>
            {plan.parent_tips.map((t, i) => <li key={i} style={{ fontSize: 13.5, color: '#78350f', lineHeight: 1.6 }}>{t}</li>)}
          </ul>
        </section>
      )}

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
        <Link href={`/course/${childId}`} style={{ flex: 1, minWidth: 200, textAlign: 'center', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: '13px', fontWeight: 800, fontSize: 14, color: '#4f46e5', textDecoration: 'none' }}>
          Open Personal Course →
        </Link>
        <Link href={`/tracker/${childId}`} style={{ flex: 1, minWidth: 200, textAlign: 'center', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: '13px', fontWeight: 800, fontSize: 14, color: '#4f46e5', textDecoration: 'none' }}>
          Open Daily Tracker →
        </Link>
      </div>
    </div>
  );
}
