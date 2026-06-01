'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { partnerAddFamilyAction } from '@/app/actions/partner';
import { 
  AlertTriangle, 
  Check, 
  ArrowLeft, 
  User, 
  Phone, 
  Mail, 
  Baby, 
  Calendar, 
  Smile, 
  BookOpen, 
  Sparkles, 
  AlertCircle 
} from 'lucide-react';

export default function PartnerAddFamilyPage() {
  const router = useRouter();
  const [parentName, setParentName] = useState('');
  const [parentPhone, setParentPhone] = useState('');
  const [parentEmail, setParentEmail] = useState('');

  const [childName, setChildName] = useState('');
  const [childDob, setChildDob] = useState('');
  const [childGender, setChildGender] = useState('');
  const [childSchool, setChildSchool] = useState('');
  const [childClass, setChildClass] = useState('');
  const [childMotherTongue, setChildMotherTongue] = useState('');
  const [childDiagnosis, setChildDiagnosis] = useState('');

  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setSuccess('');
    setLoading(true);

    try {
      const res = await partnerAddFamilyAction({
        parentName,
        parentPhone,
        parentEmail,
        childName,
        childDob,
        childGender,
        childSchool,
        childClass,
        childMotherTongue,
        childDiagnosis,
      });

      if (res.ok) {
        setSuccess(res.message || 'Family successfully registered!');
        setTimeout(() => {
          router.push('/partner/dashboard');
          router.refresh();
        }, 1500);
      } else {
        setError(res.error || 'Failed to register family. Please check details.');
      }
    } catch (err: any) {
      setError(err.message || 'An unexpected error occurred.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="max-w-3xl mx-auto px-4 py-8 animate-fade-in">
      {/* Back to Dashboard Link */}
      <div className="mb-6">
        <Link 
          href="/partner/dashboard" 
          className="text-slate-500 hover:text-indigo-600 font-bold text-sm flex items-center gap-1.5 transition-all no-underline"
        >
          <ArrowLeft size={16} /> Back to Dashboard
        </Link>
      </div>

      <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <div className="mb-6">
          <h1 className="heading-fun text-3xl font-bold text-slate-850 dark:text-slate-100">
            Add New Family
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Register a parent and their child details. The child will be attributed to your partner account.
          </p>
        </div>

        {error && (
          <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900 text-rose-800 dark:text-rose-400 text-sm rounded-2xl p-4 mb-6 flex items-start gap-2.5">
            <AlertTriangle size={18} className="mt-0.5 flex-shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {success && (
          <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900 text-emerald-800 dark:text-emerald-400 text-sm rounded-2xl p-4 mb-6 flex items-start gap-2.5">
            <Check size={18} className="mt-0.5 flex-shrink-0" />
            <span>{success}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-8">
          {/* Section: Parent Details */}
          <fieldset className="border-0 p-0 m-0 space-y-4">
            <legend className="text-base font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-2 w-full">
              <User size={18} className="text-indigo-650" />
              <span>Parent Details</span>
            </legend>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Parent Full Name *
                </label>
                <input
                  type="text"
                  required
                  value={parentName}
                  onChange={(e) => setParentName(e.target.value)}
                  placeholder="e.g. Ramesh Kumar"
                  className="input-premium"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  WhatsApp Number *
                </label>
                <input
                  type="tel"
                  required
                  value={parentPhone}
                  onChange={(e) => setParentPhone(e.target.value)}
                  placeholder="e.g. 9876543210"
                  className="input-premium"
                />
              </div>

              <div className="md:col-span-2">
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Email Address
                </label>
                <input
                  type="email"
                  value={parentEmail}
                  onChange={(e) => setParentEmail(e.target.value)}
                  placeholder="parent@example.com (optional)"
                  className="input-premium"
                />
                <p className="text-[10px] text-slate-400 mt-1">
                  We will use this email or WhatsApp to notify the parent about credentials.
                </p>
              </div>
            </div>
          </fieldset>

          {/* Section: Child Details */}
          <fieldset className="border-0 p-0 m-0 space-y-4">
            <legend className="text-base font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-2 w-full">
              <Baby size={18} className="text-indigo-650" />
              <span>Child Details</span>
            </legend>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="md:col-span-2">
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Child Full Name *
                </label>
                <input
                  type="text"
                  required
                  value={childName}
                  onChange={(e) => setChildName(e.target.value)}
                  placeholder="e.g. Aarav Kumar"
                  className="input-premium"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Date of Birth *
                </label>
                <input
                  type="date"
                  required
                  value={childDob}
                  onChange={(e) => setChildDob(e.target.value)}
                  className="input-premium"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Gender
                </label>
                <select
                  value={childGender}
                  onChange={(e) => setChildGender(e.target.value)}
                  className="input-premium"
                >
                  <option value="">Select Gender</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="other">Other</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  School Name
                </label>
                <input
                  type="text"
                  value={childSchool}
                  onChange={(e) => setChildSchool(e.target.value)}
                  placeholder="e.g. DPS Delhi"
                  className="input-premium"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Class / Grade
                </label>
                <input
                  type="text"
                  value={childClass}
                  onChange={(e) => setChildClass(e.target.value)}
                  placeholder="e.g. 3rd Class"
                  className="input-premium"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Mother Tongue
                </label>
                <input
                  type="text"
                  value={childMotherTongue}
                  onChange={(e) => setChildMotherTongue(e.target.value)}
                  placeholder="e.g. Hindi, English"
                  className="input-premium"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                  Existing Diagnosis (Suspected / Confirmed)
                </label>
                <input
                  type="text"
                  value={childDiagnosis}
                  onChange={(e) => setChildDiagnosis(e.target.value)}
                  placeholder="e.g. Speech Delay (Suspected)"
                  className="input-premium"
                />
              </div>
            </div>
          </fieldset>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
            <Link
              href="/partner/dashboard"
              className="text-sm font-bold text-slate-500 hover:text-slate-700 decoration-none no-underline"
            >
              Cancel
            </Link>
            <button
              type="submit"
              disabled={loading}
              className="btn-premium btn-premium-primary px-8 py-3 text-white font-bold rounded-2xl shadow-lg transition-all disabled:opacity-50 border-0 cursor-pointer"
            >
              {loading ? 'Registering...' : 'Register Family'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
