'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { orderReportViaWalletAction, orderReportViaReferralAction } from '@/app/actions/reports';
import {
  ArrowLeft,
  Check,
  AlertTriangle,
  FileText,
  Printer,
  Clock,
  MessageSquare,
  Sparkles,
  ChevronRight
} from 'lucide-react';

interface ReportClientProps {
  child: any;
  assessments: any[];
  expertOrder: any | null;
  referralStats: {
    signups: number;
    completed_evals: number;
    needed: number;
    qualifies_free: boolean;
  };
  parentCredits: number;
}

export default function ReportClient({
  child,
  assessments,
  expertOrder,
  referralStats,
  parentCredits,
}: ReportClientProps) {
  const [loading, setLoading] = useState(false);
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState('');

  const handleOrderWallet = async () => {
    setLoading(true);
    setMsg('');
    setErr('');
    try {
      const res = await orderReportViaWalletAction(child.id);
      if (res.ok) {
        setMsg(res.message || 'Success');
      } else {
        setErr(res.error || 'Failed to complete order.');
      }
    } catch (e: any) {
      setErr(e.message || 'Network error.');
    } finally {
      setLoading(false);
    }
  };

  const handleOrderReferral = async () => {
    setLoading(true);
    setMsg('');
    setErr('');
    try {
      const res = await orderReportViaReferralAction(child.id);
      if (res.ok) {
        setMsg(res.message || 'Success');
      } else {
        setErr(res.error || 'Failed to complete order.');
      }
    } catch (e: any) {
      setErr(e.message || 'Network error.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="max-w-4xl mx-auto py-8 px-4 space-y-8 animate-fade-in">
      {/* Navigation */}
      <Link href={`/child/${child.id}`} className="text-sm text-indigo-650 hover:underline flex items-center gap-1.5">
        <ArrowLeft size={16} /> Back to {child.name}'s Profile
      </Link>

      <div className="space-y-1">
        <h1 className="heading-fun text-3xl font-extrabold text-slate-800 dark:text-slate-100">
          Comprehensive Report
        </h1>
        <p className="text-slate-500 text-sm">
          {child.name} &bull; {child.gender || 'Child'} &bull; {child.dob}
        </p>
      </div>

      {msg && (
        <div className="bg-emerald-50 border border-emerald-250 text-emerald-800 text-sm rounded-xl p-4 flex items-center gap-2">
          <Check className="text-emerald-600" size={16} />
          <span>{msg}</span>
        </div>
      )}

      {err && (
        <div className="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-xl p-4 flex items-center gap-2">
          <AlertTriangle className="text-rose-605" size={16} />
          <span>{err}</span>
        </div>
      )}

      {assessments.length === 0 ? (
        <div className="bg-indigo-50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 rounded-3xl p-6 text-slate-700 dark:text-slate-400 text-center">
          No completed assessments found. Finish at least one evaluation module on the child dashboard, then check here.
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          {/* Assessment Modules Overview */}
          <div className="md:col-span-2 space-y-6">
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 rounded-3xl p-6 shadow-sm">
              <h2 className="heading-fun text-xl font-bold text-slate-800 dark:text-slate-100 mb-4">
                Completed Modules ({assessments.length})
              </h2>
              <div className="divide-y divide-slate-50 dark:divide-slate-800/60">
                {assessments.map((r) => {
                  let flagsList = [];
                  try {
                    flagsList = r.flags ? JSON.parse(r.flags) : [];
                  } catch {
                    // skip
                  }

                  return (
                    <div key={r.id} className="py-4 flex justify-between items-center">
                      <div>
                        <span className="font-bold text-slate-700 dark:text-slate-300 capitalize">
                          {r.module.replace('mod_', '').replace('_', ' ')}
                        </span>
                        <div className="text-xs text-slate-400 mt-0.5">
                          Completed on {new Date(r.completed_at || r.created_at).toLocaleDateString()}
                        </div>
                      </div>
                      <div className="text-right flex items-center gap-2">
                        <span className="text-sm font-bold text-slate-800 dark:text-slate-100 bg-slate-50 dark:bg-slate-800 px-3 py-1 rounded-full border border-slate-100 dark:border-slate-700/50">
                          Score: {r.score}
                        </span>
                        {flagsList.length > 0 && (
                          <span className="text-[10px] text-indigo-700 font-bold bg-indigo-50 px-2 py-0.5 rounded flex items-center gap-1">
                            <AlertTriangle size={10} /> {flagsList.length} flags
                          </span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>

          {/* Expert Report Order/Delivery Column */}
          <div className="md:col-span-1">
            {expertOrder?.status === 'delivered' ? (
              <div className="bg-indigo-50/50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
                <div className="flex items-center gap-2">
                  <FileText className="text-indigo-650" size={32} />
                  <div>
                    <h3 className="font-bold text-slate-800 dark:text-slate-200">Expert Report</h3>
                    <p className="text-[10px] text-slate-500">Delivered by Dr. Jha's Team</p>
                  </div>
                </div>
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-indigo-100/50 dark:border-slate-800 text-xs sm:text-sm text-slate-700 dark:text-slate-350 leading-relaxed whitespace-pre-wrap">
                  {expertOrder.report_text}
                </div>
                <button
                  onClick={() => window.print()}
                  className="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-xl text-xs transition-colors flex items-center justify-center gap-1 border-none cursor-pointer"
                >
                  <Printer size={14} /> Print Report / Save PDF
                </button>
              </div>
            ) : expertOrder?.status === 'pending' ? (
              <div className="bg-indigo-50/50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-850 rounded-3xl p-6 shadow-sm space-y-4 text-center flex flex-col items-center">
                <Clock className="text-indigo-650 animate-pulse" size={40} />
                <h3 className="font-bold text-indigo-900 dark:text-indigo-300">Report In Progress</h3>
                <p className="text-xs text-slate-500 leading-relaxed">
                  Our specialists are studying {child.name}'s evaluation data. We will deliver your detailed report and contact you within 24 hours.
                </p>
                <a
                  href="https://wa.me/919311883132"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-xl text-xs block text-center transition-colors flex items-center justify-center gap-1"
                >
                  <MessageSquare size={14} /> WhatsApp Support
                </a>
              </div>
            ) : (
              <div className="bg-indigo-50/50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-5 text-center flex flex-col items-center">
                <Sparkles className="text-indigo-650" size={36} />
                <h3 className="font-bold text-indigo-900 dark:text-indigo-300">Get Detailed Expert Report</h3>
                <p className="text-xs text-slate-500 leading-relaxed">
                  Dr. Jha's clinical team will evaluate {child.name}'s performance across all modules and provide structured diagnoses, personalized plans, and a 24-hr call.
                </p>

                <div className="space-y-3 pt-2 w-full">
                  <button
                    disabled={loading}
                    onClick={handleOrderWallet}
                    className="w-full bg-indigo-600 text-white font-bold py-3 rounded-xl text-xs hover:bg-indigo-700 transition-all disabled:opacity-50 cursor-pointer border-none"
                  >
                    Order via Wallet (1000 credits)
                  </button>

                  <div className="text-[10px] text-slate-400">
                    Your credits: <strong>{parentCredits} cr</strong>
                  </div>

                  <div className="border-t border-indigo-100 dark:border-slate-800 pt-4 space-y-2">
                    <p className="text-[10px] text-indigo-705 dark:text-indigo-400 font-semibold">
                      Or refer 2 parents to get it for FREE.
                    </p>
                    <button
                      disabled={loading || !referralStats.qualifies_free}
                      onClick={handleOrderReferral}
                      className="w-full bg-white dark:bg-slate-900 border-2 border-indigo-200 dark:border-indigo-800 hover:border-indigo-500 text-indigo-700 dark:text-indigo-400 font-bold py-2.5 rounded-xl text-xs disabled:opacity-50 cursor-pointer"
                    >
                      Redeem Referral Reward
                    </button>
                    <div className="text-[9px] text-slate-400">
                      Referred invites: {referralStats.completed_evals} / {referralStats.needed} completed
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
