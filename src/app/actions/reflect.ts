'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { claudeChat, claudeJson } from '@/lib/claude/client';
import { calcAgeYears } from '@/lib/evaluations/engine';
import fs from 'fs';
import path from 'path';

const REFLECT_PRICE = 1000; // 1000 credits
const HAIKU_MODEL = 'claude-3-5-haiku-20241022';
const SONNET_MODEL = 'claude-3-5-sonnet-20241022';

// 9 areas matching PHP includes/parent_eval_v3.php
function getReflectAreas() {
  return {
    child_daily: {
      emoji: '🧒',
      label_en: 'Child — daily life',
      label_hi: 'बच्चे की रोज़ की ज़िंदगी',
      sample_q_en: "How is your child doing in their daily life — sleep, eating, how they interact with you?",
      sample_q_hi: "आपका बच्चा रोज़ की ज़िंदगी में कैसा है — सोना, खाना, आपके साथ कैसे रहते हैं?",
    },
    child_study: {
      emoji: '📚',
      label_en: 'Child — studies & school',
      label_hi: 'बच्चे की पढ़ाई और स्कूल',
      sample_q_en: "How are studies and school going for them right now?",
      sample_q_hi: "पढ़ाई और स्कूल इस वक़्त कैसा चल रहा है उनके लिए?",
    },
    child_peers: {
      emoji: '👥',
      label_en: 'Child — peers & social',
      label_hi: 'दोस्त और social ज़िंदगी',
      sample_q_en: "Tell me about their friends and how they get along with peers.",
      sample_q_hi: "उनके दोस्तों के बारे में बताइए — peers के साथ कैसा रिश्ता है?",
    },
    child_emotion: {
      emoji: '💗',
      label_en: 'Child — emotional life (good + anxious)',
      label_hi: 'बच्चे की भावनात्मक ज़िंदगी',
      sample_q_en: "What good qualities do you see in them — and what worries you about how they handle emotions?",
      sample_q_hi: "उनमें क्या-क्या अच्छाई दिखती है — और किस बात की चिंता है कि वो भावनाओं को कैसे संभालते हैं?",
    },
    future_hopes: {
      emoji: '🌅',
      label_en: 'Your hopes & fears for them',
      label_hi: 'उनके भविष्य की उम्मीदें और डर',
      sample_q_en: "When you think about their future — what excites you, what scares you?",
      sample_q_hi: "उनके भविष्य के बारे में सोचते हैं तो क्या उम्मीद और क्या डर है मन में?",
    },
    couple: {
      emoji: '💑',
      label_en: 'Couple / spouse',
      label_hi: 'पति-पत्नी का रिश्ता',
      sample_q_en: "How are you and your spouse doing — in raising them, in just being together?",
      sample_q_hi: "आप और आपके spouse कैसे हैं — बच्चों को पालने में, अपनी ज़िंदगी में?",
    },
    family: {
      emoji: '🏠',
      label_en: 'Extended family / household',
      label_hi: 'परिवार और घर का माहौल',
      sample_q_en: "What's happening at home with grandparents, in-laws, the wider family?",
      sample_q_hi: "घर पर दादा-दादी, सास-ससुर, बाक़ी परिवार के साथ कैसा माहौल है?",
    },
    money_work: {
      emoji: '💼',
      label_en: 'Finances & work pressure',
      label_hi: 'पैसा और काम का दबाव',
      sample_q_en: "How are finances and your work going right now? Any pressure there?",
      sample_q_hi: "पैसा और आपका काम इन दिनों कैसा है? कोई pressure?",
    },
    self: {
      emoji: '🌱',
      label_en: 'You — your own wellbeing',
      label_hi: 'आप — अपनी देखभाल',
      sample_q_en: "And you yourself — how are you sleeping, who do you turn to when it gets heavy?",
      sample_q_hi: "और आप ख़ुद — नींद कैसी है, बोझ बढ़ने पर किससे बात करते हैं?",
    },
  };
}

function getPhaseLabel(n: number): string {
  const map: Record<number, string> = {
    1: 'Opening — warm welcome, baseline tone',
    2: 'Child behaviour & daily interaction',
    3: 'Spouse / co-parent alignment',
    4: 'Joint family & generational pressure',
    5: 'Parent\'s own emotional state',
    6: 'Body & energy — sleep, exhaustion, stress signals',
    7: 'Hope, fear, identity as a parent of a special-needs child',
    8: 'Support network — who truly understands',
    9: 'What better looks like — readiness to change',
    10: 'Closing — direction + one small step',
  };
  return map[n] || 'Reflection';
}

function scrubVoicePhrases(s: string): string {
  if (!s) return s;
  let clean = s;
  const patterns = [
    /[—–-]?\s*आवाज़[^।\.]{0,80}[।\.]/ug,
    /[—–-]?\s*आवाज\s+में[^।\.]{0,80}[।\.]/ug,
    /[—–-]?\s*सुना मैंने[^।\.]{0,80}[।\.]/ug,
    /[—–-]?\s*स्वर[^।\.]{0,80}[।\.]/ug,
    /[—–-]?\s*टोन[^।\.]{0,80}[।\.]/ug,
    /\s*[—–-]?\s*in your voice[^.]{0,80}\./ig,
    /\s*[—–-]?\s*your tone[^.]{0,80}\./ig,
    /\s*[—–-]?\s*I (heard|hear) (it|that|something)[^.]{0,80}\./ig,
    /\s*[—–-]?\s*sounds like[^.]{0,80}\./ig,
    /\s*[—–-]?\s*from how you (said|say|sound)[^.]{0,80}\./ig,
  ];
  for (const p of patterns) {
    clean = clean.replace(p, '');
  }
  clean = clean.replace(/\s{2,}/g, ' ');
  return clean.trim();
}

async function getCoverageSnapshot(sessionId: number, supabaseAdmin: any) {
  const allKeys = Object.keys(getReflectAreas());

  const { data: session } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('eval_areas_covered')
    .eq('id', sessionId)
    .single();

  const csv = session?.eval_areas_covered || '';
  const stored = csv.split(',').map((x: string) => x.trim()).filter(Boolean);

  const { data: turns } = await supabaseAdmin
    .from('parent_reflect_turns')
    .select('signals_json')
    .eq('session_id', sessionId);

  const fromTurns: string[] = [];
  if (turns) {
    for (const row of turns) {
      if (row.signals_json) {
        try {
          const sj = typeof row.signals_json === 'string' ? JSON.parse(row.signals_json) : row.signals_json;
          if (sj?.covered_area && allKeys.includes(sj.covered_area)) {
            fromTurns.push(sj.covered_area);
          }
        } catch {}
      }
    }
  }

  const coveredSet = new Set([...stored, ...fromTurns]);
  const covered = Array.from(coveredSet).filter(k => allKeys.includes(k));
  const pending = allKeys.filter(k => !covered.includes(k));

  return {
    areas_covered: covered,
    areas_pending: pending,
    pct_covered: allKeys.length > 0 ? Math.round((covered.length / allKeys.length) * 100) : 0,
  };
}

