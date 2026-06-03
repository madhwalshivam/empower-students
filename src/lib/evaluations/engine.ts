import { createAdminClient } from '@/lib/supabase/admin';
import { claudeChat } from '@/lib/claude/client';
import { getModuleConfig, cleanPrompt } from './modules';

// Per-turn question generation and answer scoring run on a FAST model (Haiku) —
// these are simple, well-scoped JSON tasks and don't need a heavyweight model.
// This roughly halves the wait between questions. The final report still uses the
// default (stronger) model for quality. Override via ANTHROPIC_FAST_MODEL.
const FAST_MODEL = process.env.ANTHROPIC_FAST_MODEL || 'claude-haiku-4-5-20251001';

// Custom OTP and auth helpers
export function calcAgeYears(dobString: string): number {
  try {
    if (!dobString) return 7.0;
    const dob = new Date(dobString);
    const ms = dob.getTime();
    // new Date('garbage') does NOT throw — it yields NaN. Guard it explicitly,
    // otherwise the age silently becomes NaN and every prompt reads "NaN years".
    if (isNaN(ms)) return 7.0;
    const years = Math.round(((Date.now() - ms) / (1000 * 60 * 60 * 24 * 365.25)) * 10) / 10;
    if (!isFinite(years) || years <= 0 || years > 25) return 7.0;
    return years;
  } catch {
    return 7.0;
  }
}

export function getAgeBand(years: number): string {
  if (years < 2) return 'infant';
  if (years < 5) return 'toddler';
  if (years < 10) return 'child';
  if (years < 13) return 'preteen';
  return 'teen';
}

