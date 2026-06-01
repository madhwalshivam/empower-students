import React from 'react';
import { FileText, CheckCircle, AlertCircle } from 'lucide-react';

export const metadata = {
  title: 'Terms and Conditions — Empower Students',
  description: 'Understand the terms, guidelines, and conditions of using Empower Students platforms.',
};

export default function TermsPage() {
  return (
    <div className="max-w-3xl mx-auto space-y-8 py-4 sm:py-8">
      {/* Header */}
      <div className="text-center space-y-2">
        <div className="inline-flex p-3 bg-indigo-50 dark:bg-slate-800 rounded-2xl text-indigo-650 mb-2">
          <FileText size={32} />
        </div>
        <h1 className="heading-fun text-3xl sm:text-4xl font-extrabold text-slate-800 dark:text-slate-100">
          Terms and Conditions
        </h1>
        <p className="text-xs text-slate-400">
          Last Updated: May 31, 2026
        </p>
      </div>

      {/* Terms Content */}
      <div className="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-8 space-y-6 text-sm text-slate-650 dark:text-slate-350 leading-relaxed shadow-sm">
        <p>
          Welcome to <strong>Empower Students</strong>. These terms and conditions outline the rules and regulations for the use of Empower Students’ Website, located at empowerstudents.in.
        </p>

        <p>
          By accessing this website, we assume you accept these terms and conditions. Do not continue to use Empower Students if you do not agree to take all of the terms and conditions stated on this page.
        </p>

        <div className="border-l-4 border-amber-500 bg-amber-50 dark:bg-slate-900 p-4 rounded-r-xl flex gap-3">
          <AlertCircle size={20} className="text-amber-600 shrink-0 mt-0.5" />
          <div className="space-y-1">
            <span className="font-bold text-amber-700 block text-xs">Medical Disclaimer & Clinical Limit</span>
            <p className="text-xs text-slate-500 leading-relaxed">
              Our digital evaluations are designed to identify cognitive, linguistic, and developmental milestones. They do not constitute formal medical or psychiatric diagnostic advice. Always consult with a registered pediatrician or child psychologist for official diagnoses.
            </p>
          </div>
        </div>

        <hr className="border-t border-slate-100 dark:border-slate-800" />

        {/* Section 1 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <CheckCircle size={16} className="text-indigo-600" /> 1. User Registration & Security
          </h2>
          <p>
            To perform child screenings, parents must log in using their registered mobile number. You are responsible for safeguarding your login codes (OTP) and ensuring that you submit accurate developmental inputs (such as birth dates) for your child.
          </p>
        </div>

        {/* Section 2 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <CheckCircle size={16} className="text-indigo-600" /> 2. Assessment Credits & Pricing
          </h2>
          <p>
            Access to premium evaluation modules or clinician screenings requires purchase of digital evaluation credits (Wallet Credits). Wallet Credits are deducted upon successfully unlocking or initiating a screening module. Credit costs and module prices are displayed in the wallet interface and subject to change with notice.
          </p>
        </div>

        {/* Section 3 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <CheckCircle size={16} className="text-indigo-600" /> 3. Fair Use & Intellectual Property
          </h2>
          <p>
            All evaluation algorithms, voice-scoring frameworks, layout assets, questions, and content compiled within this software are the exclusive intellectual property of Empower Students. You agree not to scrape, copy, or redistribute screening components or assessment sheets without direct permission.
          </p>
        </div>

        {/* Section 4 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <CheckCircle size={16} className="text-indigo-600" /> 4. Service Availability & Changes
          </h2>
          <p>
            We reserve the right to modify features, scoring weightings, module categories, and consultation hours to maintain clinical relevance. We are not liable for any temporary server downtime or latency issues affecting real-time audio evaluations.
          </p>
        </div>

        {/* Contact */}
        <div className="pt-4 border-t border-slate-100 dark:border-slate-800 text-center text-xs text-slate-400">
          For any questions regarding these terms, please contact our legal representative at support@empowerstudents.in.
        </div>
      </div>
    </div>
  );
}
