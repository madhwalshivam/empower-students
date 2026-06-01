-- 1. Alter parents table to support free evaluation tracking
ALTER TABLE public.parents ADD COLUMN IF NOT EXISTS free_eval_used_at TIMESTAMP WITH TIME ZONE;

-- 2. CREATE TABLE: eval_sessions
CREATE TABLE IF NOT EXISTS public.eval_sessions (
    id              SERIAL PRIMARY KEY,
    parent_id       UUID NOT NULL REFERENCES auth.users(id) ON DELETE CASCADE,
    child_id        INTEGER NOT NULL REFERENCES public.children(id) ON DELETE CASCADE,
    module          TEXT NOT NULL,                 -- 'mod_speech_basic' etc.
    status          TEXT NOT NULL DEFAULT 'in_progress',  -- in_progress | completed | abandoned
    is_free         BOOLEAN NOT NULL DEFAULT false,
    cost_paid       INTEGER NOT NULL DEFAULT 0,    -- in rupees (0 for free, 59 for paid)
    started_at      TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now()),
    completed_at    TIMESTAMP WITH TIME ZONE,
    current_level   INTEGER NOT NULL DEFAULT 3,    -- 1..5; starts mid
    questions_asked INTEGER NOT NULL DEFAULT 0,
    final_level     INTEGER,                       -- the level the engine settled on
    final_pct       INTEGER,                       -- percentile-ish summary (0-100)
    report_md       TEXT,                           -- AI-generated markdown report
    report_md_hi    TEXT,                           -- Hindi version (Phase 3 polish)
    sample_exercise_md TEXT                          -- one sample exercise for upsell
);

CREATE INDEX IF NOT EXISTS idx_eval_sessions_parent ON public.eval_sessions(parent_id, status);
CREATE INDEX IF NOT EXISTS idx_eval_sessions_child ON public.eval_sessions(child_id, module);
ALTER TABLE public.eval_sessions DISABLE ROW LEVEL SECURITY;

-- 3. CREATE TABLE: eval_questions
CREATE TABLE IF NOT EXISTS public.eval_questions (
    id              SERIAL PRIMARY KEY,
    session_id      INTEGER NOT NULL REFERENCES public.eval_sessions(id) ON DELETE CASCADE,
    seq_no          INTEGER NOT NULL,              -- 1, 2, 3...
    level           INTEGER NOT NULL,              -- 1..5 the level this q was generated for
    question_type   TEXT NOT NULL,                 -- mcq | naming | fill_in | describe
    prompt          TEXT NOT NULL,                 -- the question text shown to user
    options_json    TEXT,                          -- JSON array of MCQ options if applicable
    expected        TEXT,                          -- expected/correct answer (free-text 'gold')
    image_concept   TEXT,                          -- short description if image is referenced
    asked_at        TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now()),
    answered_at     TIMESTAMP WITH TIME ZONE,      -- when the user submitted
    time_seconds    INTEGER,                       -- how long they took
    user_answer     TEXT,                          -- what they entered/picked
    answer_mode     TEXT DEFAULT 'text',           -- text | voice
    acoustic_json   TEXT,                          -- JSON of acoustic features when answer_mode=voice
    audio_path      TEXT,                          -- relative path to stored audio file (voice mode)
    is_correct      INTEGER,                       -- 0/1 after AI scoring (NULL = not yet scored)
    ai_verdict      TEXT,                          -- 'correct_fast' | 'correct_slow' | 'wrong_fast' | 'wrong_slow'
    next_level      INTEGER                        -- the level the engine decided for the NEXT q
);

CREATE INDEX IF NOT EXISTS idx_eval_questions_session ON public.eval_questions(session_id, seq_no);
ALTER TABLE public.eval_questions DISABLE ROW LEVEL SECURITY;

