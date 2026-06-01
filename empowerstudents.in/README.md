# Empower Students

Parent-facing child assessment platform for **empowerstudents.in** — built on
PHP 7.4 + SQLite + Tailwind CDN, designed to run as an add-on domain on
GoDaddy shared hosting (same server as mydoctor.ltd / neurocareindia.in).

## What it does

A parent logs in with their **WhatsApp number + OTP** (via Twilio
Content templates), gets **100 free credits**, registers one or more
children, and walks them through age-appropriate assessment modules.
Modules cost credits; users top up via **Cashfree** (already
whitelisted for empowerstudents.in).

| Module               | Type                                 | Cost  | Age    |
|----------------------|--------------------------------------|-------|--------|
| Health               | Parent questionnaire                 | 3 cr  | All    |
| Pulse / breath       | PPG camera + Buteyko timer           | 2 cr  | 5+     |
| Mind power           | Parent questionnaire                 | 3 cr  | All    |
| Emotions             | Parent questionnaire                 | 3 cr  | All    |
| Behaviour            | Parent questionnaire (M-CHAT for <2y)| 3 cr  | All    |
| Special talent       | Parent questionnaire                 | 3 cr  | All    |
| Parent index         | Self-rating questionnaire            | 3 cr  | All    |
| General awareness    | 2-min adaptive MCQ                   | 5 cr  | 3+     |
| Maths                | 12-question adaptive (7 levels)      | 5 cr  | 4+     |
| Language             | Word power + timed comprehension     | 5 cr  | 5+     |
| Speech               | Read-aloud + audio + AI              | 10 cr | 4+     |
| Spontaneous speech   | Open prompt + audio + AI             | 10 cr | 2+     |
| Diet                 | Inputs → 7-day Indian meal plan      | 20 cr | All    |
| **Comprehensive AI report** | Synthesis of all modules      | **50 cr** | **Max 1 / child lifetime** |

The "1 advice per API key" rule is enforced for the comprehensive
report — admin can lift it manually for individual children if needed.

## Credit / wallet system

- **1 credit = ₹1.**
- **100 free credits** to every new parent on first WhatsApp-OTP login
  (idempotent — only granted once).
- Top-up packs on `/wallet.php`: ₹100, ₹250 (+25 bonus), ₹500 (+75 bonus),
  ₹1000 (+200 bonus). Bonuses are applied automatically by the
  return / webhook handler.
- Every charge / top-up / admin grant goes through `wallet_ledger`.
  `parents.credits` is a denormalised cache kept in sync atomically.
- Idempotent crediting: a Cashfree order can never double-credit, even
  if the user reloads the return URL or the webhook fires twice.

## File layout

```
/                        public-facing pages
  index, about, login, dashboard, add_child, child, specialists
  wallet.php             balance + top-up packs
  api_topup_create.php   JSON endpoint that creates the Cashfree order
  payment_return.php     verifies + credits after Cashfree redirect
  payment_webhook.php    Cashfree S2S webhook (HMAC-SHA256 verified)
  report.php             comprehensive AI report (max 1 per child)
  install.php            one-time DB initialiser — DELETE after first run
/includes/               config.php, db.php, auth.php, claude.php, sms.php,
                         wallet.php, cashfree.php, header/footer
/modules/                14 assessment modules (one PHP file each)
/admin/                  admin panel (login, overview, parents, parent,
                         orders, specialists, services, settings)
/assets/images/          drop your specialist photos here
/data/                   SQLite DB lives here (web access denied)
/uploads/                audio recordings (web access denied)
```

## Admin panel

`/admin/` (default `admin` / `empower@2026` — change immediately).

- **Overview** — parents/children/assessment/revenue stats + recent + flagged.
- **Parents** — searchable list with credits / VIP / blocked filters.
- **Parent detail** — full ledger, payment orders, assessments, children,
  feedback log; buttons to grant or deduct credits, send a message that
  appears as a green note on the parent's dashboard, mark VIP, block/unblock,
  reverse any ledger entry idempotently.
- **Payments** — Cashfree orders by status; manual *re-verify* hits the
  Cashfree API to recheck status and credit if PAID. Idempotent.
- **Specialists** — full CRUD for the 7-person panel.
- **Pricing** — edit credit cost per module; toggle active.
- **Settings** — change password, see integration health, see exact webhook
  URLs to paste into Cashfree dashboard.

## Deployment to GoDaddy

1. **Upload.** FTP / cPanel File Manager the entire `empowerstudents/`
   folder into the document root for the add-on domain. On GoDaddy
   shared, this is usually `~/empowerstudents.in/` or
   `~/public_html/empowerstudents/`.

