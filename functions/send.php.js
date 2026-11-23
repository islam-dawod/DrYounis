// /send.php — website + landing-page form handler (replaces send.php).
// Stores the lead in D1 and (optionally) emails a notification via Resend.
import { json, clean, newId, tsNow, insertLead } from './_lib.js';

export async function onRequestPost({ request, env }) {
  let f;
  try { f = await request.formData(); }
  catch (e) {
    try { f = new Map(Object.entries(await request.json())); } catch (e2) { f = new Map(); }
  }
  const g = (k) => clean((f.get ? f.get(k) : f[k]) || '');

  // Honeypot: pretend success without storing.
  if (g('company')) return json({ ok: true });

  const name  = g('fname') || g('name');
  const phone = g('phone');
  if (!name || !phone) return json({ ok: false, error: 'missing_fields' }, 422);
  if (phone.replace(/\D/g, '').length < 7) return json({ ok: false, error: 'invalid_phone' }, 422);

  const lead = {
    id: newId(), ts: tsNow(),
    name, phone,
    email:    g('email'),
    interest: g('interest'),
    msg:      g('msg') || g('message'),
    city:     g('city'),
    fit:      g('fit') || g('current'),
    question: g('question'),
    source:   g('source') || 'אתר ראשי',
    ip:       request.headers.get('CF-Connecting-IP') || '',
    status:   'new',
    consent:  1,                 // the form's consent checkbox is required
  };

  try { await insertLead(env, lead); }
  catch (e) { return json({ ok: false, error: 'store_failed' }, 500); }

  // Optional email notification (Resend). Configure RESEND_API_KEY + MAIL_TO.
  const key = env.RESEND_API_KEY;
  const to  = env.MAIL_TO || 'info.younisclinic@gmail.com';
  if (key) {
    const lines = [
      `שם: ${lead.name}`, `טלפון: ${lead.phone}`,
      lead.email ? `דוא״ל: ${lead.email}` : '',
      lead.city ? `עיר: ${lead.city}` : '',
      lead.interest ? `טיפול: ${lead.interest}` : '',
      lead.fit ? `מה מתאים: ${lead.fit}` : '',
      lead.question ? `שאלה: ${lead.question}` : '',
      lead.msg ? `הודעה: ${lead.msg}` : '',
      `מקור: ${lead.source}`, `התקבל: ${lead.ts}`,
    ].filter(Boolean).join('\n');
    try {
      await fetch('https://api.resend.com/emails', {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${key}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          from: env.MAIL_FROM || 'Younis Clinic <onboarding@resend.dev>',
          to: [to],
          subject: 'פנייה חדשה מהאתר — ' + lead.name,
          text: lines,
        }),
      });
    } catch (e) { /* email is best-effort; the lead is already saved */ }
  }

  return json({ ok: true });
}

export function onRequest() { return json({ ok: false, error: 'method_not_allowed' }, 405); }
