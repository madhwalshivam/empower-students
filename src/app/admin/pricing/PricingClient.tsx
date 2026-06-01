'use client';

import React, { useState, useTransition } from 'react';
import { Save, Check, AlertTriangle } from 'lucide-react';
import { savePricingAction } from '@/app/actions/admin-data';

interface Row { service_key: string; label: string; price: number; is_active: boolean; }

export default function PricingClient({ initial }: { initial: Row[] }) {
  const [rows, setRows] = useState<Row[]>(initial);
  const [pending, start] = useTransition();
  const [toast, setToast] = useState<{ type: 'ok' | 'err'; msg: string } | null>(null);

  const setPrice = (k: string, v: string) =>
    setRows((rs) => rs.map((r) => (r.service_key === k ? { ...r, price: Number(v.replace(/\D/g, '')) || 0 } : r)));
  const toggle = (k: string) =>
    setRows((rs) => rs.map((r) => (r.service_key === k ? { ...r, is_active: !r.is_active } : r)));

  const save = () =>
    start(async () => {
      const res = await savePricingAction(rows.map((r) => ({ service_key: r.service_key, price: r.price, is_active: r.is_active })));
      if (res.ok) setToast({ type: 'ok', msg: `Saved ${res.updated} prices.` });
      else setToast({ type: 'err', msg: res.error || 'Save failed.' });
      setTimeout(() => setToast(null), 4000);
    });

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-500">
        Edit credit cost for each service. <strong className="text-slate-700 dark:text-slate-200">1 credit = ₹1</strong>. New parents get 100 free credits on signup.
      </p>

      <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-xs uppercase text-slate-400 text-left border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900">
              <tr>
                <th className="px-5 py-3.5 font-bold">Service Key</th>
                <th className="px-3 py-3.5 font-bold">Label</th>
                <th className="px-3 py-3.5 font-bold text-right">Credits</th>
                <th className="px-5 py-3.5 font-bold text-center">Active</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {rows.map((r) => (
                <tr key={r.service_key} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                  <td className="px-5 py-2.5 font-mono text-xs text-indigo-600 dark:text-indigo-400">{r.service_key}</td>
                  <td className="px-3 py-2.5 text-slate-700 dark:text-slate-200">{r.label}</td>
                  <td className="px-3 py-2.5 text-right">
                    <input
                      value={r.price}
                      onChange={(e) => setPrice(r.service_key, e.target.value)}
                      inputMode="numeric"
                      className="w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-400"
                    />
                  </td>
                  <td className="px-5 py-2.5 text-center">
                    <input
                      type="checkbox"
                      checked={r.is_active}
                      onChange={() => toggle(r.service_key)}
                      className="w-4 h-4 accent-indigo-600 cursor-pointer"
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex items-center gap-3 sticky bottom-4">
        <button
          onClick={save}
          disabled={pending}
          className="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-3 rounded-2xl shadow-lg inline-flex items-center gap-2 border-0 cursor-pointer disabled:opacity-60 transition-colors"
        >
          <Save size={17} /> {pending ? 'Saving…' : 'Save all pricing'}
        </button>
        {toast && (
          <span className={`inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-xl ${
            toast.type === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
          }`}>
            {toast.type === 'ok' ? <Check size={15} /> : <AlertTriangle size={15} />} {toast.msg}
          </span>
        )}
      </div>
    </div>
  );
}
