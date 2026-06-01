import React from 'react';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { calcAgeYears, getAgeBand } from '@/lib/evaluations/engine';
import {
  MessageSquare,
  Coins,
  Plus,
  User,
  Heart,
  Brain,
  Smile,
  Globe,
  Sparkles,
  Binary,
  BookOpen,
  Check,
  Apple,
  Mic,
  HeartHandshake
} from 'lucide-react';

export const dynamic = 'force-dynamic';

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

  // Fetch parent details
  const { data: parent, error: pErr } = await supabaseAdmin
    .from('parents')
    .select('*')
    .eq('id', user.id)
    .single();

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

  // Fetch children
  const { data: children, error: cErr } = await supabaseAdmin
    .from('children')
    .select('*')
    .eq('parent_id', user.id)
    .order('created_at', { ascending: false });

  const childrenList = children || [];

  // Fetch unread feedback notes
  const { data: unreadFeedback } = await supabaseAdmin
    .from('parent_feedback')
    .select('*')
    .eq('parent_id', user.id)
    .eq('seen_by_parent', 0)
    .order('id', { ascending: false });

  const feedbackList = unreadFeedback || [];

  // Determine selected child
  const selectedCid = resolvedSearchParams.cid ? Number(resolvedSearchParams.cid) : (childrenList[0]?.id || 0);
  const selectedChild = childrenList.find((c) => Number(c.id) === selectedCid) || childrenList[0] || null;

  // If a child is selected, fetch their modules and progress
  let assessmentsList: any[] = [];
  let speechCompleted: any = null;
  let reflectCompleted: any = null;

  if (selectedChild) {
    const { data: assessments } = await supabaseAdmin
      .from('assessments')
      .select('*')
      .eq('child_id', selectedChild.id)
      .order('completed_at', { ascending: false });
    assessmentsList = assessments || [];

    // Fetch premium evaluations
    const { data: premiumSpeech } = await supabaseAdmin
      .from('eval_sessions')
      .select('*')
      .eq('child_id', selectedChild.id)
      .eq('module', 'mod_speech_basic')
      .eq('status', 'completed')
      .order('completed_at', { ascending: false })
      .limit(1)
      .maybeSingle();
    speechCompleted = premiumSpeech;

    const { data: premiumReflect } = await supabaseAdmin
      .from('parent_reflect_sessions')
      .select('*')
      .eq('parent_id', user.id)
      .eq('status', 'completed')
      .order('completed_at', { ascending: false })
      .limit(1)
      .maybeSingle();
    reflectCompleted = premiumReflect;
  }

  // Dynamic modules list mapping to Lucide Icons
  const allModules = [
    { key: 'health', label: 'Health Screening', icon: Heart, desc: 'Growth, sleep, sensory, milestone red-flags' },
    { key: 'mind_power', label: 'Mind Power', icon: Brain, desc: 'Memory, attention, problem-solving' },
    { key: 'behavior', label: 'Behaviour', icon: Smile, desc: 'Social skills, emotional regulation, self-control' },
    { key: 'general_awareness', label: 'General Awareness', icon: Globe, desc: '2-min adaptive general knowledge quiz' },
    { key: 'special_talent', label: 'Special Talent', icon: Sparkles, desc: 'Spotting a unique child gift to nurture' },
    { key: 'math', label: 'Maths Level', icon: Binary, desc: 'Adaptive fundamental number sense finder' },
    { key: 'language', label: 'Language & Reading', icon: BookOpen, desc: 'Word-power and timed comprehension checks' },
    { key: 'diet', label: 'Diet Advice', icon: Apple, desc: 'Tuned child food chart and sleep plan' },
  ];

  return (
    <div className="space-y-8 max-w-5xl mx-auto">
      {/* Unread Notes block */}
      {feedbackList.map((fb) => (
        <div key={fb.id} className="bg-indigo-50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 rounded-2xl p-5 text-indigo-900 dark:text-indigo-300 text-sm flex justify-between items-start gap-4 animate-fade-in shadow-sm">
          <div>
            <div className="font-bold flex items-center gap-1.5 mb-1 text-indigo-700 dark:text-indigo-400">
              <MessageSquare size={16} />
              <span>Note from clinician {fb.author}:</span>
            </div>
            <p className="whitespace-pre-line text-xs sm:text-sm text-slate-500">{fb.body}</p>
          </div>
          <Link
            href={`/dashboard?ack=${fb.id}${resolvedSearchParams.cid ? `&cid=${resolvedSearchParams.cid}` : ''}`}
            className="text-xs font-bold underline shrink-0 hover:text-indigo-800 text-indigo-650"
          >
            Mark Read
          </Link>
        </div>
      ))}

      {/* Greeting Banner */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="heading-fun text-3xl font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>Hi {parent.name || 'Parent'}</span>
            <Smile className="text-indigo-650" size={32} />
          </h1>
          <p className="text-sm text-slate-500 mt-1 flex items-center gap-1">
            <span>You have</span>
            <Link href="/wallet" className="text-indigo-600 dark:text-indigo-400 font-bold hover:underline inline-flex items-center gap-1">
              <Coins size={14} /> {parent.credits || 0} wallet credits
            </Link>
          </p>
        </div>
        <Link
          href="/child/add"
          className="bg-indigo-650 text-white px-5 py-3 rounded-2xl font-bold shadow-md hover:scale-[1.02] transition-transform text-center flex items-center justify-center gap-1"
        >
          <Plus size={16} /> Add Child
        </Link>
      </div>

      {childrenList.length === 0 ? (
        <div className="bg-white dark:bg-slate-900 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-3xl p-12 text-center shadow-sm flex flex-col items-center">
          <User size={48} className="text-slate-300 mb-4" />
          <h3 className="heading-fun text-xl font-bold text-slate-700 dark:text-slate-200 mb-1">
            No children registered yet
          </h3>
          <p className="text-slate-500 text-sm max-w-xs mx-auto mb-6">
            Add your child's profile to begin evaluations and unlock growth modules.
          </p>
          <Link href="/child/add" className="btn-premium btn-premium-primary text-sm font-bold flex items-center gap-1">
            <Plus size={16} /> Add My First Child
          </Link>
        </div>
      ) : (
        <div className="space-y-6">
          {/* Child Tabs */}
          <div className="flex gap-2 overflow-x-auto pb-2 scrollbar-none border-b border-slate-100 dark:border-slate-800">
            {childrenList.map((c) => {
              const active = Number(c.id) === selectedCid;
              const age = calcAgeYears(c.dob);
              const initials = c.name ? c.name.substring(0, 1).toUpperCase() : '?';

              return (
                <Link
                  key={c.id}
                  href={`/dashboard?cid=${c.id}`}
                  className={`flex items-center gap-2 px-5 py-3 rounded-2xl border-2 transition-all shrink-0 ${
                    active
                      ? 'bg-indigo-600 border-indigo-600 text-white shadow-md'
                      : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:border-indigo-300'
                  }`}
                >
                  <div className={`w-8 h-8 rounded-full font-extrabold text-sm flex items-center justify-center ${active ? 'bg-white/20 text-white' : 'bg-indigo-50 text-indigo-750'}`}>
                    {initials}
                  </div>
                  <span className="font-bold text-sm">{c.name}</span>
                  <span className={`text-xs ${active ? 'text-white/80' : 'text-slate-400'}`}>
                    · {age}y
                  </span>
                </Link>
              );
            })}
          </div>

          {/* Selected Child Detail Panel */}
          {selectedChild && (
            <div className="space-y-6 animate-fade-in">
              <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                  <h3 className="heading-fun text-2xl font-bold text-slate-800 dark:text-slate-100">
                    {selectedChild.name}
                  </h3>
                  <p className="text-xs sm:text-sm text-slate-500 mt-1">
                    {calcAgeYears(selectedChild.dob)} years old · {selectedChild.gender || '—'} ·{' '}
                    <span className="font-bold text-indigo-500 uppercase">
                      {getAgeBand(calcAgeYears(selectedChild.dob))}
                    </span>
                    {selectedChild.class_grade && ` · Grade ${selectedChild.class_grade}`}
                  </p>
                  {selectedChild.diagnosis && (
                    <p className="text-xs text-rose-650 dark:text-rose-400 mt-1 font-semibold">
                      Known diagnosis: {selectedChild.diagnosis}
                    </p>
                  )}
                </div>
                <div className="flex gap-2">
                  <Link
                    href={`/child/${selectedChild.id}`}
                    className="btn-premium btn-premium-primary text-xs font-bold py-2 px-4 shadow-sm"
                  >
                    View Details
                  </Link>
                </div>
              </div>

              {/* Premium Voice-Led Clinical Diagnostics */}
              <div className="space-y-4">
                <h4 className="heading-fun text-lg font-bold text-slate-800 dark:text-slate-205 flex items-center gap-2">
                  <Sparkles className="text-indigo-600 animate-pulse" size={20} />
                  Premium Clinical Assessments
                </h4>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Speech Evaluation Card */}
                  <div className="bg-gradient-to-br from-indigo-50/50 to-white dark:from-slate-900 dark:to-slate-950 border-2 border-indigo-100/60 dark:border-slate-800 rounded-3xl p-5 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between">
                    <div>
                      <div className="flex justify-between items-start gap-2 mb-2">
                        <div className="flex items-center gap-2">
                          <Mic className="text-indigo-650" size={24} />
                          <h5 className="font-bold text-slate-800 dark:text-slate-100 text-sm sm:text-base">
                            Speech & Language Evaluation
                          </h5>
                        </div>
                        <span className="bg-indigo-100 dark:bg-slate-850 text-indigo-700 dark:text-indigo-400 font-extrabold text-[10px] px-2 py-0.5 rounded-full">
                          Premium
                        </span>
                      </div>
                      <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-4">
                        Voice-led adaptive conversation (~5 mins) evaluating articulation, fluency, and processing with real-time Claude analysis.
                      </p>
                    </div>
                    <div className="pt-2">
                      {speechCompleted ? (
                        <div className="flex items-center justify-between text-xs">
                          <span className="text-emerald-650 dark:text-emerald-400 font-bold flex items-center gap-1">
                            <Check size={14} /> Level L{speechCompleted.final_level} ({speechCompleted.final_pct}%)
                          </span>
                          <div className="flex gap-2">
                            <Link
                              href={`/eval-speech/${selectedChild.id}`}
                              className="text-indigo-600 font-bold hover:underline"
                            >
                              Report
                            </Link>
                          </div>
                        </div>
                      ) : (
                        <Link
                          href={`/eval-speech/${selectedChild.id}`}
                          className="block text-center bg-indigo-650 text-white text-xs font-bold py-2.5 rounded-xl shadow-sm hover:opacity-90 transition-opacity"
                        >
                          Start Speech Eval (₹1,000)
                        </Link>
                      )}
                    </div>
                  </div>

                  {/* Parent Reflection Card */}
                  <div className="bg-gradient-to-br from-indigo-50/50 to-white dark:from-slate-900 dark:to-slate-950 border-2 border-indigo-100/60 dark:border-slate-800 rounded-3xl p-5 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between">
                    <div>
                      <div className="flex justify-between items-start gap-2 mb-2">
                        <div className="flex items-center gap-2">
                          <HeartHandshake className="text-indigo-650" size={24} />
                          <h5 className="font-bold text-slate-800 dark:text-slate-100 text-sm sm:text-base">
                            Parent Reflection Interview
                          </h5>
                        </div>
                        <span className="bg-indigo-100 dark:bg-slate-850 text-indigo-700 dark:text-indigo-400 font-extrabold text-[10px] px-2 py-0.5 rounded-full">
                          Premium
                        </span>
                      </div>
                      <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-4">
                        15-min guided parenting burden check-in. Includes written clinical reflection and psychologist callback.
                      </p>
                    </div>
                    <div className="pt-2">
                      {reflectCompleted ? (
                        <div className="flex items-center justify-between text-xs">
                          <span className="text-emerald-650 dark:text-emerald-400 font-bold flex items-center gap-1">
                            <Check size={14} /> Callback Scheduled
                          </span>
                          <Link
                            href="/parent-reflect"
                            className="text-indigo-600 font-bold hover:underline"
                          >
                            View Report
                          </Link>
                        </div>
                      ) : (
                        <Link
                          href="/parent-reflect"
                          className="block text-center bg-indigo-650 text-white text-xs font-bold py-2.5 rounded-xl shadow-sm hover:opacity-90 transition-opacity"
                        >
                          Start Reflection (₹1,000)
                        </Link>
                      )}
                    </div>
                  </div>
                </div>
              </div>

              {/* Assessment Modules Grid */}
              <div className="space-y-4">
                <h4 className="heading-fun text-lg font-bold text-slate-800 dark:text-slate-200">
                  Assessment Modules
                </h4>
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                  {allModules.map((m) => {
                    const completed = assessmentsList.find((a) => a.module === m.key && a.status === 'completed');
                    const IconComponent = m.icon;

                    return (
                      <div
                        key={m.key}
                        className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/60 rounded-3xl p-5 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between"
                      >
                        <div>
                          <div className="flex items-center gap-2 mb-2">
                            <IconComponent className="text-indigo-650" size={24} />
                            <h5 className="font-bold text-slate-800 dark:text-slate-100 text-sm sm:text-base">
                              {m.label}
                            </h5>
                          </div>
                          <p className="text-xs text-slate-400 dark:text-slate-500 leading-relaxed mb-4">
                            {m.desc}
                          </p>
                        </div>
                        <div className="pt-2">
                          {completed ? (
                            <div className="flex items-center justify-between text-xs">
                              <span className="text-emerald-650 dark:text-emerald-400 font-bold flex items-center gap-1">
                                <Check size={14} /> Done · {completed.score}/100
                              </span>
                              <Link
                                href={`/eval/${selectedChild.id}/${m.key}`}
                                className="text-indigo-500 font-bold hover:underline"
                              >
                                Re-do
                              </Link>
                            </div>
                          ) : (
                            <Link
                              href={`/eval/${selectedChild.id}/${m.key}`}
                              className="block text-center bg-indigo-600 text-white text-xs font-bold py-2 rounded-xl shadow-sm hover:opacity-90 transition-opacity"
                            >
                              Start Module
                            </Link>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
