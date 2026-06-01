'use client';

import React, { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { Check, X, MessageSquare } from 'lucide-react';
import { approvePartnerApplicationAction, rejectPartnerApplicationAction } from '@/app/actions/admin';

export default function PartnerApplicationActions({
  id,
  whatsappLink,
}: {
  id: number;
  whatsappLink: string;
}) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [done, setDone] = useState<'approved' | 'rejected' | null>(null);

  const approve = () =>
    startTransition(async () => {
      const res = await approvePartnerApplicationAction(id);
      if (res.ok) { setDone('approved'); router.refresh(); }
    });

  const reject = () =>
    startTransition(async () => {
      if (!confirm('Reject this application?')) return;
      const res = await rejectPartnerApplicationAction(id);
      if (res.ok) { setDone('rejected'); router.refresh(); }
    });

  if (done) {
    return (
      <span className="text-xs font-bold bg-white/20 px-3 py-1.5 rounded-full capitalize">
        {done}
      </span>
    );
  }

  return (
    <div className="flex gap-2 flex-shrink-0 flex-wrap">
      <a
        href={whatsappLink}
        target="_blank"
        rel="noopener noreferrer"
        className="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-1.5 rounded-full text-xs font-bold inline-flex items-center gap-1 no-underline"
      >
        <MessageSquare size={13} /> WhatsApp
      </a>
      <button
        onClick={approve}
        disabled={pending}
        className="bg-white text-indigo-700 hover:bg-indigo-50 px-3 py-1.5 rounded-full text-xs font-bold inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
      >
        <Check size={13} /> Approve
      </button>
      <button
        onClick={reject}
        disabled={pending}
        className="bg-white/20 hover:bg-white/30 text-white px-3 py-1.5 rounded-full text-xs font-bold inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
      >
        <X size={13} /> Reject
      </button>
    </div>
  );
}
