import React from 'react';
import { redirect } from 'next/navigation';
import Link from 'next/link';
import { headers } from 'next/headers';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import CopyLinkButton from './CopyLinkButton';
import {
  Users,
  Award,
  DollarSign,
  Share2,
  Plus,
  LogOut
} from 'lucide-react';

export default async function PartnerDashboardPage() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();

  if (!user) {
    redirect('/login');
  }

  // Double-check role
  const role = user.user_metadata?.role;
  if (role !== 'partner') {
    redirect('/dashboard');
  }

  // Reads/writes use the service-role client (RLS bypass), scoped to this partner's id.
  const db = createAdminClient();

  // Fetch partner profile
  const { data: partner, error: partnerErr } = await db
    .from('partners')
    .select('*')
    .eq('id', user.id)
    .single();

  if (partnerErr || !partner) {
    console.error('Partner profile fetch error:', partnerErr);
    // If auth role is partner but record is missing, try to self-heal
    const referralCode = `P-${user.id.substring(0, 5).toUpperCase()}`;
    const { data: newPartner } = await db
      .from('partners')
      .insert({
        id: user.id,
        name: user.user_metadata?.name || 'Partner',
        email: user.email,
        phone: user.user_metadata?.phone || '',
        whatsapp: user.user_metadata?.phone || '',
        referral_code: referralCode,
        status: 'active'
      })
      .select('*')
      .single();

    if (newPartner) {
      redirect('/partner/dashboard');
    }

    return (
      <div className="max-w-md mx-auto py-12 text-center">
        <p className="text-red-500 font-bold">Partner profile not found. Please contact support.</p>
      </div>
    );
  }

  // Fetch referred parents count & list
  const { data: referredParentsData } = await db
    .from('parents')
    .select('id, name, whatsapp, email, credits, created_at')
    .eq('partner_id', partner.id)
    .order('created_at', { ascending: false });

  const referredParents = referredParentsData || [];

  // Fetch earnings for this partner
  const { data: payoutsData } = await db
    .from('partner_payouts')
    .select('partner_amount')
    .eq('partner_id', partner.id);

  const payouts = payoutsData || [];

  const totalEarnings = payouts.reduce((sum, p) => sum + (p.partner_amount || 0), 0);

  // Generate Referral Links & Messages — derive origin from the request
  const hdrs = await headers();
  const host = hdrs.get('x-forwarded-host') || hdrs.get('host') || 'localhost:3000';
  const proto = hdrs.get('x-forwarded-proto') || (host.includes('localhost') ? 'http' : 'https');
  const referralLink = `${proto}://${host}/r/${partner.referral_code}`;

  return (
    <div className="max-w-6xl mx-auto px-4 py-8 space-y-8 animate-fade-in">
      {/* Welcome Banner */}
      <section className="bg-gradient-to-r from-indigo-600 to-violet-700 text-white rounded-3xl p-6 sm:p-8 shadow-xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
        <div>
          <span className="bg-white/20 text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider">
            Partner Portal
          </span>
          <h1 className="text-3xl font-extrabold mt-2 tracking-tight">
            Welcome back, {partner.name}!
          </h1>
          <p className="text-indigo-100 text-sm mt-1">
            Track your referrals, manage your clients, and view your revenue share payments.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link
            href="/partner/add-family"
            className="bg-white text-indigo-700 hover:bg-slate-55 shadow-md font-bold px-5 py-3 rounded-2xl text-sm flex items-center gap-1.5 transition-all cursor-pointer border-0"
          >
            <Plus size={16} /> Register Family
          </Link>
          <form action="/api/auth/signout" method="POST">
            <button
              type="submit"
              className="bg-transparent border border-white/30 text-white hover:bg-white/10 font-bold px-4 py-3 rounded-2xl text-sm flex items-center gap-1.5 transition-all cursor-pointer"
            >
              <LogOut size={16} /> Logout
            </button>
          </form>
        </div>
      </section>

      {/* Stats Cards */}
      <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        {/* Stat 1 */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/30 flex items-center justify-center text-indigo-650 dark:text-indigo-400">
            <Users size={24} />
          </div>
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Referred Families</p>
            <p className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-0.5">
              {referredParents?.length || 0}
            </p>
          </div>
        </div>

        {/* Stat 2 */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
            <DollarSign size={24} />
          </div>
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Earnings</p>
            <p className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-0.5">
              ₹{totalEarnings.toLocaleString('en-IN', { maximumFractionDigits: 2 })}
            </p>
          </div>
        </div>

        {/* Stat 3 */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-2xl bg-violet-50 dark:bg-violet-950/30 flex items-center justify-center text-violet-650 dark:text-violet-400">
            <Award size={24} />
          </div>
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Referral Code</p>
            <p className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-0.5 font-mono tracking-wide">
              {partner.referral_code}
            </p>
          </div>
        </div>
      </section>

      {/* Referral Link & Social Sharing */}
      <section className="bg-emerald-50/50 dark:bg-slate-950/20 border-2 border-emerald-200/60 rounded-3xl p-6 sm:p-8 space-y-6">
        <div>
          <h2 className="text-xl font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
            <span>🔗 Your Referral Marketing Tool</span>
          </h2>
          <p className="text-sm text-slate-500 mt-1.5 leading-relaxed">
            Every parent who registers using your link is automatically assigned to you. They immediately receive{' '}
            <strong className="text-emerald-700 dark:text-emerald-400">100 free credits</strong> to try the cognitive and behavioral assessments, and you earn commissions.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">
              Your Referral Link
            </label>
            <div className="flex gap-2">
              <input
                type="text"
                readOnly
                value={referralLink}
                className="flex-1 input-premium font-mono text-xs font-bold bg-slate-50/80 dark:bg-slate-800"
              />
              <CopyLinkButton link={referralLink} />
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">
              WhatsApp Link Share
            </label>
            <a
              href={`https://wa.me/?text=${encodeURIComponent(
                `🌟 *EmpowerStudents.in* \nChild Cognitive & Behavior Assessment \n\nRecommended by *${partner.name}*\n\nGet *100 Free Credits* to assess your child's developmental health instantly!\n\nOpen this link to claim:\n${referralLink}`
              )}`}
              target="_blank"
              rel="noopener noreferrer"
              className="w-full flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-4 rounded-xl text-sm transition-all border-0 decoration-none no-underline cursor-pointer"
            >
              <Share2 size={16} /> Share on WhatsApp
            </a>
          </div>
        </div>
      </section>

      {/* Referrals List & Table */}
      <section className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-6">
        <div>
          <h2 className="text-xl font-bold text-slate-850 dark:text-slate-100">
            👥 Registered Families ({referredParents?.length || 0})
          </h2>
          <p className="text-sm text-slate-500 mt-1">
            Directly enrolled parents and families linked to your referral code.
          </p>
        </div>

        {referredParents.length === 0 ? (
          <div className="text-center py-12 bg-slate-50 dark:bg-slate-950/20 rounded-2xl border border-dashed border-slate-200 dark:border-slate-800">
            <Users size={32} className="text-slate-400 mx-auto mb-2" />
            <p className="text-sm text-slate-500 font-medium">No parents registered under you yet.</p>
            <p className="text-xs text-slate-400 mt-1">Share your referral link above to bring in your first family!</p>
          </div>
        ) : (
          <div className="overflow-x-auto border border-slate-100 dark:border-slate-800 rounded-2xl">
            <table className="w-full text-sm text-left border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-800/40 text-slate-400 text-xs font-bold uppercase tracking-wider border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Parent Name</th>
                  <th className="px-6 py-4">Phone / WhatsApp</th>
                  <th className="px-6 py-4">Email</th>
                  <th className="px-6 py-4">Registered Date</th>
                  <th className="px-6 py-4 text-right">Balance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {referredParents.map((parent) => (
                  <tr key={parent.id} className="hover:bg-slate-50/40 dark:hover:bg-slate-800/20 transition-all">
                    <td className="px-6 py-4 font-bold text-slate-800 dark:text-slate-100">
                      {parent.name || '—'}
                    </td>
                    <td className="px-6 py-4 text-slate-500 dark:text-slate-400 font-medium">
                      {parent.whatsapp || '—'}
                    </td>
                    <td className="px-6 py-4 text-slate-500 dark:text-slate-400">
                      {parent.email || '—'}
                    </td>
                    <td className="px-6 py-4 text-slate-400">
                      {parent.created_at ? new Date(parent.created_at).toLocaleDateString('en-IN', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                      }) : '—'}
                    </td>
                    <td className="px-6 py-4 text-right text-emerald-600 dark:text-emerald-400 font-bold font-mono">
                      {parent.credits || 0} Credits
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
