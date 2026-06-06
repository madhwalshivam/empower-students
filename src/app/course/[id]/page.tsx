import React from 'react';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { ArrowLeft, BookOpen, CheckCircle2 } from 'lucide-react';
import { getCarePackContent } from '@/app/actions/carepack';

export const dynamic = 'force-dynamic';

export default async function CoursePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const childId = Number(id);
  if (isNaN(childId)) redirect('/dashboard');

  const res = await getCarePackContent(childId);
  if (!res.ok) {
    if (res.error === 'auth') redirect('/login');
    redirect(`/child/${childId}`);
  }

  const child = res.child;
  const course = res.content!.course;

  return (
    <div style={{ maxWidth: 800, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 24 }}>
      <Link href={`/child/${childId}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, color: '#6366f1', textDecoration: 'none' }}>
        <ArrowLeft size={15} /> Back to {child.name}'s Profile
      </Link>

      <div style={{ background: 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)', borderRadius: 24, padding: '28px 32px', color: '#fff', boxShadow: '0 12px 40px rgba(99,102,241,0.35)' }}>
        <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '0.1em', textTransform: 'uppercase', opacity: 0.85, marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
          <BookOpen size={13} /> Personal Course
        </div>
        <h1 style={{ margin: '0 0 10px', fontSize: 26, fontWeight: 900, letterSpacing: '-0.02em' }}>{course.title}</h1>
        <p style={{ margin: 0, fontSize: 14, lineHeight: 1.7, opacity: 0.92, maxWidth: 620 }}>{course.intro}</p>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {course.lessons?.map((l, i) => (
          <div key={i} style={{ background: '#fff', border: '1px solid #f1f5f9', borderRadius: 20, padding: '22px 26px', boxShadow: '0 2px 12px rgba(0,0,0,0.04)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12 }}>
              <span style={{ width: 36, height: 36, borderRadius: 11, background: '#ede9fe', color: '#6d28d9', fontWeight: 900, fontSize: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                {i + 1}
              </span>
              <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: '#1e293b' }}>{l.title}</h3>
            </div>
            <p style={{ margin: '0 0 12px', fontSize: 12.5, fontWeight: 700, color: '#6366f1' }}>🎯 {l.objective}</p>
            <p style={{ margin: '0 0 14px', fontSize: 14, color: '#475569', lineHeight: 1.7 }}>{l.content}</p>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, background: '#f0fdf4', border: '1px solid #d1fae5', borderRadius: 12, padding: '12px 14px' }}>
              <CheckCircle2 size={16} color="#059669" style={{ flexShrink: 0, marginTop: 1 }} />
              <span style={{ fontSize: 13, color: '#065f46', lineHeight: 1.5 }}><strong>Try this:</strong> {l.activity}</span>
            </div>
          </div>
        ))}
      </div>

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
        <Link href={`/growth-plan/${childId}`} style={{ flex: 1, minWidth: 200, textAlign: 'center', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: '13px', fontWeight: 800, fontSize: 14, color: '#4f46e5', textDecoration: 'none' }}>
          ← Back to Growth Plan
        </Link>
        <Link href={`/tracker/${childId}`} style={{ flex: 1, minWidth: 200, textAlign: 'center', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: '13px', fontWeight: 800, fontSize: 14, color: '#4f46e5', textDecoration: 'none' }}>
          Open Daily Tracker →
        </Link>
      </div>
    </div>
  );
}
