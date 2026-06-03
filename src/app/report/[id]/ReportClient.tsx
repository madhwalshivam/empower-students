'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  orderReportViaWalletAction,
  orderReportViaReferralAction,
  requestExpertReportAction,
} from '@/app/actions/reports';
import {
  ArrowLeft,
  Check,
  AlertTriangle,
  FileText,
  Printer,
  Clock,
  MessageSquare,
  Sparkles,
  Award,
  Target,
  TrendingUp,
  ClipboardList,
} from 'lucide-react';

interface ReportClientProps {
  child: any;
  assessments: any[];
  moduleReports: any[];
  expertOrder: any | null;
  referralStats: {
    signups: number;
    completed_evals: number;
    needed: number;
    qualifies_free: boolean;
  };
  parentCredits: number;
  parentName: string;
  parentPhone: string;
}

const MODULE_LABELS: Record<string, string> = {
  health: 'Health Screening',
  mind_power: 'Mind Power',
  behavior: 'Behaviour',
  behaviour: 'Behaviour',
  general_awareness: 'General Awareness',
  special_talent: 'Special Talent',
  math: 'Maths Level',
  maths: 'Maths Level',
  language: 'Language & Reading',
  diet: 'Diet & Nutrition',
  emotions: 'Emotions',
  speech: 'Speech',
};

const labelFor = (k: string) => MODULE_LABELS[k] || (k || '').replace(/_/g, ' ');

const LEVEL_COLORS: Record<string, string> = {
  'above-age': '#059669',
  'on-track': '#4f46e5',
  developing: '#d97706',
  emerging: '#dc2626',
};

const CONCERN_OPTIONS = [
  { value: 'detailed_report', label: 'Full detailed report' },
  { value: 'learning', label: 'Learning difficulty' },
  { value: 'behaviour', label: 'Behaviour / emotional' },
  { value: 'speech', label: 'Speech / language' },
  { value: 'adhd', label: 'ADHD / focus' },
  { value: 'autism', label: 'Autism / developmental' },
  { value: 'not_sure', label: 'Not sure — need guidance' },
];

