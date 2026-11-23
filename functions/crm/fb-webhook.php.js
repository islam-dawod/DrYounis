// /crm/fb-webhook.php — Facebook/Instagram Lead Ads (replaces fb-webhook.php).
// GET  = Meta webhook verification (hub.challenge).
// POST = (A) native leadgen notification -> pull from Graph API, or
//        (B) keyed POST from Make/Zapier.
import { json, clean, newId, tsNow, insertLead, leadExistsByExternalId, cfgGetJson } from '../_lib.js';

async function hmacHex(secret, body) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', enc.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(body));
  return [...new Uint8Array(sig)].map(b => b.toString(16).padStart(2, '0')).join('');
}

async function fbCfg(env) {
  const c = await cfgGetJson(env, 'fb_config');
  return {
    verify_token: c.verify_token || env.FB_VERIFY_TOKEN || '',
    app_secret:   c.app_secret   || env.FB_APP_SECRET   || '',
    page_token:   c.page_token   || env.FB_PAGE_TOKEN   || '',
    key:          c.key          || env.FB_KEY          || '',
  };
}

// ---- GET: verification ----
export async function onRequestGet({ request, env }) {
  const u = new URL(request.url);
  const mode = u.searchParams.get('hub.mode') || '';
  const token = u.searchParams.get('hub.verify_token') || '';
  const challenge = u.searchParams.get('hub.challenge') || '';
  const cfg = await fbCfg(env);
  if (mode === 'subscribe' && cfg.verify_token && token === cfg.verify_token) {
    return new Response(challenge, { status: 200, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
  }
  return json({ ok: false, error: 'verification_failed' }, 403);
}

async function graphGet(url) {
  try { const r = await fetch(url); return await r.json(); } catch (e) { return null; }
}
async function formName(formId, token) {
  if (!formId || !token) return '';
  const g = await graphGet(`https://graph.facebook.com/v21.0/${encodeURIComponent(formId)}?fields=name&access_token=${encodeURIComponent(token)}`);
  return (g && g.name) ? clean(g.name) : '';
}

export async function onRequestPost({ request, env }) {
  const cfg = await fbCfg(env);
  const raw = await request.text();
  let data; try { data = JSON.parse(raw); } catch (e) { data = null; }

  // ---- (A) native leadgen ----
  if (data && data.object === 'page' && Array.isArray(data.entry)) {
    if (cfg.app_secret) {
      const sig = request.headers.get('X-Hub-Signature-256') || '';
      const exp = 'sha256=' + await hmacHex(cfg.app_secret, raw);
      if (!sig || sig !== exp) return json({ ok: false, error: 'bad_signature' }, 401);
    }
    let saved = 0;
    for (const entry of data.entry) {
      for (const ch of (entry.changes || [])) {
        if (ch.field !== 'leadgen') continue;
        const v = ch.value || {};
        const lgid = clean(v.leadgen_id || '');
        const formId = clean(v.form_id || '');
        if (!lgid || await leadExistsByExternalId(env, lgid)) continue;

        let name = '', first = '', last = '', phone = '', email = '';
        let city = '', fit = '', question = '';
        const extra = [];
        const fname = await formName(formId, cfg.page_token);

        if (cfg.page_token) {
          const g = await graphGet(`https://graph.facebook.com/v21.0/${encodeURIComponent(lgid)}?access_token=${encodeURIComponent(cfg.page_token)}`);
          if (g && Array.isArray(g.field_data)) {
            for (const fd of g.field_data) {
              const rawName = clean(fd.name || '');
              const k = rawName.toLowerCase();
              const val = clean((fd.values || []).join(', '));
              if (!val) continue;
              if (k === 'full_name') name = val;
              else if (k === 'first_name') first = val;
              else if (k === 'last_name') last = val;
              else if (k === 'phone_number' || k === 'phone') phone = val;
              else if (k === 'email') email = val;
              else if (k === 'city' || rawName.includes('עיר') || rawName.includes('יישוב') || rawName.includes('ישוב')) city = val;
              else if (rawName.includes('מתאים')) fit = val;
              else if (rawName.includes('שאלה') || rawName.includes('מידע')) question = val;
              else extra.push(rawName + ': ' + val);
            }
          }
        }
        if (!name) name = (first + ' ' + last).trim();
        if (!name) name = 'ליד מפייסבוק';
        let msg = extra.join(' | ');
        if (!cfg.page_token) msg = (msg + ' | leadgen_id: ' + lgid + ' (חסר Page Access Token)').trim();

        await insertLead(env, {
          id: newId(), ts: tsNow(), name, phone, email,
          interest: fname, msg, city, fit, question,
          source: 'Facebook', status: 'new',
          lead_id: lgid, form_id: formId,
          page_id: clean(v.page_id || ''), ad_id: clean(v.ad_id || ''),
        });
        saved++;
      }
    }
    return json({ ok: true, saved });
  }

  // ---- (B) keyed POST (Make/Zapier) ----
  const inn = (data && typeof data === 'object') ? data : {};
  const got = String(inn.key || request.headers.get('X-CRM-Key') || '');
  if (!cfg.key || got !== cfg.key) return json({ ok: false, error: 'invalid_key' }, 401);

  let name = clean(inn.name || inn.full_name || '');
  if (!name) name = (clean(inn.first_name || '') + ' ' + clean(inn.last_name || '')).trim();
  if (!name) name = 'ליד מפייסבוק';
  const lgid = clean(inn.lead_id || inn.leadgen_id || '');
  if (lgid && await leadExistsByExternalId(env, lgid)) return json({ ok: true, duplicate: true });

  await insertLead(env, {
    id: newId(), ts: tsNow(), name,
    phone: clean(inn.phone || inn.phone_number || ''),
    email: clean(inn.email || ''),
    interest: clean(inn.interest || inn.form_name || ''),
    msg: clean(inn.msg || inn.message || ''),
    city: clean(inn.city || ''), fit: clean(inn.fit || ''), question: clean(inn.question || ''),
    source: clean(inn.source || '') || 'Facebook', status: 'new',
    lead_id: lgid,
  });
  return json({ ok: true });
}
