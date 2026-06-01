import React from 'react';
import { createAdminClient } from '@/lib/supabase/admin';
import { Phone, MessageSquare } from 'lucide-react';
import ClinicianImage from '@/components/ClinicianImage';

export const revalidate = 60; // Cache page for 60 seconds

export default async function SpecialistsPage() {
  const supabaseAdmin = createAdminClient();

  const { data: specialists } = await supabaseAdmin
    .from('specialists')
    .select('*')
    .eq('active', true)
    .order('order_no', { ascending: true });

  const activeSpecialists = specialists || [];

  return (
    <div className="max-w-5xl mx-auto py-8 px-4 space-y-12 animate-fade-in">
      {/* Header */}
      <section className="space-y-4">
        <h1 className="heading-fun text-4xl font-extrabold text-slate-800 dark:text-slate-100">
          Our Multi-Disciplinary Panel
        </h1>
        <p className="text-slate-500 max-w-3xl leading-relaxed">
          Every assessment we do is reviewed in the light of these specialties. If your child needs a deeper look, we connect you to the right professional &mdash; in person at our partner clinics or via tele-consultation.
        </p>
      </section>

      {/* Grid */}
      <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        {activeSpecialists.map((sp) => {
          const initial = sp.name ? sp.name.substring(0, 1).toUpperCase() : '?';

          return (
            <article
              key={sp.id}
              className="bg-white dark:bg-slate-900 rounded-3xl overflow-hidden border border-slate-100 dark:border-slate-800/80 shadow-sm flex flex-col justify-between"
            >
              <div>
                {/* Photo Header */}
                <div className="aspect-[4/3] w-full bg-slate-50 dark:bg-slate-955 flex items-center justify-center border-b border-slate-100 dark:border-slate-800/50">
                  <ClinicianImage
                    src={sp.photo ? (sp.photo.startsWith('http') || sp.photo.startsWith('/') || sp.photo.startsWith('data:') ? sp.photo : `/assets/images/${sp.photo}`) : ''}
                    alt={sp.name}
                    className="w-full h-full object-cover"
                    initial={initial}
                    fallbackClassName="text-slate-400 dark:text-slate-650 text-6xl font-bold font-serif"
                  />
                </div>

                {/* Content */}
                <div className="p-6 space-y-3">
                  <div>
                    <span className="text-[10px] font-bold tracking-wider text-indigo-600 dark:text-indigo-400 uppercase">
                      {sp.role}
                    </span>
                    <h2 className="heading-fun text-xl font-bold text-slate-800 dark:text-slate-100 mt-1">
                      {sp.name}
                    </h2>
                    <p className="text-xs text-slate-400 dark:text-slate-500 font-medium">
                      {sp.qualifications}
                    </p>
                  </div>
                  <p className="text-sm text-slate-600 dark:text-slate-400 leading-relaxed pt-2">
                    {sp.bio}
                  </p>
                </div>
              </div>
            </article>
          );
        })}
      </section>

      {/* Referrals Section */}
      <section className="bg-indigo-50/50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 rounded-3xl p-6 text-center">
        <p className="text-sm text-slate-600 dark:text-slate-400 flex flex-wrap items-center justify-center gap-2">
          <span>Want to refer a child or join our panel?</span>
          <a
            href="https://wa.me/919311883132"
            target="_blank"
            rel="noopener noreferrer"
            className="font-bold underline text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 flex items-center gap-1"
          >
            <MessageSquare size={14} /> WhatsApp us
          </a>
          <span>or call</span>
          <a
            href="tel:+919311883132"
            className="font-bold underline text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 flex items-center gap-1"
          >
            <Phone size={14} /> +91 9311883132
          </a>
        </p>
      </section>
    </div>
  );
}
