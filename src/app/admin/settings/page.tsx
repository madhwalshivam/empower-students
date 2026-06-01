import React from 'react';
import { Settings as SettingsIcon, CheckCircle2, XCircle, KeyRound, Webhook } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { AdminPageHeader, Card } from '@/components/admin/ui';
import ChangePasswordForm from './ChangePasswordForm';

export const dynamic = 'force-dynamic';

function StatusRow({ ok, label, detail }: { ok: boolean; label: string; detail: string }) {
  return (
    <div className="flex items-start gap-2.5 py-2">
      {ok ? <CheckCircle2 size={18} className="text-emerald-500 mt-0.5 shrink-0" /> : <XCircle size={18} className="text-rose-500 mt-0.5 shrink-0" />}
      <div>
        <span className="font-bold text-slate-800 dark:text-slate-100">{label}</span>
        <span className="text-sm text-slate-500"> — {detail}</span>
      </div>
    </div>
  );
}

export default async function AdminSettingsPage() {
  await requireAdminUser();

  const anthropic = !!process.env.ANTHROPIC_API_KEY;
  const model = process.env.ANTHROPIC_MODEL || 'claude-sonnet-4-5';
  const cashfree = !!process.env.CASHFREE_APP_ID && !!process.env.CASHFREE_SECRET_KEY;
  const cfEnv = process.env.CASHFREE_ENV || 'sandbox';
  const twilio = !!process.env.TWILIO_SID && !!process.env.TWILIO_AUTH_TOKEN;
  const contentSid = !!process.env.TWILIO_CONTENT_SID;
  const otpMode = process.env.OTP_MODE === 'demo' ? 'demo' : 'twilio_wa';

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={SettingsIcon} title="Settings" subtitle="Account security, integration health and webhook configuration." />

      <div className="grid lg:grid-cols-2 gap-6">
        <Card className="p-6">
          <h2 className="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2 mb-4"><KeyRound size={18} className="text-indigo-500" /> Change admin password</h2>
          <ChangePasswordForm />
        </Card>

        <Card className="p-6">
          <h2 className="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2 mb-3"><CheckCircle2 size={18} className="text-emerald-500" /> Integration status</h2>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            <StatusRow ok={anthropic} label="Anthropic Claude" detail={`model ${model}`} />
            <StatusRow ok={cashfree} label="Cashfree" detail={`env ${cfEnv}`} />
            <StatusRow ok={twilio} label="Twilio WhatsApp" detail={contentSid ? 'ContentSid configured' : 'ContentSid missing'} />
          </div>
          <p className="text-xs text-slate-400 mt-3">OTP mode: <code className="bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">{otpMode}</code> · Configure via <code className="bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">.env.local</code></p>
        </Card>
      </div>

      <Card className="p-6">
        <h2 className="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2 mb-4"><Webhook size={18} className="text-violet-500" /> Webhook URLs to configure</h2>
        <div className="space-y-4">
          <div>
            <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Cashfree → Developer → Webhooks</label>
            <code className="block mt-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-mono text-slate-700 dark:text-slate-300 break-all">
              https://empowerstudents.in/api/payment/verify
            </code>
          </div>
          <div>
            <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Allowed return URL</label>
            <code className="block mt-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-mono text-slate-700 dark:text-slate-300 break-all">
              https://empowerstudents.in/wallet
            </code>
          </div>
        </div>
      </Card>
    </div>
  );
}
