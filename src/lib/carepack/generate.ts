import { claudeJson } from '@/lib/claude/client';
import { calcAgeYears } from '@/lib/evaluations/engine';

// Personalised Care Pack content — a Growth Plan, a mini Course and a Daily
// Tracker, all derived from a child's actual assessment results. Generation
// prefers Claude but ALWAYS falls back to a deterministic, score-driven plan so
// a missing API key or a failed call can never leave the parent with an error.

export const CARE_PACK_PRICE = 499;

const MODULE_LABELS: Record<string, string> = {
  health: 'Health & Wellbeing',
  mind_power: 'Mind Power (memory, attention, problem-solving)',
  behavior: 'Behaviour & Emotional Regulation',
  general_awareness: 'General Awareness',
  special_talent: 'Special Talent',
  math: 'Maths & Number Sense',
  language: 'Language & Reading',
  diet: 'Diet & Nutrition',
};

export interface GrowthFocus { area: string; score: number | null; why: string; }
export interface GrowthWeek { week: number; theme: string; goals: string[]; activities: string[]; }
export interface GrowthPlan {
  summary: string;
  focus_areas: GrowthFocus[];
  weeks: GrowthWeek[];
  parent_tips: string[];
}
export interface CourseLesson { title: string; objective: string; content: string; activity: string; }
export interface Course { title: string; intro: string; lessons: CourseLesson[]; }
export interface TrackerHabit { habit: string; why: string; }
export interface Tracker { intro: string; daily_habits: TrackerHabit[]; weekly_milestones: string[]; }
export interface CarePackContent { growth_plan: GrowthPlan; course: Course; tracker: Tracker; }

function label(moduleKey: string): string {
  return MODULE_LABELS[moduleKey] || moduleKey;
}

// Compact, readable digest of the child's results to feed the model.
function summariseAssessments(assessments: any[]): string {
  if (!assessments.length) return 'No completed modules yet.';
  return assessments
    .map((a) => {
      const score = a.score != null ? `${Math.round(a.score)}/100` : 'n/a';
      const summary = (a.ai_summary || '').toString().slice(0, 280);
      return `- ${label(a.module)}: score ${score}${a.level_reached ? `, level ${a.level_reached}` : ''}${summary ? ` — ${summary}` : ''}`;
    })
    .join('\n');
}

// The lowest-scoring modules are the natural focus areas to strengthen.
function weakestAreas(assessments: any[], n = 3): any[] {
  const scored = assessments.filter((a) => a.score != null);
  if (!scored.length) return assessments.slice(0, n);
  return [...scored].sort((a, b) => (a.score || 0) - (b.score || 0)).slice(0, n);
}

