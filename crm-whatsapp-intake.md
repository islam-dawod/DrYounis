# Inbound WhatsApp → CRM intake

When someone messages the clinic's WhatsApp and is **not** already a lead,
the bot asks its intake questions, then makes **one server-to-server call**
to the CRM to create the lead. This is the reverse of the opening-message
flow in `crm-whatsapp-handoff.md`.

```
lead messages WhatsApp ─► bot asks questions ─► POST /crm/wa-intake.php ─► CRM creates / updates the lead
```

## Endpoint

`POST https://younisclinic.com/crm/wa-intake.php`

Headers:
- `Authorization: Bearer <INTAKE_KEY>` (or `X-CRM-Key: <INTAKE_KEY>`)
- `Content-Type: application/json`

`INTAKE_KEY` is generated in the CRM dashboard → **בוט WhatsApp** panel →
**יצירת Intake Key**, and handed to the bot developer through a secure
channel. It is stored only in the CRM backend (out-of-webroot) and the
bot's secret store — never in the browser or in source control. Generating
a new key in the panel immediately invalidates the old one.

## Body

Send what the conversation collected. Only `phone` is required; send the
rest when you have it.

| Field | Required | Notes |
| --- | --- | --- |
| `phone` | yes | International; `+972…`, `972…`, or local `05…` all accepted (a leading `0`/`00` is converted, not dropped). 7–15 digits. |
| `name` | no | Full name. Falls back to `פונה מ־WhatsApp` if absent. |
| `city` | no | עיר |
| `fit` | no | מה מתאים לכם כרגע? |
| `question` | no | יש לכם שאלה או מידע שתרצו לקבל מאיתנו? |
| `interest` | no | Treatment of interest. |
| `msg` | no | Free text / summary of the conversation. |
| `source` | no | Defaults to `WhatsApp`. |
| `wa_id` | no | The bot's own conversation/contact id, stored for reference. |

Example:

```bash
curl -sS -X POST "https://younisclinic.com/crm/wa-intake.php" \
  -H "Authorization: Bearer $INTAKE_KEY" \
  -H "Content-Type: application/json" --max-time 15 \
  -d '{"phone":"+972 56-916-8998","name":"ישראל ישראלי","city":"חיפה","interest":"השתלות","question":"כמה עולה ייעוץ?","source":"WhatsApp"}'
```

## Responses

```json
{ "ok": true, "result": "created",   "id": "20261009…" }   // new lead created
{ "ok": true, "result": "duplicate", "id": "20260902…" }   // phone already a lead — a note was appended instead
```

| HTTP | Meaning |
| --- | --- |
| 200 `created` | New lead added (source WhatsApp, status נוצר קשר, consent recorded as inbound). |
| 200 `duplicate` | A lead with this phone already exists; the new details were appended as a note on that lead — no duplicate created. |
| 400 `invalid_phone` | Phone missing or not 7–15 digits. |
| 401 `unauthorized` | Missing/wrong `INTAKE_KEY`. |
| 405 `method_not_allowed` | Use `POST`. |

## Notes

- **Dedup** is by the last 9 digits of the phone, so the same person is
  never added twice; repeat contacts land as notes on the existing lead.
- The lead appears in the CRM with its `עיר` and `מה מתאים לכם כרגע?`
  columns filled, and the conversation summary in its note log.
- Consent: the customer initiated the WhatsApp contact, so the lead is
  stored with consent for WhatsApp follow-up.
- Call server-to-server from the bot/Worker only. Never expose
  `INTAKE_KEY` to a browser or a client app.
