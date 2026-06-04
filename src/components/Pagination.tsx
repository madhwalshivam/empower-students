import React from 'react';
import Link from 'next/link';
import { ChevronLeft, ChevronRight } from 'lucide-react';

// Reusable server-rendered pager. Pages fetch only one slice from the DB (via
// .range()) and pass the totals here, so the server never loads a whole table at
// once. Links preserve the other query params (filter, q, …) and just swap page.
export default function Pagination({
  page,
  total,
  pageSize,
  basePath,
  params = {},
  pageParam = 'page',
}: {
  page: number;
  total: number;
  pageSize: number;
  basePath: string;
  params?: Record<string, string | number | undefined>;
  // The query key used for THIS pager's page number. Override it when a single
  // page hosts two independent pagers (e.g. 'fpage' and 'cpage').
  pageParam?: string;
}) {
  const totalPages = Math.max(1, Math.ceil(total / pageSize));

  const href = (p: number) => {
    const sp = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (k !== pageParam && v !== undefined && v !== null && String(v) !== '') sp.set(k, String(v));
    });
    sp.set(pageParam, String(p));
    return `${basePath}?${sp.toString()}`;
  };

  const from = total === 0 ? 0 : (page - 1) * pageSize + 1;
  const to = Math.min(total, page * pageSize);

  // Sliding window of page numbers (max 5).
  const WINDOW = 5;
  let start = Math.max(1, page - 2);
  const end = Math.min(totalPages, start + WINDOW - 1);
  start = Math.max(1, end - WINDOW + 1);
  const nums: number[] = [];
  for (let i = start; i <= end; i++) nums.push(i);

  const base =
    'min-w-9 h-9 px-3 inline-flex items-center justify-center rounded-lg text-sm font-semibold border no-underline transition-colors';
  const enabled = `${base} border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800`;
  const disabled = `${base} border-slate-100 dark:border-slate-800 text-slate-300 dark:text-slate-700 cursor-not-allowed`;
  const current = `${base} border-indigo-600 bg-indigo-600 text-white`;

  return (
    <div className="flex items-center justify-between gap-3 px-1 pt-4 flex-wrap">
      <span className="text-xs text-slate-400">
        {total === 0 ? 'No records' : `Showing ${from}–${to} of ${total}`}
      </span>

      {totalPages > 1 && (
        <div className="flex items-center gap-1.5">
          {page > 1 ? (
            <Link href={href(page - 1)} className={enabled} aria-label="Previous page"><ChevronLeft size={16} /></Link>
          ) : (
            <span className={disabled}><ChevronLeft size={16} /></span>
          )}

          {start > 1 && <span className="text-slate-300 px-1">…</span>}

          {nums.map((n) =>
            n === page ? (
              <span key={n} className={current}>{n}</span>
            ) : (
              <Link key={n} href={href(n)} className={enabled}>{n}</Link>
            )
          )}

          {end < totalPages && <span className="text-slate-300 px-1">…</span>}

          {page < totalPages ? (
            <Link href={href(page + 1)} className={enabled} aria-label="Next page"><ChevronRight size={16} /></Link>
          ) : (
            <span className={disabled}><ChevronRight size={16} /></span>
          )}
        </div>
      )}
    </div>
  );
}

// Small helper: turn a raw ?page= value into a safe 1-based integer.
export function parsePage(raw: string | undefined): number {
  const n = Number(raw);
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : 1;
}