async function markAreaCovered(sessionId: number, areaKey: string, supabaseAdmin: any) {
  const valid = Object.keys(getReflectAreas());
  if (!valid.includes(areaKey)) return;

  const cs = await getCoverageSnapshot(sessionId, supabaseAdmin);
  if (cs.areas_covered.includes(areaKey)) return;

  cs.areas_covered.push(areaKey);
  const newCsv = Array.from(new Set(cs.areas_covered)).join(',');

  await supabaseAdmin
    .from('parent_reflect_sessions')
    .update({ eval_areas_covered: newCsv })
    .eq('id', sessionId);
}

export async function startReflectSession(childId?: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { error: 'Not signed in' };
  }

  const supabaseAdmin = createAdminClient();

  // Validate child if provided
  if (childId && childId > 0) {
    const { data: child, error: childErr } = await supabaseAdmin
      .from('children')
      .select('*')
      .eq('id', childId)
      .eq('parent_id', user.id)
      .single();

    if (childErr || !child) {
      return { error: 'Please select a valid child context.' };
    }
  }

  // Resume check: latest in_progress session within 24h
  const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  const { data: existing } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('*')
    .eq('parent_id', user.id)
    .eq('status', 'in_progress')
    .gt('last_activity_at', oneDayAgo)
    .order('id', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (existing && Number(existing.turn_count) > 0) {
    // Retrieve previous answered turns and current open turn
    const { data: priorTurns } = await supabaseAdmin
      .from('parent_reflect_turns')
      .select('turn_no, phase, question, transcript')
      .eq('session_id', existing.id)
      .not('transcript', 'is', null)
      .order('turn_no', { ascending: true });

    const { data: openTurn } = await supabaseAdmin
      .from('parent_reflect_turns')
      .select('*')
      .eq('session_id', existing.id)
      .eq('turn_no', existing.turn_count)
      .single();

    if (openTurn) {
      return {
        session_id: existing.id,
        turn_no: openTurn.turn_no,
        phase: openTurn.phase,
        question: openTurn.question,
        options: openTurn.options_json ? (typeof openTurn.options_json === 'string' ? JSON.parse(openTurn.options_json) : openTurn.options_json) : null,
        prior_turns: priorTurns || [],
      };
    }
  }

  // Clean old in-progress session if any
  if (existing) {
    await supabaseAdmin
      .from('parent_reflect_sessions')
      .update({ status: 'abandoned' })
      .eq('id', existing.id);
  }

  // Check balance: MUST be >= 1000 (do not deduct immediately)
  const { data: parent } = await supabaseAdmin
    .from('parents')
    .select('credits, name')
    .eq('id', user.id)
    .single();

  const balance = parent?.credits || 0;
  if (balance < REFLECT_PRICE) {
    return {
      error: 'insufficient',
      need: REFLECT_PRICE,
      balance,
    };
  }

  // Start fresh
  const { data: newSession, error: sErr } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .insert({
      parent_id: user.id,
      child_id: childId || null,
      status: 'in_progress',
      cost_paid: 0, // charged on complete
      current_phase: 1,
      turn_count: 1,
    })
    .select('id')
    .single();

  if (sErr || !newSession) {
    console.error('Reflect Session create error:', sErr);
    return { error: 'Could not start reflection session. Please try again.' };
  }

  // Count parent children to phrase opening naturally
  const { data: children } = await supabaseAdmin
    .from('children')
    .select('*')
    .eq('parent_id', user.id);

  const nKids = children?.length || 0;
  const parentName = parent?.name || '';
  const parentFirst = parentName.split(' ')[0];

  // Determine initial language based on child's mother tongue
  let language = 'hi';
  if (children && children.length > 0) {
    let hi = 0, en = 0;
    for (const c of children) {
      const mt = (c.mother_tongue || '').toLowerCase().trim();
      if (mt.includes('hindi') || mt === 'hi') hi++; else en++;
    }
    language = hi >= en ? 'hi' : 'en';
  }

  let opening = '';
  if (language === 'hi') {
    opening = (parentFirst ? `नमस्ते ${parentFirst}। ` : 'नमस्ते। ')
      + 'ये एक प्राइवेट जगह है — सिर्फ़ आपके लिए। कोई सही या ग़लत जवाब नहीं है, और जब चाहें थोड़ा रुक सकते हैं। ';
    opening += nKids >= 2
      ? 'धीरे-धीरे शुरू करते हैं — मुझे बताइए, घर पर आजकल आपका दिन कैसा बीतता है?'
      : 'धीरे-धीरे शुरू करते हैं — मुझे बताइए, आजकल घर पर आपका दिन कैसा बीतता है?';
  } else {
    opening = (parentFirst ? `Hi ${parentFirst}. ` : 'Hello. ')
      + 'Welcome — this is a private space, just for you. There are no right answers here, and you can pause whenever you need to. ';
    opening += 'To start gently — tell me, what does a typical day look like for you at home these days?';
  }

  const defaultOptions = language === 'hi'
    ? ['बहुत व्यस्त रहता है', 'ठीक-ठाक चल रहा है', 'काफ़ी मुश्किल भरा है', 'विस्तार से बताती हूँ…']
    : ['Very busy', 'Going okay', 'Quite challenging', 'Let me explain…'];

  const { data: firstTurn } = await supabaseAdmin
    .from('parent_reflect_turns')
    .insert({
      session_id: newSession.id,
      turn_no: 1,
      phase: 1,
      question: opening,
      question_intent: 'probe',
      options_json: JSON.stringify(defaultOptions),
    })
    .select('id')
    .single();

  return {
    session_id: newSession.id,
    turn_no: 1,
    phase: 1,
    question: opening,
    options: defaultOptions,
    prior_turns: [],
  };
}

