import React from 'react';
import {
  Sparkles,
  Target,
  BarChart3,
  Mic,
  HeartHandshake,
  Globe,
  CreditCard,
  UserCheck,
  Phone,
  MessageSquare,
  Mail,
  Quote
} from 'lucide-react';

export default function AboutPage() {
  return (
    <div className="max-w-4xl mx-auto py-8 px-4 space-y-12 animate-fade-in">
      {/* Hero Banner */}
      <section className="text-center rounded-3xl bg-indigo-50/30 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-8 sm:p-12 space-y-4">
        <span className="inline-flex items-center gap-1 bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700/60 text-xs font-bold text-indigo-600 dark:text-indigo-400 px-4 py-1.5 rounded-full shadow-sm tracking-wider uppercase">
          <Sparkles size={12} /> In Collaboration with Global Autism Learning School
        </span>
        <h1 className="heading-fun text-4xl sm:text-5xl font-extrabold text-slate-800 dark:text-slate-100 leading-tight">
          A 360° view of your child — <br />
          <span className="text-indigo-600 bg-indigo-50 dark:bg-slate-800 px-2 py-0.5 rounded-lg">
            taken early, updated through the years.
          </span>
        </h1>
        <p className="text-slate-500 max-w-2xl mx-auto text-base sm:text-lg leading-relaxed">
          EmpowerStudents.in brings together a multi-disciplinary specialist panel and modern AI tools to help you understand your child — their strengths, struggles, and how to nurture them at home.
        </p>
      </section>

      {/* Why We Built This Quote */}
      <section className="space-y-6">
        <div className="text-center">
          <span className="italic text-indigo-600 text-lg sm:text-xl font-semibold">Why we built this</span>
          <h2 className="heading-fun text-2xl sm:text-3xl text-slate-800 dark:text-slate-100 mt-1">
            A whole picture, not just one snapshot.
          </h2>
        </div>
        <div className="bg-white dark:bg-slate-900 border-l-4 border-indigo-650 border border-slate-100 dark:border-slate-800 p-6 sm:p-8 rounded-r-3xl shadow-sm text-slate-650 dark:text-slate-350 italic text-base leading-relaxed space-y-4">
          <p>
            Most children are evaluated only when something is already going wrong — and even then, only on a single dimension at a time.
          </p>
          <p>
            We believe a 360° picture, taken early and updated through the years, is the kindest gift we can give a child and their parent. Whether your concern is speech, behaviour, learning, or simply &quot;is my child on track?&quot;, our platform helps you see clearly — without panic, without pressure.
          </p>
        </div>
      </section>

      {/* Differentiators Grid */}
      <section className="space-y-6">
        <div className="text-center">
          <span className="italic text-indigo-650 text-lg font-semibold">What makes us different</span>
          <h2 className="heading-fun text-2xl sm:text-3xl text-slate-800 dark:text-slate-100 mt-1">
            Six things parents value most
          </h2>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 hover:shadow-md transition-shadow">
            <Target className="text-indigo-600 mb-3" size={32} />
            <h3 className="font-bold text-slate-800 dark:text-slate-100 mb-2">Age-calibrated</h3>
            <p className="text-slate-500 text-xs sm:text-sm leading-relaxed">
              Every module adapts to your child's age. For under-2s we focus on subtle behaviour markers so early concerns are not missed.
            </p>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 hover:shadow-md transition-shadow">
            <BarChart3 className="text-indigo-600 mb-3" size={32} />
            <h3 className="font-bold text-slate-800 dark:text-slate-100 mb-2">Adaptive testing</h3>
            <p className="text-slate-500 text-xs sm:text-sm leading-relaxed">
              Math, general awareness and language quizzes start very easy and step up only when answers are accurate — finding their comfortable base.
            </p>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 hover:shadow-md transition-shadow">
            <Mic className="text-indigo-600 mb-3" size={32} />
            <h3 className="font-bold text-slate-800 dark:text-slate-100 mb-2">Speech that listens</h3>
            <p className="text-slate-500 text-xs sm:text-sm leading-relaxed">
              Child reads short sentences and answers questions. AI evaluates speech fluency, articulation, and voice stuttering.
            </p>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 hover:shadow-md transition-shadow">
            <HeartHandshake className="text-indigo-600 mb-3" size={32} />
            <h3 className="font-bold text-slate-800 dark:text-slate-100 mb-2">Parent-first, judgment-free</h3>
            <p className="text-slate-500 text-xs sm:text-sm leading-relaxed">
              A gentle measure of how you are currently understanding and nurturing your child — with concrete actionable tips. No parent grading.
            </p>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 hover:shadow-md transition-shadow">
            <Globe className="text-indigo-600 mb-3" size={32} />
            <h3 className="font-bold text-slate-800 dark:text-slate-100 mb-2">Bilingual — EN / हिंदी</h3>
            <p className="text-slate-500 text-xs sm:text-sm leading-relaxed">
              Reports, plans and AI consults are all available in Hindi too, so the whole family (including grandparents) can follow along.
            </p>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 hover:shadow-md transition-shadow">
            <CreditCard className="text-indigo-600 mb-3" size={32} />
            <h3 className="font-bold text-slate-800 dark:text-slate-100 mb-2">Pay only for what you use</h3>
            <p className="text-slate-500 text-xs sm:text-sm leading-relaxed">
              No subscription trap. Pick individual modules from ₹199, or bundle for discounts. First baseline check is free.
            </p>
          </div>
        </div>
      </section>

      {/* Founder Profile */}
      <section className="space-y-6">
        <div className="text-center">
          <span className="italic text-indigo-650 text-lg font-semibold">Our story</span>
          <h2 className="heading-fun text-2xl sm:text-3xl text-slate-800 dark:text-slate-100 mt-1">
            Built by a clinician, for parents.
          </h2>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-6 sm:p-8 rounded-3xl shadow-sm flex flex-col md:flex-row items-center gap-8">
          <div className="w-40 h-40 rounded-full bg-indigo-50 border border-indigo-100 text-indigo-600 flex items-center justify-center shadow-inner shrink-0">
            <UserCheck size={80} />
          </div>
          <div className="space-y-4">
            <div>
              <h3 className="heading-fun text-2xl font-bold text-slate-800 dark:text-slate-100">
                Dr. P. K. Jha
              </h3>
              <p className="text-xs uppercase font-bold text-indigo-600 tracking-wider">
                Founder · Director · Neurosurgeon
              </p>
            </div>
            <p className="text-slate-650 dark:text-slate-400 text-sm leading-relaxed">
              AIIMS-trained neurosurgeon with 30+ years of clinical experience across neurology, child development, and family care. After decades of seeing parents arrive too late — when problems had already grown — Dr. Jha founded EmpowerStudents.in to bring early, holistic, affordable assessment to every Indian family.
            </p>
            <p className="text-slate-650 dark:text-slate-400 text-sm leading-relaxed">
              The platform combines what he has learned in clinic with the patience and consistency only AI can offer at scale — so every parent gets the same depth of attention.
            </p>
            <div className="flex flex-wrap gap-2 pt-2">
              <span className="bg-indigo-50 dark:bg-slate-800 text-indigo-700 dark:text-indigo-400 text-xs px-3 py-1 rounded-full font-bold">M.Ch (AIIMS)</span>
              <span className="bg-indigo-50 dark:bg-slate-800 text-indigo-700 dark:text-indigo-400 text-xs px-3 py-1 rounded-full font-bold">30+ Years Experience</span>
              <span className="bg-indigo-50 dark:bg-slate-800 text-indigo-700 dark:text-indigo-400 text-xs px-3 py-1 rounded-full font-bold">Neuro Care India</span>
              <span className="bg-indigo-50 dark:bg-slate-800 text-indigo-700 dark:text-indigo-400 text-xs px-3 py-1 rounded-full font-bold">Greater Noida</span>
            </div>
          </div>
        </div>
      </section>

      {/* Contact Section */}
      <section className="space-y-6">
        <div className="text-center">
          <span className="italic text-indigo-650 text-lg font-semibold">Get in touch</span>
          <h2 className="heading-fun text-2xl sm:text-3xl text-slate-800 dark:text-slate-100 mt-1">
            We're a message away.
          </h2>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-6 rounded-3xl shadow-sm text-center">
          <p className="text-sm text-slate-500 max-w-md mx-auto mb-6">
            For clinic partnerships, parent inquiries, or to book a free evaluation — reach us on any of the channels below.
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <a href="tel:+919311883132" className="flex items-center gap-3 p-4 bg-slate-50 dark:bg-slate-800/50 hover:bg-indigo-50 dark:hover:bg-slate-800 rounded-2xl transition-colors">
              <Phone className="text-indigo-600 flex-shrink-0" size={24} />
              <div className="text-left">
                <p className="text-[10px] font-bold text-slate-400 uppercase">Call Us</p>
                <p className="text-sm font-bold text-indigo-700 dark:text-indigo-400">+91 9311883132</p>
              </div>
            </a>

            <a href="https://wa.me/919311883132" target="_blank" rel="noopener noreferrer" className="flex items-center gap-3 p-4 bg-slate-50 dark:bg-slate-800/50 hover:bg-indigo-50 dark:hover:bg-slate-800 rounded-2xl transition-colors">
              <MessageSquare className="text-indigo-600 flex-shrink-0" size={24} />
              <div className="text-left">
                <p className="text-[10px] font-bold text-slate-400 uppercase">WhatsApp</p>
                <p className="text-sm font-bold text-indigo-750 dark:text-indigo-400">+91 9311883132</p>
              </div>
            </a>

            <a href="mailto:support@empowerstudents.in" className="flex items-center gap-3 p-4 bg-slate-50 dark:bg-slate-800/50 hover:bg-indigo-50 dark:hover:bg-slate-800 rounded-2xl transition-colors">
              <Mail className="text-indigo-600 flex-shrink-0" size={24} />
              <div className="text-left">
                <p className="text-[10px] font-bold text-slate-400 uppercase">Email</p>
                <p className="text-sm font-bold text-indigo-700 dark:text-indigo-400">support@empowerstudents.in</p>
              </div>
            </a>
          </div>
        </div>
      </section>
    </div>
  );
}
