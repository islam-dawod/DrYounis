// /crm/index.php — CRM dashboard (auth + data + actions) on Cloudflare D1.
// UI/logic is the static /crm-app.js; this Function authenticates, serves the
// authed shell with an injected data blob, and handles all AJAX actions + CSV.
import { json, clean, newId, tsNow, shortId, waNumber, phoneKey, insertLead, addNote, cfgGetJson } from '../_lib.js';

const ST_LBL = {
  new: 'חדש', contacted: 'נוצר קשר', appointment: 'נקבע תור', treated: 'קבל טיפול',
  not_interested: 'לא מעוניין', no_answer: 'לא זמין / אין מענה',
  callback: 'לחזור למטופל', not_suitable: 'לא מתאים',
};
const ST_KEYS = Object.keys(ST_LBL);

// ---------- session cookie (HMAC-signed) ----------
const te = new TextEncoder();
async function hmac(secret, msg) {
  const k = await crypto.subtle.importKey('raw', te.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const s = await crypto.subtle.sign('HMAC', k, te.encode(msg));
  return [...new Uint8Array(s)].map(b => b.toString(16).padStart(2, '0')).join('');
}
function b64url(s) { return btoa(unescape(encodeURIComponent(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
function b64urlDec(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); return decodeURIComponent(escape(atob(s))); }

async function makeSession(env, user) {
  const payload = JSON.stringify({ u: user, exp: Date.now() + 7 * 864e5 });
  const p = b64url(payload);
  const sig = await hmac(env.SESSION_SECRET || 'dev', p);
  return p + '.' + sig;
}
async function readSession(env, cookieHeader) {
  const m = (cookieHeader || '').match(/(?:^|;\s*)crmsess=([^;]+)/);
  if (!m) return null;
  const tok = m[1];
  const dot = tok.lastIndexOf('.');
  if (dot < 0) return null;
  const p = tok.slice(0, dot), sig = tok.slice(dot + 1);
  if (await hmac(env.SESSION_SECRET || 'dev', p) !== sig) return null;
  try { const o = JSON.parse(b64urlDec(p)); if (o.exp < Date.now()) return null; return o; } catch (e) { return null; }
}
async function csrfFor(env, sessTok) { return (await hmac(env.SESSION_SECRET || 'dev', 'csrf:' + sessTok)).slice(0, 24); }
function getCookie(req) { return req.headers.get('Cookie') || ''; }
function sessTokFromCookie(req) { const m = getCookie(req).match(/(?:^|;\s*)crmsess=([^;]+)/); return m ? m[1] : ''; }

function h(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

// ---------- data ----------
async function loadAll(env) {
  const leads = (await env.DB.prepare('SELECT * FROM leads ORDER BY id DESC').all()).results || [];
  const notesRows = (await env.DB.prepare('SELECT * FROM notes').all()).results || [];
  const waRows = (await env.DB.prepare('SELECT * FROM wa').all()).results || [];
  const notes = {};
  for (const n of notesRows) { (notes[n.lead_id] = notes[n.lead_id] || []).push({ id: n.id, t: n.t, txt: n.txt, e: n.e || null }); }
  const wa = {};
  for (const w of waRows) wa[w.lead_id] = w;
  return { leads, notes, wa };
}

function waLabel(rec) {
  if (!rec || !rec.result) return '';
  const del = rec.deliveryStatus, res = rec.result, send = rec.sendStatus;
  if (del === 'read') return 'נקרא ✓✓';
  if (del === 'delivered') return 'נמסר ✓';
  if (del === 'failed') return 'נכשל (' + (rec.deliveryErrorCode || '') + ')';
  if (del === 'sent') return 'בדרך…';
  if (res === 'accepted' || send === 'accepted') return 'נשלח, ממתין';
  if (res === 'duplicate') return 'כבר נשלח';
  if (res === 'ineligible') return ({ conversation_active: 'בשיחה עם הבוט', handoff_active: 'בטיפול נציג', recently_completed: 'טופל לאחרונה', recently_contacted: 'נשלח ב־24ש׳', not_in_pilot: 'לא ברשימת הבדיקה' }[rec.reason] || 'לא זמין כעת');
  if (res === 'error') return ({ send_outcome_unknown: 'לא ודאי — בדקו ב־WhatsApp', automation_off: 'השליחה מושהית', whatsapp_unavailable: 'WhatsApp לא מחובר' }[rec.error] || 'שגיאה');
  return '';
}

// ---------- login page ----------
function loginHTML(err) {
  return `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>כניסה · CRM</title><style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0B6F70;font-family:Heebo,Arial,sans-serif;padding:20px}
.box{background:#fff;border-radius:20px;padding:34px 30px;width:min(380px,100%);box-shadow:0 20px 60px rgba(0,0,0,.25)}
h1{margin:0 0 6px;font-size:1.4rem;color:#0B6F70}.sub{margin:0 0 18px;color:#6a7a7a;font-size:.92rem}
label{display:block;margin-bottom:14px;font-size:.9rem}input{width:100%;margin-top:6px;padding:11px 13px;border:1px solid #d8e5e4;border-radius:10px;font-size:1rem;font-family:inherit}
button{width:100%;padding:12px;border:0;border-radius:999px;background:#0B6F70;color:#fff;font-weight:800;font-size:1rem;cursor:pointer}
.err{background:#fdecea;color:#c0392b;padding:10px 12px;border-radius:10px;margin-bottom:14px;font-size:.9rem}
</style></head><body><div class="box">
<h1>כניסת ניהול</h1><p class="sub">מערכת פניות — מרפאת ד״ר תחסין יונס</p>
${err ? `<div class="err">${h(err)}</div>` : ''}
<form method="post"><input type="hidden" name="action" value="login">
<label>שם משתמש<input type="text" name="user" required autofocus autocomplete="username"></label>
<label>סיסמה<input type="password" name="pw" required autocomplete="current-password"></label>
<button type="submit">כניסה</button></form></div></body></html>`;
}

// ---------- dashboard shell ----------
async function shellHTML(env, req) {
  const { leads, notes, wa } = await loadAll(env);
  const waByLabel = {};
  for (const k in wa) waByLabel[k] = waLabel(wa[k]);
  const sess = sessTokFromCookie(req);
  const csrf = await csrfFor(env, sess);
  const waCfg = await cfgGetJson(env, 'wa_config');
  const data = {
    leads, notes, wa, waLabels: waByLabel, statuses: ST_LBL, csrf,
    waConfigured: !!(waCfg.secret || env.WA_BOT_SECRET),
  };
  const blob = JSON.stringify(data).replace(/</g, '\\u003c');
  return `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>CRM · מרפאת ד״ר תחסין יונס</title><link rel="icon" href="/favicon.ico">
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--teal:#0B6F70;--teal2:#1AADAD;--pale:#EFF8F7;--ink:#231F20;--line:#e3edec;--muted:#6a7a7a}
*{box-sizing:border-box}body{margin:0;font-family:Heebo,Arial,sans-serif;background:#f4f8f8;color:var(--ink)}
.top{background:var(--teal);color:#fff;padding:14px 26px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.top h1{font-size:1.1rem;margin:0;font-weight:800}.top .spacer{flex:1}
.tzhint{font-size:.78rem;color:rgba(255,255,255,.85)}
.top a.logout{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:8px 14px;border-radius:999px;font-size:.9rem}
.wrap{max-width:2000px;margin:0 auto;padding:20px 26px}
.stats{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 18px;min-width:130px}
.stat b{display:block;font-size:1.7rem;color:var(--teal);line-height:1}.stat span{font-size:.85rem;color:var(--muted)}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.toolbar input[type=search],.toolbar select{padding:10px 14px;border:1px solid var(--line);border-radius:10px;font-size:.95rem;font-family:inherit;background:#fff}
.toolbar input[type=search]{min-width:240px;flex:1}
.toolbar a.btn,.toolbar button.btn{background:var(--teal);color:#fff;text-decoration:none;padding:10px 16px;border-radius:10px;font-size:.9rem;font-weight:700;border:0;cursor:pointer;font-family:inherit}
.toolbar button.add{background:var(--teal2)}
.table-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:auto}
table{width:100%;border-collapse:collapse;font-size:.92rem;min-width:1100px}
th,td{padding:12px 14px;text-align:right;border-bottom:1px solid var(--line);vertical-align:top}
th{background:var(--pale);color:var(--teal);font-weight:700;white-space:nowrap;position:sticky;top:0}
tr.lead:hover td{background:#fafdfd}
td.acts{white-space:nowrap;position:sticky;right:0;background:#fff;box-shadow:-6px 0 8px -8px rgba(0,0,0,.25)}
tr.lead:hover td.acts{background:#fafdfd}
th.acth{position:sticky;right:0;z-index:3}
.edt,.del{border:1px solid var(--line);cursor:pointer;font-size:.8rem;font-weight:700;padding:6px 10px;border-radius:9px;font-family:inherit;margin-inline-end:6px;white-space:nowrap}
.edt{background:var(--pale);color:var(--teal)}.del{background:#fff;color:#c0392b;border-color:#eccfcb}
td.itr{max-width:240px;white-space:normal;overflow-wrap:break-word;color:#3f4f4f}
td.msg{max-width:340px;white-space:pre-wrap;overflow-wrap:break-word;color:#3f4f4f}
td.city{white-space:nowrap;color:#3f4f4f}.src{font-size:.8rem;color:var(--muted)}
a.lnk{color:var(--teal);text-decoration:none}a.walnk{color:#0f8f4c;font-weight:700;display:inline-flex;align-items:center;gap:5px;direction:ltr;padding-left:10px;text-decoration:none}
.waico{width:15px;height:15px}
a.callbtn,.botbtn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--line);border-radius:9px;vertical-align:middle;text-decoration:none;cursor:pointer}
a.callbtn{color:var(--teal);background:var(--pale)}.botbtn{color:#0f8f4c;background:#e7f9ee;border-color:#cdefda;margin-inline-end:5px}
.botbtn.sent{color:#1857b8;background:#e5f0ff;border-color:#bcd6ff}.botbtn:disabled{opacity:.55;cursor:default}
a.callbtn svg,.botbtn svg{width:15px;height:15px}.wast{font-size:.72rem;color:var(--muted);margin-inline-start:6px;white-space:nowrap}
select.st{padding:6px 8px;border-radius:8px;border:1px solid var(--line);font-family:inherit;font-size:.85rem;cursor:pointer;max-width:165px}
.st-new{background:#fff4e0;color:#a86400}.st-contacted{background:#e5f0ff;color:#1857b8}
.st-appointment{background:#efe7fb;color:#5b3a9e}.st-treated{background:#e4f6ec;color:#1c7a45}
.st-no_answer{background:#f6ece9;color:#8a5148}.st-callback{background:#efe7fb;color:#5b3a9e}
.st-not_suitable{background:#fde8e6;color:#c0392b}.st-not_interested{background:#f0f1f2;color:#5c6564}
.nbtn{background:var(--pale);border:1px solid var(--line);border-radius:9px;padding:6px 10px;cursor:pointer;font-family:inherit;font-size:.82rem;color:var(--teal);white-space:nowrap;font-weight:700}
tr.has-notes .nbtn{background:#e4f6ec;border-color:#c7e8d5;color:#1c7a45}
tr.nrow>td{background:#fbfdfd}.notes{display:flex;flex-direction:column;gap:10px;max-width:820px}
.nlist{display:flex;flex-direction:column;gap:6px}.note{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 11px;font-size:.88rem;line-height:1.55}
.note .nhead{display:flex;align-items:center;gap:8px;margin-bottom:3px}.note .nt{font-size:.74rem;color:var(--muted)}
.note .ned{font-size:.72rem;color:#a86400}.note .nsp{flex:1}.note .nx{white-space:pre-wrap;overflow-wrap:break-word}
.nact{background:none;border:0;cursor:pointer;font-size:.95rem;padding:3px 6px;border-radius:7px;color:var(--muted);opacity:.5}
.note:hover .nact{opacity:1}.nform{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap}
.nform textarea,.nedit textarea{flex:1;min-width:220px;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-family:inherit;font-size:.9rem;resize:vertical}
.nform button,.nedit .nsave{background:var(--teal);color:#fff;border:0;border-radius:10px;padding:10px 15px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.88rem}
.nedit{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}.nedit .ncancel{background:#fff;border:1px solid var(--line);border-radius:9px;padding:8px 13px;cursor:pointer}
.nmsg,.nempty{font-size:.82rem;color:var(--muted)}
.modal{position:fixed;inset:0;background:rgba(15,40,40,.55);display:flex;align-items:flex-start;justify-content:center;padding:24px 16px;overflow:auto;z-index:50}
.modal[hidden]{display:none}.mbox{background:#fff;border-radius:18px;padding:24px;width:min(620px,100%);box-shadow:0 24px 70px rgba(0,0,0,.3)}
.mbox h3{margin:0 0 16px;color:var(--teal)}.mgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:560px){.mgrid{grid-template-columns:1fr}}
.mbox label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:12px}
.mbox input,.mbox select,.mbox textarea{width:100%;margin-top:5px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-family:inherit;font-size:.95rem;background:#fff;resize:vertical}
.mact{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:6px}
.mact .save{background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 22px;font-weight:800;cursor:pointer;font-family:inherit}
.mact .cancel{background:#fff;border:1px solid var(--line);border-radius:10px;padding:12px 18px;cursor:pointer;font-family:inherit;color:var(--muted)}
.mmsg{font-size:.85rem;color:var(--muted)}.empty{padding:50px 20px;text-align:center;color:var(--muted)}
</style></head><body>
<div class="top"><h1>CRM · מרפאת ד״ר תחסין יונס</h1>
<span class="tzhint">🕒 שעון ישראל · ${h(tsNow())}</span><div class="spacer"></div>
<a class="logout" href="?logout=1">יציאה ←</a></div>
<div id="app" class="wrap"></div>
<script id="crmdata" type="application/json">${blob}</script>
<script src="/crm-app.js?v=1"></script>
<script src="/crm-import.js?v=1"></script>
</body></html>`;
}

// ---------- CSV ----------
async function csv(env) {
  const { leads, notes } = await loadAll(env);
  const head = ['תאריך', 'שם', 'טלפון', 'דוא״ל', 'עיר', 'טיפול', 'מה מתאים כרגע', 'שאלה / מידע', 'הודעה', 'מקור', 'סטטוס', 'הערות'];
  const esc = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
  const rows = [head.map(esc).join(',')];
  for (const l of leads) {
    const nl = (notes[l.id] || []).map(n => `[${n.t}] ${n.txt}${n.e ? ' (נערך ' + n.e + ')' : ''}`).join('\n');
    rows.push([l.ts, l.name, l.phone, l.email, l.city, l.interest, l.fit, l.question, l.msg, l.source, ST_LBL[l.status] || l.status, nl].map(esc).join(','));
  }
  return new Response('﻿' + rows.join('\r\n'), {
    headers: { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="younis-leads.csv"' },
  });
}

// ========== GET ==========
export async function onRequestGet({ request, env }) {
  const url = new URL(request.url);
  if (url.searchParams.get('logout') !== null) {
    return new Response('', { status: 302, headers: { 'Location': '/crm/index.php', 'Set-Cookie': 'crmsess=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax' } });
  }
  const sess = await readSession(env, getCookie(request));
  if (!sess) return new Response(loginHTML(''), { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  if (url.searchParams.get('export') === 'csv') return csv(env);
  return new Response(await shellHTML(env, request), { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

// ========== POST ==========
export async function onRequestPost({ request, env }) {
  const ct = request.headers.get('Content-Type') || '';
  let body = {};
  if (ct.includes('application/json')) { try { body = await request.json(); } catch (e) {} }
  else { try { const f = await request.formData(); body = Object.fromEntries(f); } catch (e) {} }
  const action = body.action || '';

  // ---- login ----
  if (action === 'login') {
    const ok = clean(body.user) === (env.CRM_USER || '') && String(body.pw || '') === (env.CRM_PASS || '');
    if (!ok) return new Response(loginHTML('שם משתמש או סיסמה שגויים.'), { status: 401, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    const tok = await makeSession(env, clean(body.user));
    return new Response('', { status: 302, headers: { 'Location': '/crm/index.php', 'Set-Cookie': `crmsess=${tok}; Path=/; Max-Age=${7 * 864e2}; HttpOnly; Secure; SameSite=Lax` } });
  }

  // ---- all other actions require auth + csrf ----
  const sess = await readSession(env, getCookie(request));
  if (!sess) return json({ ok: false, error: 'unauthorized' }, 401);
  const wantCsrf = await csrfFor(env, sessTokFromCookie(request));
  if ((body.csrf || '') !== wantCsrf) return json({ ok: false, error: 'bad_csrf' }, 403);

  const id = clean(body.id || '');
  const D = env.DB;

  if (action === 'status') {
    const v = ST_KEYS.includes(body.value) ? body.value : 'new';
    await D.prepare('UPDATE leads SET status=? WHERE id=?').bind(v, id).run();
    return json({ ok: true });
  }
  if (action === 'delete') {
    await D.batch([
      D.prepare('DELETE FROM leads WHERE id=?').bind(id),
      D.prepare('DELETE FROM notes WHERE lead_id=?').bind(id),
      D.prepare('DELETE FROM wa WHERE lead_id=?').bind(id),
    ]);
    return json({ ok: true });
  }
  if (action === 'note_add') {
    const txt = clean(body.text);
    if (!id || !txt) return json({ ok: false });
    const note = { id: shortId(), t: tsNow(), txt };
    await D.prepare('INSERT INTO notes (id,lead_id,t,txt) VALUES (?,?,?,?)').bind(note.id, id, note.t, txt).run();
    const c = (await D.prepare('SELECT COUNT(*) n FROM notes WHERE lead_id=?').bind(id).first()).n;
    return json({ ok: true, note, count: c });
  }
  if (action === 'note_edit') {
    const nid = clean(body.nid), txt = clean(body.text);
    if (!id || !nid || !txt) return json({ ok: false });
    const e = tsNow();
    const r = await D.prepare('UPDATE notes SET txt=?, e=? WHERE id=? AND lead_id=?').bind(txt, e, nid, id).run();
    if (!r.meta.changes) return json({ ok: false, error: 'not_found' });
    return json({ ok: true, note: { id: nid, txt, e } });
  }
  if (action === 'note_del') {
    const nid = clean(body.nid);
    await D.prepare('DELETE FROM notes WHERE id=? AND lead_id=?').bind(nid, id).run();
    const c = (await D.prepare('SELECT COUNT(*) n FROM notes WHERE lead_id=?').bind(id).first()).n;
    return json({ ok: true, count: c });
  }
  if (action === 'lead_add') {
    const name = clean(body.name), phone = clean(body.phone);
    if (!name) return json({ ok: false, error: 'no_name' });
    if (phone.replace(/\D/g, '').length < 7) return json({ ok: false, error: 'bad_phone' });
    const lid = newId();
    await insertLead(env, {
      id: lid, ts: tsNow(), name, phone, email: clean(body.email), interest: clean(body.interest),
      msg: clean(body.msg), city: clean(body.city), fit: clean(body.fit), question: clean(body.question),
      source: clean(body.source) || 'הוספה ידנית', status: ST_KEYS.includes(body.status) ? body.status : 'new',
      manual: 1,
    });
    const note = clean(body.note);
    if (note) await addNote(env, lid, note);
    return json({ ok: true, id: lid });
  }
  if (action === 'lead_edit') {
    const lid = clean(body.lead_id), name = clean(body.name), phone = clean(body.phone);
    if (!lid) return json({ ok: false, error: 'no_id' });
    if (!name) return json({ ok: false, error: 'no_name' });
    if (phone.replace(/\D/g, '').length < 7) return json({ ok: false, error: 'bad_phone' });
    const r = await D.prepare(
      'UPDATE leads SET name=?,phone=?,phone_key=?,email=?,interest=?,msg=?,city=?,fit=?,question=?,source=COALESCE(NULLIF(?,\'\'),source),status=CASE WHEN ? IN (' + ST_KEYS.map(() => '?').join(',') + ') THEN ? ELSE status END,edited=? WHERE id=?'
    ).bind(name, phone, phoneKey(phone), clean(body.email), clean(body.interest), clean(body.msg),
      clean(body.city), clean(body.fit), clean(body.question), clean(body.source),
      body.status || '', ...ST_KEYS, body.status || '', tsNow(), lid).run();
    if (!r.meta.changes) return json({ ok: false, error: 'not_found' });
    const note = clean(body.note);
    if (note) await addNote(env, lid, note);
    return json({ ok: true });
  }
  if (action === 'wa_send' || action === 'wa_status') {
    const waCfg = await cfgGetJson(env, 'wa_config');
    const secret = waCfg.secret || env.WA_BOT_SECRET || '';
    const base = (waCfg.base_url || env.WA_BASE_URL || '').replace(/\/$/, '');
    if (!secret || !base) return json({ ok: false, error: 'not_configured' });
    const lead = await D.prepare('SELECT * FROM leads WHERE id=?').bind(id).first();
    if (!lead) return json({ ok: false, error: 'lead_not_found' });

    const hdr = { 'Authorization': 'Bearer ' + secret, 'Accept': 'application/json' };
    if (action === 'wa_status') {
      let r, bodyj;
      try { r = await fetch(base + '/crm/v1/whatsapp-opening/' + encodeURIComponent(id), { headers: hdr }); bodyj = await r.json(); } catch (e) { return json({ ok: false, error: 'fetch_failed' }); }
      if (bodyj && (bodyj.result === 'found' || bodyj.result === 'not_found')) await saveWa(env, id, bodyj, r.status);
      const rec = await D.prepare('SELECT * FROM wa WHERE lead_id=?').bind(id).first();
      return json({ ok: true, rec, label: waLabel(rec || {}) });
    }
    const phone = waNumber(lead.phone);
    if (phone.length < 7 || phone.length > 15) return json({ ok: false, error: 'bad_phone' });
    if (!lead.consent && !body.confirm) return json({ ok: false, error: 'need_consent_confirm' });
    let r, bodyj;
    try {
      r = await fetch(base + '/crm/v1/whatsapp-opening', { method: 'POST', headers: { ...hdr, 'Content-Type': 'application/json' }, body: JSON.stringify({ leadId: id, phone: '+' + phone, contactConsent: true }) });
      bodyj = await r.json();
    } catch (e) { return json({ ok: false, error: 'fetch_failed' }); }
    await saveWa(env, id, bodyj || {}, r.status);
    const rec = await D.prepare('SELECT * FROM wa WHERE lead_id=?').bind(id).first();
    const ok = r.status === 200 && ['accepted', 'duplicate'].includes(bodyj && bodyj.result);
    return json({ ok, label: waLabel(rec || {}), error: ok ? null : ((bodyj && (bodyj.reason || bodyj.error)) || 'error') });
  }

  if (action === 'import') {
    var rows = Array.isArray(body.rows) ? body.rows : [];
    if (!rows.length) return json({ ok: false, error: 'no_rows' });
    if (rows.length > 5000) rows = rows.slice(0, 5000);
    const skipDup = !!body.skip_dup;
    const defSource = clean(body.default_source) || 'ייבוא מקובץ';
    const defStatus = ST_KEYS.includes(body.default_status) ? body.default_status : 'new';
    const lblToKey = {}; for (const k of ST_KEYS) { lblToKey[k] = k; lblToKey[ST_LBL[k]] = k; }

    const ex = (await D.prepare('SELECT phone_key FROM leads').all()).results || [];
    const existing = new Set(ex.map(r => r.phone_key).filter(Boolean));

    const impPhone = (v) => {
      let d = String(v == null ? '' : v).replace(/^\s*p\s*:\s*/i, '').trim();
      let digits = d.replace(/\D/g, '');
      if (/^5\d{8}$/.test(digits)) return '0' + digits;        // lost leading zero
      return d;
    };
    const LC = ['id','ts','name','phone','phone_key','email','interest','msg','city','fit','question','source','ip','status','consent','inbound','manual','imported','wa_id','lead_id','form_id','page_id','ad_id','gclid','campaign_id','edited'];
    let added = 0, dup = 0, bad = 0, i = 0; const stmts = []; const newIds = []; const noteStmts = [];
    for (const r of rows) {
      let name = clean(r.name || '');
      let phone = impPhone(r.phone || '');
      if (!name && !phone) { bad++; continue; }
      if (!name) name = 'ללא שם';
      const pk = phoneKey(phone);
      if (skipDup && pk && existing.has(pk)) { dup++; continue; }
      if (pk) existing.add(pk);
      const lid = newId() + (i++).toString(36);
      const stRaw = clean(r.status || '');
      const st = lblToKey[stRaw] || defStatus;
      const obj = { id: lid, ts: clean(r.ts || '') || tsNow(), name, phone, phone_key: pk,
        email: clean(r.email || ''), interest: clean(r.interest || ''), msg: clean(r.msg || ''),
        city: clean(r.city || ''), fit: clean(r.fit || ''), question: clean(r.question || ''),
        source: clean(r.source || '') || defSource, ip: '', status: st,
        consent: 0, inbound: 0, manual: 0, imported: 1, wa_id: '', lead_id: '', form_id: '',
        page_id: '', ad_id: '', gclid: '', campaign_id: '', edited: '' };
      stmts.push(D.prepare('INSERT INTO leads (' + LC.join(',') + ') VALUES (' + LC.map(() => '?').join(',') + ')').bind(...LC.map(c => obj[c])));
      newIds.push(lid);
      const note = clean(r.note || '');
      if (note) noteStmts.push(D.prepare('INSERT INTO notes (id,lead_id,t,txt) VALUES (?,?,?,?)').bind(shortId(), lid, tsNow(), note));
      added++;
    }
    const all = stmts.concat(noteStmts);
    for (let j = 0; j < all.length; j += 50) await D.batch(all.slice(j, j + 50));
    if (newIds.length) await env.DB.prepare('INSERT INTO kv (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')
      .bind('import_last', JSON.stringify({ at: tsNow(), ids: newIds })).run();
    return json({ ok: true, added, dup, bad });
  }
  if (action === 'import_undo') {
    const row = await D.prepare('SELECT v FROM kv WHERE k=?').bind('import_last').first();
    if (!row || !row.v) return json({ ok: false, error: 'nothing' });
    let ids = []; try { ids = (JSON.parse(row.v).ids) || []; } catch (e) {}
    if (!ids.length) return json({ ok: false, error: 'nothing' });
    for (let j = 0; j < ids.length; j += 50) {
      const chunk = ids.slice(j, j + 50);
      const qs = chunk.map(() => '?').join(',');
      await D.batch([
        D.prepare('DELETE FROM leads WHERE id IN (' + qs + ')').bind(...chunk),
        D.prepare('DELETE FROM notes WHERE lead_id IN (' + qs + ')').bind(...chunk),
      ]);
    }
    await D.prepare('DELETE FROM kv WHERE k=?').bind('import_last').run();
    return json({ ok: true, undone: ids.length });
  }
  return json({ ok: false, error: 'unknown_action' });
}

async function saveWa(env, leadId, body, code) {
  const f = k => (body[k] === undefined ? null : body[k]);
  await env.DB.prepare(
    `INSERT INTO wa (lead_id,result,sendStatus,deliveryStatus,deliveryErrorCode,reason,error,messageId,providerStatus,requestedAt,updatedAt,httpCode,at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(lead_id) DO UPDATE SET result=excluded.result,sendStatus=excluded.sendStatus,deliveryStatus=excluded.deliveryStatus,
       deliveryErrorCode=excluded.deliveryErrorCode,reason=excluded.reason,error=excluded.error,messageId=excluded.messageId,
       providerStatus=excluded.providerStatus,requestedAt=excluded.requestedAt,updatedAt=excluded.updatedAt,httpCode=excluded.httpCode,at=excluded.at`
  ).bind(leadId, f('result'), f('sendStatus'), f('deliveryStatus'), f('deliveryErrorCode'), f('reason'), f('error'),
    f('messageId'), f('providerStatus'), f('requestedAt'), f('updatedAt'), code, tsNow()).run();
}
