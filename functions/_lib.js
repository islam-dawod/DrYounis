// Shared helpers for the Cloudflare Pages Functions backend (Dr. Younis CRM).
// Storage is Cloudflare D1 (binding: env.DB). Times are Asia/Jerusalem.

export function json(obj, status = 200, headers = {}) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8', ...headers },
  });
}

export function clean(v) {
  return String(v ?? '').replace(/[\r\n\t]+/g, ' ').trim();
}

// "YYYY-MM-DD HH:MM:SS" in Jerusalem (sv-SE gives ISO-like output)
function jeru() {
  return new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Jerusalem' });
}
export function tsNow() { return jeru().slice(0, 16); }            // YYYY-MM-DD HH:MM
export function newId() {
  const digits = jeru().replace(/\D/g, '');                        // YYYYMMDDHHMMSS
  const rnd = crypto.randomUUID().replace(/-/g, '').slice(0, 6);
  return digits + rnd;
}
export function shortId() { return crypto.randomUUID().replace(/-/g, '').slice(0, 8); }

export function phoneKey(p) {
  const d = String(p ?? '').replace(/\D/g, '');
  return d.length > 9 ? d.slice(-9) : d;
}

// International phone for WhatsApp (no +, no leading 0), assuming Israel.
export function waNumber(p) {
  let d = String(p ?? '').replace(/\D/g, '');
  if (!d) return '';
  if (d.startsWith('00')) d = d.slice(2);
  if (d.startsWith('972')) return d;
  if (d.startsWith('0')) return '972' + d.slice(1);
  if (d.length === 9 && d[0] === '5') return '972' + d;
  return d;
}

// ---- config: D1 kv table first, then env var fallback ----
export async function cfgGet(env, kvKey, envKey) {
  try {
    const row = await env.DB.prepare('SELECT v FROM kv WHERE k=?').bind(kvKey).first();
    if (row && row.v != null && row.v !== '') return row.v;
  } catch (e) { /* table may not exist yet */ }
  if (envKey && env[envKey] != null && env[envKey] !== '') return env[envKey];
  return '';
}
export async function cfgGetJson(env, kvKey) {
  try {
    const row = await env.DB.prepare('SELECT v FROM kv WHERE k=?').bind(kvKey).first();
    if (row && row.v) { try { return JSON.parse(row.v); } catch (e) {} }
  } catch (e) {}
  return {};
}
export async function cfgSet(env, kvKey, value) {
  await env.DB.prepare(
    'INSERT INTO kv (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v'
  ).bind(kvKey, value).run();
}

// ---- leads ----
const LEAD_COLS = ['id','ts','name','phone','phone_key','email','interest','msg','city','fit',
  'question','source','ip','status','consent','inbound','manual','imported','wa_id','lead_id',
  'form_id','page_id','ad_id','gclid','campaign_id','edited'];

export async function insertLead(env, lead) {
  const l = { ...lead };
  l.phone_key = phoneKey(l.phone || '');
  if (!l.status) l.status = 'new';
  const vals = LEAD_COLS.map(c => (l[c] === undefined ? null : l[c]));
  const ph = LEAD_COLS.map(() => '?').join(',');
  await env.DB.prepare(`INSERT INTO leads (${LEAD_COLS.join(',')}) VALUES (${ph})`).bind(...vals).run();
  return l.id;
}

export async function findLeadIdByPhone(env, phone) {
  const pk = phoneKey(phone);
  if (!pk) return '';
  const row = await env.DB.prepare('SELECT id FROM leads WHERE phone_key=? ORDER BY ts DESC LIMIT 1').bind(pk).first();
  return row ? row.id : '';
}

export async function leadExistsByExternalId(env, extId) {
  if (!extId) return false;
  const row = await env.DB.prepare('SELECT id FROM leads WHERE lead_id=? LIMIT 1').bind(extId).first();
  return !!row;
}

export async function addNote(env, leadId, txt) {
  await env.DB.prepare('INSERT INTO notes (id,lead_id,t,txt) VALUES (?,?,?,?)')
    .bind(shortId(), leadId, tsNow(), txt).run();
}
