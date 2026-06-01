export async function claudeChat(
  system: string,
  messages: Array<{ role: 'user' | 'assistant'; content: string }>,
  maxTokens: number = 1024,
  temperature: number = 0.4,
  modelName?: string
): Promise<string> {
  const apiKey = process.env.ANTHROPIC_API_KEY;
  const model = modelName || process.env.ANTHROPIC_MODEL || 'claude-sonnet-4-5';
  const apiUrl = 'https://api.anthropic.com/v1/messages';

  if (!apiKey) {
    console.error('[claude] ANTHROPIC_API_KEY not configured');
    return '';
  }

  try {
    const response = await fetch(apiUrl, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-api-key': apiKey,
        'anthropic-version': '2023-06-01',
      },
      body: JSON.stringify({
        model,
        max_tokens: maxTokens,
        temperature,
        system,
        messages,
      }),
    });

    if (!response.ok) {
      const errText = await response.text();
      console.error(`[claude] HTTP error ${response.status}: ${errText.slice(0, 400)}`);
      return '';
    }

    const data = await response.json();
    if (!data || !Array.isArray(data.content)) return '';

    for (const block of data.content) {
      if (block.type === 'text') {
        return block.text || '';
      }
    }
    return '';
  } catch (err: any) {
    console.error('[claude] request error:', err);
    return '';
  }
}

export async function claudeJson(
  system: string,
  userPrompt: string,
  maxTokens: number = 1024,
  temperature: number = 0.2,
  modelName?: string
): Promise<any | null> {
  const sys = `${system}\n\nReturn ONLY valid minified JSON. No prose, no code fences.`;
  const txt = await claudeChat(sys, [{ role: 'user', content: userPrompt }], maxTokens, temperature, modelName);
  if (!txt) return null;

  let clean = txt.trim();
  if (clean.includes('```')) {
    clean = clean.replace(/^```(?:json)?\s*/i, '');
    clean = clean.replace(/\s*```\s*$/, '');
    clean = clean.trim();
  }

  try {
    return JSON.parse(clean);
  } catch {
    // Attempt extract
    const match = clean.match(/(\{[\s\S]*\}|\[[\s\S]*\])/);
    if (match) {
      try {
        return JSON.parse(match[1]);
      } catch {
        // failed
      }
    }
    console.error('[claudeJson] failed parse:', clean.slice(0, 400));
    return null;
  }
}
