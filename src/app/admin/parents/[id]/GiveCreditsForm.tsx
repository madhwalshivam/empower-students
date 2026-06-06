'use client';

import React, { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { Coins, Plus, Minus } from 'lucide-react';
import { adjustParentCreditsAction } from '@/app/actions/admin';

// Admin tool: grant (or deduct) wallet credits on a parent's account. The amount
// is unrestricted — admins can give as many credits as they like. Quick presets
// make common grants one click; the change is logged to the wallet ledger.
const PRESETS = [50, 100, 250, 500, 1000];

export default function GiveCreditsForm({
  parentId,
  current,
}: {
  parentId: string;
  current: number;
}) {
  const router = useRouter();
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, start] = useTransition();

  const submit = (sign: 1 | -1) =>
    start(async () => {
      setMsg(null);
      const value = Math.trunc(Number(amount));
      if (!Number.isFinite(value) || value <= 0) {
        setMsg({ ok: false, text: 'Enter a positive amount.' });
        return;
      }
      const res = await adjustParentCreditsAction(parentId, sign * value, reason);
      if (res.ok) {
        setMsg({ ok: true, text: `Done — new balance is ${res.credits} credits.` });
        setAmount('');
        setReason('');
        router.refresh();
      } else {
        setMsg({ ok: false, text: res.error || 'Failed to adjust credits.' });
      }
    });

  return (
    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-sm p-6">
      <h2 className="font-bold text-slate-800 dark:text-slate-100 mb-1 flex items-center gap-2">
        <Coins size={18} className="text-indigo-600" /> Manage credits
      </h2>
      <p className="text-sm text-slate-500 mb-4">
        Current balance: <span className="font-bold text-slate-700 dark:text-slate-200">{current} credits</span>
      </p>

      <div className="flex flex-wrap gap-2 mb-3">
        {PRESETS.map((p) => (
          <button
            key={p}
            type="button"
            onClick={() => setAmount(String(p))}
            className="px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-800 hover:border-indigo-400 hover:text-indigo-600 cursor-pointer transition-colors"
          >
            +{p}
          </button>
        ))}
      </div>

      <input
        type="number"
        min={1}
        value={amount}
        onChange={(e) => setAmount(e.target.value)}
        placeholder="Amount of credits"
        className="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2.5 text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-400 mb-3"
      />
      <input
        type="text"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder="Reason (optional) — e.g. goodwill, refund, promo"
        className="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2.5 text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-400 mb-4"
      />

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => submit(1)}
          disabled={busy}
          className="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm px-4 py-2.5 rounded-xl border-0 cursor-pointer inline-flex items-center justify-center gap-1.5 disabled:opacity-60"
        >
          <Plus size={15} /> {busy ? 'Saving…' : 'Add credits'}
        </button>
        <button
          type="button"
          onClick={() => submit(-1)}
          disabled={busy}
          className="bg-white dark:bg-slate-800 hover:bg-rose-50 dark:hover:bg-rose-950/30 text-rose-600 font-bold text-sm px-4 py-2.5 rounded-xl border border-rose-200 dark:border-rose-900 cursor-pointer inline-flex items-center justify-center gap-1.5 disabled:opacity-60"
          title="Deduct credits"
        >
          <Minus size={15} /> Deduct
        </button>
      </div>

      {msg && (
        <p className={`text-sm font-semibold mt-3 ${msg.ok ? 'text-emerald-600' : 'text-rose-600'}`}>
          {msg.text}
        </p>
      )}
    </div>
  );
}
