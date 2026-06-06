'use client';

import React, { useState, useEffect, useTransition, useCallback } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft, BarChart2, Flag, CheckCircle2, Circle,
  ChevronLeft, ChevronRight, Calendar, TrendingUp, Loader2,
} from 'lucide-react';
import { getCarePackContent } from '@/app/actions/carepack';
import { getTrackerChecks, toggleTrackerCheck, getTrackerHistory } from '@/app/actions/tracker';
import {
  isoWeekKey, mondayOfWeek, weekLabel, todayDayIdx,
  addWeeks, isCurrentWeek,
} from '@/lib/tracker/week-utils';

// ── Day header labels with dates ─────────────────────────────────────────────

function dayHeaders(weekKey: string): { abbr: string; date: string; today: boolean }[] {
  const mon = mondayOfWeek(weekKey);
  const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const todayIdx = todayDayIdx();
  const cw = isCurrentWeek(weekKey);
  return days.map((abbr, i) => {
    const d = new Date(mon);
    d.setDate(mon.getDate() + i);
    return {
      abbr,
      date: d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }),
      today: cw && i === todayIdx,
    };
  });
}

// ── Main Page ────────────────────────────────────────────────────────────────

export default function TrackerPage() {
  const params = useParams();
  const childId = Number(params.id);

  const [child, setChild] = useState<any>(null);
  const [tracker, setTracker] = useState<any>(null);
  const [weekKey, setWeekKey] = useState<string>(() => isoWeekKey(new Date()));
  const [checks, setChecks] = useState<Record<string, boolean>>({});
  const [history, setHistory] = useState<{ weekKey: string; label: string; checked: number; total: number }[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'tracker' | 'history'>('tracker');
  const [, startTransition] = useTransition();
  const [saving, setSaving] = useState<string | null>(null);

  // Load care pack content on mount
  useEffect(() => {
    if (!childId || isNaN(childId)) return;
    getCarePackContent(childId).then((res) => {
      if (!res.ok) {
        setError(res.error === 'auth' ? 'Please log in again.' : 'Daily tracker is not available for this child yet.');
        setLoading(false);
        return;
      }
      setChild(res.child);
      setTracker(res.content!.tracker);
      setLoading(false);
    });
  }, [childId]);

  // Load checks whenever weekKey changes
  const loadChecks = useCallback((wk: string) => {
    if (!childId || isNaN(childId)) return;
    getTrackerChecks(childId, wk).then((res) => {
      if (res.ok) setChecks(res.checks || {});
    });
  }, [childId]);

  useEffect(() => { loadChecks(weekKey); }, [weekKey, loadChecks]);

  // Load history when history tab is opened
  useEffect(() => {
    if (activeTab !== 'history' || !childId) return;
    getTrackerHistory(childId).then((res) => {
      if (res.ok) setHistory(res.history || []);
    });
  }, [activeTab, childId]);

  const handleToggle = (habitIdx: number, dayIdx: number) => {
    const key = `${habitIdx}-${dayIdx}`;
    const newVal = !checks[key];

    // Optimistic UI update
    setChecks((prev) => ({ ...prev, [key]: newVal }));
    setSaving(key);

    startTransition(async () => {
      const res = await toggleTrackerCheck(childId, weekKey, habitIdx, dayIdx, newVal);
      if (!res.ok) {
        // Revert on error
        setChecks((prev) => ({ ...prev, [key]: !newVal }));
      }
      setSaving(null);
    });
  };

  const navigateWeek = (delta: number) => {
    setWeekKey((wk) => addWeeks(wk, delta));
  };

  // ── Computed ─────────────────────────────────────────────────────────────

  const totalCells = (tracker?.daily_habits?.length || 0) * 7;
  const checkedCount = Object.values(checks).filter(Boolean).length;
  const completionPct = totalCells > 0 ? Math.round((checkedCount / totalCells) * 100) : 0;
  const headers = tracker ? dayHeaders(weekKey) : [];
  const isCurrent = isCurrentWeek(weekKey);

  // ── Render ────────────────────────────────────────────────────────────────

  if (loading) {
    return (
      <div style={{ maxWidth: 860, margin: '0 auto', padding: '60px 0', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16 }}>
        <Loader2 size={36} style={{ color: '#059669', animation: 'spin 1s linear infinite' }} />
        <p style={{ color: '#94a3b8', fontSize: 14 }}>Loading tracker…</p>
        <style>{`@keyframes spin { from { transform:rotate(0deg) } to { transform:rotate(360deg) } }`}</style>
      </div>
    );
  }

  if (error || !tracker) {
    return (
      <div style={{ maxWidth: 860, margin: '0 auto', padding: '40px 16px' }}>
        <p style={{ color: '#ef4444', fontSize: 14 }}>{error || 'Tracker content not available.'}</p>
        <Link href="/dashboard" style={{ color: '#6366f1', fontSize: 13, fontWeight: 600 }}>← Back to Dashboard</Link>
      </div>
    );
  }

  return (
    <div style={{ maxWidth: 860, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 24, padding: '0 0 40px' }}>
      <style>{`@keyframes spin { from { transform:rotate(0deg) } to { transform:rotate(360deg) } }`}</style>

      {/* Back link */}
      <Link href={`/child/${childId}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, color: '#6366f1', textDecoration: 'none' }}>
        <ArrowLeft size={15} /> Back to {child?.name}&apos;s Profile
      </Link>

      {/* Hero banner */}
      <div style={{ background: 'linear-gradient(135deg, #059669 0%, #0d9488 100%)', borderRadius: 24, padding: '28px 32px', color: '#fff', boxShadow: '0 12px 40px rgba(5,150,105,0.3)' }}>
        <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '0.1em', textTransform: 'uppercase', opacity: 0.85, marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
          <BarChart2 size={13} /> Daily Tracker
        </div>
        <h1 style={{ margin: '0 0 10px', fontSize: 26, fontWeight: 900, letterSpacing: '-0.02em' }}>{child?.name}&apos;s Daily Tracker</h1>
        <p style={{ margin: 0, fontSize: 14, lineHeight: 1.7, opacity: 0.92, maxWidth: 620 }}>{tracker.intro}</p>

        {/* Weekly progress bar */}
        <div style={{ marginTop: 18, display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ flex: 1, height: 8, background: 'rgba(255,255,255,0.25)', borderRadius: 100, overflow: 'hidden' }}>
            <div style={{ height: '100%', width: `${completionPct}%`, background: '#fff', borderRadius: 100, transition: 'width 0.4s ease' }} />
          </div>
          <span style={{ fontSize: 13, fontWeight: 800, color: '#fff', whiteSpace: 'nowrap' }}>
            {checkedCount}/{totalCells} this week
          </span>
        </div>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 0, borderRadius: 14, overflow: 'hidden', border: '1px solid #e2e8f0', background: '#f8fafc' }}>
        {([
          ['tracker', 'Weekly Checklist', Calendar],
          ['history', 'Progress History', TrendingUp],
        ] as const).map(([key, lbl, Icon]) => (
          <button
            key={key}
            onClick={() => setActiveTab(key)}
            style={{
              flex: 1, padding: '11px 16px', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700,
              display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6,
              background: activeTab === key ? '#059669' : 'transparent',
              color: activeTab === key ? '#fff' : '#64748b',
              transition: 'all 0.2s',
            }}
          >
            <Icon size={15} /> {lbl}
          </button>
        ))}
      </div>

      {/* ── TRACKER TAB ── */}
      {activeTab === 'tracker' && (
        <>
          {/* Week navigator */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', background: '#fff', border: '1px solid #f1f5f9', borderRadius: 16, padding: '12px 16px', boxShadow: '0 2px 8px rgba(0,0,0,0.04)' }}>
            <button onClick={() => navigateWeek(-1)} style={{ background: '#f1f5f9', border: 'none', borderRadius: 8, padding: '6px 10px', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4, fontSize: 13, fontWeight: 600, color: '#475569' }}>
              <ChevronLeft size={16} /> Prev
            </button>
            <div style={{ textAlign: 'center' }}>
              <div style={{ fontSize: 13, fontWeight: 800, color: '#1e293b' }}>
                {isCurrent ? '📅 Current Week' : weekLabel(weekKey)}
              </div>
              <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>
                {isCurrent ? weekLabel(weekKey) : weekKey}
              </div>
            </div>
            <button
              onClick={() => navigateWeek(1)}
              disabled={isCurrent}
              style={{ background: isCurrent ? '#f8fafc' : '#f1f5f9', border: 'none', borderRadius: 8, padding: '6px 10px', cursor: isCurrent ? 'not-allowed' : 'pointer', display: 'flex', alignItems: 'center', gap: 4, fontSize: 13, fontWeight: 600, color: isCurrent ? '#cbd5e1' : '#475569' }}
            >
              Next <ChevronRight size={16} />
            </button>
          </div>

          {/* Habit checklist grid */}
          <div style={{ background: '#fff', border: '1px solid #f1f5f9', borderRadius: 20, padding: '8px', boxShadow: '0 2px 12px rgba(0,0,0,0.04)', overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 580 }}>
              <thead>
                <tr>
                  <th style={{ textAlign: 'left', padding: '12px 16px', fontSize: 11, fontWeight: 800, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Daily Habit
                  </th>
                  {headers.map((h, i) => (
                    <th key={i} style={{ padding: '12px 8px', textAlign: 'center', minWidth: 56 }}>
                      <div style={{ fontSize: 12, fontWeight: 800, color: h.today ? '#059669' : '#64748b' }}>{h.abbr}</div>
                      <div style={{ fontSize: 10, color: h.today ? '#059669' : '#94a3b8', fontWeight: h.today ? 700 : 400 }}>{h.date}</div>
                      {h.today && <div style={{ width: 4, height: 4, borderRadius: '50%', background: '#059669', margin: '3px auto 0' }} />}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {tracker.daily_habits?.map((h: any, hi: number) => {
                  const rowChecked = [0,1,2,3,4,5,6].filter((di) => checks[`${hi}-${di}`]).length;
                  return (
                    <tr key={hi} style={{ borderTop: '1px solid #f1f5f9' }}>
                      <td style={{ padding: '14px 16px' }}>
                        <div style={{ fontSize: 13.5, fontWeight: 700, color: '#1e293b', lineHeight: 1.3 }}>{h.habit}</div>
                        <div style={{ fontSize: 11.5, color: '#94a3b8', marginTop: 2 }}>{h.why}</div>
                        {rowChecked > 0 && (
                          <div style={{ marginTop: 4, fontSize: 11, color: '#059669', fontWeight: 700 }}>
                            ✓ {rowChecked}/7 days
                          </div>
                        )}
                      </td>
                      {[0,1,2,3,4,5,6].map((di) => {
                        const key = `${hi}-${di}`;
                        const isChecked = !!checks[key];
                        const isSaving = saving === key;
                        const dayHdr = headers[di];
                        const isFuture = isCurrent && di > todayDayIdx();

                        return (
                          <td key={di} style={{ padding: '14px 8px', textAlign: 'center' }}>
                            <button
                              onClick={() => !isFuture && handleToggle(hi, di)}
                              disabled={isFuture || isSaving}
                              title={isFuture ? 'This day has not arrived yet' : isChecked ? 'Mark as not done' : 'Mark as done'}
                              style={{
                                width: 34, height: 34, borderRadius: 10, border: 'none',
                                cursor: isFuture ? 'not-allowed' : 'pointer',
                                background: isChecked ? '#059669' : isFuture ? '#f8fafc' : '#f1f5f9',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                margin: '0 auto', transition: 'all 0.15s ease',
                                transform: isSaving ? 'scale(0.85)' : isChecked ? 'scale(1.05)' : 'scale(1)',
                                outline: dayHdr?.today && !isChecked && !isFuture ? '2px solid #059669' : 'none',
                                outlineOffset: 2,
                                boxShadow: isChecked ? '0 2px 8px rgba(5,150,105,0.3)' : 'none',
                              }}
                            >
                              {isSaving ? (
                                <Loader2 size={14} style={{ color: '#94a3b8', animation: 'spin 0.6s linear infinite' }} />
                              ) : isChecked ? (
                                <CheckCircle2 size={18} color="#fff" />
                              ) : (
                                <Circle size={18} color={isFuture ? '#e2e8f0' : '#94a3b8'} />
                              )}
                            </button>
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <p style={{ margin: '-8px 4px 0', fontSize: 12, color: '#94a3b8' }}>
            Tick the boxes with {child?.name} each day. Progress is saved automatically. Future days are locked until they arrive.
          </p>

          {/* Weekly milestones */}
          {tracker.weekly_milestones?.length > 0 && (
            <section>
              <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 18, fontWeight: 900, color: '#1e293b', margin: '0 0 14px' }}>
                <Flag size={19} color="#059669" /> Weekly Milestones
              </h2>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {tracker.weekly_milestones.map((m: string, i: number) => (
                  <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: 14, background: '#fff', border: '1px solid #f1f5f9', borderRadius: 16, padding: '14px 18px' }}>
                    <span style={{ width: 32, height: 32, borderRadius: 10, background: '#ecfdf5', color: '#059669', fontWeight: 900, fontSize: 13, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                      {i + 1}
                    </span>
                    <span style={{ fontSize: 13.5, color: '#475569', lineHeight: 1.5 }}>{m}</span>
                  </div>
                ))}
              </div>
            </section>
          )}
        </>
      )}

      {/* ── HISTORY TAB ── */}
      {activeTab === 'history' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <h2 style={{ fontSize: 18, fontWeight: 900, color: '#1e293b', margin: 0 }}>📊 Progress History</h2>
          {history.length === 0 ? (
            <div style={{ background: '#fff', border: '1px solid #f1f5f9', borderRadius: 20, padding: '40px 24px', textAlign: 'center' }}>
              <p style={{ color: '#94a3b8', fontSize: 14, margin: 0 }}>
                No tracked weeks yet. Start ticking habits in the Weekly Checklist tab!
              </p>
            </div>
          ) : (
            history.map((wk) => {
              const pct = Math.round((wk.checked / Math.max(wk.total, 1)) * 100);
              return (
                <div
                  key={wk.weekKey}
                  onClick={() => { setWeekKey(wk.weekKey); setActiveTab('tracker'); }}
                  style={{ background: '#fff', border: '1px solid #f1f5f9', borderRadius: 16, padding: '16px 20px', boxShadow: '0 2px 8px rgba(0,0,0,0.03)', cursor: 'pointer' }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 10, flexWrap: 'wrap', gap: 4 }}>
                    <div>
                      <div style={{ fontSize: 14, fontWeight: 800, color: '#1e293b' }}>{wk.label}</div>
                      <div style={{ fontSize: 11, color: '#94a3b8' }}>
                        {wk.weekKey}{isCurrentWeek(wk.weekKey) ? ' · Current week' : ''}
                      </div>
                    </div>
                    <span style={{
                      background: pct >= 70 ? '#ecfdf5' : pct >= 40 ? '#fefce8' : '#fef2f2',
                      color: pct >= 70 ? '#059669' : pct >= 40 ? '#ca8a04' : '#ef4444',
                      fontWeight: 800, fontSize: 13, padding: '4px 10px', borderRadius: 999,
                    }}>
                      {pct}%
                    </span>
                  </div>
                  <div style={{ height: 8, background: '#f1f5f9', borderRadius: 100, overflow: 'hidden' }}>
                    <div style={{
                      height: '100%', width: `${Math.min(pct, 100)}%`, borderRadius: 100, transition: 'width 0.4s',
                      background: pct >= 70 ? '#059669' : pct >= 40 ? '#eab308' : '#f87171',
                    }} />
                  </div>
                  <div style={{ marginTop: 6, fontSize: 12, color: '#64748b' }}>
                    {wk.checked} habits checked — click to view this week
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}

      {/* Navigation footer */}
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