export async function submitReflectAnswer(
  sessionId: number,
  turnNo: number,
  phase: number,
  transcript: string,
  timeSeconds: number,
  acoustic: any,
  selectedOption?: string,
  audioFileBase64?: string
) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { error: 'Not signed in' };
  }

  const supabaseAdmin = createAdminClient();

  // Validate session
  const { data: session, error: sErr } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('*')
    .eq('id', sessionId)
    .eq('parent_id', user.id)
    .single();

  if (sErr || !session || session.status !== 'in_progress') {
    return { error: 'Session not found or already completed.' };
  }

  // Audio upload if base64 provided
  let audioPath = null;
  if (audioFileBase64) {
    try {
      const buffer = Buffer.from(audioFileBase64, 'base64');
      const uploadDir = path.join(process.cwd(), 'public', 'uploads', 'reflect');
      if (!fs.existsSync(uploadDir)) {
        fs.mkdirSync(uploadDir, { recursive: true });
      }
      const fname = `t${turnNo}_${Date.now()}.webm`;
      const dest = path.join(uploadDir, fname);
      fs.writeFileSync(dest, buffer);
      audioPath = `/uploads/reflect/${fname}`;
    } catch (e) {
      console.error('Audio write error:', e);
    }
  }

  const answer = transcript || selectedOption || '';
  if (!answer.trim()) {
    return { error: 'Please say or type something.' };
  }

  // 1. Detect emotions from transcript + acoustics using Haiku
  let emotions = null;
  if (transcript && transcript.trim().length >= 3) {
    const sigLines = [];
    if (acoustic?.duration_sec) sigLines.push(`duration ${Math.round(acoustic.duration_sec * 10) / 10}s`);
    if (acoustic?.wpm) sigLines.push(`speed ${Math.round(acoustic.wpm)} WPM`);
    if (acoustic?.silence_ratio) sigLines.push(`${Math.round(acoustic.silence_ratio * 100)}% silent`);
    if (acoustic?.time_to_first_speech_sec) sigLines.push(`started after ${Math.round(acoustic.time_to_first_speech_sec * 10) / 10}s`);
    const sigStr = sigLines.length > 0 ? `\nAcoustic signals: ${sigLines.join(', ')}` : '';

    const sysEm = `You are an emotion-detection assistant for a private voice-driven reflection session `
      + `with a parent of a special-needs child. Given the transcript and acoustic signals, `
      + `estimate emotion intensities (0.0–1.0). Be conservative — most emotions sit at 0.0–0.25; `
      + `only clearly present emotions exceed 0.5.\n\n`
      + `GUIDELINES:\n`
      + `- Long pauses + low volume + slow speech → exhaustion, grief, resignation\n`
      + `- Fast clipped speech with high variance → anxiety, frustration, overwhelm\n`
      + `- Monotone flat delivery → numbness, dissociation, burnout\n`
      + `- Voice catches, breaks, or volume drops mid-sentence → guilt, shame, suppressed grief\n`
      + `- Words say 'fine' but voice is fast/sharp/dead → MISMATCH, the truer feeling is in the voice\n`
      + `- Parents often hide negative emotions to seem 'good' — look for subtle voice signals\n`
      + `- 'Protective love' is real and present — name it when warm voice + child-mention coexist\n\n`
      + `OUTPUT (JSON only, no prose, no fences):\n`
      + `{\n`
      + `  "sadness": 0.0,\n`
      + `  "guilt": 0.0,\n`
      + `  "shame": 0.0,\n`
      + `  "anger": 0.0,\n`
      + `  "frustration": 0.0,\n`
      + `  "anxiety": 0.0,\n`
      + `  "fear": 0.0,\n`
      + `  "exhaustion": 0.0,\n`
      + `  "loneliness": 0.0,\n`
      + `  "hope": 0.0,\n`
      + `  "protective_love": 0.0,\n`
      + `  "resignation": 0.0,\n`
      + `  "felt_sense": "one short sentence describing the underlying emotional tone of this answer"\n`
      + `}`;

    const usrEm = `Transcript: "${transcript}"${sigStr}`;

    const resEm = await claudeJson(sysEm, usrEm, 400, 0.2, HAIKU_MODEL);
    if (resEm) {
      emotions = {
        sadness: Math.max(0, Math.min(1, Number(resEm.sadness || 0))),
        guilt: Math.max(0, Math.min(1, Number(resEm.guilt || 0))),
        shame: Math.max(0, Math.min(1, Number(resEm.shame || 0))),
        anger: Math.max(0, Math.min(1, Number(resEm.anger || 0))),
        frustration: Math.max(0, Math.min(1, Number(resEm.frustration || 0))),
        anxiety: Math.max(0, Math.min(1, Number(resEm.anxiety || 0))),
        fear: Math.max(0, Math.min(1, Number(resEm.fear || 0))),
        exhaustion: Math.max(0, Math.min(1, Number(resEm.exhaustion || 0))),
        loneliness: Math.max(0, Math.min(1, Number(resEm.loneliness || 0))),
        hope: Math.max(0, Math.min(1, Number(resEm.hope || 0))),
        protective_love: Math.max(0, Math.min(1, Number(resEm.protective_love || 0))),
        resignation: Math.max(0, Math.min(1, Number(resEm.resignation || 0))),
        felt_sense: String(resEm.felt_sense || '').trim(),
      };
    }
  }

  // Update current turn row
  await supabaseAdmin
    .from('parent_reflect_turns')
    .update({
      transcript: answer,
      audio_path: audioPath,
      emotions_json: emotions ? JSON.stringify(emotions) : null,
      acoustic_json: acoustic ? JSON.stringify(acoustic) : null,
      answered_at: new Date().toISOString(),
      time_seconds: timeSeconds,
    })
    .eq('session_id', sessionId)
    .eq('turn_no', turnNo);

  // 2. Guide call (Sonnet) to decide next step
  const { data: priorTurns } = await supabaseAdmin
    .from('parent_reflect_turns')
    .select('*')
    .eq('session_id', sessionId)
    .order('turn_no', { ascending: true });

  const currentCoverage = await getCoverageSnapshot(sessionId, supabaseAdmin);

  // Mark latest answered turn's covered area (if guides matched one on the previous run)
  const currentTurnData = priorTurns?.find(t => t.turn_no === turnNo);
  if (currentTurnData?.signals_json) {
    try {
      const tsj = typeof currentTurnData.signals_json === 'string' ? JSON.parse(currentTurnData.signals_json) : currentTurnData.signals_json;
      if (tsj?.covered_area) {
        await markAreaCovered(sessionId, tsj.covered_area, supabaseAdmin);
      }
    } catch {}
  }

  // Load children context
  const { data: children } = await supabaseAdmin
    .from('children')
    .select('*')
    .eq('parent_id', user.id);

  let childrenContext = '(No children on file. Treat the conversation as fully about the parent.)';
  let language = 'hi';
  if (children && children.length > 0) {
    let hi = 0, en = 0;
    const lines = children.map(c => {
      const age = c.dob ? calcAgeYears(c.dob) : 7;
      const bits = [c.name || '(unnamed)', `${age}y`, c.gender || '', c.diagnosis || '', c.mother_tongue ? `mt: ${c.mother_tongue}` : ''].filter(Boolean);
      const mt = (c.mother_tongue || '').toLowerCase().trim();
      if (mt.includes('hindi') || mt === 'hi') hi++; else en++;
      return `- ${bits.join(', ')}`;
    });
    childrenContext = lines.join('\n');
    language = hi >= en ? 'hi' : 'en';
  }

  const previousQuestions = priorTurns?.map(t => t.question).filter(Boolean) || [];

  const targetTurns = 14;
  const maxTurns = 20;
  const sys = prBuildGuideSystem(phase, turnNo, targetTurns, maxTurns, childrenContext, language, currentCoverage, previousQuestions);
  const usrPayload = prBuildHistoryPayload(priorTurns || [], answer, emotions, acoustic);

  // Call Sonnet for guide decision
  const decision = await claudeJson(sys, usrPayload, 1200, 0.4, SONNET_MODEL);

  if (!decision) {
    return { error: 'Counsellor is taking a breath. Please try again.' };
  }

  const nextPhase = Number(decision.next_phase || phase);
  const isDone = decision.done === true || turnNo >= maxTurns;

  // Persist signals on the turn just answered
  const signals = {
    ...(decision.signals || {}),
    covered_area: decision.covered_area || '',
  };
  await supabaseAdmin
    .from('parent_reflect_turns')
    .update({
      signals_json: JSON.stringify(signals),
    })
    .eq('session_id', sessionId)
    .eq('turn_no', turnNo);

  // Mark the new covered area as well
  if (decision.covered_area) {
    await markAreaCovered(sessionId, decision.covered_area, supabaseAdmin);
  }

  if (isDone) {
    await finalizeReflectSession(sessionId, supabaseAdmin);
    return {
      done: true,
      reflection: scrubVoicePhrases(decision.reflection || ''),
    };
  }

  // Create next turn
  const nextTurnNo = turnNo + 1;
  const nextQ = scrubVoicePhrases(decision.next_question || '');
  const nextOptions = decision.next_options || [];

  await supabaseAdmin
    .from('parent_reflect_turns')
    .insert({
      session_id: sessionId,
      turn_no: nextTurnNo,
      phase: nextPhase,
      question: nextQ,
      question_intent: decision.intent || 'probe',
      options_json: JSON.stringify(nextOptions),
    });

  await supabaseAdmin
    .from('parent_reflect_sessions')
    .update({
      turn_count: nextTurnNo,
      current_phase: nextPhase,
      last_activity_at: new Date().toISOString(),
    })
    .eq('id', sessionId);

  return {
    done: false,
    reflection: scrubVoicePhrases(decision.reflection || ''),
    tone_insight: decision.tone_insight || '',
    turn: {
      turn_no: nextTurnNo,
      phase: nextPhase,
      question: nextQ,
      options: nextOptions,
    },
  };
}

