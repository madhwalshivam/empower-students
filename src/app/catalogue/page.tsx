import React from 'react';
import Link from 'next/link';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { calcAgeYears } from '@/lib/evaluations/engine';

export const dynamic = 'force-dynamic';

export default async function CataloguePage({
  searchParams,
}: {
  searchParams: Promise<{ cid?: string; g?: string }>;
}) {
  const resolvedParams = await searchParams;
  const cidStr = resolvedParams.cid;
  const filterGroup = resolvedParams.g || 'all';

  const supabase = await createClient();

  // Validate session (optional for browsing, but helps match child age)
  const { data: { user } } = await supabase.auth.getUser();

  let child: any = null;
  let age: number | null = null;

  if (user) {
    const db = createAdminClient();
    if (cidStr) {
      const { data } = await db
        .from('children')
        .select('*')
        .eq('id', Number(cidStr))
        .eq('parent_id', user.id)
        .maybeSingle();
      child = data;
    } else {
      // Pick first child automatically
      const { data } = await db
        .from('children')
        .select('*')
        .eq('parent_id', user.id)
        .order('id', { ascending: true })
        .limit(1)
        .maybeSingle();
      child = data;
    }

    if (child) {
      age = calcAgeYears(child.dob);
    }
  }

  // Pre-defined modules list matching modules.ts
  const modulesList = [
    {
      key: 'mind_power',
      label: 'Mind Power Cognitive Screening',
      short_desc: 'Evaluates working memory, attention & focus, logical reasoning, and visual spatial processing.',
      icon: '🧠',
      price: 199,
      tier: 'cognitive',
      group: 'special',
    },
    {
      key: 'behavior',
      label: 'Behavioral & Social Screener',
      short_desc: 'Measures self-regulation, empathy, peer conflict response, and rule cooperation.',
      icon: '🤝',
      price: 249,
      tier: 'social',
      group: 'special',
    },
    {
      key: 'emotions',
      label: 'Emotional Literacy Index',
      short_desc: 'Tests emotional vocabulary, coping strategies, and perspective-taking.',
      icon: '🎭',
      price: 199,
      tier: 'social',
      group: 'all',
    },
    {
      key: 'maths',
      label: 'Mathematical Thinking Hub',
      short_desc: 'Arithmetic speed, number sense, place values, and real-world word problems.',
      icon: '🔢',
      price: 149,
      tier: 'cognitive',
      group: 'all',
    },
    {
      key: 'language',
      label: 'Language & Literacy Lab',
      short_desc: 'Covers vocabulary span, reading comprehension, verb tenses, and creative description.',
      icon: '📖',
      price: 149,
      tier: 'cognitive',
      group: 'all',
    },
    {
      key: 'special_talent',
      label: 'Aptitude & Talent Discovery',
      short_desc: 'Finds artistic, kinesthetic, social-lead, or scientific sparks in your child.',
      icon: '✨',
      price: 299,
      tier: 'discovery',
      group: 'consult',
    },
  ];

  // Apply filters
  const filtered = modulesList.filter((m) => {
    if (filterGroup !== 'all' && m.group !== filterGroup) return false;
    return true;
  });

  return (
    <div className="max-w-5xl mx-auto py-8 px-4 space-y-8 animate-fade-in">
      <header className="space-y-2">
        <h1 className="heading-fun text-3xl font-extrabold text-slate-800 dark:text-slate-100">
          Module Catalogue
        </h1>
        <p className="text-slate-500 max-w-2xl text-sm">
          {child ? (
            <>
              Showing modules matched to{' '}
              <strong className="text-slate-700 dark:text-slate-300">{child.name}</strong> ({age?.toFixed(1)} yrs).
            </>
          ) : (
            'Pay only for what you need. Each module has its own adaptive assessment, AI report, and plan.'
          )}
        </p>
      </header>

      {/* Filter Tabs */}
      <div className="flex flex-wrap gap-2">
        {['all', 'special', 'consult'].map((g) => {
          const active = filterGroup === g;
          return (
            <Link
              key={g}
              href={`/catalogue?g=${g}${child ? `&cid=${child.id}` : ''}`}
              className={`px-4 py-1.5 rounded-full text-xs font-bold transition-all border ${
                active
                  ? 'bg-indigo-650 border-indigo-650 text-white shadow-sm'
                  : 'bg-white dark:bg-slate-900 border-slate-100 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-50'
              }`}
            >
              {g === 'all' ? 'All Modules' : g === 'special' ? 'Cognitive & Social' : 'Aptitude & Discovery'}
            </Link>
          );
        })}
      </div>

      {/* Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        {filtered.map((m) => (
          <div
            key={m.key}
            className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 rounded-3xl p-6 shadow-sm flex flex-col justify-between hover:shadow-md hover:border-indigo-200 dark:hover:border-indigo-900 transition-all group"
          >
            <div>
              <div className="flex justify-between items-start mb-4">
                <span className="text-4xl">{m.icon}</span>
                <span className="text-[10px] font-bold tracking-widest text-indigo-600 dark:text-indigo-400 uppercase bg-indigo-50 dark:bg-indigo-950/20 px-2 py-0.5 rounded">
                  {m.tier}
                </span>
              </div>
              <h3 className="font-bold text-slate-800 dark:text-slate-100 text-lg group-hover:text-indigo-650 dark:group-hover:text-indigo-400 transition-colors mb-2">
                {m.label}
              </h3>
              <p className="text-slate-500 text-xs sm:text-sm leading-relaxed mb-6">
                {m.short_desc}
              </p>
            </div>

            <div className="flex items-center justify-between border-t border-slate-50 dark:border-slate-800/60 pt-4">
              <div>
                <span className="text-xl font-extrabold text-slate-800 dark:text-slate-100">
                  ₹{m.price}
                </span>
              </div>
              <Link
                href={child ? `/eval/${child.id}/${m.key}` : '/login'}
                className="text-xs font-bold text-indigo-600 dark:text-indigo-400 group-hover:underline"
              >
                Start Evaluation &rarr;
              </Link>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
