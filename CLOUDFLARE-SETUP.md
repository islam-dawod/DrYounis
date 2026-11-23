# Moving the backend to Cloudflare (Pages Functions + D1)

The static site already runs on Cloudflare Pages. This adds the **backend**
(forms + webhooks now; CRM dashboard next) as **Pages Functions** in the same
project, with **D1** (free SQLite) replacing the Hostinger JSON files. The
public URLs stay identical (`/send.php`, `/crm/fb-webhook.php`, …) so Meta,
Google Ads and the WhatsApp bot need **no reconfiguration**.

## What's included now

| URL | File | Purpose |
| --- | --- | --- |
| `/send.php` | `functions/send.php.js` | Website + LP forms → D1 (+ optional email) |
| `/crm/wa-intake.php` | `functions/crm/wa-intake.php.js` | Inbound WhatsApp bot creates a lead |
| `/crm/gads-webhook.php` | `functions/crm/gads-webhook.php.js` | Google Ads lead form |
| `/crm/fb-webhook.php` | `functions/crm/fb-webhook.php.js` | Facebook/Instagram lead ads |

Still to come (next step): the **CRM dashboard** (`/crm/`) — table, notes,
statuses, import, CSV, bot buttons — rebuilt on the same D1.

## One-time setup (you do this in Cloudflare)

Install the CLI once: `npm i -g wrangler` then `wrangler login`.

**1. Create the database**
```bash
wrangler d1 create younis-crm
```
Copy the `database_id` it prints into `wrangler.toml` (replace
`REPLACE_WITH_D1_DATABASE_ID`), commit, push.

**2. Create the tables**
```bash
wrangler d1 execute younis-crm --remote --file=schema.sql
```

**3. Bind D1 to the Pages project**
Dashboard → your Pages project → **Settings → Functions → D1 database
bindings** → Add: variable name **`DB`**, database **younis-crm**.

**4. Add the secrets** (Settings → **Variables and Secrets**, type *Secret*):

| Name | For | Needed |
| --- | --- | --- |
| `GADS_KEY` | Google Ads webhook | if you use Google Ads |
| `FB_VERIFY_TOKEN` `FB_APP_SECRET` `FB_PAGE_TOKEN` `FB_KEY` | Facebook webhook | if you use Facebook |
| `WA_INTAKE_KEY` | inbound WhatsApp bot | if you use the bot intake |
| `RESEND_API_KEY` `MAIL_TO` `MAIL_FROM` | email notifications on form leads | optional |

(These are the same values you had on Hostinger. The CRM dashboard, once
built, will also let you save/rotate them from its panels.)

**5. Redeploy** — any push triggers a new Pages build that picks up the
Functions and bindings.

## Migrating existing leads from Hostinger

Download from Hostinger File Manager (folder `younis-crm-data`, **outside**
`public_html`): `leads.ndjson`, `status.json`, `notes.json`, `wa.json`.
Send me the JSON files (not the secrets) and I'll generate an import SQL /
script to load them into D1. Keep Hostinger running until this is done and
verified.

## Testing (after setup)

```bash
# wa-intake: no key -> 401, GET -> 405
curl -s -X POST https://younisclinic.com/crm/wa-intake.php -d '{}' -H 'Content-Type: application/json'

# a form lead (also exercises /send.php)
curl -s -X POST https://younisclinic.com/send.php \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'fname=בדיקה' --data-urlencode 'phone=+972569168998' \
  --data-urlencode 'city=חיפה' --data-urlencode 'source=בדיקה'
```
Then check the row exists: `wrangler d1 execute younis-crm --remote --command "SELECT id,name,phone,source FROM leads ORDER BY ts DESC LIMIT 5"`.

## Important

- **Do not switch the nameservers / cut off Hostinger** until the CRM
  dashboard is also migrated and the data is imported — the live CRM and
  webhooks on Hostinger must keep working during the transition.
- Nothing here contains secrets; they live only in Cloudflare settings.