export async function finishReflectEarly(sessionId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  const { data: session } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('*')
    .eq('id', sessionId)
    .eq('parent_id', user.id)
    .single();

  if (!session || session.status !== 'in_progress') {
    return { error: 'Session not active' };
  }

  // Generate closing summary turn in database
  const { data: lastTurn } = await supabaseAdmin
    .from('parent_reflect_turns')
    .select('*')
    .eq('session_id', sessionId)
    .order('turn_no', { ascending: false })
    .limit(1)
    .single();

  if (lastTurn && !lastTurn.transcript) {
    // Fill transcript of open turn
    await supabaseAdmin
      .from('parent_reflect_turns')
      .update({
        transcript: '(Finished early)',
        answered_at: new Date().toISOString(),
      })
      .eq('id', lastTurn.id);
  }

  await finalizeReflectSession(sessionId, supabaseAdmin);
  return { ok: true };
}

export async function discardReflectSession(sessionId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  await supabaseAdmin
    .from('parent_reflect_sessions')
    .update({ status: 'abandoned' })
    .eq('id', sessionId)
    .eq('parent_id', user.id);

  return { ok: true };
}

export async function reopenReflectSession(sessionId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();

  const { data: session } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('*')
    .eq('id', sessionId)
    .eq('parent_id', user.id)
    .single();

  if (!session || session.status !== 'completed') {
    return { error: 'Only completed sessions can be reopened.' };
  }

  const maxFollowups = 3;
  const currentFollowups = Number(session.followup_count || 0);
  if (currentFollowups >= maxFollowups) {
    return { error: 'Maximum follow-up limit reached.' };
  }

  // Count parent children to find language
  const { data: children } = await supabaseAdmin
    .from('children')
    .select('mother_tongue')
    .eq('parent_id', user.id);

  let language = 'hi';
  if (children && children.length > 0) {
    let hi = 0, en = 0;
    for (const c of children) {
      const mt = (c.mother_tongue || '').toLowerCase().trim();
      if (mt.includes('hindi') || mt === 'hi') hi++; else en++;
    }
    language = hi >= en ? 'hi' : 'en';
  }

  const remaining = maxFollowups - currentFollowups;
  let q = '';
  if (language === 'hi') {
    q = "वापस आने का शुक्रिया। पिछली बार जो हमने बात की, उसके बाद क्या कुछ ऐसा है जो आप जोड़ना चाहेंगी, या पूछना चाहेंगी? आराम से बताइए।";
  } else {
    q = "Welcome back. Since our last conversation, is there anything you'd like to add, or anything you've been wanting to ask? Take your time.";
  }

  const nextTurnNo = Number(session.turn_count || 0) + 1;

  await supabaseAdmin
    .from('parent_reflect_sessions')
    .update({
      status: 'in_progress',
      turn_count: nextTurnNo,
      last_activity_at: new Date().toISOString(),
    })
    .eq('id', sessionId);

  const defaultOptions = language === 'hi'
    ? ['कुछ नहीं, सब ठीक है', 'विस्तार से बताती हूँ…']
    : ['Nothing to add', 'Let me explain…'];

  await supabaseAdmin
    .from('parent_reflect_turns')
    .insert({
      session_id: sessionId,
      turn_no: nextTurnNo,
      phase: 10,
      question: q,
      question_intent: 'probe',
      options_json: JSON.stringify(defaultOptions),
    });

  return {
    session_id: sessionId,
    turn_no: nextTurnNo,
    phase: 10,
    question: q,
    options: defaultOptions,
    remaining,
  };
}