-- 4. CREATE TABLE: parent_reflect_sessions
CREATE TABLE IF NOT EXISTS public.parent_reflect_sessions (
    id                  SERIAL PRIMARY KEY,
    parent_id           UUID NOT NULL REFERENCES auth.users(id) ON DELETE CASCADE,
    child_id            INTEGER REFERENCES public.children(id) ON DELETE SET NULL,  -- which child this is about
    status              TEXT NOT NULL DEFAULT 'in_progress',  -- in_progress | completed | abandoned
    cost_paid           INTEGER NOT NULL DEFAULT 0,           -- always 499 (no free tier)
    started_at          TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now()),
    last_activity_at    TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now()),
    completed_at        TIMESTAMP WITH TIME ZONE,
    current_phase       INTEGER NOT NULL DEFAULT 1,            -- 1..10
    turn_count          INTEGER NOT NULL DEFAULT 0,            -- how many AI questions have been asked
    followup_count      INTEGER NOT NULL DEFAULT 0,
    -- Final outputs (filled on close, both via Sonnet)
    parent_summary_md   TEXT,                                  -- the warm, supportive report parent sees
    parent_action_md    TEXT,                                  -- 'one thing to try this week'
    admin_clinical_md   TEXT,                                  -- clinical view (NCI staff only)
    admin_risk_level    TEXT,                                  -- 'green' | 'amber' | 'red'
    admin_follow_up_by  TEXT,                                  -- ISO datetime — when NCI must call back
    generated_at        TIMESTAMP WITH TIME ZONE,
    -- Aggregated risk signals (0..1 each, computed on close)
    sig_marital_stress  REAL DEFAULT 0,
    sig_in_law_stress   REAL DEFAULT 0,
    sig_parent_burnout  REAL DEFAULT 0,
    sig_child_distress  REAL DEFAULT 0,
    sig_isolation       REAL DEFAULT 0,
    sig_safety_red_flag INTEGER DEFAULT 0                      -- 0 or 1
);

CREATE INDEX IF NOT EXISTS idx_pr_sessions_parent ON public.parent_reflect_sessions(parent_id, status);
CREATE INDEX IF NOT EXISTS idx_pr_sessions_admin  ON public.parent_reflect_sessions(status, admin_risk_level, completed_at);
ALTER TABLE public.parent_reflect_sessions DISABLE ROW LEVEL SECURITY;

-- 5. CREATE TABLE: parent_reflect_turns
CREATE TABLE IF NOT EXISTS public.parent_reflect_turns (
    id                  SERIAL PRIMARY KEY,
    session_id          INTEGER NOT NULL REFERENCES public.parent_reflect_sessions(id) ON DELETE CASCADE,
    turn_no             INTEGER NOT NULL,                      -- 1, 2, 3...
    phase               INTEGER NOT NULL,                      -- 1..10 phase the AI was operating in
    question            TEXT NOT NULL,                         -- AI's question this turn
    question_intent     TEXT,                                  -- probe | reframe | forward | slow | challenge | close
    asked_at            TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now()),
    -- Parent's response (set when answer comes in)
    transcript          TEXT,
    answered_at         TIMESTAMP WITH TIME ZONE,
    time_seconds        INTEGER,
    acoustic_json       TEXT,                                  -- WPM, duration, pause_count, silence_ratio, volume_variance, time_to_first_speech_sec, transcript_confidence
    emotions_json       TEXT,                                  -- 11 intensities + felt_sense (from Haiku emotion call)
    -- AI's interpretation
    ai_reflection       TEXT,                                  -- 1-2 sentences mirroring what parent said
    ai_tone_insight     TEXT,                                  -- 1 sentence on voice + words combined
    signals_json        TEXT                                   -- per-turn signals snapshot
);

CREATE INDEX IF NOT EXISTS idx_pr_turns_session ON public.parent_reflect_turns(session_id, turn_no);
ALTER TABLE public.parent_reflect_turns DISABLE ROW LEVEL SECURITY;

-- 6. Insert new service prices into service_prices
INSERT INTO public.service_prices (service_key, label, price, audience, is_active) VALUES
('mod_speech_eval', 'Speech & Language Evaluation', 59, 'parent', true),
('plan_speech_week1', 'Speech 1-Week Plan', 99, 'parent', true),
('mod_parent_reflect', 'Parent Reflection (with psychologist callback)', 499, 'parent', true)
ON CONFLICT (service_key) DO UPDATE 
SET label = EXCLUDED.label, price = EXCLUDED.price, audience = EXCLUDED.audience, is_active = EXCLUDED.is_active;
