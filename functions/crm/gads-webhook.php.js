// /crm/gads-webhook.php — Google Ads Lead Form Webhook (replaces gads-webhook.php).
// Google sends the lead data with a google_key that must match our stored key.
import { json, clean, newId, tsNow, insertLead, cfgGet } from '../_lib.js';

export async function onRequestPost({ request, env }) {
  const KEY = await cfgGet(env, 'gads_key', 'GADS_KEY');

  let data;
  try { data = await request.json(); }
  catch (e) { return json({ ok: false, error: 'bad_payload' }, 400); }
  if (!data || typeof data !== 'object') return json({ ok: false, error: 'bad_payload' }, 400);

  const sent = String(data.google_key || '');
  if (!KEY || sent !== KEY) return json({ ok: false, error: 'invalid_key' }, 401);

  let name = '', first = '', last = '', phone = '', email = '';
  for (const c of (data.user_column_data || [])) {
    const id = String(c.column_id || '').toUpperCase();
    const v = clean(c.string_value || '');
    if (!v) continue;
    if (id === 'FULL_NAME' || id === 'NAME') name = v;
    else if (id === 'FIRST_NAME') first = v;
    else if (id === 'LAST_NAME') last = v;
    else if (id === 'PHONE_NUMBER') phone = v;
    else if (id === 'EMAIL') email = v;
  }
  if (!name) name = (first + ' ' + last).trim();
  if (!name) name = 'ליד מ־Google Ads';

  const isTest = !!data.is_test;
  const id = newId();
  await insertLead(env, {
    id, ts: tsNow(), name, phone, email,
    source: 'Google Ads' + (isTest ? ' (בדיקה)' : ''),
    status: 'new',
    gclid: clean(data.gcl_id || ''),
    campaign_id: clean(data.campaign_id || ''),
    lead_id: clean(data.lead_id || ''),
  });

  return json({ ok: true, lead_id: clean(data.lead_id || '') });
}

export function onRequest() {
  return json({ ok: false, error: 'method_not_allowed' }, 405, { Allow: 'POST' });
}
