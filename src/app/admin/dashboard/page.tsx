import React from 'react';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import {
  Users, UserPlus, UserCheck, Baby, Activity,
  HeartHandshake, Inbox, MessageSquare, Calendar, CheckCircle2, TrendingUp,
  LineChart, Target, Megaphone, Wallet, IndianRupee, Gift, Sprout, BookOpen,
  BarChart3, ClipboardList, FileText, CreditCard,
} from 'lucide-react';
import SpecialistManager from './SpecialistManager';
import PartnerApplicationActions from './PartnerApplicationActions';

export const dynamic = 'force-dynamic';

const inr = (n: number) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
const num = (n: number) => Number(n || 0).toLocaleString('en-IN');

export default async function AdminDashboardPage() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect('/login');
  if (user.user_metadata?.role !== 'admin') redirect('/dashboard');

  // Admin reads use the service-role client; access is gated by the role check above.
  const db = createAdminClient();

  // Graceful helpers — a missing table or column yields 0 / [] instead of crashing.
  const countOf = async (table: string, mod: (q: any) => any = (q) => q): Promise<number> => {
    try {
      const { count, error } = await mod(db.from(table).select('*', { count: 'exact', head: true }));
      return error ? 0 : count || 0;
    } catch { return 0; }
  };
  const rowsOf = async (table: string, mod: (q: any) => any = (q) => q): Promise<any[]> => {
    try {
      const { data, error } = await mod(db.from(table).select('*'));
      return error ? [] : data || [];
    } catch { return []; }
  };

  // Date windows
  const now = Date.now();
  const todayStart = new Date(new Date().setHours(0, 0, 0, 0)).toISOString();
  const monthStart = new Date(now - 29 * 86400000).toISOString();
  const weekStart = new Date(now - 6 * 86400000).toISOString();

  const [
    parentCount, childCount, partnerCount, activePartnerCount, assessmentCount, reportsCount,
    leadNew, leadContacted, leadBooked, leadConverted, leadTotal, leadToday, leadWeek, leadMonth,
    carePacks, carePacks30d, attributedParents,
    paymentRows, pendingPayouts, paidPayouts, walletRows,
    leadRows, recentParents, recentAssessmentsData, specialists, pendingApplications,
  ] = await Promise.all([
    countOf('parents'),
    countOf('children'),
    countOf('partners'),
    countOf('partners', (q) => q.eq('status', 'active')),
    countOf('assessments'),
    countOf('reports'),
    countOf('leads', (q) => q.eq('status', 'new')),
    countOf('leads', (q) => q.eq('status', 'contacted')),
    countOf('leads', (q) => q.eq('status', 'booked')),
    countOf('leads', (q) => q.eq('status', 'converted')),
    countOf('leads'),
    countOf('leads', (q) => q.gte('created_at', todayStart)),
    countOf('leads', (q) => q.gte('created_at', weekStart)),
    countOf('leads', (q) => q.gte('created_at', monthStart)),
    countOf('care_packs'),
    countOf('care_packs', (q) => q.gte('purchased_at', monthStart)),
    countOf('parents', (q) => q.not('partner_id', 'is', null)),
    rowsOf('payment_orders', (q) => q.eq('status', 'success')),
    rowsOf('partner_payouts', (q) => q.eq('status', 'pending')),
    rowsOf('partner_payouts', (q) => q.eq('status', 'paid')),
    rowsOf('wallet_ledger'),
    rowsOf('leads', (q) => q.gte('created_at', monthStart).order('created_at', { ascending: false })),
    rowsOf('parents', (q) => q.order('created_at', { ascending: false }).limit(8)),
    rowsOf('assessments', (q) =>
      q.select('id, module, status, score, completed_at, created_at, child:children(id,name,parent:parents(name))')
        .order('created_at', { ascending: false }).limit(8)),
    rowsOf('specialists', (q) => q.order('order_no', { ascending: true })),
    rowsOf('partner_applications', (q) => q.eq('status', 'pending').order('created_at', { ascending: false })),
  ]);

  // Derived figures
  const revenueInr = paymentRows.reduce((s, p) => s + (p.amount || 0), 0);
  const paidOrders = paymentRows.length;
  const owedTotal = pendingPayouts.reduce((s, p) => s + (p.partner_amount || 0), 0);
  const owedPartners = new Set(pendingPayouts.map((p) => p.partner_id)).size;
  const paidLifetime = paidPayouts.reduce((s, p) => s + (p.partner_amount || 0), 0);
  const conversionRate = leadTotal > 0 ? Math.round((leadConverted / leadTotal) * 1000) / 10 : 0;

  const sum = (key: string, where: (sk: string) => boolean) =>
    walletRows.filter((w) => where(w.service_key) && (w.amount || 0) < 0).reduce((s, w) => s - (w.amount || 0), 0);
  const carePackRevenue = sum('care_pack', (k) => k === 'care_pack');
  const topupRevenue = sum('tracker_topup', (k) => k === 'tracker_topup');
  const standaloneRevenue = sum('standalone', (k) => k === 'growth_plan' || k === 'personal_course');
  const paidTotalRevenue = carePackRevenue + topupRevenue + standaloneRevenue;

  // 14-day lead trend
  const days: { date: string; label: string; count: number }[] = [];
  for (let i = 13; i >= 0; i--) {
    const d = new Date(now - i * 86400000);
    const key = d.toISOString().slice(0, 10);
    days.push({ date: key, label: String(d.getDate()).padStart(2, '0'), count: 0 });
  }
  for (const l of leadRows) {
    const key = String(l.created_at || '').slice(0, 10);
    const slot = days.find((x) => x.date === key);
    if (slot) slot.count++;
  }
  const maxDaily = Math.max(1, ...days.map((d) => d.count));

  // Concern breakdown
  const concernLabels: Record<string, string> = {
    speech: 'Speech / Language', behaviour: 'Behaviour / Emotional', autism: 'Autism / Developmental',
    learning: 'Learning Difficulty', adhd: 'ADHD / Focus', sensory_motor: 'Sensory / Motor', not_sure: 'Needs guidance',
  };
  const concernMap = new Map<string, number>();
  for (const l of leadRows) {
    if (l.concern) concernMap.set(l.concern, (concernMap.get(l.concern) || 0) + 1);
  }
  const concerns = [...concernMap.entries()].map(([k, c]) => ({ key: k, count: c })).sort((a, b) => b.count - a.count);
  const concernTotal = concerns.reduce((s, c) => s + c.count, 0);

  // Top campaigns (UTM)
  const campMap = new Map<string, { src: string; med: string; camp: string; leads: number; qualified: number; converted: number }>();
  for (const l of leadRows) {
    const src = l.utm_source || 'direct';
    const med = l.utm_medium || '—';
    const camp = l.utm_campaign || '—';
    const k = `${src}|${med}|${camp}`;
    const e = campMap.get(k) || { src, med, camp, leads: 0, qualified: 0, converted: 0 };
    e.leads++;
    if (l.status === 'booked' || l.status === 'converted') e.qualified++;
    if (l.status === 'converted') e.converted++;
    campMap.set(k, e);
  }
  const campaigns = [...campMap.values()].sort((a, b) => b.leads - a.leads).slice(0, 8);

  const SectionLabel = ({ icon: Icon, children }: { icon: any; children: React.ReactNode }) => (
    <h2 className="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2 flex items-center gap-1.5">
      <Icon size={14} className="text-slate-400" /> {children}
    </h2>
  );

  return (
    <div id="top" className="space-y-6 animate-fade-in">
      {/* ACTION REQUIRED — partner applications */}
      {pendingApplications.length > 0 ? (
        <section className="bg-indigo-50 dark:bg-indigo-950/20 border border-indigo-200 dark:border-indigo-900 rounded-3xl p-5 sm:p-6">
          <div className="flex items-center justify-between flex-wrap gap-3 mb-4">
            <div>
              <div className="text-xs uppercase tracking-wider text-indigo-600 font-bold flex items-center gap-1.5">
                <HeartHandshake size={14} /> Action required
              </div>
              <h2 className="text-xl font-bold mt-1 text-slate-800 dark:text-slate-100">
                {pendingApplications.length} partner application{pendingApplications.length === 1 ? '' : 's'} pending
                <span className="text-sm font-normal text-slate-500 ml-2">— review and approve</span>
              </h2>
            </div>
          </div>
          <div className="space-y-2">
            {pendingApplications.map((app) => {
              const wa = String(app.whatsapp || '').replace(/\D/g, '');
              const waLink = `https://wa.me/${wa}?text=${encodeURIComponent(`Hello ${app.name}! Your EmpowerStudents partner application has been approved. You can now log in at empowerstudents.in/partner-login using this WhatsApp number.`)}`;
              return (
                <div key={app.id} className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl px-4 py-3 flex items-center justify-between flex-wrap gap-3">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="bg-indigo-100 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0">
                      {String(app.name || '?').charAt(0).toUpperCase()}
                    </div>
                    <div className="min-w-0">
                      <div className="font-semibold text-slate-800 dark:text-slate-100">{app.name}</div>
                      <div className="text-xs text-slate-500 truncate">
                        {app.clinic || '—'}{app.city ? ` · ${app.city}` : ''} · {app.whatsapp}
                      </div>
                    </div>
                  </div>
                  <PartnerApplicationActions id={app.id} whatsappLink={waLink} />
                </div>
              );
            })}
          </div>
        </section>
      ) : (
        <section className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900 rounded-3xl p-4 flex items-center gap-3">
          <CheckCircle2 size={28} className="text-emerald-600 flex-shrink-0" />
          <div>
            <div className="font-semibold text-emerald-900 dark:text-emerald-300">No pending partner applications</div>
            <div className="text-xs text-emerald-700 dark:text-emerald-500">New partner applications will appear here for review.</div>
          </div>
        </section>
      )}

      {/* LEAD FUNNEL */}
      <div id="leads">
        <SectionLabel icon={Inbox}>Lead funnel</SectionLabel>
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
          <FunnelCard icon={Inbox} label="New" value={leadNew} hint="uncontacted" color={leadNew > 0 ? 'rose' : 'slate'} />
          <FunnelCard icon={MessageSquare} label="Contacted" value={leadContacted} hint="in conversation" color="sky" />
          <FunnelCard icon={Calendar} label="Booked" value={leadBooked} hint="eval scheduled" color="violet" />
          <FunnelCard icon={CheckCircle2} label="Converted" value={leadConverted} hint="paying patients" color="emerald" />
          <PlainStat icon={TrendingUp} label="Conversion" value={`${conversionRate}%`} hint="lead → patient" accent="indigo" />
        </div>
      </div>

      {/* 14-DAY TREND + CONCERNS */}
      <div className="grid lg:grid-cols-3 gap-4">
        <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-5 lg:col-span-2">
          <div className="flex items-center justify-between mb-3">
            <div>
              <h2 className="font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-1.5"><LineChart size={16} className="text-indigo-500" /> Leads over the last 14 days</h2>
              <p className="text-xs text-slate-500 mt-0.5">Today: <strong className="text-slate-700 dark:text-slate-200">{leadToday}</strong> · This week: <strong className="text-slate-700 dark:text-slate-200">{leadWeek}</strong> · 30-day total: <strong className="text-slate-700 dark:text-slate-200">{leadMonth}</strong></p>
            </div>
          </div>
          <div className="es-chart-container">
            {days.map((d) => {
              const h = Math.max(6, Math.round((d.count / maxDaily) * 100));
              return (
                <div key={d.date} className="es-chart-column">
                  <div className="es-chart-tooltip">{d.count}</div>
                  <div className="es-chart-bar-container">
                    <div 
                      className={`es-chart-bar-fill ${d.count > 0 ? 'active' : ''}`} 
                      style={{ height: `${h}%` }} 
                      title={`${d.date}: ${d.count}`} 
                    />
                  </div>
                  <div className="es-chart-label">{d.label}</div>
                </div>
              );
            })}
          </div>
        </div>

        <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-5">
          <h2 className="font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-1.5"><Target size={16} className="text-rose-500" /> What parents ask about</h2>
          <p className="text-xs text-slate-500 mb-4 mt-0.5">Last 30 days · {concernTotal} leads</p>
          {concerns.length === 0 ? (
            <p className="text-sm text-slate-400">Nothing yet.</p>
          ) : (
            <ul className="space-y-2.5">
              {concerns.map((c) => {
                const pct = concernTotal > 0 ? Math.round((c.count / concernTotal) * 100) : 0;
                return (
                  <li key={c.key}>
                    <div className="flex justify-between text-xs mb-1">
                      <span className="font-medium text-slate-700 dark:text-slate-300">{concernLabels[c.key] || c.key}</span>
                      <span className="text-slate-500">{c.count} · {pct}%</span>
                    </div>
                    <div className="bg-slate-100 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                      <div className="bg-rose-500 h-full rounded-full" style={{ width: `${pct}%` }} />
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </div>

      {/* TOP CAMPAIGNS */}
      <div id="campaigns" className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-5">
        <div className="mb-3">
          <h2 className="font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-1.5"><Megaphone size={16} className="text-violet-500" /> Top campaigns (last 30 days)</h2>
          <p className="text-xs text-slate-500 mt-0.5">Which ads are delivering leads — and which are converting to patients</p>
        </div>
        {campaigns.length === 0 ? (
          <div className="text-center py-8 text-sm text-slate-400">No UTM-tagged traffic yet. Once your ads go live, campaigns will appear here.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs uppercase text-slate-500 text-left">
                <tr className="border-b border-slate-200 dark:border-slate-800">
                  <th className="py-2 pr-3">Source</th><th className="py-2 pr-3">Medium</th><th className="py-2 pr-3">Campaign</th>
                  <th className="py-2 pr-3 text-right">Leads</th><th className="py-2 pr-3 text-right">Qualified</th>
                  <th className="py-2 pr-3 text-right">Converted</th><th className="py-2 text-right">Conv %</th>
                </tr>
              </thead>
              <tbody>
                {campaigns.map((c, i) => {
                  const rate = c.leads > 0 ? Math.round((c.converted / c.leads) * 1000) / 10 : 0;
                  return (
                    <tr key={i} className="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                      <td className="py-2 pr-3"><span className="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded text-xs font-semibold">{c.src}</span></td>
                      <td className="py-2 pr-3 text-slate-600 dark:text-slate-400">{c.med}</td>
                      <td className="py-2 pr-3 font-mono text-xs text-slate-700 dark:text-slate-300">{c.camp}</td>
                      <td className="py-2 pr-3 text-right font-semibold">{c.leads}</td>
                      <td className="py-2 pr-3 text-right text-violet-700 dark:text-violet-400">{c.qualified}</td>
                      <td className="py-2 pr-3 text-right text-emerald-700 dark:text-emerald-400 font-semibold">{c.converted}</td>
                      <td className="py-2 text-right">{rate >= 10 ? <span className="text-emerald-600 font-bold">{rate}%</span> : rate > 0 ? <span className="text-slate-700 dark:text-slate-300">{rate}%</span> : <span className="text-slate-400">—</span>}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* CARE PACK REVENUE */}
      <div id="payments">
        <SectionLabel icon={Wallet}>Care Pack revenue</SectionLabel>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <PlainStat icon={IndianRupee} label="Total paid revenue" value={inr(paidTotalRevenue)} hint="Care Packs + top-ups + standalone" accent="emerald" />
          <PlainStat icon={Gift} label="Care Packs sold" value={num(carePacks)} hint={`+${carePacks30d} in last 30 days`} accent="rose" />
          <PlainStat icon={TrendingUp} label="Tracker top-ups" value={num(0)} hint={`${inr(topupRevenue)} recurring revenue`} accent="violet" />
          <PlainStat icon={Activity} label="Daily logs today" value={num(0)} hint="0 all-time" accent="sky" />
        </div>
      </div>

      {/* PARTNERS */}
      <div id="partners">
        <SectionLabel icon={HeartHandshake}>Partners</SectionLabel>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <PlainStat icon={IndianRupee} label="Owed to partners" value={inr(owedTotal)} hint={owedTotal > 0 ? `across ${owedPartners} partner${owedPartners === 1 ? '' : 's'}` : 'all paid up'} accent="amber" />
          <PlainStat icon={UserCheck} label="Active partners" value={num(activePartnerCount)} hint="tutors / centres" accent="emerald" />
          <PlainStat icon={Users} label="Referred parents" value={num(attributedParents)} hint="attributed via ?ref=" accent="violet" />
          <PlainStat icon={IndianRupee} label="Paid out lifetime" value={inr(paidLifetime)} hint="total disbursed" accent="slate" />
        </div>
      </div>

      {/* ENGAGEMENT CARDS */}
      <div className="grid md:grid-cols-3 gap-4">
        <FeatureCard icon={Sprout} title="Growth Plans" rows={[
          ['Generated (auto with pack)', num(carePacks)],
          ['Care Pack revenue', inr(carePackRevenue)],
        ]} note="Generated automatically when a parent buys a Care Pack" />
        <FeatureCard icon={BookOpen} title="Personal Courses" rows={[
          ['Total courses', num(carePacks)],
          ['Lessons completed', '0'],
          ['Avg progress', '0%'],
        ]} note="5 lessons each, AI-generated per child" />
        <FeatureCard icon={BarChart3} title="Daily Tracker" rows={[
          ['Active (days left > 0)', '0'],
          ['Days consumed', '0'],
          ['Top-ups', '0'],
          ['Top-up revenue', inr(topupRevenue)],
        ]} note="30 days included · 149 cr per top-up" />
      </div>

      {/* PLATFORM ACTIVITY */}
      <div id="activity">
        <SectionLabel icon={BarChart3}>Platform activity</SectionLabel>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          <PlainStat icon={Users} label="Parents" value={num(parentCount)} accent="indigo" />
          <PlainStat icon={Baby} label="Children" value={num(childCount)} accent="sky" />
          <PlainStat icon={ClipboardList} label="Assessments" value={num(assessmentCount)} accent="rose" />
          <PlainStat icon={FileText} label="Reports" value={num(reportsCount)} accent="violet" />
          <PlainStat icon={CreditCard} label="Paid orders" value={num(paidOrders)} accent="amber" />
          <PlainStat icon={IndianRupee} label="Revenue INR" value={inr(revenueInr)} accent="emerald" />
        </div>
      </div>

      {/* RECENT PARENTS + ASSESSMENTS */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 mb-3 flex items-center gap-1.5"><UserPlus size={18} className="text-indigo-500" /> New registered parents</h2>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {recentParents.length === 0 ? (
              <p className="text-sm text-slate-400 py-4 italic">No parents registered yet.</p>
            ) : recentParents.map((p) => (
              <div key={p.id} className="py-3 flex justify-between items-center first:pt-0 last:pb-0">
                <div>
                  <h4 className="text-sm font-bold text-slate-800 dark:text-slate-100">{p.name || '—'}</h4>
                  <p className="text-xs text-slate-400 mt-0.5">{p.email || p.whatsapp || '—'}</p>
                </div>
                <span className="text-xs text-slate-400 font-medium">{p.created_at ? new Date(p.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '—'}</span>
              </div>
            ))}
          </div>
        </div>

        <div id="evaluations" className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 mb-3 flex items-center gap-1.5"><ClipboardList size={18} className="text-rose-500" /> Recent platform assessments</h2>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {recentAssessmentsData.length === 0 ? (
              <p className="text-sm text-slate-400 py-4 italic">No assessments completed yet.</p>
            ) : recentAssessmentsData.map((a: any) => (
              <div key={a.id} className="py-3 flex justify-between items-center first:pt-0 last:pb-0">
                <div>
                  <h4 className="text-sm font-bold text-slate-800 dark:text-slate-100">{a.child?.name || 'Child'} <span className="text-slate-400 font-normal">· {a.module}</span></h4>
                  <p className="text-xs text-slate-400 mt-0.5">Parent: {a.child?.parent?.name || '—'}</p>
                </div>
                <div className="text-right">
                  <span className="text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/20 px-2 py-0.5 rounded-full">{a.score != null ? `Score ${a.score}` : '—'}</span>
                  <p className="text-[10px] text-slate-400 mt-1">{a.completed_at ? new Date(a.completed_at).toLocaleDateString('en-IN') : (a.created_at ? new Date(a.created_at).toLocaleDateString('en-IN') : 'In progress')}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* SPECIALISTS */}
      <section id="specialists" className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm">
        <SpecialistManager initialSpecialists={specialists} />
      </section>

      <p className="text-xs text-slate-400 text-center pt-2 pb-6">
        Lead emails are sent to <strong>drpankajjha@gmail.com</strong> · Marketing campaigns: ₹500/day per platform (Google + Facebook + Instagram)
      </p>
    </div>
  );
}

// ── Local presentational helpers (server-rendered) ──────────────────────────
const ACCENT: Record<string, string> = {
  indigo: 'bg-indigo-50 dark:bg-indigo-950/30 text-indigo-600 dark:text-indigo-400',
  sky: 'bg-sky-50 dark:bg-sky-950/30 text-sky-600 dark:text-sky-400',
  rose: 'bg-rose-50 dark:bg-rose-950/30 text-rose-600 dark:text-rose-400',
  violet: 'bg-violet-50 dark:bg-violet-950/30 text-violet-600 dark:text-violet-400',
  amber: 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400',
  emerald: 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400',
  slate: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
};

function PlainStat({ icon: Icon, label, value, hint, accent = 'slate' }: { icon: any; label: string; value: string; hint?: string; accent?: string }) {
  return (
    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4">
      <div className="text-xs uppercase text-slate-500 font-semibold flex items-center gap-1.5">
        <span className={`w-6 h-6 rounded-lg flex items-center justify-center ${ACCENT[accent]}`}><Icon size={13} /></span>
        {label}
      </div>
      <div className="text-2xl font-bold mt-1.5 text-slate-800 dark:text-slate-100">{value}</div>
      {hint && <div className="text-xs text-slate-400 mt-0.5">{hint}</div>}
    </div>
  );
}

function FunnelCard({ icon: Icon, label, value, hint, color }: { icon: any; label: string; value: number; hint: string; color: string }) {
  const text: Record<string, string> = { rose: 'text-rose-600', sky: 'text-sky-600', violet: 'text-violet-600', emerald: 'text-emerald-600', slate: 'text-slate-400' };
  const border = color === 'rose' && value > 0 ? 'border-rose-300' : 'border-slate-200 dark:border-slate-800';
  return (
    <div className={`bg-white dark:bg-slate-900 rounded-2xl border-2 ${border} p-4`}>
      <div className="text-xs uppercase text-slate-500 font-semibold flex items-center gap-1"><Icon size={13} /> {label}</div>
      <div className={`text-3xl font-bold mt-1 ${text[color]}`}>{num(value)}</div>
      <div className="text-xs text-slate-400 mt-1">{hint}</div>
    </div>
  );
}

function FeatureCard({ icon: Icon, title, rows, note }: { icon: any; title: string; rows: [string, string][]; note: string }) {
  return (
    <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-5">
      <div className="flex items-center gap-2 mb-3">
        <span className="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center"><Icon size={18} /></span>
        <h3 className="font-bold text-slate-800 dark:text-slate-100">{title}</h3>
      </div>
      <div className="space-y-1.5 text-sm">
        {rows.map(([k, v], i) => (
          <div key={i} className={`flex justify-between ${i === rows.length - 1 ? 'border-t border-slate-100 dark:border-slate-800 pt-1.5 mt-1.5' : ''}`}>
            <span className="text-slate-500">{k}</span><strong className="text-slate-800 dark:text-slate-100">{v}</strong>
          </div>
        ))}
      </div>
      <p className="text-xs text-slate-400 mt-3">{note}</p>
    </div>
  );
}
