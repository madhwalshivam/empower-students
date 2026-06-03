-- ============================================================
-- Evaluation Engine Tables
-- Run this in Supabase SQL Editor:
-- https://app.supabase.com → SQL Editor → New Query → Paste → Run
-- ============================================================

-- 1. Sessions table — one per child+module attempt
CREATE TABLE IF NOT EXISTS public.child_eval_sessions (
  id                BIGSERIAL PRIMARY KEY,
  child_id          BIGINT NOT NULL REFERENCES public.children(id) ON DELETE CASCADE,
  module            TEXT NOT NULL,
  age_at_session    NUMERIC(5,2) NOT NULL DEFAULT 0,
  target_turns      INT NOT NULL DEFAULT 8,
  turn_count        INT NOT NULL DEFAULT 0,
  status            TEXT NOT NULL DEFAULT 'in_progress', -- in_progress | completed | abandoned
  overall_score     NUMERIC(6,2),
  report_json       TEXT,
  started_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  completed_at      TIMESTAMPTZ,
  last_activity_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 2. Turns table — one row per question/answer exchange
CREATE TABLE IF NOT EXISTS public.child_eval_turns (
  id               BIGSERIAL PRIMARY KEY,
  session_id       BIGINT NOT NULL REFERENCES public.child_eval_sessions(id) ON DELETE CASCADE,
  turn_no          INT NOT NULL,
  axis             TEXT,
  difficulty       INT NOT NULL DEFAULT 3,
  question_json    TEXT,
  answer_json      TEXT,
  is_correct       BOOLEAN,
  score            NUMERIC(6,2),
  feedback         TEXT,
  ai_meta_json     TEXT,
  response_seconds NUMERIC(8,2),
  asked_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  answered_at      TIMESTAMPTZ
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_child_eval_sessions_child_id ON public.child_eval_sessions(child_id);
CREATE INDEX IF NOT EXISTS idx_child_eval_sessions_status  ON public.child_eval_sessions(status);
CREATE INDEX IF NOT EXISTS idx_child_eval_turns_session_id ON public.child_eval_turns(session_id);

-- Disable RLS (using service-role admin client in code)
ALTER TABLE public.child_eval_sessions DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.child_eval_turns    DISABLE ROW LEVEL SECURITY;
