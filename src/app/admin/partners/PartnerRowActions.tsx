'use client';

import React, { useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { Check, X, Play, Pause } from 'lucide-react';
import { updatePartnerStatusAction } from '@/app/actions/admin';

export default function PartnerRowActions({
  partnerId,
  currentStatus,
}: {
  partnerId: string;
  currentStatus: 'active' | 'pending' | 'paused' | 'terminated';
}) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  const handleStatusChange = (status: 'active' | 'pending' | 'paused' | 'terminated') => {
    startTransition(async () => {
      const res = await updatePartnerStatusAction(partnerId, status);
      if (res.ok) {
        router.refresh();
      } else {
        alert(res.error || 'Failed to update partner status.');
      }
    });
  };

  return (
    <div className="flex items-center gap-1.5 justify-end">
      {currentStatus === 'pending' && (
        <>
          <button
            onClick={() => handleStatusChange('active')}
            disabled={pending}
            className="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
          >
            <Check size={12} /> Approve
          </button>
          <button
            onClick={() => {
              if (confirm('Reject this partner registration?')) {
                handleStatusChange('terminated');
              }
            }}
            disabled={pending}
            className="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
          >
            <X size={12} /> Reject
          </button>
        </>
      )}

      {currentStatus === 'active' && (
        <button
          onClick={() => handleStatusChange('paused')}
          disabled={pending}
          className="bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
        >
          <Pause size={12} /> Pause
        </button>
      )}

      {currentStatus === 'paused' && (
        <>
          <button
            onClick={() => handleStatusChange('active')}
            disabled={pending}
            className="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
          >
            <Play size={12} /> Activate
          </button>
          <button
            onClick={() => {
              if (confirm('Terminate this partner account?')) {
                handleStatusChange('terminated');
              }
            }}
            disabled={pending}
            className="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
          >
            <X size={12} /> Terminate
          </button>
        </>
      )}

      {currentStatus === 'terminated' && (
        <button
          onClick={() => handleStatusChange('active')}
          disabled={pending}
          className="bg-slate-600 hover:bg-slate-700 text-white text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1 border-0 cursor-pointer disabled:opacity-60"
        >
          <Play size={12} /> Re-activate
        </button>
      )}
    </div>
  );
}