export async function getReflectSessionReport(sessionId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  const { data: session } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select(`
      *,
      children (
        name,
        dob
      )
    `)
    .eq('id', sessionId)
    .eq('parent_id', user.id)
    .single();

  if (!session || session.status !== 'completed') {
    return { error: 'Report not ready' };
  }

  let listing = null;
  if (session.v3_listing_json) {
    try {
      listing = typeof session.v3_listing_json === 'string' ? JSON.parse(session.v3_listing_json) : session.v3_listing_json;
    } catch {}
  }

  return {
    parent_summary_md: session.parent_summary_md,
    v3_listing: listing,
    risk_level: session.admin_risk_level,
    safety_red_flag: session.sig_safety_red_flag,
    child_name: session.children?.name,
    completed_at: session.completed_at,
  };
}

// Internal finalization helper
async function finalizeReflectSession(sessionId: number, supabaseAdmin: any) {
  const { data: session } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('*')
    .eq('id', sessionId)
    .single();

  const isFollowupClose = !!session.parent_summary_md;

  await supabaseAdmin
    .from('parent_reflect_sessions')
    .update({
      status: 'completed',
      completed_at: new Date().toISOString(),
      generated_at: new Date().toISOString(),
    })
    .eq('id', sessionId);

  if (isFollowupClose) {
    await supabaseAdmin
      .from('parent_reflect_sessions')
      .update({
        followup_count: Number(session.followup_count || 0) + 1,
      })
      .eq('id', sessionId);
  }

  // Charge wallet if not already charged
  const { data: ledgerEntry } = await supabaseAdmin
    .from('wallet_ledger')
    .select('id')
    .eq('parent_id', session.parent_id)
    .eq('service_key', 'mod_parent_reflect')
    .eq('ref_id', sessionId)
    .eq('amount', -REFLECT_PRICE)
    .maybeSingle();

  if (!ledgerEntry) {
    const { data: parent } = await supabaseAdmin
      .from('parents')
      .select('credits')
      .eq('id', session.parent_id)
      .single();

    const bal = parent?.credits || 0;
    const nextCredits = Math.max(0, bal - REFLECT_PRICE);

    // Update parent balance
    await supabaseAdmin
      .from('parents')
      .update({ credits: nextCredits })
      .eq('id', session.parent_id);

    // Log ledger
    await supabaseAdmin.from('wallet_ledger').insert({
      parent_id: session.parent_id,
      amount: -REFLECT_PRICE,
      balance_after: nextCredits,
      service_key: 'mod_parent_reflect',
      ref_id: sessionId,
      reason: 'Parent Reflection — report generated',
      created_by: 'system',
    });

    await supabaseAdmin
      .from('parent_reflect_sessions')
      .update({ cost_paid: REFLECT_PRICE })
      .eq('id', sessionId);
  }

  // Aggregate signals across all turns
  const { data: turns } = await supabaseAdmin
    .from('parent_reflect_turns')
    .select('signals_json')
    .eq('session_id', sessionId);

  const sums = {
    marital_stress: 0.0,
    in_law_stress: 0.0,
    parent_burnout: 0.0,
    child_distress: 0.0,
    isolation: 0.0,
  };
  let count = 0;
  let redFlag = 0;

  if (turns) {
    for (const row of turns) {
      if (row.signals_json) {
        try {
          const s = typeof row.signals_json === 'string' ? JSON.parse(row.signals_json) : row.signals_json;
          if (s) {
            count++;
            sums.marital_stress += Number(s.marital_stress || 0);
            sums.in_law_stress += Number(s.in_law_stress || 0);
            sums.parent_burnout += Number(s.parent_burnout || 0);
            sums.child_distress += Number(s.child_distress || 0);
            sums.isolation += Number(s.isolation || 0);
            if (s.safety_red_flag === 1 || s.safety_red_flag === true) {
              redFlag = 1;
            }
          }
        } catch {}
      }
    }
  }

  if (count > 0) {
    sums.marital_stress = Math.round((sums.marital_stress / count) * 1000) / 1000;
    sums.in_law_stress = Math.round((sums.in_law_stress / count) * 1000) / 1000;
    sums.parent_burnout = Math.round((sums.parent_burnout / count) * 1000) / 1000;
    sums.child_distress = Math.round((sums.child_distress / count) * 1000) / 1000;
    sums.isolation = Math.round((sums.isolation / count) * 1000) / 1000;
  }

  const maxVal = Math.max(sums.parent_burnout, sums.marital_stress, sums.in_law_stress, sums.child_distress, sums.isolation);
  let risk = 'green';
  if (redFlag === 1) risk = 'red';
  else if (maxVal >= 0.6) risk = 'amber';

  const hoursMap: Record<string, number> = { red: 24, amber: 48, green: 72 };
  const followBy = new Date(Date.now() + (hoursMap[risk] || 72) * 3600 * 1000).toISOString();

  await supabaseAdmin
    .from('parent_reflect_sessions')
    .update({
      sig_marital_stress: sums.marital_stress,
      sig_in_law_stress: sums.in_law_stress,
      sig_parent_burnout: sums.parent_burnout,
      sig_child_distress: sums.child_distress,
      sig_isolation: sums.isolation,
      sig_safety_red_flag: redFlag,
      admin_risk_level: risk,
      admin_follow_up_by: followBy,
    })
    .eq('id', sessionId);

  // Generate parent-facing report & structured listing in parallel
  await Promise.all([
    generateParentReportMd(sessionId, supabaseAdmin),
    generateStructuredListingJson(sessionId, supabaseAdmin),
  ]);
}

