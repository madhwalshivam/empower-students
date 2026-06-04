'use client';

import React, { useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { IndianRupee } from 'lucide-react';
import { markPartnerPaidAction } from '@/app/actions/admin';

// Settles a partner's outstanding commission. Shows only when there's something
// pending; clicking it marks all their pending payouts as paid.
export default function PartnerPayoutButton({
  partnerId,
  pending,
}: {
  partnerId: string;
  pending: number;
}) {
  const router = useRouter();
  const [busy, start] = useTransition();

  if (pending <= 0) return null;

  const label = pending.toLocaleString('en-IN', { maximumFractionDigits: 2 });

  const pay = () =>
    start(async () => {
      if (!confirm(`Mark ₹${label} as paid to this partner? This clears their pending commission.`)) return;
      const res = await markPartnerPaidAction(partnerId);
      if (res.ok) {
        router.refresh();
      } else {
        alert(res.error || 'Failed to record payout.');
      }
    });

  return (
    <button
      onClick={pay}
      disabled={busy}
      className="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
      title="Mark pending commission as paid"
    >
      <IndianRupee size={12} /> {busy ? 'Paying…' : `Pay ₹${label}`}
    </button>
  );
}
