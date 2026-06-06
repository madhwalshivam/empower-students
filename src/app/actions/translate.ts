'use server';

import { claudeJson } from '@/lib/claude/client';

// Batch-translate UI strings English -> Hindi for the runtime translator.
// Returns a map of { originalString: hindiString }. Strings not confidently
// translated are simply omitted (the caller keeps the English). Safe to call
// with no API key — it just returns {} and the UI stays on the seed dictionary.
export async function translateBatchAction(texts: string[]): Promise<Record<string, string>> {
  const uniq = Array.from(
    new Set((texts || []).map((t) => (t || '').trim()).filter(Boolean))
  ).slice(0, 80);

  if (uniq.length === 0) return {};

  const system =
    'You are a professional UI localizer for an Indian parenting & child-development web app. ' +
    'Translate each English UI string into natural, simple, friendly Hindi (Devanagari script) — the kind an Indian parent reads easily. ' +
    'RULES: translate meaning, not word-for-word; keep it concise so it still fits buttons and labels; ' +
    'keep numbers, currency (₹, "cr"/credits as क्रेडिट), and product/brand names sensible; ' +
    'transliterate human names into Devanagari; never add quotes, notes, or extra text.';

  const userPrompt =
    'Translate each of these strings to Hindi. Return ONLY a JSON object that maps the EXACT original ' +
    'English string (as the key) to its Hindi translation (as the value).\n\nStrings:\n' +
    JSON.stringify(uniq);

  try {
    const obj = await claudeJson(system, userPrompt, 4000, 0.2);
    if (!obj || typeof obj !== 'object') return {};
    const out: Record<string, string> = {};
    for (const key of uniq) {
      const val = (obj as Record<string, unknown>)[key];
      if (typeof val === 'string' && val.trim()) out[key] = val.trim();
    }
    return out;
  } catch (err) {
    console.error('[translateBatchAction] failed:', err);
    return {};
  }
}
