-- ============================================================
-- Leads table — captures form submissions (free-eval form on the
-- marketing site AND "Detailed Expert Report" requests from the app).
-- Run in Supabase SQL Editor if the table does not already exist.
-- ============================================================

CREATE TABLE IF NOT EXISTS public.leads (
  id           BIGSERIAL PRIMARY KEY,
  parent_name  TEXT,
  phone        TEXT,
  child_age    TEXT,
  concern      TEXT,
  source       TEXT,                      -- e.g. 'website', 'expert_report'
  status       TEXT NOT NULL DEFAULT 'new', -- new | contacted | booked | converted | lost | spam
  notes        TEXT,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_leads_status ON public.leads(status);
CREATE INDEX IF NOT EXISTS idx_leads_created ON public.leads(created_at DESC);

-- Service-role admin client is used in code, so RLS can stay off.
ALTER TABLE public.leads DISABLE ROW LEVEL SECURITY;
