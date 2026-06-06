'use client';

import { useEffect } from 'react';
import { useTranslation } from './I18nContext';
import { translateBatchAction } from '@/app/actions/translate';
import { HINDI_DICT, HINDI_REGEX_RULES, HINDI_SKIP } from './hindiDict';

// Runtime DOM translator. When the language is Hindi it walks the page's text
// (and key attributes), replacing English with Hindi.
//
// Two-track design for INSTANT feel:
//   • localPass()  — SYNCHRONOUS. Applies seed-dictionary / regex / localStorage
//                    cache hits with zero delay (no network, no debounce). Runs
//                    immediately on load and on every DOM mutation, so known
//                    strings are translated with no perceptible lag.
//   • aiPass()     — background. Collects only the strings nothing local could
//                    translate, batch-translates them via Claude, caches them in
//                    localStorage, then re-runs localPass(). After the first time
//                    a string is seen it lives in the cache → instant forever.
//
// Switching language reloads the page (see I18nContext), so we only ever ADD
// Hindi on a Hindi load and never need to revert.

const CACHE_KEY = 'es_hi_cache_v1';
const SKIP_TAGS = new Set([
  'SCRIPT', 'STYLE', 'NOSCRIPT', 'CODE', 'PRE', 'TEXTAREA', 'INPUT', 'SELECT',
]);
const ATTRS = ['placeholder', 'title', 'aria-label'];

type FlaggedText = Text & { __i18nHI?: string };
type FlaggedEl = HTMLElement & { __i18nAttr?: Record<string, string> };

let cache: Record<string, string> = {};

function loadCache() {
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    if (raw) cache = JSON.parse(raw) || {};
  } catch { /* ignore */ }
}
function saveCache() {
  try { localStorage.setItem(CACHE_KEY, JSON.stringify(cache)); } catch { /* ignore */ }
}

function translatable(s: string): boolean {
  const t = s.trim();
  if (t.length < 2 || t.length > 400) return false;
  if (HINDI_SKIP.has(t)) return false;
  if (/[ऀ-ॿ]/.test(t)) return false;                      // already Devanagari
  if (!/[A-Za-z]/.test(t)) return false;                  // no latin letters
  if (/^https?:\/\//i.test(t) || /\S+@\S+\.\S+/.test(t)) return false;
  return true;
}

function localLookup(t: string): string | null {
  if (HINDI_DICT[t]) return HINDI_DICT[t];
  if (cache[t]) return cache[t];
  for (const { re, to } of HINDI_REGEX_RULES) {
    if (re.test(t)) return t.replace(re, to);
  }
  return null;
}

function isSkippedAncestor(node: Node | null): boolean {
  let el = node instanceof Element ? node : node?.parentElement;
  while (el) {
    if (SKIP_TAGS.has(el.tagName)) return true;
    if (el.hasAttribute('data-no-translate')) return true;
    el = el.parentElement;
  }
  return false;
}

function applyText(node: FlaggedText, hindi: string) {
  const raw = node.nodeValue || '';
  const lead = raw.match(/^\s*/)?.[0] || '';
  const trail = raw.match(/\s*$/)?.[0] || '';
  const next = lead + hindi + trail;
  node.nodeValue = next;
  node.__i18nHI = next;
}

export default function HindiTranslator() {
  const { language } = useTranslation();

  useEffect(() => {
    if (language !== 'hi' || typeof window === 'undefined') return;

    loadCache();
    let observer: MutationObserver | null = null;
    let aiTimer: ReturnType<typeof setTimeout> | null = null;
    let aiBusy = false;
    let applying = false;
    const unknown = new Set<string>();

    // SYNCHRONOUS: translate everything we already know, right now. Also records
    // strings we DON'T know into `unknown` for the background AI pass.
    function localPass() {
      if (applying) return;
      applying = true;
      try {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let n: Node | null;
        while ((n = walker.nextNode())) {
          const node = n as FlaggedText;
          const raw = node.nodeValue || '';
          if (!raw.trim() || node.__i18nHI === raw) continue;
          if (isSkippedAncestor(node)) continue;
          const key = raw.trim();
          if (!translatable(key)) continue;
          const hit = localLookup(key);
          if (hit) applyText(node, hit);
          else unknown.add(key);
        }

        for (const attr of ATTRS) {
          document.querySelectorAll(`[${attr}]`).forEach((raw) => {
            const el = raw as FlaggedEl;
            if (isSkippedAncestor(el)) return;
            const val = el.getAttribute(attr) || '';
            const key = val.trim();
            if (!translatable(key) || el.__i18nAttr?.[attr] === val) return;
            const hit = localLookup(key);
            if (hit) {
              el.setAttribute(attr, hit);
              el.__i18nAttr = { ...(el.__i18nAttr || {}), [attr]: hit };
            } else {
              unknown.add(key);
            }
          });
        }
      } finally {
        applying = false;
      }
      if (unknown.size > 0) scheduleAI();
    }

    function scheduleAI() {
      if (aiTimer) clearTimeout(aiTimer);
      aiTimer = setTimeout(runAI, 120);
    }

    async function runAI() {
      if (aiBusy || unknown.size === 0) return;
      aiBusy = true;
      const items = Array.from(unknown);
      unknown.clear();
      try {
        let changed = false;
        for (let i = 0; i < items.length; i += 60) {
          const chunk = items.slice(i, i + 60);
          const map = await translateBatchAction(chunk);
          for (const [en, hi] of Object.entries(map)) {
            if (hi && hi !== en) { cache[en] = hi; changed = true; }
          }
        }
        if (changed) {
          saveCache();
          localPass(); // apply the freshly cached translations
        }
      } finally {
        aiBusy = false;
        if (unknown.size > 0) scheduleAI();
      }
    }

    // Run the instant pass NOW (before paint where possible), then keep up with
    // navigation / async content. localPass is idempotent, so re-runs triggered
    // by our own writes settle immediately.
    localPass();
    observer = new MutationObserver(() => { if (!applying) localPass(); });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });

    return () => {
      observer?.disconnect();
      if (aiTimer) clearTimeout(aiTimer);
    };
  }, [language]);

  return null;
}