2. **PHP version.** In cPanel → MultiPHP Manager, set the domain to
   **PHP 7.4**. Make sure these extensions are enabled (cPanel → Select
   PHP Version → Extensions): `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `json`.

3. **Folder permissions.**
   ```
   chmod 755 includes admin modules assets
   chmod 775 data uploads
   ```

4. **Configure secrets** in `includes/config.php` (or set as cPanel
   environment variables — the file falls back to `getenv()`):

   | Constant                | Purpose                                       |
   |-------------------------|-----------------------------------------------|
   | `ANTHROPIC_API_KEY`     | Claude API key                                |
   | `ANTHROPIC_MODEL`       | Default `claude-sonnet-4-5` (can be opus)     |
   | `OTP_MODE`              | `demo` for testing; `twilio_wa` for live      |
   | `TWILIO_SID`            | Twilio Account SID (`AC…`)                    |
   | `TWILIO_TOKEN`          | Twilio Auth Token                             |
   | `TWILIO_WA_FROM`        | e.g. `whatsapp:+15558734404`                  |
   | `TWILIO_CONTENT_SID`    | **Pre-approved template** SID (`HX…`) — mandatory |
   | `CASHFREE_ENV`          | `sandbox` or `production`                     |
   | `CASHFREE_APP_ID`       | from Cashfree dashboard                       |
   | `CASHFREE_SECRET_KEY`   | from Cashfree dashboard                       |

   > **Twilio WhatsApp gotcha:** plain `Body` messages are *silently
   > dropped* by Meta unless the user has messaged you in the last 24 h.
   > You **must** create a pre-approved Content Template with two
   > variables — `{{1}}` for the OTP and `{{2}}` for the verify URL —
   > and set its SID as `TWILIO_CONTENT_SID`. (This is a lesson learned
   > the hard way on the sister `mydoctor.ltd` deployment.)

5. **Configure Cashfree dashboard** (your account is already whitelisted
   for empowerstudents.in):
   - Webhook URL → `https://empowerstudents.in/payment_webhook.php`
   - Allowed return URL → `https://empowerstudents.in/payment_return.php`
   - Add your IP ranges if asked.

6. **Run the installer** at `https://empowerstudents.in/install.php`. It
   creates the database, seeds 7 panel specialists, the default admin,
   and 14 service prices. Confirm everything is ✅ then **delete
   `install.php`** from the server.

7. **Upload specialist photos** to `/assets/images/` (filenames listed
   in that folder's `README.txt`; the panel renders placeholders if a
   photo is missing).

8. **Log into `/admin/`** and:
   - Change the admin password from *Settings*.
   - Edit specialists, pricing as needed.

9. **(Optional) Force HTTPS.** Uncomment the HTTPS-redirect block at
   the top of `.htaccess` after your SSL certificate is active.

## Testing checklist

- [ ] `install.php` shows green ✓ for PDO SQLite, cURL, Anthropic key,
      Cashfree, Twilio.
- [ ] Homepage loads, services grid renders.
- [ ] Parent login: phone → OTP (delivered as WhatsApp template
      message) → 100 free credits granted (visible in nav pill).
- [ ] Add child → child profile loads.
- [ ] Run **Health** (3 cr) → balance drops to 97; ledger has the entry.
- [ ] Try **Speech** when balance < 10 → redirected to wallet with
      "you need 10 credits" banner.
- [ ] Top up ₹100 → Cashfree Drop-in → return → balance reflects ₹100.
- [ ] **Pulse / breath** module on phone → camera lights up, 15-sec
      PPG returns a bpm.
- [ ] `/report.php?id=…` → generate AI report (50 cr); second attempt
      blocked with "max 1 per child" message.
- [ ] `/admin/` → Parents → click parent → grant 50 credits → ledger
      shows the entry → parent sees the new balance.
- [ ] `/admin/orders.php` → re-verify works for any pending order.
- [ ] Webhook signature verification: Cashfree dashboard "Test webhook"
      should succeed (returns 200).
- [ ] Delete `install.php`.

## Hardening notes

- **Wallet integrity** — every credit movement is wrapped in a
  transaction so `parents.credits` and `wallet_ledger` cannot diverge.
  All admin actions go through `_post_ledger` so audit trail is
  complete.
- **Idempotency** — `wallet_charge_for_service` keys on
  `(parent, service, ref_id)` and never double-charges. Cashfree
  crediting keys on `payment_orders.credited` flag.
- **CSRF** tokens guard every POST in admin and parent flows.
- **OTPs** are bcrypt-hashed, expire in 5 min, max 5 verify attempts,
  30 s resend cooldown.
- **Webhook signature** is verified with HMAC-SHA256 against the body,
  rejecting any tampered or replayed payload.
- **Children scoped by parent_id** — `child_for_parent()` enforces
  ownership on every module load, so a parent can never poke at
  another's data via URL.
- **`/data/` and `/uploads/`** are denied at the .htaccess level.

## Support

- Dr. P. K. Jha — call **+91-9311696923**, WhatsApp **+91-9311883132**
- Sister sites: [neurocareindia.in](https://neurocareindia.in),
  [mydoctor.ltd](https://mydoctor.ltd)
