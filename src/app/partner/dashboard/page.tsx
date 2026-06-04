import React from 'react';
import { redirect } from 'next/navigation';
import Link from 'next/link';
import { headers } from 'next/headers';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import CopyLinkButton from './CopyLinkButton';
import Pagination, { parsePage } from '@/components/Pagination';
import {
  Users,
  Award,
  DollarSign,
  Share2,
  Plus,
  Clock,
  CheckCircle2,
  TrendingUp,
  Receipt
} from 'lucide-react';

export default async function PartnerDashboardPage({
  searchParams,
}: {
  searchParams: Promise<{ fpage?: string; cpage?: string }>;
}) {
  const sp = await searchParams;
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

  // Two independent pagers on this page: families (fpage) and commissions (cpage).
  const fpage = parsePage(sp.fpage);
  const cpage = parsePage(sp.cpage);
  const FAM_SIZE = 10;
  const COMM_SIZE = 10;

  // Referred families — only the current page is fetched (with an exact count).
  const famFrom = (fpage - 1) * FAM_SIZE;
  const { data: referredParentsData, count: famCount } = await db
    .from('parents')
    .select('id, name, whatsapp, email, credits, created_at', { count: 'exact' })
    .eq('partner_id', partner.id)
    .order('created_at', { ascending: false })
    .range(famFrom, famFrom + FAM_SIZE - 1);

  const referredParents = referredParentsData || [];
  const familiesTotal = famCount ?? 0;

  // Earnings TOTALS need every payout, but we only pull two tiny columns to keep
  // it light — not the full joined rows.
  const { data: sumRows } = await db
    .from('partner_payouts')
    .select('partner_amount, status')
    .eq('partner_id', partner.id);
  const allPayouts = sumRows || [];
  const totalEarnings = allPayouts.reduce((sum, p) => sum + (p.partner_amount || 0), 0);
  const pendingEarnings = allPayouts
    .filter((p: any) => p.status === 'pending')
    .reduce((sum, p) => sum + (p.partner_amount || 0), 0);
  const paidEarnings = allPayouts
    .filter((p: any) => p.status === 'paid')
    .reduce((sum, p) => sum + (p.partner_amount || 0), 0);

  // Commission HISTORY — paginated, full detail (joined parent name).
  const commFrom = (cpage - 1) * COMM_SIZE;
  const { data: payoutsData, count: commCount } = await db
    .from('partner_payouts')
    .select('partner_amount, gross_amount, share_rate_used, status, created_at, parent:parents(name)', { count: 'exact' })
    .eq('partner_id', partner.id)
    .order('created_at', { ascending: false })
    .range(commFrom, commFrom + COMM_SIZE - 1);

  const payouts = payoutsData || [];
  const commissionsTotal = commCount ?? 0;

  const inr = (n: number) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

  // Generate Referral Links & Messages — derive origin from the request
  const hdrs = await headers();
  const host = hdrs.get('x-forwarded-host') || hdrs.get('host') || 'localhost:3000';
  const proto = hdrs.get('x-forwarded-proto') || (host.includes('localhost') ? 'http' : 'https');
  const referralLink = `${proto}://${host}/r/${partner.referral_code}`;

  return (
    <div className="max-w-6xl mx-auto px-4 py-8 space-y-8 animate-fade-in">
      {/* Welcome Banner — gradient set inline because the Tailwind gradient
          utilities aren't compiled in this project (no Tailwind build step);
          this matches the inline-gradient idiom used elsewhere in the app. */}
      <section
        className="text-white rounded-3xl p-6 sm:p-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6"
        style={{
          background: 'linear-gradient(135deg, #4f46e5, #7c3aed)',
          boxShadow: '0 18px 40px -12px rgba(79,70,229,0.45)',
        }}
      >
        <div>
          <span
            className="text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider"
            style={{ background: 'rgba(255,255,255,0.18)' }}
          >
            Partner Portal
          </span>
          <h1 className="text-3xl font-extrabold mt-2 tracking-tight text-white">
            Welcome back, {partner.name}!
          </h1>
          <p className="text-sm mt-1" style={{ color: '#e0e7ff' }}>
            Track your referrals, manage your clients, and view your revenue share payments.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link
            href="/partner/add-family"
            className="text-indigo-700 shadow-md font-bold px-5 py-3 rounded-2xl text-sm flex items-center gap-1.5 transition-all cursor-pointer border-0"
            style={{ background: '#ffffff' }}
          >
            <Plus size={16} /> Register Family
          </Link>
          
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
              {familiesTotal || 0}
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
      <section
        className="rounded-3xl p-6 sm:p-8 space-y-6"
        style={{ background: '#ecfdf5', border: '1.5px solid #a7f3d0' }}
      >
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

      {/* Earnings — commission breakdown + history */}
      <section className="space-y-4">
        <h2 className="text-xl font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
          <TrendingUp size={20} className="text-indigo-600" /> Your Earnings
        </h2>
        <p className="text-sm text-slate-500 -mt-2">
          You earn <strong className="text-indigo-650">15%</strong> commission every time a parent you referred tops up their wallet.
        </p>

        {/* Breakdown cards */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm flex items-center gap-4">
            <div className="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/30 flex items-center justify-center text-indigo-650 dark:text-indigo-400">
              <DollarSign size={24} />
            </div>
            <div>
              <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Earned</p>
              <p className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-0.5">{inr(totalEarnings)}</p>
            </div>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm flex items-center gap-4">
            <div className="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/30 flex items-center justify-center text-amber-600 dark:text-amber-400">
              <Clock size={24} />
            </div>
            <div>
              <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Pending Payout</p>
              <p className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-0.5">{inr(pendingEarnings)}</p>
            </div>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm flex items-center gap-4">
            <div className="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 size={24} />
            </div>
            <div>
              <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Paid Out</p>
              <p className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-0.5">{inr(paidEarnings)}</p>
            </div>
          </div>
        </div>

        {/* Commission history */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
          <h3 className="text-base font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
            <Receipt size={18} className="text-slate-400" /> Commission History
          </h3>
          {payouts.length === 0 ? (
            <div className="text-center py-10 bg-slate-50 dark:bg-slate-950/20 rounded-2xl border border-dashed border-slate-200 dark:border-slate-800">
              <DollarSign size={28} className="text-slate-400 mx-auto mb-2" />
              <p className="text-sm text-slate-500 font-medium">No commissions yet.</p>
              <p className="text-xs text-slate-400 mt-1">You&apos;ll earn here when a referred parent tops up their wallet.</p>
            </div>
          ) : (
            <div className="overflow-x-auto border border-slate-100 dark:border-slate-800 rounded-2xl">
              <table className="w-full text-sm text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50 dark:bg-slate-800/40 text-slate-400 text-xs font-bold uppercase tracking-wider border-b border-slate-100 dark:border-slate-800">
                    <th className="px-5 py-3">Date</th>
                    <th className="px-5 py-3">Parent</th>
                    <th className="px-5 py-3 text-right">Top-up</th>
                    <th className="px-5 py-3 text-center">Rate</th>
                    <th className="px-5 py-3 text-right">Your Commission</th>
                    <th className="px-5 py-3 text-center">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {payouts.map((p: any, i: number) => (
                    <tr key={i} className="hover:bg-slate-50/40 dark:hover:bg-slate-800/20 transition-all">
                      <td className="px-5 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap">
                        {p.created_at ? new Date(p.created_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'}
                      </td>
                      <td className="px-5 py-3 font-semibold text-slate-700 dark:text-slate-200">{p.parent?.name || '—'}</td>
                      <td className="px-5 py-3 text-right text-slate-500 dark:text-slate-400">{inr(p.gross_amount)}</td>
                      <td className="px-5 py-3 text-center text-slate-400">{Math.round((p.share_rate_used || 0) * 100)}%</td>
                      <td className="px-5 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400 font-mono">{inr(p.partner_amount)}</td>
                      <td className="px-5 py-3 text-center">
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wide ${
                          p.status === 'paid'
                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400'
                            : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400'
                        }`}>
                          {p.status || 'pending'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {commissionsTotal > COMM_SIZE && (
            <Pagination
              page={cpage}
              total={commissionsTotal}
              pageSize={COMM_SIZE}
              basePath="/partner/dashboard"
              pageParam="cpage"
              params={{ fpage: fpage > 1 ? fpage : undefined }}
            />
          )}
        </div>
      </section>

      {/* Referrals List & Table */}
      <section className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-6">
        <div>
          <h2 className="text-xl font-bold text-slate-850 dark:text-slate-100">
            👥 Registered Families ({familiesTotal || 0})
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

        {familiesTotal > FAM_SIZE && (
          <Pagination
            page={fpage}
            total={familiesTotal}
            pageSize={FAM_SIZE}
            basePath="/partner/dashboard"
            pageParam="fpage"
            params={{ cpage: cpage > 1 ? cpage : undefined }}
          />
        )}
      </section>
    </div>
  );
}