async function generateParentReportMd(sessionId: number, supabaseAdmin: any) {
  const { data: session } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('*')
    .eq('id', sessionId)
    .single();

  const { data: turns } = await supabaseAdmin
    .from('parent_reflect_turns')
    .select('*')
    .eq('session_id', sessionId)
    .order('turn_no', { ascending: true });

  if (!turns || turns.length === 0) return;

  // Determine language from children
  const { data: children } = await supabaseAdmin
    .from('children')
    .select('mother_tongue')
    .eq('parent_id', session.parent_id);

  let hi = 0, en = 0;
  if (children) {
    for (const c of children) {
      const mt = (c.mother_tongue || '').toLowerCase().trim();
      if (mt.includes('hindi') || mt === 'hi') hi++; else en++;
    }
  }
  const language = hi >= en ? 'hi' : 'en';

  const convoLines = ['=== Conversation transcript ==='];
  for (const t of turns) {
    const q = (t.question || '').trim();
    const a = (t.transcript || '').trim();
    if (q) convoLines.push(`AI: ${q}`);
    if (a) convoLines.push(`Parent: ${a}`);
  }
  const usrText = convoLines.join('\n');

  const sys = prBuildParentReportSystem(language, hi + en);

  const md = await claudeChat(sys, [{ role: 'user', content: usrText }], 1800, 0.6, SONNET_MODEL);
  if (md) {
    let clean = md.trim();
    if (clean.startsWith('```')) {
      clean = clean.replace(/^```(?:markdown|md)?\s*/i, '');
      clean = clean.replace(/\s*```\s*$/, '');
      clean = clean.trim();
    }
    await supabaseAdmin
      .from('parent_reflect_sessions')
      .update({ parent_summary_md: clean })
      .eq('id', sessionId);
  }
}

async function generateStructuredListingJson(sessionId: number, supabaseAdmin: any) {
  const { data: session } = await supabaseAdmin
    .from('parent_reflect_sessions')
    .select('*')
    .eq('id', sessionId)
    .single();

  const { data: turns } = await supabaseAdmin
    .from('parent_reflect_turns')
    .select('*')
    .eq('session_id', sessionId)
    .order('turn_no', { ascending: true });

  if (!turns || turns.length === 0) return;

  const convoLines = [];
  for (const t of turns) {
    const q = (t.question || '').trim();
    const a = (t.transcript || '').trim();
    if (q) convoLines.push(`AI: ${q.substring(0, 250)}`);
    if (a) convoLines.push(`PARENT: ${a.substring(0, 500)}`);
  }
  const convoText = convoLines.join('\n');

  // Determine language from children
  const { data: children } = await supabaseAdmin
    .from('children')
    .select('mother_tongue')
    .eq('parent_id', session.parent_id);

  let hi = 0, en = 0;
  if (children) {
    for (const c of children) {
      const mt = (c.mother_tongue || '').toLowerCase().trim();
      if (mt.includes('hindi') || mt === 'hi') hi++; else en++;
    }
  }
  const language = hi >= en ? 'hi' : 'en';

  const areas = getReflectAreas();
  const areaKeysList = Object.keys(areas).join(', ');
  let areaSpecs = '';
  for (const [key, a] of Object.entries(areas)) {
    areaSpecs += `  - ${key}: "${a.label_en}" — { use ${key} as the JSON object key }\n`;
  }

  const langBlock = language === 'hi'
    ? "Write 'finding', 'severity_note', and 'one_line_summary' fields in conversational HINDI (Devanagari, respectful 'आप'). Code-switching with English words is fine. Use phrases the parent ACTUALLY USED — quote 1-2 short phrases per area where natural."
    : "Write 'finding', 'severity_note', and 'one_line_summary' fields in warm conversational English. Use 1-2 short quoted phrases from the parent's actual answers where natural.";

  const sys = `You are a senior clinical psychologist generating a STRUCTURED LISTING REPORT from a parent's listening interview. The interview mapped 9 life areas. You will now produce one report row per area, plus an overall summary.

${langBlock}

CORE RULES:
1. This is a LISTING — NOT a solutions report. Do NOT write "try this" or "you should". The listing names the problem; the 7-day course addresses it.
2. For areas NOT mentioned in the conversation, mark "covered": false and use neutral language ("This area was not explored in today's interview.") — DO NOT make up findings.
3. INDEX (0-100) = burden score. 0 = no burden detected. 100 = severe, immediate. Calibrate honestly:
   - 0-20: green (no significant concern)
   - 21-40: light amber (minor concern, monitor)
   - 41-60: amber (real concern, attention needed)
   - 61-80: orange (heavy concern, urgent attention)
   - 81-100: red (critical, address immediately)
4. SEVERITY: low | moderate | high | critical (matches the bands above)
5. URGENCY: can_wait | this_month | this_week | today (today only if safety_red_flag or acute crisis)
6. COURSE_DAY: which of the 7 days of the home course best addresses this area. Use:
     1 = praise practice (child_climate)
     2 = couple connection (couple)
     3 = discipline reset (child behaviour)
     4 = self-care window (parent wellbeing)
     5 = boundary practice (joint family)
     6 = reach out (support / isolation)
     7 = reflection share (couple harmony)
   Pick the day whose theme most directly addresses this area. For areas that have no good match, set course_day to null.

OUTPUT — JSON ONLY, this exact shape:
{
  "areas": {
    "child_daily":    { "covered": true|false, "finding": "...", "index": 0-100, "severity": "...", "urgency": "...", "severity_note": "...", "course_day": 1-7 or null },
    "child_study":    { ... },
    "child_peers":    { ... },
    "child_emotion":  { ... },
    "future_hopes":   { ... },
    "couple":         { ... },
    "family":         { ... },
    "money_work":     { ... },
    "self":           { ... }
  },
  "overall_index": 0-100,
  "top_3_urgent_areas": ["area_key", "area_key", "area_key"],
  "one_line_summary": "ONE warm sentence that names what we heard today (in ${language}).",
  "course_plan": [
    { "day": 1, "theme": "Praise practice", "addresses_areas": ["child_daily", "child_emotion"], "why": "1 line tailored to THIS parent's situation" },
    { "day": 2, "theme": "Couple connection", "addresses_areas": ["couple"], "why": "..." },
    { "day": 3, "theme": "Discipline reset", "addresses_areas": ["child_emotion"], "why": "..." },
    { "day": 4, "theme": "Self-care window", "addresses_areas": ["self"], "why": "..." },
    { "day": 5, "theme": "Boundary practice", "addresses_areas": ["family"], "why": "..." },
    { "day": 6, "theme": "Reach out", "addresses_areas": ["self"], "why": "..." },
    { "day": 7, "theme": "Reflection share", "addresses_areas": ["couple"], "why": "..." }
  ],
  "safety_flag": 0|1
}

Area keys must be: ${areaKeysList}`;

  const usr = `=== Conversation transcript ===\n${convoText}\n\nGenerate the structured listing JSON now. JSON only.`;

  const parsed = await claudeJson(sys, usr, 3500, 0.4, SONNET_MODEL);
  if (parsed && parsed.areas) {
    parsed.language = language;
    parsed.generated_at = new Date().toISOString();
    parsed.session_id = sessionId;

    await supabaseAdmin
      .from('parent_reflect_sessions')
      .update({
        v3_listing_json: JSON.stringify(parsed),
        v3_listing_at: new Date().toISOString(),
      })
      .eq('id', sessionId);
  }
}