function buildFallback(child: any, assessments: any[]): CarePackContent {
  const name = child.name || 'your child';
  const age = child.dob ? calcAgeYears(child.dob) : null;
  const weak = weakestAreas(assessments, 3);
  const strong = [...assessments.filter((a) => a.score != null)].sort((a, b) => (b.score || 0) - (a.score || 0))[0];

  const focus_areas: GrowthFocus[] = (weak.length ? weak : assessments.slice(0, 3)).map((a) => ({
    area: label(a.module),
    score: a.score != null ? Math.round(a.score) : null,
    why: a.score != null && a.score < 60
      ? `Results suggest ${name} would benefit from focused, playful practice here.`
      : `A steady area — small daily reinforcement will keep it growing.`,
  }));

  const focusNames = focus_areas.map((f) => f.area);
  const weeks: GrowthWeek[] = [1, 2, 3, 4].map((w) => {
    const area = focusNames[(w - 1) % Math.max(1, focusNames.length)] || 'overall development';
    return {
      week: w,
      theme: `Week ${w}: Strengthening ${area}`,
      goals: [
        `Spend 15 focused minutes a day on ${area.toLowerCase()}.`,
        `Notice and praise one small win each day.`,
        `Keep a short note of what worked and what felt hard.`,
      ],
      activities: [
        `A short game or task targeting ${area.toLowerCase()}.`,
        `One real-life practice moment (during play, meals or travel).`,
        `A 2-minute end-of-day reflection together with ${name}.`,
      ],
    };
  });

  const growth_plan: GrowthPlan = {
    summary: `A 4-week, gentle, parent-led plan personalised for ${name}${age ? `, age ${age}` : ''}. ` +
      `It builds on ${strong ? label(strong.module).split(' (')[0] : 'existing strengths'} while strengthening the areas that need the most support.`,
    focus_areas,
    weeks,
    parent_tips: [
      'Keep sessions short and positive — consistency beats intensity.',
      'Follow the child’s interest; turn practice into play wherever you can.',
      'Celebrate effort, not just correct answers.',
      'If a task feels too hard, make it easier rather than skipping it.',
    ],
  };

  const course: Course = {
    title: `${name}'s Personal Course`,
    intro: `Five short lessons you can do together, each building a skill from ${name}'s results.`,
    lessons: (focusNames.length ? focusNames : ['Focus & Attention', 'Confidence', 'Daily Routine'])
      .concat(['Building Confidence', 'Making It a Habit'])
      .slice(0, 5)
      .map((area, i) => ({
        title: `Lesson ${i + 1}: ${area}`,
        objective: `Help ${name} grow steadily in ${area.toLowerCase()}.`,
        content: `A simple, parent-friendly explanation of why ${area.toLowerCase()} matters at this age and how to support it at home through everyday moments.`,
        activity: `Try one 10-minute activity focused on ${area.toLowerCase()} and note how ${name} responds.`,
      })),
  };

  const tracker: Tracker = {
    intro: `A simple 4-week daily tracker. Tick each habit off with ${name} — small steps, every day.`,
    daily_habits: [
      { habit: `15 minutes of focused practice`, why: `Builds the target skills steadily.` },
      { habit: `One act of encouragement`, why: `Confidence grows when effort is noticed.` },
      { habit: `Healthy meal + good sleep routine`, why: `The brain learns best when rested and well-fed.` },
      { habit: `5 minutes of free play / movement`, why: `Play consolidates learning and reduces stress.` },
    ],
    weekly_milestones: [
      'Week 1: Routine is set and followed most days.',
      'Week 2: First small improvement noticed in a focus area.',
      'Week 3: Child initiates a habit on their own.',
      'Week 4: Review progress and pick the next focus.',
    ],
  };

  return { growth_plan, course, tracker };
}

// Lightweight shape-check so a malformed model response falls back cleanly.
function isValidContent(c: any): c is CarePackContent {
  return (
    c &&
    c.growth_plan && Array.isArray(c.growth_plan.weeks) && c.growth_plan.weeks.length > 0 &&
    c.course && Array.isArray(c.course.lessons) && c.course.lessons.length > 0 &&
    c.tracker && Array.isArray(c.tracker.daily_habits) && c.tracker.daily_habits.length > 0
  );
}

export async function generateCarePack(child: any, assessments: any[]): Promise<CarePackContent> {
  const fallback = buildFallback(child, assessments);

  const name = child.name || 'the child';
  const age = child.dob ? calcAgeYears(child.dob) : null;
  const grade = child.class_grade ? `Grade ${child.class_grade}` : 'unknown grade';
  const diagnosis = child.diagnosis && child.diagnosis !== 'none' ? child.diagnosis : 'none reported';

  const system =
    'You are a child-development specialist writing a warm, practical, parent-facing care pack. ' +
    'Use simple, encouraging language a non-expert parent can act on. Be specific to the results provided. ' +
    'Never give medical diagnoses or alarming language.';

  const userPrompt = `Child: ${name}, age ${age ?? 'unknown'}, ${grade}, gender ${child.gender || 'n/a'}, diagnosis: ${diagnosis}.

Completed assessment results:
${summariseAssessments(assessments)}

Create a personalised care pack as JSON with EXACTLY this shape:
{
  "growth_plan": {
    "summary": "2-3 sentence overview personalised to ${name}",
    "focus_areas": [{"area": "string", "score": number-or-null, "why": "1 sentence"}],
    "weeks": [{"week": 1, "theme": "string", "goals": ["..."], "activities": ["..."]}],
    "parent_tips": ["4 short tips"]
  },
  "course": {
    "title": "string",
    "intro": "1-2 sentences",
    "lessons": [{"title": "string", "objective": "string", "content": "2-4 sentences", "activity": "1 sentence"}]
  },
  "tracker": {
    "intro": "1-2 sentences",
    "daily_habits": [{"habit": "string", "why": "short"}],
    "weekly_milestones": ["4 strings, one per week"]
  }
}
Rules: 3 focus_areas, exactly 4 weeks, exactly 5 lessons, 4-5 daily_habits, exactly 4 weekly_milestones. Output JSON only.`;

  try {
    const result = await claudeJson(system, userPrompt, 3000, 0.5);
    if (isValidContent(result)) return result;
  } catch (err) {
    console.error('[carepack] generation failed, using fallback:', err);
  }
  return fallback;
}
