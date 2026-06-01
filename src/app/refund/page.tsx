import React from 'react';
import { CreditCard, RotateCcw, HelpCircle, AlertTriangle } from 'lucide-react';

export const metadata = {
  title: 'Refund Policy — Empower Students',
  description: 'Review our terms regarding digital credit refunds and clinical session cancelations.',
};

export default function RefundPage() {
  return (
    <div className="max-w-3xl mx-auto space-y-8 py-4 sm:py-8">
      {/* Header */}
      <div className="text-center space-y-2">
        <div className="inline-flex p-3 bg-indigo-50 dark:bg-slate-800 rounded-2xl text-indigo-650 mb-2">
          <CreditCard size={32} />
        </div>
        <h1 className="heading-fun text-3xl sm:text-4xl font-extrabold text-slate-800 dark:text-slate-100">
          Refund Policy
        </h1>
        <p className="text-xs text-slate-400">
          Last Updated: May 31, 2026
        </p>
      </div>

      {/* Refund Content */}
      <div className="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-8 space-y-6 text-sm text-slate-650 dark:text-slate-350 leading-relaxed shadow-sm">
        <p>
          At <strong>Empower Students</strong>, we strive to deliver high-quality cognitive evaluations and screening services. Please read our guidelines regarding payments, credits, and cancelation refunds.
        </p>

        <div className="border-l-4 border-indigo-600 bg-indigo-50 dark:bg-slate-900 p-4 rounded-r-xl flex gap-3">
          <AlertTriangle size={20} className="text-indigo-750 shrink-0 mt-0.5" />
          <div className="space-y-1">
            <span className="font-bold text-indigo-850 block text-xs">Digital Services Summary</span>
            <p className="text-xs text-slate-500 leading-relaxed">
              Because our cognitive tests and digital reports are made available instantly upon unlocking, we implement a strict evaluation policy. Please read our specific scenarios below.
            </p>
          </div>
        </div>

        <hr className="border-t border-slate-100 dark:border-slate-800" />

        {/* Section 1 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <RotateCcw size={16} className="text-indigo-600" /> 1. Wallet Credits Purchases
          </h2>
          <p>
            Payments made to load credits into the Parent Wallet are generally non-refundable once credit balances have been partially or fully spent on children evaluation modules. 
          </p>
          <p>
            If you have purchased a wallet pack by mistake and have not spent any credits, you may request a full refund within <strong>48 hours</strong> of the transaction.
          </p>
        </div>

        {/* Section 2 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <RotateCcw size={16} className="text-indigo-600" /> 2. Clinician-Led ISAA Screenings
          </h2>
          <p>
            For booked clinical screenings (such as the ISAA Autism Screening conducted by our specialist panel):
          </p>
          <ul className="list-disc pl-5 space-y-1.5 font-normal">
            <li><strong>Cancelations &gt; 24 hours in advance:</strong> You will receive a 100% refund of the booking credit/amount to your parent wallet.</li>
            <li><strong>Cancelations &lt; 24 hours or No-shows:</strong> No refund will be issued, as the counselor’s schedule is reserved exclusively for your family.</li>
          </ul>
        </div>

        {/* Section 3 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <RotateCcw size={16} className="text-indigo-600" /> 3. Technical Issues
          </h2>
          <p>
            If a network disconnect, voice recording capture error, or database failure interrupts your child’s evaluation module and prevents report generation:
          </p>
          <p>
            Please email us with your registered parent phone number and child name. Upon verification of the system log error, we will immediately credit the module cost back to your Parent Wallet.
          </p>
        </div>

        {/* Section 4 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <HelpCircle size={16} className="text-indigo-600" /> 4. Refund Processing Time
          </h2>
          <p>
            Approved bank refunds are processed back to the original payment source (UPI, Card, NetBanking) via our payment gateway within <strong>5 to 7 working days</strong>.
          </p>
        </div>

        {/* Contact */}
        <div className="pt-4 border-t border-slate-100 dark:border-slate-800 text-center text-xs text-slate-400">
          For billing questions, refund claims, or payment receipts, please email support@empowerstudents.in.
        </div>
      </div>
    </div>
  );
}
