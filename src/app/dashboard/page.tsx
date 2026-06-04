import React from 'react';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { calcAgeYears } from '@/lib/evaluations/engine';
import {
  MessageSquare,
  Coins,
  Plus,
  User,
  Smile,
  Sparkles,
  Check,
  Mic,
  ChevronRight,
  HeartHandshake,
  ArrowRight
} from 'lucide-react';

export const dynamic = 'force-dynamic';

const TOTAL_MODULES = 8;

interface PageProps {
  searchParams: Promise<{ cid?: string; ack?: string }>;
}

export default async function DashboardPage({ searchParams }: PageProps) {
  const resolvedSearchParams = await searchParams;
  const supabase = await createClient();

  // Validate session
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    redirect('/login');
  }

  // Redirect based on role
  const role = user.user_metadata?.role;
  if (role === 'admin') {
    redirect('/admin/dashboard');
  } else if (role === 'partner') {
    redirect('/partner/dashboard');
  }

  const supabaseAdmin = createAdminClient();

  // Handle Mark as Read feedback item acknowledgment if requested
  if (resolvedSearchParams.ack) {
    const feedbackId = Number(resolvedSearchParams.ack);
    if (!isNaN(feedbackId)) {
      await supabaseAdmin
        .from('parent_feedback')
        .update({ seen_by_parent: 1 })
        .eq('id', feedbackId)
        .eq('parent_id', user.id);
    }
  }

  // These three reads are independent of each other (all keyed by the logged-in
  // user), so fire them together instead of one-after-another. Replacing the
  // sequential waterfall with parallel batches is what makes the dashboard open
  // quickly instead of stacking ~6 network round-trips back to back.
  const [
    { data: parent },
    { data: children },
    { data: unreadFeedback },
    { data: premiumReflect },
  ] = await Promise.all([
    supabaseAdmin.from('parents').select('*').eq('id', user.id).single(),
    supabaseAdmin
      .from('children')
      .select('*')
      .eq('parent_id', user.id)
      .order('created_at', { ascending: false }),
    supabaseAdmin
      .from('parent_feedback')
      .select('*')
      .eq('parent_id', user.id)
      .eq('seen_by_parent', 0)
      .order('id', { ascending: false }),
    supabaseAdmin
      .from('parent_reflect_sessions')
      .select('*')
      .eq('parent_id', user.id)
      .eq('status', 'completed')
      .order('completed_at', { ascending: false })
      .limit(1)
      .maybeSingle(),
  ]);

  if (!parent) {
    // Proactively provision parent if missing
    await supabaseAdmin.from('parents').insert({
      id: user.id,
      whatsapp: user.user_metadata?.whatsapp || '',
      name: user.user_metadata?.name || 'Parent',
      credits: 100,
    });
    redirect('/dashboard');
  }

  const childrenList = children || [];
  const feedbackList = unreadFeedback || [];
  const reflectCompleted: any = premiumReflect;

  // Determine selected child
  const selectedCid = resolvedSearchParams.cid ? Number(resolvedSearchParams.cid) : (childrenList[0]?.id || 0);
  const selectedChild = childrenList.find((c) => Number(c.id) === selectedCid) || childrenList[0] || null;

  // Second parallel batch — these depend on the children/selected child resolved
  // above, but don't depend on each other, so run them together too.
  const [{ data: allCompleted }, { data: premiumSpeech }] = await Promise.all([
    childrenList.length > 0
      ? supabaseAdmin
          .from('assessments')
          .select('child_id, module')
          .in('child_id', childrenList.map((c) => c.id))
          .eq('status', 'completed')
      : Promise.resolve({ data: [] as any[] }),
    selectedChild
      ? supabaseAdmin
          .from('eval_sessions')
          .select('*')
          .eq('child_id', selectedChild.id)
          .eq('module', 'mod_speech_basic')
          .eq('status', 'completed')
          .order('completed_at', { ascending: false })
          .limit(1)
          .maybeSingle()
      : Promise.resolve({ data: null }),
  ]);

  // Completed-module counts per child (distinct modules) — drives the list + progress
  const completedByChild: Record<number, Set<string>> = {};
  (allCompleted || []).forEach((a: any) => {
    const cid = Number(a.child_id);
    if (!completedByChild[cid]) completedByChild[cid] = new Set<string>();
    if (a.module) completedByChild[cid].add(a.module);
  });

  const speechCompleted: any = premiumSpeech;

  const selectedDoneCount = selectedChild ? (completedByChild[Number(selectedChild.id)]?.size || 0) : 0;
  const selectedPct = Math.round((selectedDoneCount / TOTAL_MODULES) * 100);

  return (
    <div style={{ maxWidth: 1100, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 28 }}>
      {/* Unread Notes block */}
      {feedbackList.map((fb) => (
        <div key={fb.id} style={{ background: '#eef2ff', border: '1px solid #e0e7ff', borderRadius: 16, padding: 20, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
          <div>
            <div style={{ fontWeight: 800, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4, color: '#4338ca', fontSize: 14 }}>
              <MessageSquare size={16} />
              <span>Note from clinician {fb.author}:</span>
            </div>
            <p style={{ whiteSpace: 'pre-line', fontSize: 13, color: '#64748b', margin: 0 }}>{fb.body}</p>
          </div>
          <Link
            href={`/dashboard?ack=${fb.id}${resolvedSearchParams.cid ? `&cid=${resolvedSearchParams.cid}` : ''}`}
            style={{ fontSize: 12, fontWeight: 700, color: '#4f46e5', textDecoration: 'underline', flexShrink: 0 }}
          >
            Mark Read
          </Link>
        </div>
      ))}

      {/* Greeting */}
      <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 16 }}>
        <div>
          <h1 style={{ fontSize: 30, fontWeight: 900, color: '#1e293b', margin: 0, display: 'flex', alignItems: 'center', gap: 8, letterSpacing: '-0.02em' }}>
            <span>Hi {parent.name || 'Parent'}</span>
            <Smile color="#6366f1" size={30} />
          </h1>
          <p style={{ fontSize: 14, color: '#64748b', margin: '6px 0 0', display: 'flex', alignItems: 'center', gap: 4 }}>
            <span>You have</span>
            <Link href="/wallet" style={{ color: '#4f46e5', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
              <Coins size={14} /> {parent.credits || 0} wallet credits
            </Link>
          </p>
        </div>
        {childrenList.length > 0 && (
          <Link
            href="/child/add"
            style={{ background: 'linear-gradient(135deg, #4f46e5, #6366f1)', color: '#fff', padding: '12px 20px', borderRadius: 14, fontWeight: 700, fontSize: 14, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6, boxShadow: '0 6px 18px rgba(99,102,241,0.32)' }}
          >
            <Plus size={16} /> Add Child
          </Link>
        )}
      </div>

      {childrenList.length === 0 ? (
        <div style={{ background: '#fff', border: '2px dashed #e2e8f0', borderRadius: 24, padding: 48, textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
          <User size={48} color="#cbd5e1" style={{ marginBottom: 16 }} />
          <h3 style={{ fontSize: 20, fontWeight: 800, color: '#334155', margin: '0 0 4px' }}>No children registered yet</h3>
          <p style={{ color: '#64748b', fontSize: 14, maxWidth: 320, margin: '0 0 24px' }}>
            Add your child&apos;s profile to begin evaluations and unlock growth modules.
          </p>
          <Link href="/child/add" style={{ background: 'linear-gradient(135deg, #4f46e5, #6366f1)', color: '#fff', padding: '12px 22px', borderRadius: 14, fontWeight: 700, fontSize: 14, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <Plus size={16} /> Add My First Child
          </Link>
        </div>
      ) : (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'flex-start' }}>
          {/* ── LEFT: Children list ── */}
          <aside style={{ flex: '1 1 250px', maxWidth: 320, background: '#fff', border: '1px solid #eef0f4', borderRadius: 20, padding: 14, boxShadow: '0 2px 14px rgba(15,23,42,0.04)' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 8px 12px' }}>
              <span style={{ fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94a3b8' }}>My Children</span>
              <span style={{ fontSize: 11, fontWeight: 800, color: '#4f46e5', background: '#eef2ff', padding: '2px 9px', borderRadius: 99 }}>{childrenList.length}</span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              {childrenList.map((c) => {
                const active = Number(c.id) === selectedCid;
                const age = calcAgeYears(c.dob);
                const initials = c.name ? c.name.substring(0, 1).toUpperCase() : '?';
                const done = completedByChild[Number(c.id)]?.size || 0;

                return (
                  <Link
                    key={c.id}
                    href={`/dashboard?cid=${c.id}`}
                    style={{
                      display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 14,
                      textDecoration: 'none', transition: 'background 0.15s',
                      background: active ? 'linear-gradient(135deg, #4f46e5, #7c3aed)' : 'transparent',
                      boxShadow: active ? '0 6px 16px rgba(99,102,241,0.30)' : 'none',
                    }}
                  >
                    <div style={{
                      width: 40, height: 40, borderRadius: '50%', flexShrink: 0,
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                      fontWeight: 900, fontSize: 16,
                      background: active ? 'rgba(255,255,255,0.22)' : '#eef2ff',
                      color: active ? '#fff' : '#4f46e5',
                    }}>
                      {initials}
                    </div>
                    <div style={{ minWidth: 0, flex: 1 }}>
                      <div style={{ fontWeight: 800, fontSize: 14, color: active ? '#fff' : '#1e293b', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                        {c.name}
                      </div>
                      <div style={{ fontSize: 11, color: active ? 'rgba(255,255,255,0.85)' : '#94a3b8', marginTop: 1 }}>
                        {age}y{c.class_grade ? ` · Grade ${c.class_grade}` : ''} · {done}/{TOTAL_MODULES} done
                      </div>
                    </div>
                    {active && <ChevronRight size={16} color="rgba(255,255,255,0.85)" style={{ flexShrink: 0 }} />}
                  </Link>
                );
              })}
            </div>

            <Link
              href="/child/add"
              style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6, padding: '10px 12px', borderRadius: 14, border: '1.5px dashed #d8dce4', color: '#94a3b8', fontSize: 12, fontWeight: 700, textDecoration: 'none' }}
            >
              <Plus size={15} /> Add Child
            </Link>
          </aside>

          {/* ── RIGHT: Selected child ── */}
          <div style={{ flex: '600 1 400px', minWidth: 0, display: 'flex', flexDirection: 'column', gap: 24 }}>
            {selectedChild && (
              <>
                {/* Child Summary Card */}
                <div style={{ background: '#fff', borderRadius: 22, border: '1px solid #eef0f4', overflow: 'hidden', boxShadow: '0 4px 24px rgba(99,102,241,0.07)' }}>
                  <div style={{ height: 5, background: 'linear-gradient(90deg, #4f46e5, #7c3aed)' }} />
                  <div style={{ padding: 26, display: 'flex', flexWrap: 'wrap', gap: 20, alignItems: 'center', justifyContent: 'space-between' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                      <div style={{ width: 60, height: 60, borderRadius: '50%', background: 'linear-gradient(135deg, #4f46e5, #7c3aed)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 900, fontSize: 24, boxShadow: '0 6px 16px rgba(99,102,241,0.35)', flexShrink: 0 }}>
                        {selectedChild.name?.[0]?.toUpperCase() || '?'}
                      </div>
                      <div>
                        <h3 style={{ fontSize: 21, fontWeight: 900, color: '#1e293b', margin: 0, letterSpacing: '-0.01em' }}>{selectedChild.name}</h3>
                        <p style={{ margin: '5px 0 0', fontSize: 13, color: '#64748b' }}>
                          {calcAgeYears(selectedChild.dob)} yrs · {selectedChild.gender || '—'}
                          {selectedChild.class_grade && <> · <span style={{ color: '#6d28d9', fontWeight: 700 }}>Grade {selectedChild.class_grade}</span></>}
                        </p>
                        {selectedChild.diagnosis && (
                          <p style={{ margin: '5px 0 0', fontSize: 12, color: '#dc2626', fontWeight: 700 }}>Diagnosis: {selectedChild.diagnosis}</p>
                        )}
                      </div>
                    </div>

                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 12 }}>
                      <div style={{ textAlign: 'right' }}>
                        <div style={{ fontSize: 11, color: '#94a3b8', fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 6 }}>Modules Completed</div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <div style={{ width: 130, height: 6, background: '#eef0f4', borderRadius: 99, overflow: 'hidden' }}>
                            <div style={{ width: `${selectedPct}%`, height: '100%', background: 'linear-gradient(90deg, #4f46e5, #7c3aed)', borderRadius: 99 }} />
                          </div>
                          <span style={{ fontSize: 13, fontWeight: 900, color: '#4f46e5' }}>{selectedDoneCount}/{TOTAL_MODULES}</span>
                        </div>
                      </div>
                      <Link
                        href={`/child/${selectedChild.id}`}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 7, background: 'linear-gradient(135deg, #4f46e5, #6366f1)', color: '#fff', padding: '11px 20px', borderRadius: 13, fontWeight: 700, fontSize: 14, textDecoration: 'none', boxShadow: '0 4px 14px rgba(99,102,241,0.35)' }}
                      >
                        View Profile &amp; Modules <ArrowRight size={15} />
                      </Link>
                    </div>
                  </div>
                </div>

                {/* Premium Clinical Assessments */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                  <h4 style={{ fontSize: 17, fontWeight: 800, color: '#1e293b', margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
                    <Sparkles color="#4f46e5" size={19} /> Premium Clinical Assessments
                  </h4>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
                    {/* Speech Evaluation Card */}
                    <div style={{ background: '#fff', border: '1px solid #e7e9f0', borderRadius: 20, padding: 20, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', gap: 14, boxShadow: '0 2px 14px rgba(15,23,42,0.04)' }}>
                      <div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8, marginBottom: 8 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <Mic color="#6366f1" size={22} />
                            <h5 style={{ fontWeight: 800, color: '#1e293b', fontSize: 15, margin: 0 }}>Speech &amp; Language</h5>
                          </div>
                          <span style={{ background: '#eef2ff', color: '#4338ca', fontWeight: 800, fontSize: 10, padding: '3px 8px', borderRadius: 99 }}>Premium</span>
                        </div>
                        <p style={{ fontSize: 12, color: '#64748b', lineHeight: 1.6, margin: 0 }}>
                          Voice-led adaptive conversation (~5 mins) evaluating articulation, fluency, and processing with real-time AI analysis.
                        </p>
                      </div>
                      {speechCompleted ? (
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12 }}>
                          <span style={{ color: '#059669', fontWeight: 700, display: 'flex', alignItems: 'center', gap: 4 }}>
                            <Check size={14} /> Level L{speechCompleted.final_level} ({speechCompleted.final_pct}%)
                          </span>
                          <Link href={`/eval-speech/${selectedChild.id}`} style={{ color: '#4f46e5', fontWeight: 700, textDecoration: 'none' }}>Report</Link>
                        </div>
                      ) : (
                        <Link href={`/eval-speech/${selectedChild.id}`} style={{ display: 'block', textAlign: 'center', background: 'linear-gradient(135deg, #4f46e5, #6366f1)', color: '#fff', fontSize: 13, fontWeight: 700, padding: '11px', borderRadius: 12, textDecoration: 'none' }}>
                          Start Speech Eval (₹1,000)
                        </Link>
                      )}
                    </div>

                    {/* Parent Reflection Card */}
                    <div style={{ background: '#fff', border: '1px solid #e7e9f0', borderRadius: 20, padding: 20, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', gap: 14, boxShadow: '0 2px 14px rgba(15,23,42,0.04)' }}>
                      <div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8, marginBottom: 8 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <HeartHandshake color="#6366f1" size={22} />
                            <h5 style={{ fontWeight: 800, color: '#1e293b', fontSize: 15, margin: 0 }}>Parent Reflection</h5>
                          </div>
                          <span style={{ background: '#eef2ff', color: '#4338ca', fontWeight: 800, fontSize: 10, padding: '3px 8px', borderRadius: 99 }}>Premium</span>
                        </div>
                        <p style={{ fontSize: 12, color: '#64748b', lineHeight: 1.6, margin: 0 }}>
                          15-min guided parenting burden check-in. Includes written clinical reflection and a psychologist callback.
                        </p>
                      </div>
                      {reflectCompleted ? (
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12 }}>
                          <span style={{ color: '#059669', fontWeight: 700, display: 'flex', alignItems: 'center', gap: 4 }}>
                            <Check size={14} /> Callback Scheduled
                          </span>
                          <Link href="/parent-reflect" style={{ color: '#4f46e5', fontWeight: 700, textDecoration: 'none' }}>View Report</Link>
                        </div>
                      ) : (
                        <Link href="/parent-reflect" style={{ display: 'block', textAlign: 'center', background: 'linear-gradient(135deg, #4f46e5, #6366f1)', color: '#fff', fontSize: 13, fontWeight: 700, padding: '11px', borderRadius: 12, textDecoration: 'none' }}>
                          Start Reflection (₹1,000)
                        </Link>
                      )}
                    </div>
                  </div>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
