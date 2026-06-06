'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { resetEvaluationAction } from '@/app/actions/child';
import { unlockCarePackAction } from '@/app/actions/carepack';
import { unlockSpeechEvalAction } from '@/app/actions/speech';
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
  CheckCircle2,
  Gift,
  RotateCcw,
  BarChart2,
  AlertTriangle,
  Play,
  RefreshCw,
  FileText,
  Coins,
  Trophy,
  ChevronRight,
  Lock,
  Wallet,
  X,
  Loader2,
  Mic,
} from 'lucide-react';

const CARE_PACK_PRICE = 499;

interface ChildDetailClientProps {
  child: any;
  assessments: any[];
  carePackUnlocked: boolean;
  trackerDaysRemaining: number;
  parentCredits: number;
  inProgressModules?: string[];
  speechUnlocked?: boolean;
}

export default function ChildDetailClient({
  child,
  assessments,
  carePackUnlocked,
  trackerDaysRemaining,
  parentCredits,
  inProgressModules = [],
  speechUnlocked = false,
}: ChildDetailClientProps) {
  const router = useRouter();
  const [resetLoading, setResetLoading] = useState(false);
  const [resetError, setResetError] = useState('');
  const [resetSuccess, setResetSuccess] = useState('');

  // Care Pack unlock confirmation modal
  const [showUnlock, setShowUnlock] = useState(false);
  const [unlocking, setUnlocking] = useState(false);
  const [unlockError, setUnlockError] = useState('');

  // Speech eval unlock modal
  const [showSpeechUnlock, setShowSpeechUnlock] = useState(false);
  const [speechUnlocking, setSpeechUnlocking] = useState(false);
  const [speechUnlockError, setSpeechUnlockError] = useState('');

  const handleSpeechUnlock = async () => {
    setSpeechUnlocking(true);
    setSpeechUnlockError('');
    try {
      const res = await unlockSpeechEvalAction(Number(child.id));
      if (res.ok) {
        setShowSpeechUnlock(false);
        router.refresh();
      } else if (res.insufficient) {
        setSpeechUnlockError(`You need ${res.need} credits but have only ${res.balance}. Please top up.`);
      } else {
        setSpeechUnlockError(res.error || 'Could not unlock.');
      }
    } catch (err: any) {
      setSpeechUnlockError(err.message || 'Something went wrong.');
    } finally {
      setSpeechUnlocking(false);
    }
  };

  const handleUnlock = async () => {
    setUnlocking(true);
    setUnlockError('');
    try {
      const res = await unlockCarePackAction(Number(child.id));
      if (res.ok) {
        setShowUnlock(false);
        router.refresh();
      } else if (res.insufficient) {
        setUnlockError(`You need ${res.need} credits but have only ${res.balance}. Please top up your wallet.`);
      } else {
        setUnlockError(res.error || 'Could not unlock. Please try again.');
      }
    } catch (err: any) {
      setUnlockError(err.message || 'Something went wrong.');
    } finally {
      setUnlocking(false);
    }
  };

  const completedCount = assessments.length;
  const totalModules = 8;
  const progressPct = Math.round((completedCount / totalModules) * 100);

  const allModules = [
    { key: 'health', label: 'Health Screening', icon: Heart, desc: 'Growth, sleep, sensory, milestone red-flags', color: '#ef4444' },
    { key: 'mind_power', label: 'Mind Power', icon: Brain, desc: 'Memory, attention, problem-solving', color: '#8b5cf6' },
    { key: 'behavior', label: 'Behaviour', icon: Smile, desc: 'Social skills, emotional regulation, self-control', color: '#f59e0b' },
    { key: 'general_awareness', label: 'General Awareness', icon: Globe, desc: '2-min adaptive general knowledge quiz', color: '#06b6d4' },
    { key: 'special_talent', label: 'Special Talent', icon: Sparkles, desc: 'Spotting a unique child gift to nurture', color: '#ec4899' },
    { key: 'math', label: 'Maths Level', icon: Binary, desc: 'Adaptive fundamental number sense finder', color: '#10b981' },
    { key: 'language', label: 'Language & Reading', icon: BookOpen, desc: 'Word-power and timed comprehension checks', color: '#3b82f6' },
    { key: 'diet', label: 'Diet & Nutrition', icon: Apple, desc: 'Tuned child food chart and sleep plan', color: '#84cc16' },
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
        setResetSuccess('Evaluation reset successfully. Reloading...');
        setTimeout(() => { router.refresh(); }, 1200);
      } else {
        setResetError(res.error || 'Failed to reset evaluation.');
      }
    } catch (err: any) {
      setResetError(err.message || 'An error occurred during reset.');
    } finally {
      setResetLoading(false);
    }
  };

  // Calculate age from DOB
  const age = child.dob ? (() => {
    const dob = new Date(child.dob);
    const now = new Date();
    const yr = now.getFullYear() - dob.getFullYear();
    const mo = now.getMonth() - dob.getMonth();
    return mo < 0 ? yr - 1 : yr;
  })() : null;

  return (
    <div style={{ maxWidth: 960, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 28 }}>

      {/* Back link */}
      <Link href="/dashboard" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, color: '#6366f1', textDecoration: 'none' }}>
        <ArrowLeft size={15} /> Back to Dashboard
      </Link>

      {/* ── Child Hero Card ── */}
      <div style={{ background: '#fff', borderRadius: 24, border: '1px solid #f1f5f9', boxShadow: '0 4px 24px rgba(99,102,241,0.07)', overflow: 'hidden' }}>
        {/* Colour band */}
        <div style={{ background: 'linear-gradient(135deg, #4f46e5, #7c3aed)', height: 6 }} />

        <div style={{ padding: '28px 32px', display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'center', justifyContent: 'space-between' }}>
          {/* Avatar + info */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 20 }}>
            <div style={{
              width: 72, height: 72, borderRadius: '50%',
              background: 'linear-gradient(135deg, #4f46e5, #7c3aed)',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              color: '#fff', fontWeight: 900, fontSize: 28,
              boxShadow: '0 8px 20px rgba(99,102,241,0.35)',
              flexShrink: 0,
            }}>
              {child.name ? child.name[0].toUpperCase() : '?'}
            </div>
            <div>
              <h1 style={{ fontSize: 26, fontWeight: 900, color: '#1e293b', letterSpacing: '-0.02em', margin: 0 }}>
                {child.name}
              </h1>
              <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b', display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                {age !== null && <span style={{ fontWeight: 600 }}>{age} yrs old</span>}
                {age !== null && <span style={{ color: '#cbd5e1' }}>·</span>}
                <span>{child.gender || 'Gender not set'}</span>
                {child.class_grade && <><span style={{ color: '#cbd5e1' }}>·</span><span style={{ background: '#ede9fe', color: '#6d28d9', fontWeight: 700, fontSize: 11, padding: '2px 8px', borderRadius: 20 }}>Grade {child.class_grade}</span></>}
              </p>
              {child.diagnosis && child.diagnosis !== 'none' && (
                <p style={{ margin: '6px 0 0', fontSize: 12, color: '#dc2626', fontWeight: 700, background: '#fef2f2', padding: '3px 10px', borderRadius: 20, display: 'inline-block' }}>
                  Diagnosis: {child.diagnosis}
                </p>
              )}
            </div>
          </div>

          {/* Progress + report button */}
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 12 }}>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 12, color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 6 }}>
                Assessment Progress
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div style={{ width: 140, height: 6, background: '#f1f5f9', borderRadius: 99, overflow: 'hidden' }}>
                  <div style={{ width: `${progressPct}%`, height: '100%', background: 'linear-gradient(90deg, #4f46e5, #7c3aed)', borderRadius: 99, transition: 'width 0.6s ease' }} />
                </div>
                <span style={{ fontSize: 13, fontWeight: 800, color: '#4f46e5' }}>{completedCount}/{totalModules}</span>
              </div>
            </div>
            <Link
              href={`/report/${child.id}`}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 8,
                background: completedCount > 0 ? 'linear-gradient(135deg, #4f46e5, #7c3aed)' : '#e2e8f0',
                color: completedCount > 0 ? '#fff' : '#94a3b8',
                padding: '11px 22px', borderRadius: 14,
                fontWeight: 700, fontSize: 14, textDecoration: 'none',
                boxShadow: completedCount > 0 ? '0 6px 20px rgba(99,102,241,0.35)' : 'none',
                transition: 'transform 0.15s',
              }}
            >
              <FileText size={16} /> View AI Report
            </Link>
          </div>
        </div>
      </div>

      {/* ── Care Pack Banner ── */}
      {completedCount > 0 && !carePackUnlocked && (
        <div style={{
          background: 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)',
          borderRadius: 24, padding: '28px 32px', color: '#fff',
          display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'center', justifyContent: 'space-between',
          boxShadow: '0 12px 40px rgba(99,102,241,0.4)', position: 'relative', overflow: 'hidden',
        }}>
          <div style={{ position: 'absolute', top: -30, right: -30, width: 140, height: 140, borderRadius: '50%', background: 'rgba(255,255,255,0.06)' }} />
          <div style={{ position: 'absolute', bottom: -20, left: '40%', width: 100, height: 100, borderRadius: '50%', background: 'rgba(255,255,255,0.04)' }} />
          <div>
            <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '0.1em', textTransform: 'uppercase', opacity: 0.8, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 6 }}>
              <Gift size={13} /> Personalised Care Pack for {child.name}
            </div>
            <h2 style={{ fontSize: 20, fontWeight: 900, margin: '0 0 8px', letterSpacing: '-0.01em' }}>
              Growth Plan · Personal Course · Daily Tracker
            </h2>
            <p style={{ fontSize: 13, opacity: 0.85, margin: 0, maxWidth: 480, lineHeight: 1.6 }}>
              AI-generated from {child.name}'s actual results. Three tools in one bundle — save 148 credits vs buying separately.
            </p>
          </div>
          <div style={{ textAlign: 'center', flexShrink: 0 }}>
            <div style={{ fontSize: 36, fontWeight: 900, lineHeight: 1 }}>499</div>
            <div style={{ fontSize: 12, opacity: 0.7, textDecoration: 'line-through', marginBottom: 14 }}>647 cr separately</div>
            <button
              onClick={() => { setUnlockError(''); setShowUnlock(true); }}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 8, background: '#fff', color: '#4f46e5', padding: '11px 24px', borderRadius: 14, fontWeight: 800, fontSize: 14, border: 'none', cursor: 'pointer', boxShadow: '0 4px 16px rgba(0,0,0,0.15)' }}
            >
              <Coins size={16} /> Unlock Care Pack
            </button>
          </div>
        </div>
      )}

      {/* Care Pack Active state */}
      {carePackUnlocked && (
        <div style={{ background: '#f0fdf4', border: '1px solid #a7f3d0', borderRadius: 24, padding: '24px 28px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
            <Trophy size={20} color="#059669" />
            <h3 style={{ fontSize: 16, fontWeight: 800, color: '#065f46', margin: 0 }}>{child.name}'s Care Pack — Active</h3>
            <span style={{ background: '#059669', color: '#fff', fontSize: 11, fontWeight: 700, padding: '2px 10px', borderRadius: 20 }}>ACTIVE</span>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 14 }}>
            {[
              { href: `/growth-plan/${child.id}`, icon: Heart, label: 'Growth Plan', sub: '4-week personalised plan' },
              { href: `/course/${child.id}`, icon: BookOpen, label: 'Personal Course', sub: '5 AI-written lessons' },
              { href: `/tracker/${child.id}`, icon: BarChart2, label: 'Daily Tracker', sub: `${trackerDaysRemaining} days remaining` },
            ].map((item) => (
              <Link key={item.href} href={item.href} style={{ background: '#fff', borderRadius: 16, padding: '18px 20px', textDecoration: 'none', border: '1px solid #d1fae5', display: 'flex', flexDirection: 'column', gap: 8, transition: 'box-shadow 0.15s' }}>
                <item.icon size={22} color="#059669" />
                <div style={{ fontWeight: 800, fontSize: 14, color: '#065f46' }}>{item.label}</div>
                <div style={{ fontSize: 12, color: '#6b7280' }}>{item.sub}</div>
              </Link>
            ))}
          </div>
        </div>
      )}

      {/* ── Assessment Modules ── */}
      <div>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
          <h2 style={{ fontSize: 20, fontWeight: 900, color: '#1e293b', letterSpacing: '-0.02em', margin: 0 }}>
            Assessment Modules
          </h2>
          <span style={{ fontSize: 12, fontWeight: 700, color: '#64748b', background: '#f8fafc', border: '1px solid #e2e8f0', padding: '4px 12px', borderRadius: 20 }}>
            {completedCount} of {totalModules} completed
          </span>
        </div>

        {/* ── Premium Clinical Services ── */}
        <div style={{ background: '#fff', border: '1px solid #f1f5f9', borderRadius: 20, padding: '20px 24px', boxShadow: '0 2px 12px rgba(0,0,0,0.04)', marginBottom: 16 }}>
          <h3 style={{ fontSize: 15, fontWeight: 800, color: '#1e293b', margin: '0 0 14px', display: 'flex', alignItems: 'center', gap: 8 }}>
            <Sparkles size={17} color="#4f46e5" /> Premium Clinical Services
          </h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
            {/* Speech & Language */}
            {speechUnlocked ? (
              <Link href={`/eval-speech/${child.id}`} style={{ background: 'linear-gradient(135deg,#f0fdf4,#ecfdf5)', border: '1.5px solid #a7f3d0', borderRadius: 16, padding: '16px 18px', textDecoration: 'none', display: 'flex', flexDirection: 'column', gap: 8 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span style={{ fontWeight: 800, fontSize: 13, color: '#065f46' }}>Speech &amp; Language</span>
                  <span style={{ background: '#059669', color: '#fff', fontSize: 10, fontWeight: 800, padding: '2px 8px', borderRadius: 99 }}>Unlocked</span>
                </div>
                <span style={{ fontSize: 12, color: '#059669', fontWeight: 700 }}>Open Evaluation →</span>
              </Link>
            ) : (
              <div style={{ background: '#fafafa', border: '1px solid #e2e8f0', borderRadius: 16, padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 8 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span style={{ fontWeight: 800, fontSize: 13, color: '#1e293b' }}>Speech &amp; Language</span>
                  <span style={{ background: '#eef2ff', color: '#4338ca', fontSize: 10, fontWeight: 800, padding: '2px 8px', borderRadius: 99 }}>₹1,000</span>
                </div>
                <p style={{ fontSize: 12, color: '#64748b', margin: 0, lineHeight: 1.5 }}>Adaptive voice evaluation — articulation, fluency, comprehension.</p>
                <button
                  onClick={() => { setSpeechUnlockError(''); setShowSpeechUnlock(true); }}
                  style={{ background: 'linear-gradient(135deg,#4f46e5,#7c3aed)', color: '#fff', border: 'none', borderRadius: 12, padding: '9px', fontSize: 13, fontWeight: 700, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}
                >
                  <Lock size={14} /> Unlock for {child.name}
                </button>
              </div>
            )}
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 16 }}>
          {allModules.map((m) => {
            const completed = assessments.find((a) => a.module === m.key && a.status === 'completed');
            const inProgress = !completed && inProgressModules.includes(m.key);
            const IconComponent = m.icon;

            return (
              <div
                key={m.key}
                style={{
                  background: '#fff',
                  border: `1px solid ${completed ? '#d1fae5' : inProgress ? '#fde68a' : '#f1f5f9'}`,
                  borderRadius: 20,
                  padding: '20px 22px',
                  boxShadow: '0 2px 12px rgba(0,0,0,0.04)',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: 16,
                  transition: 'box-shadow 0.2s, transform 0.2s',
                  position: 'relative',
                  overflow: 'hidden',
                }}
              >
                {/* Status indicator bar */}
                {completed ? (
                  <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: 'linear-gradient(90deg, #10b981, #34d399)' }} />
                ) : inProgress ? (
                  <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: 'linear-gradient(90deg, #f59e0b, #fbbf24)' }} />
                ) : null}

                {/* Icon + title */}
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14 }}>
                  <div style={{
                    width: 44, height: 44, borderRadius: 14, flexShrink: 0,
                    background: `${m.color}15`,
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                  }}>
                    <IconComponent size={22} color={m.color} />
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <h3 style={{ fontSize: 14, fontWeight: 800, color: '#1e293b', margin: '0 0 4px', lineHeight: 1.3 }}>
                      {m.label}
                    </h3>
                    <p style={{ fontSize: 12, color: '#94a3b8', margin: 0, lineHeight: 1.5 }}>{m.desc}</p>
                  </div>
                </div>

                {/* Status + CTA */}
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', paddingTop: 14, borderTop: '1px solid #f8fafc' }}>
                  {completed ? (
                    <>
                      <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 700, color: '#059669' }}>
                        <CheckCircle2 size={15} />
                        Done{completed.score !== null ? ` · ${completed.score}/100` : ''}
                      </span>
                      <Link
                        href={`/eval/${child.id}/${m.key}`}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 700, color: '#6366f1', background: '#ede9fe', padding: '6px 14px', borderRadius: 10, textDecoration: 'none' }}
                      >
                        <RefreshCw size={12} /> Redo
                      </Link>
                    </>
                  ) : inProgress ? (
                    <>
                      <span style={{ fontSize: 12, fontWeight: 700, color: '#d97706', display: 'flex', alignItems: 'center', gap: 5 }}>
                        <span style={{ width: 7, height: 7, borderRadius: '50%', background: '#f59e0b', display: 'inline-block' }} />
                        In progress
                      </span>
                      <Link
                        href={`/eval/${child.id}/${m.key}`}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: 6,
                          fontSize: 13, fontWeight: 800, color: '#fff',
                          background: 'linear-gradient(135deg, #f59e0b, #f97316)',
                          padding: '8px 18px', borderRadius: 12,
                          textDecoration: 'none',
                          boxShadow: '0 4px 12px rgba(245,158,11,0.3)',
                        }}
                      >
                        <RefreshCw size={13} /> Resume
                      </Link>
                    </>
                  ) : (
                    <>
                      <span style={{ fontSize: 12, fontWeight: 600, color: '#94a3b8', display: 'flex', alignItems: 'center', gap: 5 }}>
                        <span style={{ width: 7, height: 7, borderRadius: '50%', background: '#e2e8f0', display: 'inline-block' }} />
                        Not started
                      </span>
                      <Link
                        href={`/eval/${child.id}/${m.key}`}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: 6,
                          fontSize: 13, fontWeight: 800, color: '#fff',
                          background: 'linear-gradient(135deg, #4f46e5, #6366f1)',
                          padding: '8px 18px', borderRadius: 12,
                          textDecoration: 'none',
                          boxShadow: '0 4px 12px rgba(99,102,241,0.3)',
                        }}
                      >
                        <Play size={13} fill="#fff" /> Begin
                      </Link>
                    </>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* ── Fresh Evaluation Reset ── */}
      <div style={{ background: '#fafafa', border: '1px solid #e2e8f0', borderRadius: 24, padding: '28px 32px' }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 18 }}>
          <div style={{ width: 48, height: 48, borderRadius: 16, background: '#ede9fe', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
            <RotateCcw size={22} color="#6d28d9" />
          </div>
          <div style={{ flex: 1 }}>
            <h3 style={{ fontSize: 16, fontWeight: 800, color: '#1e293b', margin: '0 0 6px' }}>
              Start a Fresh Evaluation for {child.name}
            </h3>
            <p style={{ fontSize: 13, color: '#64748b', margin: '0 0 16px', lineHeight: 1.7 }}>
              Children grow and change every few months. Reset all modules to re-evaluate — old results are safely archived so you can compare growth over time.
            </p>

            {resetError && (
              <div style={{ background: '#fef2f2', border: '1px solid #fecaca', color: '#dc2626', borderRadius: 12, padding: '10px 14px', fontSize: 13, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
                <AlertTriangle size={15} /> {resetError}
              </div>
            )}
            {resetSuccess && (
              <div style={{ background: '#f0fdf4', border: '1px solid #a7f3d0', color: '#059669', borderRadius: 12, padding: '10px 14px', fontSize: 13, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
                <CheckCircle2 size={15} /> {resetSuccess}
              </div>
            )}

            <button
              onClick={handleReset}
              disabled={resetLoading}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 8,
                background: resetLoading ? '#e2e8f0' : '#4f46e5',
                color: resetLoading ? '#94a3b8' : '#fff',
                padding: '11px 22px', borderRadius: 14,
                fontWeight: 800, fontSize: 14, border: 'none', cursor: resetLoading ? 'not-allowed' : 'pointer',
                boxShadow: resetLoading ? 'none' : '0 4px 16px rgba(99,102,241,0.3)',
                transition: 'all 0.2s',
              }}
            >
              <RotateCcw size={15} />
              {resetLoading ? 'Resetting...' : 'Reset & Start Fresh'}
            </button>
          </div>
        </div>
      </div>

      {/* ── Speech Eval Unlock Modal ── */}
      {showSpeechUnlock && (
        <div
          onClick={() => !speechUnlocking && setShowSpeechUnlock(false)}
          style={{ position: 'fixed', inset: 0, zIndex: 50, background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(3px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
        >
          <div onClick={e => e.stopPropagation()} style={{ background: '#fff', borderRadius: 24, width: '100%', maxWidth: 420, boxShadow: '0 24px 60px rgba(0,0,0,0.3)', overflow: 'hidden' }}>
            <div style={{ background: 'linear-gradient(135deg,#4f46e5,#7c3aed)', padding: '24px 28px', color: '#fff', position: 'relative' }}>
              <button onClick={() => !speechUnlocking && setShowSpeechUnlock(false)} style={{ position: 'absolute', top: 16, right: 16, background: 'rgba(255,255,255,0.18)', border: 'none', borderRadius: 10, width: 32, height: 32, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', cursor: 'pointer' }}>
                <X size={16} />
              </button>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
                <Mic size={18} />
                <span style={{ fontSize: 12, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase', opacity: 0.85 }}>Unlock Speech Eval</span>
              </div>
              <h3 style={{ margin: 0, fontSize: 20, fontWeight: 900 }}>Unlock for {child.name}?</h3>
            </div>
            <div style={{ padding: '24px 28px' }}>
              <p style={{ margin: '0 0 18px', fontSize: 14, color: '#475569', lineHeight: 1.6 }}>
                Do you want to unlock <strong>Speech &amp; Language Evaluation</strong> for {child.name}? This is a <strong>one-time unlock</strong> — you can retake the evaluation as many times as you need.
              </p>
              <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 16, padding: '16px 18px', marginBottom: 18 }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                  <span style={{ fontSize: 13, color: '#64748b', fontWeight: 600 }}>Amount</span>
                  <span style={{ fontSize: 20, fontWeight: 900, color: '#4f46e5' }}>1,000 credits</span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <span style={{ fontSize: 13, color: '#64748b', fontWeight: 600 }}>Your balance</span>
                  <span style={{ fontSize: 14, fontWeight: 800, color: parentCredits >= 1000 ? '#059669' : '#dc2626' }}>
                    {parentCredits} credits
                  </span>
                </div>
              </div>
              {parentCredits < 1000 && (
                <div style={{ background: '#fff7ed', border: '1px solid #fed7aa', color: '#9a3412', borderRadius: 12, padding: '10px 14px', fontSize: 13, fontWeight: 600, marginBottom: 16 }}>
                  Not enough credits. <Link href="/wallet?need=1000" style={{ color: '#9a3412', fontWeight: 800 }}>Top up →</Link>
                </div>
              )}
              {speechUnlockError && (
                <div style={{ background: '#fef2f2', border: '1px solid #fecaca', color: '#dc2626', borderRadius: 12, padding: '10px 14px', fontSize: 13, fontWeight: 600, marginBottom: 16 }}>
                  {speechUnlockError}
                </div>
              )}
              <div style={{ display: 'flex', gap: 10 }}>
                <button onClick={() => setShowSpeechUnlock(false)} disabled={speechUnlocking} style={{ flex: 1, background: '#f1f5f9', color: '#475569', padding: '12px', borderRadius: 14, fontWeight: 800, fontSize: 14, border: 'none', cursor: 'pointer' }}>Cancel</button>
                <button
                  onClick={handleSpeechUnlock}
                  disabled={speechUnlocking || parentCredits < 1000}
                  style={{ flex: 1.4, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8, background: speechUnlocking || parentCredits < 1000 ? '#c7d2fe' : 'linear-gradient(135deg,#4f46e5,#7c3aed)', color: '#fff', padding: '12px', borderRadius: 14, fontWeight: 800, fontSize: 14, border: 'none', cursor: speechUnlocking || parentCredits < 1000 ? 'not-allowed' : 'pointer' }}
                >
                  {speechUnlocking ? <><Loader2 size={15} className="animate-spin" /> Unlocking…</> : <><Mic size={15} /> Yes, unlock</>}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ── Unlock Confirmation Popup ── */}
      {showUnlock && (
        <div
          onClick={() => !unlocking && setShowUnlock(false)}
          style={{
            position: 'fixed', inset: 0, zIndex: 50,
            background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(3px)',
            display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
          }}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            style={{
              background: '#fff', borderRadius: 24, width: '100%', maxWidth: 440,
              boxShadow: '0 24px 60px rgba(0,0,0,0.3)', overflow: 'hidden', position: 'relative',
            }}
          >
            <div style={{ background: 'linear-gradient(135deg, #4f46e5, #7c3aed)', padding: '24px 28px', color: '#fff', position: 'relative' }}>
              <button
                onClick={() => !unlocking && setShowUnlock(false)}
                aria-label="Close"
                style={{ position: 'absolute', top: 16, right: 16, background: 'rgba(255,255,255,0.18)', border: 'none', borderRadius: 10, width: 32, height: 32, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', cursor: 'pointer' }}
              >
                <X size={16} />
              </button>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
                <Lock size={18} />
                <span style={{ fontSize: 12, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase', opacity: 0.85 }}>Confirm Unlock</span>
              </div>
              <h3 style={{ margin: 0, fontSize: 20, fontWeight: 900 }}>Unlock {child.name}'s Care Pack?</h3>
            </div>

            <div style={{ padding: '24px 28px' }}>
              <p style={{ margin: '0 0 18px', fontSize: 14, color: '#475569', lineHeight: 1.6 }}>
                Do you want to unlock this module? This unlocks the personalised <strong>Growth Plan</strong>, <strong>Personal Course</strong> and <strong>Daily Tracker</strong> for {child.name}.
              </p>

              <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 16, padding: '16px 18px', marginBottom: 18 }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                  <span style={{ fontSize: 13, color: '#64748b', fontWeight: 600 }}>Amount</span>
                  <span style={{ fontSize: 20, fontWeight: 900, color: '#4f46e5' }}>{CARE_PACK_PRICE} credits</span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <span style={{ fontSize: 13, color: '#64748b', fontWeight: 600 }}>Your balance</span>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 14, fontWeight: 800, color: parentCredits >= CARE_PACK_PRICE ? '#059669' : '#dc2626' }}>
                    <Wallet size={14} /> {parentCredits} credits
                  </span>
                </div>
              </div>

              {parentCredits < CARE_PACK_PRICE && (
                <div style={{ background: '#fff7ed', border: '1px solid #fed7aa', color: '#9a3412', borderRadius: 12, padding: '10px 14px', fontSize: 13, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16 }}>
                  <AlertTriangle size={15} /> Not enough credits.{' '}
                  <Link href={`/wallet?need=${CARE_PACK_PRICE}`} style={{ color: '#9a3412', fontWeight: 800, textDecoration: 'underline' }}>Top up</Link>
                </div>
              )}

              {unlockError && (
                <div style={{ background: '#fef2f2', border: '1px solid #fecaca', color: '#dc2626', borderRadius: 12, padding: '10px 14px', fontSize: 13, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16 }}>
                  <AlertTriangle size={15} /> {unlockError}
                </div>
              )}

              <div style={{ display: 'flex', gap: 10 }}>
                <button
                  onClick={() => setShowUnlock(false)}
                  disabled={unlocking}
                  style={{ flex: 1, background: '#f1f5f9', color: '#475569', padding: '12px', borderRadius: 14, fontWeight: 800, fontSize: 14, border: 'none', cursor: unlocking ? 'not-allowed' : 'pointer' }}
                >
                  Cancel
                </button>
                <button
                  onClick={handleUnlock}
                  disabled={unlocking || parentCredits < CARE_PACK_PRICE}
                  style={{
                    flex: 1.4, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8,
                    background: unlocking || parentCredits < CARE_PACK_PRICE ? '#c7d2fe' : 'linear-gradient(135deg, #4f46e5, #7c3aed)',
                    color: '#fff', padding: '12px', borderRadius: 14, fontWeight: 800, fontSize: 14, border: 'none',
                    cursor: unlocking || parentCredits < CARE_PACK_PRICE ? 'not-allowed' : 'pointer',
                  }}
                >
                  {unlocking ? (<><Loader2 size={15} className="animate-spin" /> Unlocking…</>) : (<><Coins size={15} /> Yes, unlock for {CARE_PACK_PRICE}</>)}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
}
