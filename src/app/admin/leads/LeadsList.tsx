'use client';

import React, { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { MessageSquare, Phone, ChevronDown, Download, Check } from 'lucide-react';
import { updateLeadStatusAction } from '@/app/actions/admin-data';

interface Lead {
  id: number; parent_name?: string; phone?: string; child_age?: string; concern?: string;
  source?: string; status?: string; notes?: string; created_at?: string;
}

const CONCERN: Record<string, string> = {
  speech: 'Speech / Language', behaviour: 'Behaviour / Emotional', autism: 'Autism / Developmental',
  learning: 'Learning Difficulty', adhd: 'ADHD / Focus', sensory_motor: 'Sensory / Motor', not_sure: 'Needs guidance',
};
const STATUSES = ['new', 'contacted', 'booked', 'converted', 'lost', 'spam'];

function LeadCard({ lead }: { lead: Lead }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState(lead.status || 'new');
  const [notes, setNotes] = useState(lead.notes || '');
  const [pending, start] = useTransition();
  const [saved, setSaved] = useState(false);

  const wa = String(lead.phone || '').replace(/\D/g, '');
  const save = () =>
    start(async () => {
      const res = await updateLeadStatusAction(lead.id, status, notes);
      if (res.ok) { setSaved(true); setTimeout(() => setSaved(false), 2500); router.refresh(); }
    });

  return (
    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 sm:p-5 shadow-sm">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div className="flex items-center gap-2 flex-wrap">
            <span className="font-bold text-slate-800 dark:text-slate-100">{lead.parent_name || 'Unknown'}</span>
            {lead.source && <span className="text-[10px] uppercase font-bold tracking-wide bg-slate-100 dark:bg-slate-800 text-slate-500 px-2 py-0.5 rounded">{lead.source}</span>}
          </div>
          <div className="text-xs text-slate-400 mt-0.5">#{lead.id} · {lead.created_at ? new Date(lead.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''}</div>
        </div>
        <div className="flex gap-2">
          <a href={`https://wa.me/${wa}`} target="_blank" rel="noopener noreferrer" className="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3 py-2 rounded-full inline-flex items-center gap-1 no-underline">
            <MessageSquare size={13} /> WhatsApp
          </a>
          <a href={`tel:${wa}`} className="bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold px-3 py-2 rounded-full inline-flex items-center gap-1 no-underline">
            <Phone size={13} /> Call
          </a>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-3 text-sm">
        <div className="text-slate-500">Phone: <span className="font-semibold text-slate-700 dark:text-slate-200">{lead.phone || '—'}</span></div>
        <div className="text-slate-500">Child age: <span className="font-semibold text-slate-700 dark:text-slate-200">{lead.child_age || '—'}</span></div>
        <div className="text-slate-500">Concern: <span className="font-semibold text-rose-600">{CONCERN[lead.concern || ''] || lead.concern || '—'}</span></div>
      </div>

      <button onClick={() => setOpen((v) => !v)} className="mt-3 text-indigo-600 text-sm font-semibold inline-flex items-center gap-1 border-0 bg-transparent cursor-pointer">
        <ChevronDown size={14} className={`transition-transform ${open ? 'rotate-180' : ''}`} /> Update status / add notes
      </button>

      {open && (
        <div className="mt-3 border-t border-slate-100 dark:border-slate-800 pt-3 flex flex-col sm:flex-row gap-2">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-sm capitalize focus:outline-none focus:ring-2 focus:ring-indigo-400">
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          <input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Notes…" className="flex-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" />
          <button onClick={save} disabled={pending} className="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm px-4 py-2 rounded-xl border-0 cursor-pointer disabled:opacity-60 inline-flex items-center gap-1">
            {saved ? <><Check size={14} /> Saved</> : pending ? 'Saving…' : 'Save'}
          </button>
        </div>
      )}
    </div>
  );
}

export default function LeadsList({ leads }: { leads: Lead[] }) {
  const exportCsv = () => {
    const headers = ['id', 'parent_name', 'phone', 'child_age', 'concern', 'source', 'status', 'created_at'];
    const esc = (v: any) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const csv = [headers.join(','), ...leads.map((l) => headers.map((h) => esc((l as any)[h])).join(','))].join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'leads.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  };

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <button onClick={exportCsv} disabled={!leads.length} className="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm px-4 py-2 rounded-xl inline-flex items-center gap-1.5 border-0 cursor-pointer disabled:opacity-50">
          <Download size={15} /> Export CSV
        </button>
      </div>
      {leads.map((l) => <LeadCard key={l.id} lead={l} />)}
    </div>
  );
}
