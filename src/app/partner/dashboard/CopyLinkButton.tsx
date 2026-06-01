'use client';

import React, { useState } from 'react';
import { Copy, Check } from 'lucide-react';

export default function CopyLinkButton({ link }: { link: string }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(link);
    } catch {
      // Fallback for browsers without clipboard API
      const ta = document.createElement('textarea');
      ta.value = link;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <button
      type="button"
      onClick={handleCopy}
      className="bg-slate-800 dark:bg-slate-700 text-white font-bold px-4 py-2.5 rounded-xl text-xs flex items-center gap-1 hover:bg-slate-900 border-0 cursor-pointer"
    >
      {copied ? <Check size={14} /> : <Copy size={14} />} {copied ? 'Copied!' : 'Copy'}
    </button>
  );
}
