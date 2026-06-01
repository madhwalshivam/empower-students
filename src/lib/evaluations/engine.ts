import { createClient } from '@/lib/supabase/client';
import { createAdminClient } from '@/lib/supabase/admin';
import { claudeChat } from '@/lib/claude/client';
import { getModuleConfig, cleanPrompt } from './modules';

// Custom OTP and auth helpers
export function calcAgeYears(dobString: string): number {
  try {
    const dob = new Date(dobString);
    const diffMs = Date.now() - dob.getTime();
    return Math.round((diffMs / (1000 * 60 * 60 * 24 * 365.25)) * 10) / 10;
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

export async function ceStartSession(childId: number, moduleKey: string): Promise<number> {
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
    return Number(existing.id);
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

  return Number(newSession.id);
}

export async function ceGenerateNextQuestion(sessionId: number): Promise<any> {
  const supabase = createAdminClient();

  // Load Session and Child Info
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
  const nextTurnNo = activeTurns.length + 1;
  const targetTurns = session.target_turns;

  if (nextTurnNo > targetTurns) {
    return { ok: true, done: true, message: 'All turns complete' };
  }

  const moduleKey = session.module;
  const age = session.age_at_session;
  // @ts-ignore
  const childName = session.children?.name || 'Child';
  // @ts-ignore
  const motherTongue = session.children?.mother_tongue || 'English';
  const cfg = getModuleConfig(moduleKey, age);

  // Analyze history & determine next axis/difficulty
  let historySummary = '';
  const axesCovered: Record<string, number> = {};
  let correctStreak = 0;
  let lastDifficulty = 3;

  for (const t of activeTurns) {
    const q = JSON.parse(t.question_json || '{}');
    const a = JSON.parse(t.answer_json || '{}');
    const axis = t.axis || '?';
    axesCovered[axis] = (axesCovered[axis] || 0) + 1;

    historySummary += `Turn ${t.turn_no} (axis=${axis}, difficulty=${t.difficulty}): Asked: ${q.prompt?.slice(0, 100)} | Child answered: ${JSON.stringify(a).slice(0, 100)} | Correct=${t.is_correct ? 'YES' : 'no'} score=${t.score}\n`;

    lastDifficulty = t.difficulty;
    if (t.is_correct) {
      correctStreak++;
    } else {
      correctStreak = 0;
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

  // Calibrate difficulty
  let nextDifficulty = lastDifficulty;
  if (correctStreak >= 2) {
    nextDifficulty = Math.min(5, lastDifficulty + 1);
  }
  if (activeTurns.length >= 2) {
    const lastTwoCorrect = (activeTurns[activeTurns.length - 1].is_correct ? 1 : 0) +
                           (activeTurns[activeTurns.length - 2].is_correct ? 1 : 0);
    if (lastTwoCorrect === 0) {
      nextDifficulty = Math.max(1, lastDifficulty - 1);
    }
  }

  let systemPrompt = cfg.system_prompt;
  systemPrompt += `\n\nChild details:\n  Name: ${childName}\n  Age: ${age} years\n  Age band: ${getAgeBand(age)}\n  Mother tongue: ${motherTongue}\n`;
  systemPrompt += `\nThis is turn ${nextTurnNo} of ${targetTurns}.\nProbe this axis: ${nextAxis} (description: ${cfg.axes[nextAxis]?.desc || ''})\nTarget difficulty: ${nextDifficulty} (1=very easy, 5=very hard for age).\n`;

  if (historySummary) {
    systemPrompt += `\nConversation so far:\n${historySummary}`;
  }

  const responseText = await claudeChat(
    systemPrompt,
    [{ role: 'user', content: cfg.question_user_prompt }],
    800,
    0.7
  );

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
  answerPayload: { text?: string; choice?: string; response_seconds?: number }
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

  // Fetch latest unanswered turn
  const { data: turn, error: tErr } = await supabase
    .from('child_eval_turns')
    .select('*')
    .eq('session_id', sessionId)
    .is('answer_json', null)
    .order('turn_no', { ascending: false })
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

  const userContent = `Question asked:\n${JSON.stringify(qObj)}\n\nChild's answer:\n${JSON.stringify(answerPayload)}\n\nScore it. Output JSON only:\n{ "is_correct": true|false, "score_0_100": 0-100, "feedback_for_child": "kind feedback", "insight_for_report": "parent report insight" }`;

  const responseText = await claudeChat(
    systemPrompt,
    [{ role: 'user', content: userContent }],
    500,
    0.3
  );

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

  const isCorrect = !!scoreObj.is_correct;
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

export async function ceFinaliseSession(sessionId: number): Promise<any> {
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
