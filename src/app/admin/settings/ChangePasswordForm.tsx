'use client';

import React, { useState, useTransition } from 'react';
import { Lock, Check, AlertTriangle } from 'lucide-react';
import { changeAdminPasswordAction } from '@/app/actions/admin-data';

export default function ChangePasswordForm() {
  const [pw, setPw] = useState('');
  const [pending, start] = useTransition();
  const [toast, setToast] = useState<{ type: 'ok' | 'err'; msg: string } | null>(null);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (pw.trim().length < 8) { setToast({ type: 'err', msg: 'Minimum 8 characters.' }); return; }
    start(async () => {
      const res = await changeAdminPasswordAction(pw.trim());
      if (res.ok) { setToast({ type: 'ok', msg: 'Password updated.' }); setPw(''); }
      else setToast({ type: 'err', msg: res.error || 'Failed.' });
      setTimeout(() => setToast(null), 4000);
    });
  };

  return (
    <form onSubmit={submit} className="space-y-3">
      <div className="relative">
        <Lock size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input
          type="password"
          value={pw}
          onChange={(e) => setPw(e.target.value)}
          placeholder="New password (min 8 chars)"
          className="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
        />
      </div>
      <button
        type="submit"
        disabled={pending}
        className="w-full bg-indigo-600 text-white font-bold py-2.5 rounded-xl border-0 cursor-pointer hover:bg-indigo-700 disabled:opacity-60 transition-colors"
      >
        {pending ? 'Updating…' : 'Update password'}
      </button>
      {toast && (
        <div className={`flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-xl ${toast.type === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
          {toast.type === 'ok' ? <Check size={15} /> : <AlertTriangle size={15} />} {toast.msg}
        </div>
      )}
    </form>
  );
}