// ───────────────────────────────────────────────────────────
// Prompt builders matching PHP includes/parent_eval_v3.php
// ───────────────────────────────────────────────────────────
function prBuildGuideSystem(
  currentPhase: number,
  turnNo: number,
  target: number,
  cap: number,
  childrenContext: string,
  language: string,
  coverage: any,
  previousQuestions: string[]
): string {
  const areas = getReflectAreas();
  const areasCovered = coverage.areas_covered || [];
  const areasPending = coverage.areas_pending || Object.keys(areas);

  const coveredList = areasCovered.length === 0 ? '(none yet)' : areasCovered.join(', ');
  const pendingList = areasPending.length === 0 ? '(all covered)' : areasPending.join(', ');

  let areaDescriptions = '';
  for (const [key, a] of Object.entries(areas)) {
    areaDescriptions += `  • ${key} (${a.label_en}) — sample: "${a.sample_q_en}"\n`;
  }

  const turnsLeft = Math.max(0, target - turnNo);

  const prevQBlock = previousQuestions.length > 0
    ? `\n=== PREVIOUS QUESTIONS YOU HAVE ALREADY ASKED (do NOT repeat any of these — even with slight rewording) ===\n${previousQuestions.map((q, idx) => `  Q${idx + 1}: ${q.trim()}`).join('\n')}\n`
    : '';

  const langBlock = language === 'hi'
    ? "LANGUAGE: Speak in conversational HINDI (Devanagari). Warm, natural, code-switching with English words for modern concepts (anxiety, depression, screen, therapy, peers) is fine — natural for Indian parents. Use 'आप' (respectful). NEVER literary Hindi."
    : "LANGUAGE: Speak in warm, conversational English.";

  return `You are conducting a private, voice-driven LISTENING interview with a parent. Your job is to map the WHOLE LANDSCAPE of their life across 9 areas, in roughly 13-15 turns total. You are a wise, calm, completely non-judgemental listener.

CRITICAL RULE — YOU DO NOT GIVE ADVICE.
This interview is for HEARING, not fixing. Even if the parent says something where the "answer" feels obvious to you, your job is to:
  - acknowledge what you heard
  - reflect a phrase or feeling back
  - ASK ABOUT THE NEXT AREA
You NEVER say "you might try…", "have you considered…", "one thing that helps…", "what often works is…". No solutions, no experiments, no homework. The 7-day course (which they may buy later) is where solutions happen. Your job is the listing of the problem — and listing the problem IS the start of the solution.

YOUR JOB STRUCTURE:
- Cover 9 areas with one question each (plus follow-ups when needed). Total 13-15 turns.
- The parent should leave feeling fully heard across all dimensions of their life — not deeply analysed on one.

THE 9 AREAS YOU MUST COVER:
${areaDescriptions}

COVERAGE STATE:
- Already covered organically (don't re-ask): ${coveredList}
- Still to ask: ${pendingList}

PARENT'S CHILDREN (context — DO NOT name a specific child unless the parent does first):
${childrenContext}

${langBlock}

CORE PRINCIPLES:
- One question per turn, under 30 words.
- ALWAYS reference / acknowledge / mirror something from the parent's previous answer before asking the next area's question. Examples:
   • "When you say your father-in-law disagrees with everything — I hear how alone you feel in those decisions. Coming to a different area — how is your child doing with friends?"
   • "जब आप कहते हैं 'थक गई हूँ' — सुना मैंने। अब एक और चीज़ पूछूँ — पैसे और काम का कैसा pressure है इन दिनों?"
- Honour silence. If they pause or struggle, do NOT fill it with another question — gently rephrase or say "जो भी आये, कोई जल्दी नहीं" / "Take your time, no rush".
- Be Indian-context aware: joint families, log kya kahenge, izzat, financial reality of therapy, generational beliefs.
- If the parent surfaces something heavy in passing (e.g. "I sometimes cry alone"), spend ONE follow-up there to honour it, then GENTLY move to the next area. Do NOT dwell.

ADAPTIVE COVERAGE:
- If the parent already touched an area organically in a previous turn, DO NOT re-ask. Mark it covered and move to a pending area.
- If the parent has no joint family / has lost a parent / is a single parent — skip the relevant area entirely. Add an empty note for it later.
- If the parent's answers are very short (5-15 words each), they're nervous or guarded — ask shorter, easier questions; do not push depth.
- If the parent gives long answers (60+ words), they need the space — give one follow-up there, then keep moving across areas.

SAFETY OVERRIDE (NON-NEGOTIABLE):
If the parent describes self-harm thoughts, harm to the child, active domestic violence, or imminent crisis:
  - Set safety_red_flag: 1
  - Set intent: "slow"
  - Set done: false (one more turn at least)
  - In next_question: gently surface what you heard, validate it makes sense to feel that way, softly mention iCall (9152987821) or Vandrevala Foundation (1860-2662-345). Do NOT moralise. Do NOT say "you should call".

SIGNALS (track every turn, 0.0–1.0):
- child_distress, marital_stress, in_law_stress, financial_stress, parent_burnout, isolation, safety_red_flag (0|1)

OUTPUT — return ONLY this JSON, no prose:
{
  "reflection": "1-2 sentences mirroring what they said, in their words. Empty string for very first turn.",
  "tone_insight": "1 short sentence on what their voice + words suggest. Empty if not clear.",
  "covered_area": "key of the area their LAST answer mostly covered — one of: child_daily, child_study, child_peers, child_emotion, future_hopes, couple, family, money_work, self. Or empty string if their answer covered nothing structured.",
  "next_area": "key of the next area you will ask about. Pick from pending areas above.",
  "intent": "probe | mirror | move_on | slow | close",
  "signals": {
    "child_distress": 0.0,
    "marital_stress": 0.0,
    "in_law_stress": 0.0,
    "financial_stress": 0.0,
    "parent_burnout": 0.0,
    "isolation": 0.0,
    "safety_red_flag": 0
  },
  "next_question": "The single next question, under 30 words. MUST briefly mirror their last answer before asking about the next_area. NO advice, NO solutions, NO 'you might try'.",
  "done": false
}

PACING:
- This is turn ${turnNo}. Target is ${target} turns (hard cap ${cap}). ${turnsLeft} turns left.
- If turn_no >= (${target} - 1) AND most areas covered (5+), START WINDING UP. Set done: true.
- Closing question (when done=true): a warm "is there anything else weighing on you that we didn't touch today?" — give them one last open invitation, NOT a summary. No advice.

${prevQBlock}
REMEMBER: You are a listener, not a counsellor. The structured listing report (separately generated) is what will help the parent see all of this clearly. Your only job is to make them feel HEARD across all areas.`;
}