export default function ReportClient({
  child,
  moduleReports,
  expertOrder,
  referralStats,
  parentCredits,
  parentName,
  parentPhone,
}: ReportClientProps) {
  const [selectedKey, setSelectedKey] = useState<string>(
    moduleReports.length > 0 ? moduleReports[0].module : 'expert'
  );
  const [loading, setLoading] = useState(false);
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState('');

  // Expert form state
  const [form, setForm] = useState({
    parentName: parentName || '',
    phone: parentPhone || '',
    concern: 'detailed_report',
    preferredTime: '',
    message: '',
  });
  const [submitted, setSubmitted] = useState(false);

  const handleOrderWallet = async () => {
    setLoading(true); setMsg(''); setErr('');
    try {
      const res = await orderReportViaWalletAction(child.id);
      if (res.ok) setMsg(res.message || 'Success'); else setErr(res.error || 'Failed to complete order.');
    } catch (e: any) { setErr(e.message || 'Network error.'); } finally { setLoading(false); }
  };

  const handleOrderReferral = async () => {
    setLoading(true); setMsg(''); setErr('');
    try {
      const res = await orderReportViaReferralAction(child.id);
      if (res.ok) setMsg(res.message || 'Success'); else setErr(res.error || 'Failed to complete order.');
    } catch (e: any) { setErr(e.message || 'Network error.'); } finally { setLoading(false); }
  };

  const handleSubmitRequest = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true); setMsg(''); setErr('');
    try {
      const res = await requestExpertReportAction({
        childId: child.id,
        parentName: form.parentName,
        phone: form.phone,
        concern: form.concern,
        preferredTime: form.preferredTime,
        message: form.message,
      });
      if (res.ok) { setSubmitted(true); setMsg(res.message || 'Request received!'); }
      else setErr(res.error || 'Failed to submit request.');
    } catch (e: any) { setErr(e.message || 'Network error.'); } finally { setLoading(false); }
  };

  const selectedReport = moduleReports.find((r) => r.module === selectedKey);

  const card: React.CSSProperties = { background: '#fff', border: '1px solid #eef0f4', borderRadius: 18, boxShadow: '0 2px 14px rgba(15,23,42,0.04)' };
  const inputStyle: React.CSSProperties = { width: '100%', boxSizing: 'border-box', border: '1px solid #d8dce4', borderRadius: 10, padding: '10px 12px', fontSize: 14, color: '#1e293b', outline: 'none', background: '#fff' };

  return (
    <div style={{ maxWidth: 1080, margin: '0 auto', padding: '32px 16px', display: 'flex', flexDirection: 'column', gap: 22 }}>
      {/* Navigation */}
      <Link href={`/child/${child.id}`} style={{ fontSize: 14, color: '#4f46e5', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
        <ArrowLeft size={16} /> Back to {child.name}&apos;s Profile
      </Link>

      <div>
        <h1 style={{ fontSize: 30, fontWeight: 900, color: '#1e293b', margin: 0, letterSpacing: '-0.02em' }}>Reports</h1>
        <p style={{ color: '#64748b', fontSize: 14, margin: '6px 0 0' }}>
          {child.name} &bull; {child.gender || 'Child'} &bull; {child.dob}
        </p>
      </div>

      {msg && (
        <div style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', fontSize: 14, borderRadius: 12, padding: 14, display: 'flex', alignItems: 'center', gap: 8 }}>
          <Check size={16} color="#059669" /> <span>{msg}</span>
        </div>
      )}
      {err && (
        <div style={{ background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', fontSize: 14, borderRadius: 12, padding: 14, display: 'flex', alignItems: 'center', gap: 8 }}>
          <AlertTriangle size={16} color="#dc2626" /> <span>{err}</span>
        </div>
      )}

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 22, alignItems: 'flex-start' }}>
        {/* ── LEFT: Reports sidebar ── */}
        <aside style={{ ...card, flex: '1 1 240px', maxWidth: 300, padding: 14 }}>
          <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94a3b8', padding: '4px 8px 10px' }}>
            Module Reports
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {moduleReports.length === 0 && (
              <p style={{ fontSize: 12, color: '#94a3b8', padding: '4px 8px', margin: 0 }}>
                No module reports yet. Finish a module to see its report here.
              </p>
            )}
            {moduleReports.map((r) => {
              const active = selectedKey === r.module;
              return (
                <button
                  key={r.module}
                  onClick={() => { setSelectedKey(r.module); }}
                  style={{
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8,
                    textAlign: 'left', cursor: 'pointer', border: 'none', borderRadius: 12, padding: '10px 12px',
                    background: active ? 'linear-gradient(135deg, #4f46e5, #7c3aed)' : 'transparent',
                    color: active ? '#fff' : '#1e293b',
                  }}
                >
                  <span style={{ fontWeight: 700, fontSize: 13.5 }}>{labelFor(r.module)}</span>
                  <span style={{
                    fontSize: 11, fontWeight: 800, padding: '2px 8px', borderRadius: 99,
                    background: active ? 'rgba(255,255,255,0.22)' : '#eef2ff',
                    color: active ? '#fff' : '#4f46e5',
                  }}>
                    {Math.round(r.score ?? r.report?.overall_score ?? 0)}
                  </span>
                </button>
              );
            })}
          </div>

          <div style={{ borderTop: '1px solid #f1f5f9', margin: '12px 0', height: 1, background: '#f1f5f9' }} />

          <button
            onClick={() => setSelectedKey('expert')}
            style={{
              width: '100%', display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer',
              border: selectedKey === 'expert' ? '1px solid transparent' : '1px solid #e0e7ff',
              borderRadius: 12, padding: '11px 12px',
              background: selectedKey === 'expert' ? 'linear-gradient(135deg, #4f46e5, #7c3aed)' : '#f5f3ff',
              color: selectedKey === 'expert' ? '#fff' : '#4338ca', fontWeight: 800, fontSize: 13,
            }}
          >
            <Sparkles size={16} /> Detailed Expert Report
          </button>
        </aside>

        {/* ── RIGHT: Main panel ── */}
        <div style={{ flex: '600 1 380px', minWidth: 0 }}>
          {selectedKey === 'expert' ? (
            /* ---------- EXPERT REPORT ---------- */
            expertOrder?.status === 'delivered' ? (
              <div style={{ ...card, padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <FileText size={30} color="#4f46e5" />
                  <div>
                    <h3 style={{ margin: 0, fontWeight: 800, color: '#1e293b' }}>Expert Report</h3>
                    <p style={{ margin: 0, fontSize: 11, color: '#94a3b8' }}>Delivered by Dr. Jha&apos;s Team</p>
                  </div>
                </div>
                <div style={{ background: '#fafafe', border: '1px solid #eef0f4', borderRadius: 14, padding: 16, fontSize: 14, color: '#334155', lineHeight: 1.7, whiteSpace: 'pre-wrap' }}>
                  {expertOrder.report_text}
                </div>
                <button onClick={() => window.print()} style={{ background: '#4f46e5', color: '#fff', fontWeight: 700, padding: '11px', borderRadius: 12, fontSize: 13, border: 'none', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <Printer size={14} /> Print Report / Save PDF
                </button>
              </div>
            ) : expertOrder?.status === 'pending' ? (
              <div style={{ ...card, padding: 28, textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
                <Clock size={40} color="#4f46e5" />
                <h3 style={{ margin: 0, fontWeight: 800, color: '#3730a3' }}>Report In Progress</h3>
                <p style={{ fontSize: 13, color: '#64748b', lineHeight: 1.6, margin: 0, maxWidth: 360 }}>
                  Our specialists are studying {child.name}&apos;s evaluation data. We&apos;ll deliver your detailed report and contact you within 24 hours.
                </p>
                <a href="https://wa.me/919311883132" target="_blank" rel="noopener noreferrer" style={{ width: '100%', maxWidth: 280, background: '#16a34a', color: '#fff', fontWeight: 700, padding: '11px', borderRadius: 12, fontSize: 13, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <MessageSquare size={14} /> WhatsApp Support
                </a>
              </div>
            ) : submitted ? (
              <div style={{ ...card, padding: 32, textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
                <div style={{ width: 56, height: 56, borderRadius: '50%', background: '#ecfdf5', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <Check size={28} color="#059669" />
                </div>
                <h3 style={{ margin: 0, fontWeight: 800, color: '#065f46' }}>Request Submitted!</h3>
                <p style={{ fontSize: 13, color: '#64748b', lineHeight: 1.6, margin: 0, maxWidth: 380 }}>
                  Our clinical team will review {child.name}&apos;s results and contact you within 24 hours on the number you provided.
                </p>
              </div>
            ) : (
              <div style={{ ...card, padding: 26 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
                  <Sparkles size={24} color="#4f46e5" />
                  <h3 style={{ margin: 0, fontWeight: 900, color: '#1e293b', fontSize: 19 }}>Get a Detailed Expert Report</h3>
                </div>
                <p style={{ fontSize: 13, color: '#64748b', lineHeight: 1.6, margin: '0 0 18px' }}>
                  Dr. Jha&apos;s clinical team will study {child.name}&apos;s performance across all completed modules and provide structured insights, a personalised plan, and a callback. Fill the form and we&apos;ll reach out.
                </p>

                <form onSubmit={handleSubmitRequest} style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
                    <div>
                      <label style={{ fontSize: 12, fontWeight: 700, color: '#475569', display: 'block', marginBottom: 5 }}>Your name</label>
                      <input style={inputStyle} value={form.parentName} onChange={(e) => setForm({ ...form, parentName: e.target.value })} placeholder="Parent name" required />
                    </div>
                    <div>
                      <label style={{ fontSize: 12, fontWeight: 700, color: '#475569', display: 'block', marginBottom: 5 }}>Phone / WhatsApp</label>
                      <input style={inputStyle} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="10-digit number" inputMode="tel" required />
                    </div>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
                    <div>
                      <label style={{ fontSize: 12, fontWeight: 700, color: '#475569', display: 'block', marginBottom: 5 }}>Main concern</label>
                      <select style={inputStyle} value={form.concern} onChange={(e) => setForm({ ...form, concern: e.target.value })}>
                        {CONCERN_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                      </select>
                    </div>
                    <div>
                      <label style={{ fontSize: 12, fontWeight: 700, color: '#475569', display: 'block', marginBottom: 5 }}>Preferred call time</label>
                      <input style={inputStyle} value={form.preferredTime} onChange={(e) => setForm({ ...form, preferredTime: e.target.value })} placeholder="e.g. Weekdays after 6 PM" />
                    </div>
                  </div>

                  <div>
                    <label style={{ fontSize: 12, fontWeight: 700, color: '#475569', display: 'block', marginBottom: 5 }}>Anything you&apos;d like the expert to focus on? (optional)</label>
                    <textarea style={{ ...inputStyle, resize: 'vertical', minHeight: 80 }} value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} placeholder="Share any specific worries or observations..." />
                  </div>

                  <button type="submit" disabled={loading} style={{ background: 'linear-gradient(135deg, #4f46e5, #6366f1)', color: '#fff', fontWeight: 800, padding: '13px', borderRadius: 12, fontSize: 14, border: 'none', cursor: loading ? 'default' : 'pointer', opacity: loading ? 0.6 : 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                    {loading ? 'Submitting…' : 'Request Expert Report'}
                  </button>
                </form>

                {/* Alternative instant unlock options */}
                <div style={{ borderTop: '1px solid #f1f5f9', marginTop: 18, paddingTop: 16 }}>
                  <p style={{ fontSize: 12, color: '#94a3b8', margin: '0 0 10px' }}>Prefer to unlock instantly?</p>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    <button disabled={loading} onClick={handleOrderWallet} style={{ flex: '1 1 160px', background: '#eef2ff', color: '#4338ca', fontWeight: 700, padding: '10px', borderRadius: 10, fontSize: 12, border: 'none', cursor: 'pointer' }}>
                      Use Wallet (1000 cr) · You have {parentCredits}
                    </button>
                    <button disabled={loading || !referralStats.qualifies_free} onClick={handleOrderReferral} style={{ flex: '1 1 160px', background: '#fff', color: '#4f46e5', fontWeight: 700, padding: '10px', borderRadius: 10, fontSize: 12, border: '1.5px solid #c7d2fe', cursor: referralStats.qualifies_free ? 'pointer' : 'default', opacity: referralStats.qualifies_free ? 1 : 0.55 }}>
                      Redeem Referral ({referralStats.completed_evals}/{referralStats.needed})
                    </button>
                  </div>
                </div>
              </div>
            )
          ) : selectedReport ? (
            /* ---------- MODULE REPORT DETAIL ---------- */
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {/* Score banner */}
              <div style={{ background: 'linear-gradient(135deg, #4f46e5, #7c3aed)', borderRadius: 20, padding: 24, color: '#fff', boxShadow: '0 10px 30px rgba(99,102,241,0.30)' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
                  <div>
                    <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.85, display: 'flex', alignItems: 'center', gap: 6 }}>
                      <Award size={14} /> {labelFor(selectedReport.module)}
                    </div>
                    <div style={{ fontSize: 13, opacity: 0.8, marginTop: 4 }}>
                      Completed {selectedReport.completed_at ? new Date(selectedReport.completed_at).toLocaleDateString() : ''}
                    </div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontSize: 44, fontWeight: 900, lineHeight: 1 }}>{Math.round(selectedReport.report?.overall_score ?? selectedReport.score ?? 0)}</div>
                    {selectedReport.report?.level && (
                      <span style={{ display: 'inline-block', marginTop: 6, background: 'rgba(255,255,255,0.2)', padding: '3px 12px', borderRadius: 99, fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                        {selectedReport.report.level}
                      </span>
                    )}
                  </div>
                </div>
              </div>

              {/* Summary */}
              {selectedReport.report?.summary && (
                <div style={{ ...card, padding: 22 }}>
                  <h3 style={{ margin: '0 0 10px', fontSize: 16, fontWeight: 800, color: '#1e293b', display: 'flex', alignItems: 'center', gap: 8 }}>
                    <FileText size={17} color="#4f46e5" /> Summary
                  </h3>
                  <p style={{ fontSize: 14, color: '#475569', lineHeight: 1.7, margin: 0, whiteSpace: 'pre-wrap' }}>{selectedReport.report.summary}</p>
                </div>
              )}

              {/* Recommended focus */}
              {selectedReport.report?.recommended_focus && (
                <div style={{ background: '#f5f3ff', border: '1px solid #e0e7ff', borderRadius: 16, padding: 20 }}>
                  <div style={{ fontSize: 11, fontWeight: 800, color: '#4f46e5', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 6, display: 'flex', alignItems: 'center', gap: 6 }}>
                    <Target size={14} /> Focus This Week
                  </div>
                  <p style={{ fontSize: 14, fontWeight: 600, color: '#3730a3', margin: 0, lineHeight: 1.6 }}>{selectedReport.report.recommended_focus}</p>
                </div>
              )}

              {/* Strengths */}
              {Array.isArray(selectedReport.report?.strengths) && selectedReport.report.strengths.length > 0 && (
                <div style={{ ...card, padding: 22 }}>
                  <h3 style={{ margin: '0 0 12px', fontSize: 16, fontWeight: 800, color: '#1e293b', display: 'flex', alignItems: 'center', gap: 8 }}>
                    <TrendingUp size={17} color="#059669" /> Strengths
                  </h3>
                  <ul style={{ margin: 0, padding: 0, listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 8 }}>
                    {selectedReport.report.strengths.map((s: string, i: number) => (
                      <li key={i} style={{ fontSize: 14, color: '#475569', display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                        <Check size={16} color="#059669" style={{ marginTop: 2, flexShrink: 0 }} /> <span>{s}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* Gaps / areas to nurture */}
              {Array.isArray(selectedReport.report?.gaps) && selectedReport.report.gaps.length > 0 && (
                <div style={{ ...card, padding: 22 }}>
                  <h3 style={{ margin: '0 0 12px', fontSize: 16, fontWeight: 800, color: '#1e293b', display: 'flex', alignItems: 'center', gap: 8 }}>
                    <ClipboardList size={17} color="#d97706" /> Areas to Work On
                  </h3>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                    {selectedReport.report.gaps.map((g: any, i: number) => (
                      <div key={i} style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 12, padding: 14 }}>
                        <div style={{ fontWeight: 800, fontSize: 13.5, color: '#92400e' }}>{g.label || labelFor(g.axis || '')}</div>
                        {g.description && <div style={{ fontSize: 13, color: '#78716c', marginTop: 4, lineHeight: 1.6 }}>{g.description}</div>}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* CTA to expert report */}
              <button
                onClick={() => setSelectedKey('expert')}
                style={{ ...card, padding: 18, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, cursor: 'pointer', textAlign: 'left', background: '#f5f3ff', border: '1px solid #e0e7ff' }}
              >
                <span style={{ display: 'flex', alignItems: 'center', gap: 10, color: '#4338ca', fontWeight: 700, fontSize: 14 }}>
                  <Sparkles size={18} /> Want a deeper expert review? Request a detailed report
                </span>
                <ArrowLeft size={16} color="#4338ca" style={{ transform: 'rotate(180deg)' }} />
              </button>
            </div>
          ) : (
            <div style={{ ...card, padding: 32, textAlign: 'center', color: '#64748b', fontSize: 14 }}>
              No completed module reports yet. Finish an evaluation module to see its report here.
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
