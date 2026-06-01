import React from 'react';
import Link from 'next/link';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import HomeLoginForm from '@/components/HomeLoginForm';
import ClinicianImage from '@/components/ClinicianImage';
import {
  Mic,
  FileText,
  Award,
  Languages,
  Gift,
  Heart,
  BookOpen,
  MessageSquare,
  HelpCircle,
  Phone,
  ArrowRight,
  Smile
} from 'lucide-react';

export const revalidate = 60; // Cache page for 60 seconds

export default async function HomePage() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();

  // Load specialists from Supabase database using admin client to bypass RLS
  const supabaseAdmin = createAdminClient();
  const { data: specialists } = await supabaseAdmin
    .from('specialists')
    .select('*')
    .eq('active', true)
    .order('order_no', { ascending: true });

  const activeSpecialists = specialists || [];

  return (
    <div className="space-y-16">
      {/* Hero Banner */}
      <section className="relative rounded-3xl overflow-hidden bg-indigo-50 dark:bg-slate-900 p-8 sm:p-12 border border-indigo-100/60 dark:border-slate-800">
        <div className="grid grid-cols-1 md:grid-cols-12 gap-8 items-center">
          <div className="md:col-span-7 space-y-6">
            <span className="inline-flex items-center gap-1 bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-400 font-bold text-xs px-4 py-1.5 rounded-full shadow-sm tracking-wider uppercase">
              <Smile size={14} /> AI + Clinicians · Parent-First
            </span>
            <h1 className="heading-fun text-4xl sm:text-5xl lg:text-6xl text-slate-800 dark:text-slate-100 leading-tight">
              Understand your child. <br />
              <span className="text-indigo-600">Strengthen yourself.</span>
            </h1>
            <p className="text-slate-600 dark:text-slate-300 text-base sm:text-lg max-w-xl leading-relaxed">
              A warm, AI-guided journey for Indian families. Take a free 2-min check first. Then go deeper —
              voice-led parent reflection, adaptive cognitive evaluations for your child, and clinician-led screening if you need it.
            </p>
            <div className="flex flex-wrap gap-2 text-xs font-semibold text-slate-600 dark:text-slate-400">
              <span className="inline-flex items-center gap-1 bg-white/85 dark:bg-slate-800/80 px-3.5 py-2 rounded-full border border-slate-100 dark:border-slate-700/50">
                <Mic size={14} className="text-indigo-600" /> Voice-first
              </span>
              <span className="inline-flex items-center gap-1 bg-white/85 dark:bg-slate-800/80 px-3.5 py-2 rounded-full border border-slate-100 dark:border-slate-700/50">
                <FileText size={14} className="text-indigo-600" /> Real PDF reports
              </span>
              <span className="inline-flex items-center gap-1 bg-white/85 dark:bg-slate-800/80 px-3.5 py-2 rounded-full border border-slate-100 dark:border-slate-700/50">
                <Award size={14} className="text-indigo-600" /> AIIMS-trained
              </span>
              <span className="inline-flex items-center gap-1 bg-white/85 dark:bg-slate-800/80 px-3.5 py-2 rounded-full border border-slate-100 dark:border-slate-700/50">
                <Languages size={14} className="text-indigo-600" /> Hindi · English · Hinglish
              </span>
            </div>
          </div>
          <div className="md:col-span-5 flex justify-center">
            {user ? (() => {
              const role = user.user_metadata?.role || 'parent';
              const dashboardUrl = role === 'admin' 
                ? '/admin/dashboard' 
                : (role === 'partner' ? '/partner/dashboard' : '/dashboard');
              
              return (
                <div className="card-premium max-w-sm w-full bg-white dark:bg-slate-900 border border-indigo-100 text-center py-10 px-8 shadow-xl flex flex-col items-center">
                  <Smile size={48} className="text-indigo-600 mb-4" />
                  <h3 className="heading-fun text-2xl font-bold mb-2">Welcome Back!</h3>
                  <p className="text-sm text-slate-500 mb-6">
                    You are logged into your account. Head over to the dashboard to view your tools, reports, or portal.
                  </p>
                  <Link href={dashboardUrl} className="w-full btn-premium btn-premium-primary">
                    Go to Dashboard <ArrowRight size={16} className="ml-2" />
                  </Link>
                </div>
              );
            })() : (
              <HomeLoginForm />
            )}
          </div>
        </div>
      </section>

      {/* Free Perk Banner */}
      <div className="bg-indigo-50/50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 rounded-3xl p-5 sm:p-6 flex items-start gap-4">
        <Gift size={28} className="text-indigo-650 flex-shrink-0 mt-0.5" />
        <div>
          <h4 className="font-bold text-indigo-900 dark:text-indigo-300 mb-0.5">FREE: 2-min parenting self-check.</h4>
          <p className="text-sm text-slate-500">
            Take it the moment you sign in. See where you stand across 4 areas — instant report, no payment.
          </p>
        </div>
      </div>

      {/* What We Do Grid */}
      <section className="space-y-6">
        <div className="text-center space-y-2">
          <h2 className="heading-fun text-3xl sm:text-4xl text-slate-800 dark:text-slate-100">What we do</h2>
          <p className="text-slate-500 max-w-md mx-auto text-sm">
            Four offerings. Use one or all. Start with whichever feels most pressing.
          </p>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          {/* Card 1 */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-md hover:shadow-lg transition-shadow flex flex-col">
            <Heart size={36} className="text-indigo-600 mb-4" />
            <span className="inline-block text-[11px] font-bold uppercase tracking-wider text-indigo-700 bg-indigo-50 dark:bg-indigo-950/50 dark:text-indigo-400 px-3 py-1 rounded-full self-start mb-3">
              ₹1,000 · voice
            </span>
            <h3 className="heading-fun text-xl font-bold mb-2">Parent Evaluation</h3>
            <p className="text-slate-500 text-sm flex-grow leading-relaxed">
              A 15-min AI-guided voice reflection on your home, your child, your own state. Detailed PDF across 9 life areas + callback from our psychologist.
            </p>
            <Link href="/parent-reflect" className="mt-6 w-full btn-premium btn-premium-outline py-2.5 text-xs text-center border-2 border-indigo-100 text-indigo-700 dark:text-indigo-400 hover:bg-indigo-50">
              Start parent eval &rarr;
            </Link>
          </div>

          {/* Card 2 */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-md hover:shadow-lg transition-shadow flex flex-col">
            <BookOpen size={36} className="text-indigo-650 mb-4" />
            <span className="inline-block text-[11px] font-bold uppercase tracking-wider text-indigo-700 bg-indigo-50 dark:bg-indigo-950/50 dark:text-indigo-400 px-3 py-1 rounded-full self-start mb-3">
              Free baseline · ₹999 course
            </span>
            <h3 className="heading-fun text-xl font-bold mb-2">Child Learning Hub</h3>
            <p className="text-slate-500 text-sm flex-grow leading-relaxed">
              10 adaptive evaluations for your child — Speech, Mind Power, Behaviour, GK, Maths, Language and more. AI calibrates to age (4–14).
            </p>
            <Link href="/child-learn" className="mt-6 w-full btn-premium btn-premium-outline py-2.5 text-xs text-center border-2 border-indigo-100 text-indigo-700 dark:text-indigo-400 hover:bg-indigo-50">
              Open child hub &rarr;
            </Link>
          </div>

          {/* Card 3 */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-md hover:shadow-lg transition-shadow flex flex-col">
            <Smile size={36} className="text-indigo-700 mb-4" />
            <span className="inline-block text-[11px] font-bold uppercase tracking-wider text-indigo-700 bg-indigo-50 dark:bg-indigo-950/50 dark:text-indigo-400 px-3 py-1 rounded-full self-start mb-3">
              Clinician-led
            </span>
            <h3 className="heading-fun text-xl font-bold mb-2">ISAA Autism Screening</h3>
            <p className="text-slate-500 text-sm flex-grow leading-relaxed">
              Indian Scale for Assessment of Autism — the NIMHANS-validated 40-item tool. Conducted by a trained clinician, scored and shared securely.
            </p>
            <a href="https://wa.me/919311883132?text=Hi,%20I%20would%20like%20to%20know%20more%20about%20ISAA%20assessment." target="_blank" rel="noopener noreferrer" className="mt-6 w-full btn-premium btn-premium-outline py-2.5 text-xs text-center border-2 border-indigo-100 text-indigo-700 dark:text-indigo-400 hover:bg-indigo-50 flex items-center justify-center gap-1.5">
              <MessageSquare size={14} /> Ask on WhatsApp
            </a>
          </div>

          {/* Card 4 */}
          <div className="bg-slate-50 dark:bg-slate-900 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-3xl p-6 flex flex-col overflow-hidden">
            <div className="flex items-center justify-between mb-4">
              <HelpCircle size={36} className="text-slate-400 opacity-50" />
              <span className="bg-slate-100 dark:bg-slate-800 text-slate-650 dark:text-slate-400 font-bold text-[9px] px-2 py-0.5 rounded-full tracking-wider uppercase">
                Coming soon
              </span>
            </div>
            <span className="inline-block text-[11px] font-bold uppercase tracking-wider text-slate-500 bg-slate-100 dark:bg-slate-800 px-3 py-1 rounded-full self-start mb-3">
              Ages 15-25
            </span>
            <h3 className="heading-fun text-xl font-bold mb-2 text-slate-600 dark:text-slate-400">Young Adults</h3>
            <p className="text-slate-400 dark:text-slate-500 text-sm flex-grow leading-relaxed">
              For students 15-25 — career interests, study habits, identity, emotional resilience. Adaptive AI evaluations tailored to teens.
            </p>
            <button disabled className="mt-6 w-full py-2.5 text-xs text-center border-2 border-slate-100 dark:border-slate-800 text-slate-400 rounded-xl cursor-not-allowed">
              Launching soon
            </button>
          </div>
        </div>
      </section>

      {/* Specialists Section */}
      {activeSpecialists.length > 0 && (
        <section className="space-y-8">
          <div className="text-center space-y-2">
            <h2 className="heading-fun text-3xl sm:text-4xl text-slate-800 dark:text-slate-100">
              Trusted by parents · backed by clinicians
            </h2>
            <p className="text-slate-500 max-w-md mx-auto text-sm">
              Our panel of doctors, therapists and counsellors
            </p>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4 max-w-5xl mx-auto">
            {activeSpecialists.map((sp) => {
              const initial = sp.name ? sp.name.substring(0, 1) : '?';
              return (
                <div key={sp.id} className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 rounded-2xl p-4 text-center hover:-translate-y-1 transition-transform shadow-sm">
                  <ClinicianImage
                    src={sp.photo ? (sp.photo.startsWith('http') || sp.photo.startsWith('/') || sp.photo.startsWith('data:') ? sp.photo : `/assets/images/${sp.photo}`) : ''}
                    alt={sp.name}
                    className="w-20 h-20 rounded-full object-cover mx-auto mb-3 border-4 border-indigo-50"
                    initial={initial}
                    fallbackClassName="w-20 h-20 rounded-full bg-indigo-50 text-indigo-600 font-bold text-2xl flex items-center justify-center mx-auto mb-3 shadow-inner"
                  />
                  <h4 className="font-bold text-xs text-slate-800 dark:text-slate-200 line-clamp-1">{sp.name}</h4>
                  <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5 line-clamp-1">{sp.role}</p>
                </div>
              );
            })}
          </div>
          <div className="text-center text-sm text-slate-500 flex flex-wrap justify-center items-center gap-3">
            <span>Need to speak to a real person?</span>
            <a href="https://wa.me/919311883132" className="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400 font-bold underline" target="_blank" rel="noopener noreferrer">
              <MessageSquare size={14} /> WhatsApp +91 9311883132
            </a>
            <span>·</span>
            <a href="tel:+919311883132" className="inline-flex items-center gap-1 text-indigo-650 font-bold underline">
              <Phone size={14} /> Call +91 9311883132
            </a>
          </div>
        </section>
      )}

      {/* FAQ Section */}
      <section className="max-w-3xl mx-auto space-y-6">
        <div className="text-center space-y-2">
          <h2 className="heading-fun text-3xl text-slate-800 dark:text-slate-100">Common questions</h2>
          <p className="text-slate-500 text-sm">If you're new, start here.</p>
        </div>
        <div className="space-y-3">
          <details className="group bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 [&_summary::-webkit-details-marker]:hidden">
            <summary className="flex items-center justify-between font-bold text-slate-800 dark:text-slate-200 cursor-pointer text-sm sm:text-base list-none">
              <span>Is the 2-min parenting check really free?</span>
              <span className="transition group-open:rotate-180 text-indigo-500">▼</span>
            </summary>
            <p className="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mt-3">
              Yes. After signing in you get an unlimited free parenting self-check across 4 areas. Real report, no payment, no commitment. Take it as often as you want to see how you change over time.
            </p>
          </details>

          <details className="group bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 [&_summary::-webkit-details-marker]:hidden">
            <summary className="flex items-center justify-between font-bold text-slate-800 dark:text-slate-200 cursor-pointer text-sm sm:text-base list-none">
              <span>What ages does Child Learning Hub support?</span>
              <span className="transition group-open:rotate-180 text-indigo-500">▼</span>
            </summary>
            <p className="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mt-3">
              4 to 14 years currently. AI calibrates question difficulty based on your child's exact age. Under-8s use parent-guided mode; 8+ self-led. Ages 15-25 launching soon.
            </p>
          </details>

          <details className="group bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 [&_summary::-webkit-details-marker]:hidden">
            <summary className="flex items-center justify-between font-bold text-slate-800 dark:text-slate-200 cursor-pointer text-sm sm:text-base list-none">
              <span>Is the Parent Evaluation also useful if my child is fine?</span>
              <span className="transition group-open:rotate-180 text-indigo-500">▼</span>
            </summary>
            <p className="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mt-3">
              Absolutely. The Parent Evaluation covers 9 areas — couple alignment, finances, your own well-being, family stress, hopes for your child — not just child concerns. Most parents tell us they wished they'd done it earlier.
            </p>
          </details>

          <details className="group bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 [&_summary::-webkit-details-marker]:hidden">
            <summary className="flex items-center justify-between font-bold text-slate-800 dark:text-slate-200 cursor-pointer text-sm sm:text-base list-none">
              <span>Is my data safe?</span>
              <span className="transition group-open:rotate-180 text-indigo-500">▼</span>
            </summary>
            <p className="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mt-3">
              Yes. Your conversations, child's data, and reports are never shared. Reports are accessible only by you (via your WhatsApp number) and the clinician you choose to share with.
            </p>
          </details>

          <details className="group bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 [&_summary::-webkit-details-marker]:hidden">
            <summary className="flex items-center justify-between font-bold text-slate-800 dark:text-slate-200 cursor-pointer text-sm sm:text-base list-none">
              <span>What is ISAA?</span>
              <span className="transition group-open:rotate-180 text-indigo-500">▼</span>
            </summary>
            <p className="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mt-3">
              Indian Scale for Assessment of Autism — a 40-item NIMHANS-validated tool. It's conducted by a trained clinician, scored, and the result is shared with you via a secure PIN-protected link. Used for India's disability certification process.
            </p>
          </details>
        </div>
      </section>
    </div>
  );
}
