'use client';

import React, { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { RefreshCw } from 'lucide-react';
import { reverifyPaymentAction } from '@/app/actions/admin-data';

export default function ReverifyButton({ orderId }: { orderId: string }) {
  const router = useRouter();
  const [pending, start] = useTransition();
  const [msg, setMsg] = useState<string | null>(null);

  const run = () =>
    start(async () => {
      const res = await reverifyPaymentAction(orderId);
      if (res.ok) {
        setMsg(res.status === 'credited' ? 'Credited!' : res.status === 'already_credited' ? 'Already credited' : res.status || 'Done');
        router.refresh();
      } else {
        setMsg(res.error || 'Error');
      }
      setTimeout(() => setMsg(null), 4000);
    });

  return (
    <button
      onClick={run}
      disabled={pending}
      className="inline-flex items-center gap-1 text-indigo-600 font-semibold text-xs hover:underline border-0 bg-transparent cursor-pointer disabled:opacity-50"
    >
      <RefreshCw size={12} className={pending ? 'animate-spin' : ''} /> {msg || 're-verify'}
    </button>
  );
}
