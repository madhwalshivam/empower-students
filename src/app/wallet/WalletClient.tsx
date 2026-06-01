'use client';

import React, { useState } from 'react';
import { useSearchParams } from 'next/navigation';
import Script from 'next/script';
import { createTopupOrderAction } from '@/app/actions/payment';
import { Coins, AlertTriangle, ArrowRight } from 'lucide-react';

interface WalletClientProps {
  initialBalance: number;
  history: any[];
}

declare global {
  interface Window {
    Cashfree: any;
  }
}

export default function WalletClient({ initialBalance, history }: WalletClientProps) {
  const searchParams = useSearchParams();
  const status = searchParams.get('status');
  const errorParam = searchParams.get('error');
  const orderIdParam = searchParams.get('order_id');

  const [balance, setBalance] = useState(initialBalance);
  const [loadingAmt, setLoadingAmt] = useState<number | null>(null);
  const [error, setError] = useState('');
  const [cashfreeLoaded, setCashfreeLoaded] = useState(false);

  const packs = [
    { amt: 100, bonus: 0, tag: 'Starter' },
    { amt: 250, bonus: 25, tag: 'Most Popular' },
    { amt: 500, bonus: 75, tag: 'Family' },
    { amt: 1000, bonus: 200, tag: 'Annual' },
  ];

  const handleTopup = async (amount: number) => {
    setError('');
    setLoadingAmt(amount);

    try {
      if (!window.Cashfree) {
        throw new Error('Cashfree SDK is still loading. Please try again in a moment.');
      }

      const res = await createTopupOrderAction(amount);
      if (res.ok && res.paymentSessionId) {
        const cashfree = window.Cashfree({
          mode: res.cashfreeEnv === 'production' ? 'production' : 'sandbox',
        });
        cashfree.checkout({
          paymentSessionId: res.paymentSessionId,
          redirectTarget: '_self',
        });
      } else {
        setError(res.error || 'Failed to start checkout. Please try again.');
      }
    } catch (err: any) {
      setError(err.message || 'An unexpected error occurred.');
    } finally {
      setLoadingAmt(null);
    }
  };

  return (
    <div className="space-y-8 max-w-4xl mx-auto">
      {/* Cashfree SDK Script Load */}
      <Script
        src="https://sdk.cashfree.com/js/v3/cashfree.js"
        onLoad={() => setCashfreeLoaded(true)}
      />

      {status === 'success' && (
        <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-sm rounded-2xl p-4 flex items-center gap-2.5">
          <span className="text-xl">🎉</span>
          <div>
            <p className="font-bold">Payment Successful!</p>
            <p className="text-xs text-emerald-600 dark:text-emerald-450 mt-0.5">Your credits have been updated successfully. Order ID: {orderIdParam}</p>
          </div>
        </div>
      )}

      {status === 'failed' && (
        <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-300 text-sm rounded-2xl p-4 flex items-center gap-2.5">
          <span className="text-xl">❌</span>
          <div>
            <p className="font-bold">Payment Failed</p>
            <p className="text-xs text-rose-600 dark:text-rose-450 mt-0.5">Reason: {errorParam || 'Transaction cancelled or failed.'} Order ID: {orderIdParam || 'N/A'}</p>
          </div>
        </div>
      )}

      <div className="flex flex-col md:flex-row gap-8 items-start">
        {/* Balance Card */}
        <div className="card-premium w-full md:w-1/3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-6 shadow-sm flex flex-col">
          <span className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
            <Coins size={14} className="text-indigo-600" /> Current Balance
          </span>
          <div className="text-4xl font-extrabold text-slate-800 dark:text-slate-100 mt-2 flex items-baseline gap-1">
            <span>{balance}</span>
            <span className="text-sm font-bold text-slate-500">cr</span>
          </div>
          <p className="text-xs text-slate-400 dark:text-slate-500 mt-4 leading-relaxed">
            Credits can be used for Parent Evaluations, Child Learning Hub courses, and tracker top-ups.
          </p>
        </div>

        {/* Top-up Selection */}
        <div className="w-full md:w-2/3 space-y-4">
          <h2 className="heading-fun text-xl font-bold text-slate-800 dark:text-slate-100">
            Top Up Credits
          </h2>
          <p className="text-sm text-slate-500">
            Select a pack to add credits to your parent wallet. <strong>1 credit = ₹1</strong>.
          </p>

          {error && (
            <div className="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-xl p-3 flex items-center gap-2">
              <AlertTriangle size={16} />
              <span>{error}</span>
            </div>
          )}

          <div className="grid grid-cols-2 gap-4">
            {packs.map((p) => {
              const loading = loadingAmt === p.amt;
              return (
                <button
                  key={p.amt}
                  disabled={loadingAmt !== null}
                  onClick={() => handleTopup(p.amt)}
                  className="bg-white dark:bg-slate-900 hover:border-indigo-600 text-left rounded-2xl border-2 border-slate-100 dark:border-slate-800 p-4 transition-all hover:shadow-md disabled:opacity-50 cursor-pointer"
                >
                  <span className="text-[10px] font-bold text-indigo-650 dark:text-indigo-400 uppercase tracking-wider">
                    {p.tag}
                  </span>
                  <div className="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">
                    ₹{p.amt}
                  </div>
                  <div className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {p.amt} credits
                    {p.bonus > 0 && (
                      <span className="text-indigo-600 font-bold ml-1">
                        + {p.bonus} bonus
                      </span>
                    )}
                  </div>
                </button>
              );
            })}
          </div>
        </div>
      </div>

      {/* Wallet History Ledger */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 rounded-3xl p-6 shadow-sm">
        <h2 className="heading-fun text-xl font-bold text-slate-800 dark:text-slate-100 mb-4">
          Transaction Activity
        </h2>
        {history.length === 0 ? (
          <p className="text-slate-400 dark:text-slate-600 text-sm py-4">No transactions found.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead>
                <tr className="border-b border-slate-100 dark:border-slate-800 text-xs font-bold text-slate-400 dark:text-slate-500 uppercase">
                  <th className="pb-3">Date</th>
                  <th className="pb-3">Service</th>
                  <th className="pb-3">Details</th>
                  <th className="pb-3 text-right">Amount</th>
                  <th className="pb-3 text-right">Balance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                {history.map((h) => {
                  const isPositive = Number(h.amount) > 0;
                  const dateStr = new Date(h.created_at).toLocaleString();

                  return (
                    <tr key={h.id} className="text-slate-600 dark:text-slate-400">
                      <td className="py-3.5 text-xs">{dateStr}</td>
                      <td className="py-3.5 font-semibold text-slate-700 dark:text-slate-300">
                        {h.service_key || '—'}
                      </td>
                      <td className="py-3.5 text-slate-500 text-xs sm:text-sm">{h.reason}</td>
                      <td className={`py-3.5 text-right font-bold ${isPositive ? 'text-indigo-600' : 'text-rose-650'}`}>
                        {isPositive ? '+' : ''}
                        {h.amount}
                      </td>
                      <td className="py-3.5 text-right text-slate-400 dark:text-slate-500 font-medium">
                        {h.balance_after}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