export async function ceStartSession(childId: number, moduleKey: string): Promise<{ sessionId: number; created: boolean }> {
  const supabase = createAdminClient();

  // 1. Check if there's an in-progress session started within the last 24 hours
  const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  const { data: existing, error: checkError } = await supabase
    .from('child_eval_sessions')
    .select('id')
    .eq('child_id', childId)
    .eq('module', moduleKey)
    .eq('status', 'in_progress')
    .gt('started_at', oneDayAgo)
    .order('id', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (existing) {
    return { sessionId: Number(existing.id), created: false };
  }

  // 2. Fetch child DOB
  const { data: child, error: childError } = await supabase
    .from('children')
    .select('dob')
    .eq('id', childId)
    .single();

  if (childError || !child) {
    throw new Error('Child not found');
  }

  const age = calcAgeYears(child.dob);
  const cfg = getModuleConfig(moduleKey, age);
  const targetTurns = cfg.turns;

  // 3. Create new session
  const { data: newSession, error: createError } = await supabase
    .from('child_eval_sessions')
    .insert({
      child_id: childId,
      module: moduleKey,
      age_at_session: age,
      target_turns: targetTurns,
      status: 'in_progress',
      started_at: new Date().toISOString(),
      last_activity_at: new Date().toISOString(),
    })
    .select('id')
    .single();

  if (createError || !newSession) {
    throw new Error(`Failed to start evaluation session: ${createError?.message}`);
  }

  return { sessionId: Number(newSession.id), created: true };
}

// Resolve the output language for AI text. The navbar EN/हिं toggle (lang)
// takes priority; otherwise fall back to the child's mother tongue.
function resolveOutputLang(lang: string | undefined, motherTongue: string): string {
  if (lang === 'hi') return 'Hindi (Devanagari script)';
  if (lang === 'en') return 'English';
  return motherTongue || 'English';
}

export async function ceGenerateNextQuestion(sessionId: number, lang?: string): Promise<any> {
  const supabase = createAdminClient();

  // Load Session and Child Info
  const { data: session, error: sErr } = await supabase
    .from('child_eval_sessions')
    .select(`
      *,
      children (
        name,
        dob,
        mother_tongue,
        class_grade
      )
    `)
    .eq('id', sessionId)
    .single();

  if (sErr || !session) {
    return { ok: false, error: 'Session not found' };
  }

  if (session.status !== 'in_progress') {
    return { ok: false, error: 'Session not active', done: true };
  }

  // Load completed turns
  const { data: turns, error: tErr } = await supabase
    .from('child_eval_turns')
    .select('*')
    .eq('session_id', sessionId)
    .order('turn_no', { ascending: true });

  const activeTurns = turns || [];
  const targetTurns = session.target_turns;

  // IDEMPOTENT: if a question was already generated but NOT yet answered, return
  // that SAME question. This means a reload (or resuming the session) shows the
  // exact same pending question instead of calling the AI again — saving API
  // tokens, and never generating a new question until the current one is submitted.
  const pendingTurn = activeTurns.find((t: any) => !t.answer_json);
  if (pendingTurn) {
    let pendingQ: any = {};
    try { pendingQ = JSON.parse(pendingTurn.question_json || '{}'); } catch { /* keep {} */ }
    return {
      ok: true,
      turn_no: pendingTurn.turn_no,
      total: targetTurns,
      axis: pendingTurn.axis,
      difficulty: pendingTurn.difficulty,
      question: pendingQ,
      resumed: true,
    };
  }

  const nextTurnNo = activeTurns.length + 1;

  if (nextTurnNo > targetTurns) {
    return { ok: true, done: true, message: 'All turns complete' };
  }

  const moduleKey = session.module;
  const age = session.age_at_session;
  // @ts-ignore
  const childName = session.children?.name || 'Child';
  // @ts-ignore
  const motherTongue = session.children?.mother_tongue || 'English';
  // @ts-ignore
  const classGrade = session.children?.class_grade || '';
  const cfg = getModuleConfig(moduleKey, age);

  // Analyze history & determine next axis/difficulty
  let historySummary = '';
  const axesCovered: Record<string, number> = {};
  let correctStreak = 0;
  let wrongStreak = 0;
  let lastDifficulty = 0;
  let lastWasCorrect: boolean | null = null;

  for (const t of activeTurns) {
    const q = JSON.parse(t.question_json || '{}');
    const a = JSON.parse(t.answer_json || '{}');
    const axis = t.axis || '?';
    axesCovered[axis] = (axesCovered[axis] || 0) + 1;

    historySummary += `Turn ${t.turn_no} (axis=${axis}, difficulty=${t.difficulty}): Asked: ${q.prompt?.slice(0, 100)} | Child answered: ${JSON.stringify(a).slice(0, 100)} | Correct=${t.is_correct ? 'YES' : 'no'} score=${t.score}\n`;

    lastDifficulty = t.difficulty;
    if (t.is_correct) {
      correctStreak++;
      wrongStreak = 0;
      lastWasCorrect = true;
    } else {
      wrongStreak++;
      correctStreak = 0;
      lastWasCorrect = false;
    }
  }

  // Select least covered axis
  const axesKeys = Object.keys(cfg.axes);
  const axisCounts: Record<string, number> = {};
  axesKeys.forEach(k => {
    axisCounts[k] = axesCovered[k] || 0;
  });

  const sortedAxes = Object.entries(axisCounts).sort((a, b) => a[1] - b[1]);
  const nextAxis = sortedAxes[0][0];

  // Calibrate difficulty — a responsive staircase (1..5, relative to the child's
  // age). Every CORRECT answer makes the next question harder; every WRONG one
  // makes it easier. Consecutive runs move faster so the test homes in quickly.
  // Questionnaire modules (health, diet) are non-adaptive: difficulty is fixed.
  const isAdaptive = cfg.adaptive !== false;
  let nextDifficulty: number;
  if (!isAdaptive) {
    nextDifficulty = 3;
  } else if (activeTurns.length === 0) {
    nextDifficulty = 2; // gentle warm-up; climbs fast if the child is doing well
  } else {
    nextDifficulty = lastDifficulty || 3;
    if (lastWasCorrect) {
      nextDifficulty += correctStreak >= 3 ? 2 : 1;
    } else {
      nextDifficulty -= wrongStreak >= 2 ? 2 : 1;
    }
    nextDifficulty = Math.max(1, Math.min(5, nextDifficulty));
  }

  const diffLabels: Record<number, string> = {
    1: 'VERY EASY for this age — a warm-up almost every child this age gets right',
    2: 'EASY for this age',
    3: 'MEDIUM — typical for a child of exactly this age/grade',
    4: 'HARD for this age — stretches a strong child',
    5: 'VERY HARD for this age — only an advanced child this age would solve it',
  };

  const outLang = resolveOutputLang(lang, motherTongue);

  let systemPrompt = cfg.system_prompt;
  systemPrompt += `\n\nCHILD PROFILE — calibrate every question to THIS child:\n  Name: ${childName}\n  Age: ${age} years (age band: ${getAgeBand(age)})\n`;
  if (classGrade) systemPrompt += `  School grade/class: ${classGrade}\n`;
  systemPrompt += `  Mother tongue: ${motherTongue}\n`;
  systemPrompt += `\nOUTPUT LANGUAGE: Write EVERYTHING the child sees — the prompt, the stimulus, and EVERY option — in ${outLang}. Do not mix in the other language.\n`;
  systemPrompt += `\nThis is question ${nextTurnNo} of ${targetTurns}.\nProbe this axis: ${nextAxis} (${cfg.axes[nextAxis]?.desc || ''}).\n`;

  if (isAdaptive) {
    systemPrompt += `TARGET DIFFICULTY: ${nextDifficulty}/5 — ${diffLabels[nextDifficulty]}.\n`;
    systemPrompt += `Difficulty is RELATIVE to a ${age}-year-old${classGrade ? ` in grade ${classGrade}` : ''}. Genuinely match this level — do NOT default to medium.\n`;

    if (lastWasCorrect === true) {
      systemPrompt += `The child answered the PREVIOUS question CORRECTLY → this question MUST be clearly HARDER than the last.\n`;
    } else if (lastWasCorrect === false) {
      systemPrompt += `The child got the PREVIOUS question WRONG → this question MUST be clearly EASIER than the last to rebuild confidence.\n`;
    }
  }
  systemPrompt += `Do NOT repeat or lightly reword any question already asked below.\n`;

  if (historySummary) {
    systemPrompt += `\nConversation so far:\n${historySummary}`;
  }

  const genMessages = [{ role: 'user' as const, content: cfg.question_user_prompt }];
  let responseText = await claudeChat(systemPrompt, genMessages, 700, 0.7, FAST_MODEL);
  // Fallback to the default model if the fast model is unavailable / returns empty.
  if (!responseText) {
    responseText = await claudeChat(systemPrompt, genMessages, 700, 0.7);
  }

  let clean = responseText.trim();
  if (clean.includes('```')) {
    clean = clean.replace(/^```(?:json)?\s*/i, '');
    clean = clean.replace(/\s*```\s*$/, '');
    clean = clean.trim();
  }

  let qObj: any;
  try {
    qObj = JSON.parse(clean);
  } catch {
    // Attempt parsing via regex
    const match = clean.match(/(\{[\s\S]*\})/);
    if (match) {
      try {
        qObj = JSON.parse(match[1]);
      } catch {
        return { ok: false, error: 'AI returned unparsable question format' };
      }
    } else {
      return { ok: false, error: 'AI returned unparsable question format' };
    }
  }

  // Memory mode rules
  const memoryTypes = ['digit_span', 'word_recall', 'follow_instruction'];
  const reasoningTypes = ['find_pattern', 'odd_one_out', 'mental_math', 'category_speed', 'spot_difference', 'ranking_logic'];
  const qtype = qObj.type || '';

  if (memoryTypes.includes(qtype)) {
    qObj.memory_mode = true;
    if (!qObj.display_seconds || qObj.display_seconds < 2) {
      const stim = qObj.stimulus || '';
      if (qtype === 'digit_span') {
        const digitsCount = stim.replace(/\D/g, '').length;
        qObj.display_seconds = Math.max(3, Math.min(10, digitsCount + 1));
      } else if (qtype === 'word_recall') {
        const wordCount = stim.split(/[\s,]+/).filter(Boolean).length;
        qObj.display_seconds = Math.max(4, Math.min(12, wordCount + 2));
      } else {
        qObj.display_seconds = 6;
      }
    }
    qObj.prompt = cleanPrompt(qObj.prompt || '');
  } else if (reasoningTypes.includes(qtype)) {
    qObj.memory_mode = false;
  }

  // Persist new turn
  const { error: insertErr } = await supabase
    .from('child_eval_turns')
    .insert({
      session_id: sessionId,
      turn_no: nextTurnNo,
      axis: nextAxis,
      difficulty: nextDifficulty,
      question_json: JSON.stringify(qObj),
      asked_at: new Date().toISOString(),
    });

  if (insertErr) {
    return { ok: false, error: `Failed to record turn: ${insertErr.message}` };
  }

  // Update session activity
  await supabase
    .from('child_eval_sessions')
    .update({
      turn_count: nextTurnNo,
      last_activity_at: new Date().toISOString(),
    })
    .eq('id', sessionId);

  return {
    ok: true,
    turn_no: nextTurnNo,
    total: targetTurns,
    axis: nextAxis,
    difficulty: nextDifficulty,
    question: qObj,
  };
}

export async function ceSubmitAnswer(
  sessionId: number,
  answerPayload: { text?: string; choice?: string; response_seconds?: number },
  lang?: string
): Promise<any> {
  const supabase = createAdminClient();

  // Load session
  const { data: session, error: sErr } = await supabase
    .from('child_eval_sessions')
    .select('*')
    .eq('id', sessionId)
    .single();

  if (sErr || !session) {
    return { ok: false, error: 'Session not found' };
  }

  if (session.status !== 'in_progress') {
    return { ok: false, error: 'Session is not active' };
  }

  // Fetch the pending unanswered turn. This MUST be the SAME turn that
  // ceGenerateNextQuestion displays — i.e. the LOWEST-numbered unanswered turn
  // (it scans turns ascending and returns the first without an answer). If this
  // ordering disagrees (e.g. descending → highest), the answer gets written to a
  // different turn than the one on screen, leaving the displayed turn forever
  // unanswered — so the same question is shown again and again and the session
  // never advances. Keep both ascending.
  const { data: turn, error: tErr } = await supabase
    .from('child_eval_turns')
    .select('*')
    .eq('session_id', sessionId)
    .is('answer_json', null)
    .order('turn_no', { ascending: true })
    .limit(1)
    .maybeSingle();

  if (tErr || !turn) {
    return { ok: false, error: 'No pending unanswered question found' };
  }

  const moduleKey = session.module;
  const age = session.age_at_session;
  const cfg = getModuleConfig(moduleKey, age);
  const axis = turn.axis || '';
  const qObj = JSON.parse(turn.question_json);

  // Score via Claude
  let systemPrompt = cfg.scoring_system_prompt || "You are an evaluator for a child cognitive test. Score the child's answer fairly.";
  systemPrompt += `\n\nChild's age: ${age} years.\nQuestion axis: ${axis}\nQuestion difficulty: ${turn.difficulty}/5\n`;
  if (lang === 'hi' || lang === 'en') {
    systemPrompt += `Write "feedback_for_child" in ${lang === 'hi' ? 'Hindi (Devanagari script)' : 'English'}.\n`;
  }

  const userContent = `Question asked:\n${JSON.stringify(qObj)}\n\nChild's answer:\n${JSON.stringify(answerPayload)}\n\nScore it. Output JSON only:\n{ "is_correct": true|false, "score_0_100": 0-100, "feedback_for_child": "kind feedback", "insight_for_report": "parent report insight" }`;

  const scoreMessages = [{ role: 'user' as const, content: userContent }];
  let responseText = await claudeChat(systemPrompt, scoreMessages, 400, 0.3, FAST_MODEL);
  // Fallback to the default model if the fast model is unavailable / returns empty.
  if (!responseText) {
    responseText = await claudeChat(systemPrompt, scoreMessages, 400, 0.3);
  }

  let clean = responseText.trim();
  if (clean.includes('```')) {
    clean = clean.replace(/^```(?:json)?\s*/i, '');
    clean = clean.replace(/\s*```\s*$/, '');
    clean = clean.trim();
  }

  let scoreObj: any;
  try {
    scoreObj = JSON.parse(clean);
  } catch {
    const match = clean.match(/(\{[\s\S]*\})/);
    if (match) {
      try {
        scoreObj = JSON.parse(match[1]);
      } catch {
        scoreObj = { is_correct: false, score_0_100: 50, feedback_for_child: 'Good try!', insight_for_report: 'Review needed.' };
      }
    } else {
      scoreObj = { is_correct: false, score_0_100: 50, feedback_for_child: 'Good try!', insight_for_report: 'Review needed.' };
    }
  }

  // Coerce robustly: the model occasionally returns is_correct as the STRING
  // "false"/"true", and !"false" would wrongly read as correct — which would
  // break the adaptive staircase (a wrong answer must register as wrong).
  const rawCorrect = scoreObj.is_correct;
  const isCorrect = typeof rawCorrect === 'string'
    ? rawCorrect.trim().toLowerCase() === 'true'
    : !!rawCorrect;
  const score = Math.max(0, Math.min(100, Number(scoreObj.score_0_100 || 50)));
  const feedback = scoreObj.feedback_for_child || '';

  // Response speed math
  let responseSeconds = answerPayload.response_seconds || 0;
  if (responseSeconds <= 0) {
    const askedTime = new Date(turn.asked_at).getTime();
    responseSeconds = Math.max(0.5, Math.round((Date.now() - askedTime) / 100) / 10);
  }

  const difficulty = turn.difficulty;
  let expected = 8 + 4 * (difficulty - 1);
  if (age < 7) {
    expected *= 1.6;
  } else if (age < 10) {
    expected *= 1.2;
  }

  let speedAdjustedScore = score;
  if (isCorrect) {
    if (responseSeconds <= expected * 0.7) {
      speedAdjustedScore = Math.min(100, score + 10);
    } else if (responseSeconds >= expected * 1.5) {
      speedAdjustedScore = Math.max(0, score - 10);
    }
  } else {
    if (responseSeconds <= expected * 0.5) {
      speedAdjustedScore = Math.max(0, score - 20);
    }
  }

  // Save answer
  const { error: updateErr } = await supabase
    .from('child_eval_turns')
    .update({
      answer_json: JSON.stringify(answerPayload),
      is_correct: isCorrect,
      score: speedAdjustedScore,
      feedback: feedback,
      ai_meta_json: JSON.stringify(scoreObj),
      response_seconds: responseSeconds,
      answered_at: new Date().toISOString(),
    })
    .eq('id', turn.id);

  if (updateErr) {
    return { ok: false, error: `Failed to save answer: ${updateErr.message}` };
  }

  // Update session activity
  await supabase
    .from('child_eval_sessions')
    .update({
      last_activity_at: new Date().toISOString(),
    })
    .eq('id', sessionId);

  return {
    ok: true,
    is_correct: isCorrect,
    score: speedAdjustedScore,
    raw_score: score,
    response_seconds: responseSeconds,
    expected_seconds: expected,
    feedback: feedback,
  };
}

export async function ceFinaliseSession(sessionId: number, lang?: string): Promise<any> {
  const supabase = createAdminClient();

  // Load session
  const { data: session, error: sErr } = await supabase
    .from('child_eval_sessions')
    .select(`
      *,
      children (
        name,
        dob,
        mother_tongue
      )
    `)
    .eq('id', sessionId)
    .single();

  if (sErr || !session) {
    return { ok: false, error: 'Session not found' };
  }

  if (session.status === 'completed' && session.report_json) {
    return { ok: true, report: JSON.parse(session.report_json), cached: true };
  }

  // Load turns
  const { data: turns, error: tErr } = await supabase
    .from('child_eval_turns')
    .select('*')
    .eq('session_id', sessionId)
    .order('turn_no', { ascending: true });

  const activeTurns = turns || [];
  if (activeTurns.length === 0) {
    return { ok: false, error: 'No questions answered in this session' };
  }

  const moduleKey = session.module;
  const age = session.age_at_session;
  // @ts-ignore
  const childName = session.children?.name || 'Child';
  const cfg = getModuleConfig(moduleKey, age);

  // Aggregate stats by axis
  const axisData: Record<string, any> = {};
  activeTurns.forEach(t => {
    const axis = t.axis || 'general';
    if (!axisData[axis]) {
      axisData[axis] = { count: 0, scoreSum: 0, correct: 0, insights: [] };
    }
    axisData[axis].count++;
    axisData[axis].scoreSum += t.score || 0;
    axisData[axis].correct += t.is_correct ? 1 : 0;

    try {
      const meta = JSON.parse(t.ai_meta_json || '{}');
      if (meta.insight_for_report) {
        axisData[axis].insights.push(meta.insight_for_report);
      }
    } catch {
      // skip
    }
  });

  let overallScoreSum = 0;
  let overallTurnsCount = 0;
  Object.values(axisData).forEach((d: any) => {
    overallScoreSum += d.scoreSum;
    overallTurnsCount += d.count;
  });

  const overallScore = overallTurnsCount > 0 ? Math.round((overallScoreSum / overallTurnsCount) * 10) / 10 : 0;

  // Ask Claude for the final report
  let systemPrompt = cfg.report_system_prompt || "You are a child psychologist writing a 1-page evaluation report for the parent.";
  systemPrompt += `\n\nChild: ${childName}, age ${age} years.\nModule: ${moduleKey}.\nOverall score: ${overallScore}/100.\n\nPer-axis performance:\n`;
  if (lang === 'hi' || lang === 'en') {
    systemPrompt += `\nWRITE THE ENTIRE REPORT (summary, strengths, gaps, recommended_focus) in ${lang === 'hi' ? 'Hindi (Devanagari script)' : 'English'}.\n`;
  }

  Object.entries(axisData).forEach(([axis, d]: [string, any]) => {
    const avg = d.count > 0 ? Math.round(d.scoreSum / d.count) : 0;
    const insights = d.insights.slice(0, 3).join(' | ');
    systemPrompt += `  • ${axis}: ${d.correct}/${d.count} correct, avg score ${avg}/100. Insights: ${insights}\n`;
  });

  const userContent = `Write the final report. Output JSON only:
{
  "overall_score": ${overallScore},
  "level": "emerging|developing|on-track|above-age",
  "summary": "3-4 sentences for the parent — warm, honest, specific to this child. Use child's name.",
  "strengths": ["specific strength 1", "strength 2"],
  "gaps": [{"axis": "axis_key", "label": "human label", "description": "1-2 lines", "course_day": 1}],
  "recommended_focus": "the ONE thing you'd tell this parent to focus on this week"
}`;

  const responseText = await claudeChat(
    systemPrompt,
    [{ role: 'user', content: userContent }],
    1500,
    0.5
  );

  let clean = responseText.trim();
  if (clean.includes('```')) {
    clean = clean.replace(/^```(?:json)?\s*/i, '');
    clean = clean.replace(/\s*```\s*$/, '');
    clean = clean.trim();
  }

  let reportObj: any;
  try {
    reportObj = JSON.parse(clean);
  } catch {
    const match = clean.match(/(\{[\s\S]*\})/);
    if (match) {
      try {
        reportObj = JSON.parse(match[1]);
      } catch {
        reportObj = {
          overall_score: overallScore,
          level: overallScore >= 75 ? 'on-track' : (overallScore >= 50 ? 'developing' : 'emerging'),
          summary: `Report generation failed. Score: ${overallScore}/100.`,
          strengths: [],
          gaps: [],
          recommended_focus: 'Practice child cognitive developmental drills daily.',
        };
      }
    } else {
      reportObj = {
        overall_score: overallScore,
        level: overallScore >= 75 ? 'on-track' : (overallScore >= 50 ? 'developing' : 'emerging'),
        summary: `Report generation failed. Score: ${overallScore}/100.`,
        strengths: [],
        gaps: [],
        recommended_focus: 'Practice child cognitive developmental drills daily.',
      };
    }
  }

  reportObj.axis_breakdown = axisData;

  // Save report back to session
  await supabase
    .from('child_eval_sessions')
    .update({
      status: 'completed',
      overall_score: overallScore,
      report_json: JSON.stringify(reportObj),
      completed_at: new Date().toISOString(),
    })
    .eq('id', sessionId);

  // Bridge to the legacy assessments table so it populates correctly
  try {
    const legacyFlags = (reportObj.gaps || []).map((g: any) => ({
      q: g.label || g.axis || '',
      severity: 'watch',
    }));

    await supabase
      .from('assessments')
      .insert({
        child_id: session.child_id,
        module: moduleKey,
        age_band: getAgeBand(age),
        status: 'completed',
        score: overallScore,
        level_reached: reportObj.level || '',
        ai_summary: reportObj.summary || '',
        flags: JSON.stringify(legacyFlags),
        completed_at: new Date().toISOString(),
      });
  } catch (err: any) {
    console.error('[ceFinaliseSession] failed legacy bridge:', err.message);
  }

  return { ok: true, report: reportObj };
}