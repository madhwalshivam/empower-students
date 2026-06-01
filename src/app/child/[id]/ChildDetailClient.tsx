'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { resetEvaluationAction } from '@/app/actions/child';
import {
  ArrowLeft,
  Heart,
  Brain,
  Smile,
  Globe,
  Sparkles,
  Binary,
  BookOpen,
  Apple,
  Check,
  Gift,
  RotateCcw,
  BarChart2,
  AlertTriangle
} from 'lucide-react';

interface ChildDetailClientProps {
  child: any;
  assessments: any[];
  carePack: any;
  parentCredits: number;
}

export default function ChildDetailClient({
  child,
  assessments,
  carePack,
  parentCredits,
}: ChildDetailClientProps) {
  const router = useRouter();
  const [resetLoading, setResetLoading] = useState(false);
  const [resetError, setResetError] = useState('');
  const [resetSuccess, setResetSuccess] = useState('');

  const completedCount = assessments.length;
  const progressPct = Math.round((completedCount / 8) * 100);

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

  const handleReset = async () => {
    const ok1 = window.confirm(
      `Start a fresh evaluation for ${child.name}?\n\n` +
      `• All modules reset to "not done"\n` +
      `• Old data stays archived (you can compare later)\n` +
      `• You'll need to re-run modules and re-pay for an expert report\n\n` +
      `Continue?`
    );
    if (!ok1) return;

    const typed = window.prompt(`To confirm, type RESET (in capitals) below:`);
    if (typed !== 'RESET') {
      setResetError('Cancelled. You did not type RESET.');
      return;
    }

    setResetLoading(true);
    setResetError('');
    setResetSuccess('');

    try {
      const res = await resetEvaluationAction(Number(child.id));
      if (res.ok) {
        setResetSuccess('Evaluation reset. Reloading child page...');
        setTimeout(() => {
          router.refresh();
        }, 1200);
      } else {
        setResetError(res.error || 'Failed to reset evaluation.');
      }
    } catch (err: any) {
      setResetError(err.message || 'An error occurred during reset.');
    } finally {
      setResetLoading(false);
    }
  };

  return (
    <div className="space-y-8 max-w-5xl mx-auto">
      {/* Back Button */}
      <Link href="/dashboard" className="text-sm text-indigo-650 dark:text-indigo-400 hover:underline flex items-center gap-1">
        <ArrowLeft size={16} /> Back to Dashboard
      </Link>

      {/* Child Header Card */}
      <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-100 dark:border-slate-800 p-6 sm:p-8 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div className="flex items-center gap-4">
          <div className="w-16 h-16 bg-indigo-600 rounded-full flex items-center justify-center text-white font-extrabold text-2xl shadow-md">
            {child.name ? child.name.substring(0, 1).toUpperCase() : '?'}
          </div>
          <div>
            <h1 className="heading-fun text-3xl font-extrabold text-slate-800 dark:text-slate-100">
              {child.name}
            </h1>
            <p className="text-sm text-slate-500 mt-1">
              {child.dob} ({child.gender || '—'}) ·{' '}
              <span className="font-bold text-indigo-500">Grade {child.class_grade || '—'}</span>
            </p>
            {child.diagnosis && (
              <p className="text-xs text-rose-600 dark:text-rose-400 mt-1 font-semibold">
                Diagnosis: {child.diagnosis}
              </p>
            )}
          </div>
        </div>
        <Link
          href={`/report/${child.id}`}
          className="bg-indigo-650 text-white px-5 py-3 rounded-2xl font-bold hover:scale-[1.02] transition-transform text-center shadow-md"
        >
          View AI Report
        </Link>
      </div>

      {/* Care Pack block */}
      {completedCount > 0 && (
        <div className="bg-indigo-600 rounded-3xl p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
          <div className="absolute -top-1.5 right-6 bg-white/20 text-white px-4 py-1 rounded-full text-xs font-bold">
            SAVE 148 cr
          </div>

          {!carePack ? (
            <div className="grid sm:grid-cols-3 gap-6 items-center">
              <div className="sm:col-span-2">
                <p className="text-xs uppercase tracking-wider opacity-90 font-bold flex items-center gap-1.5">
                  <Gift size={14} /> Care Pack for {child.name}
                </p>
                <h2 className="heading-fun text-2xl sm:text-3xl font-bold mt-1 mb-2">
                  Three personalised tools, ready in 60 seconds
                </h2>
                <p className="opacity-90 text-sm leading-relaxed">
                  Based on {child.name}'s actual assessment results — AI generates a 4-week growth plan, a personal course of 5 lessons, and unlocks 30 days of daily tracking.
                </p>
              </div>
              <div className="text-center sm:text-right">
                <div className="text-4xl font-extrabold">499 cr</div>
                <div className="text-xs opacity-75 line-through mb-3">647 cr separately</div>
                <Link
                  href={`/care-pack/${child.id}`}
                  className="inline-block bg-white text-indigo-650 hover:bg-slate-50 px-6 py-2.5 rounded-full font-bold text-sm transition-all shadow-lg hover:scale-105"
                >
                  Buy Care Pack
                </Link>
              </div>
            </div>
          ) : (
            <div>
              <div className="flex items-center gap-2 mb-4">
                <Gift size={20} />
                <h3 className="heading-fun text-xl font-bold">{child.name}'s Care Pack</h3>
                <span className="bg-emerald-100 text-emerald-800 text-xs font-bold px-2 py-0.5 rounded-full">
                  ACTIVE
                </span>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-slate-800">
                <Link
                  href={`/growth-plan/${child.id}`}
                  className="bg-white hover:scale-[1.02] transition-transform rounded-2xl p-5 shadow-lg block"
                >
                  <Heart size={28} className="text-indigo-600 mb-2" />
                  <h4 className="font-bold text-base mb-1">Growth Plan</h4>
                  <p className="text-xs text-slate-500">4-week personalised action plan</p>
                </Link>
                <Link
                  href={`/course/${child.id}`}
                  className="bg-white hover:scale-[1.02] transition-transform rounded-2xl p-5 shadow-lg block"
                >
                  <BookOpen size={28} className="text-indigo-600 mb-2" />
                  <h4 className="font-bold text-base mb-1">Personal Course</h4>
                  <p className="text-xs text-slate-500">5 AI-written lessons</p>
                </Link>
                <Link
                  href={`/tracker/${child.id}`}
                  className="bg-white hover:scale-[1.02] transition-transform rounded-2xl p-5 shadow-lg block"
                >
                  <BarChart2 size={28} className="text-indigo-600 mb-2" />
                  <h4 className="font-bold text-base mb-1">Daily Tracker</h4>
                  <p className="text-xs text-slate-500">{carePack.tracker_days_remaining || 0} days remaining</p>
                </Link>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Modules List */}
      <div className="space-y-4">
        <h2 className="heading-fun text-2xl font-bold text-slate-800 dark:text-slate-200">
          Assessment Modules
        </h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {allModules.map((m) => {
            const completed = assessments.find((a) => a.module === m.key && a.status === 'completed');
            const IconComponent = m.icon;

            return (
              <div
                key={m.key}
                className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between"
              >
                <div>
                  <div className="flex items-center gap-2 mb-2">
                    <IconComponent className="text-indigo-650 flex-shrink-0" size={24} />
                    <div className="flex-grow">
                      <h3 className="font-bold text-slate-800 dark:text-slate-100 text-sm sm:text-base">
                        {m.label}
                      </h3>
                      <p className="text-xs text-slate-400 dark:text-slate-500">{m.desc}</p>
                    </div>
                  </div>
                </div>
                <div className="pt-4 border-t border-slate-50 dark:border-slate-800/80 mt-4 flex items-center justify-between">
                  {completed ? (
                    <>
                      <span className="text-xs text-emerald-650 dark:text-emerald-400 font-bold flex items-center gap-1">
                        <Check size={14} /> Done {completed.score !== null && `· ${completed.score}/100`}
                      </span>
                      <Link
                        href={`/eval/${child.id}/${m.key}`}
                        className="text-xs font-bold text-slate-500 hover:text-indigo-600 bg-slate-100 dark:bg-slate-800 px-3.5 py-1.5 rounded-lg"
                      >
                        Re-do
                      </Link>
                    </>
                  ) : (
                    <>
                      <span className="text-xs text-slate-400 dark:text-slate-650">Pending</span>
                      <Link
                        href={`/eval/${child.id}/${m.key}`}
                        className="text-xs font-bold text-white bg-indigo-600 px-4 py-1.5 rounded-lg shadow-sm"
                      >
                        Start
                      </Link>
                    </>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Fresh Evaluation reset block */}
      <div className="bg-indigo-50/50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 rounded-3xl p-6 sm:p-8">
        <div className="flex items-start gap-4">
          <RotateCcw size={28} className="text-indigo-650 flex-shrink-0 mt-1" />
          <div className="space-y-3 flex-grow">
            <h3 className="font-bold text-indigo-900 dark:text-indigo-300">
              Want to do a fresh evaluation for {child.name}?
            </h3>
            <p className="text-sm text-slate-500 leading-relaxed">
              After 3–6 months, kids change. Start an entirely new evaluation round — all modules reset to "not done". Old results stay safely archived for comparison.
            </p>

            {resetError && (
              <div className="bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-xl p-3 flex items-center gap-1.5">
                <AlertTriangle size={14} />
                <span>{resetError}</span>
              </div>
            )}

            {resetSuccess && (
              <div className="bg-emerald-50 border border-emerald-200 text-emerald-850 text-xs rounded-xl p-3 font-semibold flex items-center gap-1.5">
                <Check size={14} />
                <span>{resetSuccess}</span>
              </div>
            )}

            <button
              onClick={handleReset}
              disabled={resetLoading}
              className="bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50 text-xs cursor-pointer border-none"
            >
              {resetLoading ? 'Resetting...' : 'Start Fresh Evaluation'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
