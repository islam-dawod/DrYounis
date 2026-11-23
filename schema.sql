-- Cloudflare D1 schema for the Dr. Younis CRM (replaces the Hostinger JSON files).
-- Apply with:  wrangler d1 execute younis-crm --remote --file=schema.sql

CREATE TABLE IF NOT EXISTS leads (
  id          TEXT PRIMARY KEY,
  ts          TEXT,
  name        TEXT,
  phone       TEXT,
  phone_key   TEXT,            -- last 9 digits, for dedupe/search
  email       TEXT,
  interest    TEXT,
  msg         TEXT,
  city        TEXT,
  fit         TEXT,
  question    TEXT,
  source      TEXT,
  ip          TEXT,
  status      TEXT DEFAULT 'new',
  consent     INTEGER DEFAULT 0,
  inbound     INTEGER DEFAULT 0,
  manual      INTEGER DEFAULT 0,
  imported    INTEGER DEFAULT 0,
  wa_id       TEXT,
  lead_id     TEXT,            -- external id (FB leadgen / Google Ads / bot)
  form_id     TEXT,
  page_id     TEXT,
  ad_id       TEXT,
  gclid       TEXT,
  campaign_id TEXT,
  edited      TEXT
);
CREATE INDEX IF NOT EXISTS idx_leads_ts        ON leads(ts);
CREATE INDEX IF NOT EXISTS idx_leads_phone_key ON leads(phone_key);
CREATE INDEX IF NOT EXISTS idx_leads_lead_id   ON leads(lead_id);

CREATE TABLE IF NOT EXISTS notes (
  id      TEXT PRIMARY KEY,
  lead_id TEXT,
  t       TEXT,
  txt     TEXT,
  e       TEXT
);
CREATE INDEX IF NOT EXISTS idx_notes_lead ON notes(lead_id);

CREATE TABLE IF NOT EXISTS wa (
  lead_id           TEXT PRIMARY KEY,
  result            TEXT,
  sendStatus        TEXT,
  deliveryStatus    TEXT,
  deliveryErrorCode INTEGER,
  reason            TEXT,
  error             TEXT,
  messageId         TEXT,
  providerStatus    TEXT,
  requestedAt       TEXT,
  updatedAt         TEXT,
  httpCode          INTEGER,
  at                TEXT
);

-- Runtime-saved configuration (Google Ads key, Facebook config, WhatsApp config).
-- Secrets the admin pastes in the dashboard land here; env vars are the fallback.
CREATE TABLE IF NOT EXISTS kv (
  k TEXT PRIMARY KEY,
  v TEXT
);