function prBuildHistoryPayload(
  priorTurns: any[],
  latestTranscript: string,
  latestEmotions: any,
  latestAcoustic: any
): string {
  const lines = ['=== CONVERSATION SO FAR ==='];
  if (!priorTurns || priorTurns.length === 0) {
    lines.push('(this is the first parent answer — only the latest answer is available)');
  } else {
    for (const t of priorTurns) {
      const q = (t.question || '').trim();
      const a = (t.transcript || '').trim();
      let emLine = '';
      if (t.emotions_json) {
        try {
          const em = typeof t.emotions_json === 'string' ? JSON.parse(t.emotions_json) : t.emotions_json;
          if (em) {
            const list = Object.entries(em)
              .filter(([k, v]) => k !== 'felt_sense' && typeof v === 'number' && v >= 0.4)
              .sort((a: any, b: any) => b[1] - a[1]);

            if (list.length > 0) {
              emLine = ` [voice: ${list.map((x: any) => `${x[0]} ${Math.round(x[1] * 100)}%`).join(', ')}]`;
            }
          }
        } catch {}
      }
      lines.push(`Turn ${t.turn_no} (phase ${t.phase}):`);
      lines.push(`  AI asked: ${q}`);
      if (a !== '') {
        lines.push(`  Parent said: ${a}${emLine}`);
      } else {
        lines.push(`  Parent: (no answer captured this turn)`);
      }
    }
  }

  lines.push('');
  lines.push('=== LATEST PARENT ANSWER (this turn — to be analysed for the next question) ===');
  lines.push(latestTranscript.trim());

  if (latestEmotions) {
    const list = Object.entries(latestEmotions)
      .filter(([k, v]) => k !== 'felt_sense' && typeof v === 'number' && v >= 0.35)
      .sort((a: any, b: any) => b[1] - a[1]);

    if (list.length > 0) {
      lines.push(`Voice emotion signals: ${list.map((x: any) => `${x[0]} ${Math.round(x[1] * 100)}%`).join(', ')}`);
      if (latestEmotions.felt_sense) {
        lines.push(`Felt sense: ${latestEmotions.felt_sense}`);
      }
    }
  }

  if (latestAcoustic) {
    const a = [];
    for (const k of ['wpm', 'pause_count', 'silence_ratio', 'duration_sec']) {
      if (typeof latestAcoustic[k] !== 'undefined') {
        const val = latestAcoustic[k];
        a.push(`${k}=${typeof val === 'number' ? Math.round(val * 100) / 100 : val}`);
      }
    }
    if (a.length > 0) {
      lines.push(`Acoustic: ${a.join(', ')}`);
    }
  }

  lines.push('');
  lines.push('Now produce the JSON decision for the next turn. Remember: the next_question must DIRECTLY build on what the parent just said, not jump to a generic phase question.');
  return lines.join('\n');
}

function prBuildParentReportSystem(language: string, nKids: number): string {
  const langBlock = language === 'hi'
    ? "Write this entire report in conversational HINDI (Devanagari). Warm, natural, non-formal. Use 'आप' (respectful you), never 'तुम'. English words for modern concepts (therapy, anxiety, parenting, support) are fine — Indians naturally code-switch."
    : "Write the entire report in warm, conversational English.";

  const kidsNote = nKids >= 2
    ? "The parent has multiple children. Refer to them as 'your children' / 'आपके बच्चे' generally — do not single out a specific child unless the parent named one."
    : "The parent has one child on file.";

  return `You are summarising a private voice reflection conducted with a parent of a special-needs child. The reflection is over. You will produce a warm written report the parent will read.

${langBlock}

CONTEXT:
${kidsNote}

TONE:
- Warm, hopeful, never clinical or preachy.
- Validate before suggesting. Recognise their effort.
- This parent is exhausted and carrying invisible weight. Speak to that.
- Avoid "you should". Use "you might consider", "one thing that often helps".
- India-aware: joint families, in-law dynamics, izzat, log kya kahenge, financial stress around therapy, generational beliefs about disability are real.
- DO NOT diagnose, DO NOT label, DO NOT prescribe specific therapies.

STRUCTURE — produce EXACTLY these markdown sections, in this order:

## Where you are now
*(2-3 sentences naming what you heard. Honest, validating. Reflect the dominant emotional tone of the conversation. Acknowledge their strengths — they showed up, they spoke, they care.)*

## A point to ponder
*(One thoughtful observation worth sitting with. Not advice. Not a fix. A noticing — perhaps a pattern, a contradiction the parent themselves surfaced, or an insight that respects their intelligence and invites their own reflection. 2-3 sentences.)*

## One small thing to try this week
*(Concrete, doable in 10 minutes a day, framed as "you might consider..." or "one thing that often helps parents in this place is...". Specific. NOT a list of don'ts.)*

## What modern parenting psychology says
*(One short paragraph — 2-3 sentences — connecting their situation to a real, evidence-based principle: e.g. attachment regulation, co-regulation under stress, secure base, repair after rupture, generational scripts. Name the principle in plain language. Connect it directly to what the parent is going through.)*

## How this helps your child
*(2-3 sentences explaining how this small change in the parent's state ripples to the child. Realistic, not magical. Children feel calm in calm bodies — that level of mechanism. Keep it grounded.)*

DO NOT include:
- Section headers other than the five above
- Diagnosis, advice to seek a specific therapy/medication
- Any reference to "you should" or "you must"
- Lists longer than 3 items
- Generic platitudes like "be kind to yourself"

Output ONLY the markdown report. No preamble, no JSON, no fences.`;
}
