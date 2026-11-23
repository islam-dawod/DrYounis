// /crm/wa-intake.php — inbound WhatsApp bot creates a lead (replaces wa-intake.php).
// Auth: Bearer intake_key (kv wa_config.intake_key, or env WA_INTAKE_KEY).
import { json, clean, newId, tsNow, waNumber, phoneKey,
         insertLead, findLeadIdByPhone, addNote, cfgGetJson } from '../_lib.js';

function intlPhone(p) {
  const d = waNumber(p);
  return (d.length >= 7 && d.length <= 15) ? '+' + d : '';
}

export async function onRequestPost({ request, env }) {
  const waCfg = await cfgGetJson(env, 'wa_config');
  const key = waCfg.intake_key || env.WA_INTAKE_KEY || '';
  const auth = request.headers.get('Authorization') || '';
  let got = '';
  const m = auth.match(/Bearer\s+(.+)/i);
  if (m) got = m[1].trim();
  if (!got) got = (request.headers.get('X-CRM-Key') || '').trim();
  if (!key || got !== key) return json({ ok: false, error: 'unauthorized' }, 401);

  let inn;
  try { inn = await request.json(); }
  catch (e) {
    try { const f = await request.formData(); inn = Object.fromEntries(f); } catch (e2) { inn = {}; }
  }
  const g = (k) => clean(inn[k] || '');

  const phone = intlPhone(g('phone') || g('phone_number'));
  if (!phone) return json({ ok: false, error: 'invalid_phone' }, 400);

  let name = g('name') || g('full_name');
  if (!name) name = (g('first_name') + ' ' + g('last_name')).trim();
  if (!name) name = 'פונה מ־WhatsApp';

  const city = g('city'), fit = g('fit') || g('current'), question = g('question');
  const msg = g('msg') || g('message'), interest = g('interest');
  const source = g('source') || 'WhatsApp';
  const waId = g('wa_id') || g('leadId');

  // Dedupe by phone — append a note instead of duplicating.
  const existing = await findLeadIdByPhone(env, phone);
  if (existing) {
    const parts = [];
    if (interest) parts.push('טיפול: ' + interest);
    if (city) parts.push('עיר: ' + city);
    if (fit) parts.push('מה מתאים: ' + fit);
    if (question) parts.push('שאלה: ' + question);
    if (msg) parts.push(msg);
    await addNote(env, existing, 'פנייה חדשה ב־WhatsApp' + (parts.length ? ' — ' + parts.join(' | ') : ''));
    return json({ ok: true, result: 'duplicate', id: existing });
  }

  const id = newId();
  await insertLead(env, {
    id, ts: tsNow(), name, phone,
    email: g('email'), interest, msg, city, fit, question,
    source, status: 'contacted', consent: 1, inbound: 1, wa_id: waId,
  });
  if (question || msg) await addNote(env, id, 'מהשיחה ב־WhatsApp: ' + (question + ' ' + msg).trim());

  return json({ ok: true, result: 'created', id });
}

export function onRequest() {
  return json({ ok: false, error: 'method_not_allowed' }, 405, { Allow: 'POST' });
}
