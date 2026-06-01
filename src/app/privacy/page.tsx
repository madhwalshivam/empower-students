import React from 'react';
import { Shield, Eye, Lock, FileText } from 'lucide-react';

export const metadata = {
  title: 'Privacy Policy — Empower Students',
  description: 'Learn how we collect, protect, and handle your child’s data and developmental assessments.',
};

export default function PrivacyPage() {
  return (
    <div className="max-w-3xl mx-auto space-y-8 py-4 sm:py-8">
      {/* Header */}
      <div className="text-center space-y-2">
        <div className="inline-flex p-3 bg-indigo-50 dark:bg-slate-800 rounded-2xl text-indigo-650 mb-2">
          <Shield size={32} />
        </div>
        <h1 className="heading-fun text-3xl sm:text-4xl font-extrabold text-slate-800 dark:text-slate-100">
          Privacy Policy
        </h1>
        <p className="text-xs text-slate-400">
          Last Updated: May 31, 2026
        </p>
      </div>

      {/* Policy Content */}
      <div className="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-8 space-y-6 text-sm text-slate-650 dark:text-slate-350 leading-relaxed shadow-sm">
        <p>
          At <strong>Empower Students</strong> (accessible from empowerstudents.in), one of our main priorities is the privacy of our visitors and registered parents. This Privacy Policy document contains types of information that is collected and recorded by Empower Students and how we use it.
        </p>

        <div className="border-l-4 border-indigo-600 bg-indigo-50 dark:bg-slate-900 p-4 rounded-r-xl space-y-1">
          <span className="font-bold text-indigo-700 block">Critical Children’s Data Policy</span>
          <p className="text-xs text-slate-500">
            Because we facilitate child developmental screenings, we implement higher-tier data isolation, secure audio uploads for screening evaluation, and strictly restrict access only to authenticated parents and our designated specialist panel.
          </p>
        </div>

        <hr className="border-t border-slate-100 dark:border-slate-800" />

        {/* Section 1 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <Eye size={16} className="text-indigo-600" /> 1. Information We Collect
          </h2>
          <p>
            We collect information directly from you (the parent) and through the child’s interactions with our adaptive testing modules:
          </p>
          <ul className="list-disc pl-5 space-y-1.5 font-normal">
            <li><strong>Parental Contact Information:</strong> Name, phone number (used for secure WhatsApp OTP login), email address, and wallet credit logs.</li>
            <li><strong>Child Developmental Profiles:</strong> Child name, birth month/year (required to calibrate evaluations accurately to developmental milestones), and gender.</li>
            <li><strong>Evaluation Submissions:</strong> Interactive cognitive responses, audio speech recordings, general awareness inputs, and evaluation outcomes.</li>
            <li><strong>Specialist Consultations:</strong> Notes, diagnostic reports, and medical history shared with clinicians for ISAA Autism Screenings.</li>
          </ul>
        </div>

        {/* Section 2 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <FileText size={16} className="text-indigo-600" /> 2. How We Use Your Information
          </h2>
          <p>
            We use the collected data solely to deliver the service, refine our developmental scoring engines, and coordinate with professional counselors:
          </p>
          <ul className="list-disc pl-5 space-y-1.5 font-normal">
            <li>Calibrate assessment modules and calculate standard progress quotients.</li>
            <li>Provide downloadable PDF reports representing the child’s cognitive developmental status.</li>
            <li>Allow verified developmental specialists to evaluate audio recordings and parent screening responses.</li>
            <li>Authenticate parent access and process secure transaction receipts.</li>
          </ul>
        </div>

        {/* Section 3 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <Lock size={16} className="text-indigo-600" /> 3. Data Protection & Security
          </h2>
          <p>
            We secure your personal credentials and child data via Supabase encrypted databases. We enforce Row Level Security (RLS) policies so that child evaluations and clinician logs are only accessible to the parent who generated them and the clinicians handling their case. Audio recordings and personal documents are locked via encrypted cloud storage buckets.
          </p>
        </div>

        {/* Section 4 */}
        <div className="space-y-3">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <Shield size={16} className="text-indigo-600" /> 4. Parental Consent & Data Deletion
          </h2>
          <p>
            We do not collect any developmental profile inputs without explicit parental registration. You retain the absolute right to view, modify, or permanently delete your parent account and associated children profile logs. To request complete data erasure, contact us at <strong>support@empowerstudents.in</strong>.
          </p>
        </div>

        {/* Contact */}
        <div className="pt-4 border-t border-slate-100 dark:border-slate-800 text-center text-xs text-slate-400">
          If you have any questions or require more information about our Privacy Policy, do not hesitate to contact us at support@empowerstudents.in.
        </div>
      </div>
    </div>
  );
}
