<?php
/**
 * includes/partners.php — partner directory, pitch helpers, attribution, commissions.
 *
 * RECONSTRUCTED from past-chat fragments after admin/partners.php was accidentally
 * overwritten. The 33 records on the server are PRESERVED — this rebuilds only the
 * helpers and UI that wrap them. Schema matches production exactly so no data lost.
 *
 * Used by:
 *   /admin/partners.php  — admin UI
 *   /p.php               — public ?ref=CODE redirect
 *   /login.php           — attribution on signup
 *   /includes/wallet.php — auto-record commission on charges
 */
require_once __DIR__ . '/db.php';

if (!defined('PARTNER_REVENUE_SHARE_PCT')) define('PARTNER_REVENUE_SHARE_PCT', 20);

/* ════════════════════════════════════════════════════════════════════
   SCHEMA — idempotent migration
   Three tables: partners, partner_messages, partner_commissions
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('ensure_partners_schema')) {
function ensure_partners_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS partners (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                name            TEXT NOT NULL,
                contact_person  TEXT,
                phone           TEXT,
                whatsapp        TEXT,
                email           TEXT,
                address         TEXT,
                area            TEXT,
                pincode         TEXT,
                category        TEXT,
                website         TEXT,
                google_maps_url TEXT,
                rating          REAL,
                review_count    INTEGER,
                status          TEXT DEFAULT 'cold',
                referral_code   TEXT UNIQUE,
                messaged_at     TEXT,
                responded_at    TEXT,
                onboarded_at    TEXT,
                notes           TEXT,
                source          TEXT,
                created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at      TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_partners_status   ON partners(status)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_partners_area     ON partners(area)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_partners_code     ON partners(referral_code)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_partners_category ON partners(category)");

        db()->exec("
            CREATE TABLE IF NOT EXISTS partner_messages (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                partner_id  INTEGER NOT NULL,
                direction   TEXT NOT NULL,            -- 'out' | 'in'
                channel     TEXT,                     -- 'whatsapp' | 'call' | 'email' | 'visit'
                message     TEXT,
                logged_by   TEXT,
                created_at  TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_pmsg_partner ON partner_messages(partner_id)");

        db()->exec("
            CREATE TABLE IF NOT EXISTS partner_commissions (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                partner_id      INTEGER NOT NULL,
                parent_id       INTEGER,
                child_id        INTEGER,
                source_type     TEXT,                 -- 'expert_report' | 'evaluation' | 'topup' | 'care_pack' | 'tracker_topup'
                source_id       INTEGER,              -- order id / wallet_ledger id
                gross_amount    INTEGER,              -- credits (1 cr = ₹1)
                share_pct       INTEGER,              -- snapshot at time of charge
                commission      INTEGER,              -- credits owed to partner
                status          TEXT DEFAULT 'pending',
                paid_at         TEXT,
                notes           TEXT,
                created_at      TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_pcomm_partner ON partner_commissions(partner_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_pcomm_status  ON partner_commissions(status)");

        // Ensure parents.partner_id column exists (for attribution)
        $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
        $has_pid = false;
        foreach ($cols as $c) if ($c['name'] === 'partner_id') { $has_pid = true; break; }
        if (!$has_pid) {
            try { db()->exec("ALTER TABLE parents ADD COLUMN partner_id INTEGER"); } catch (Throwable $_) {}
            try { db()->exec("CREATE INDEX IF NOT EXISTS idx_parents_partner ON parents(partner_id)"); } catch (Throwable $_) {}
        }
    } catch (Throwable $e) {
        error_log('partners schema migration: ' . $e->getMessage());
    }
}
}
ensure_partners_schema();

/* ════════════════════════════════════════════════════════════════════
   CATEGORIES — clinical vs educational
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('partner_categories')) {
function partner_categories(): array {
    return [
        // Clinical / therapy
        'ot'             => 'Occupational Therapy',
        'speech'         => 'Speech Therapy',
        'special_ed'     => 'Special Education',
        'autism'         => 'Autism Centre',
        'multi'          => 'Multi-disciplinary clinic',
        // Educational
        'tutor'          => 'Individual tutor / home tuition',
        'tuition_centre' => 'Tuition / coaching centre',
        'school'         => 'School / pre-school',
        // Catch-all
        'other'          => 'Other',
    ];
}
}

if (!function_exists('partner_is_educational')) {
function partner_is_educational(string $category): bool {
    return in_array($category, ['tutor', 'tuition_centre', 'school'], true);
}
}

if (!function_exists('partner_status_options')) {
function partner_status_options(): array {
    return ['cold', 'messaged', 'interested', 'partner', 'declined', 'unreachable'];
}
}

/**
 * Tailwind classes for the status pill. PHP 7.4 compatible (no match()).
 * $variant = 'pill' (used in the detail header) or 'tag' (used in list rows).
 */
if (!function_exists('partner_status_classes')) {
function partner_status_classes(string $status, string $variant = 'pill'): string {
    if ($status === 'partner')    return 'bg-emerald-100 text-emerald-700';
    if ($status === 'interested') return 'bg-amber-100 text-amber-700';
    if ($status === 'messaged')   return 'bg-sky-100 text-sky-700';
    if ($status === 'declined' || $status === 'unreachable') {
        return ($variant === 'tag') ? 'bg-slate-200 text-slate-500' : 'bg-slate-200 text-slate-600';
    }
    // cold / unknown
    return 'bg-rose-100 text-rose-700';
}
}

/* ════════════════════════════════════════════════════════════════════
   CODE GENERATION — 6 alphanumeric, no ambiguous chars
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('generate_partner_code')) {
function generate_partner_code(): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($tries = 0; $tries < 20; $tries++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $st = db()->prepare("SELECT 1 FROM partners WHERE referral_code = ?");
        $st->execute([$code]);
        if (!$st->fetchColumn()) return $code;
    }
    return 'P' . substr(uniqid(), -5);
}
}

if (!function_exists('partner_referral_link')) {
function partner_referral_link(string $code): string {
    return 'https://empowerstudents.in/p/' . $code;
}
}

/* ════════════════════════════════════════════════════════════════════
   PARTNER LOOKUP & STATS
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('partner_by_code')) {
function partner_by_code(string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $st = db()->prepare("SELECT * FROM partners WHERE UPPER(referral_code) = ?");
    $st->execute([$code]);
    return $st->fetch() ?: null;
}
}

if (!function_exists('partner_stats')) {
function partner_stats(): array {
    $stats = [
        'total'        => 0,
        'cold'         => 0,
        'messaged'     => 0,
        'interested'   => 0,
        'partner'      => 0,
        'declined'     => 0,
        'unreachable'  => 0,
    ];
    try {
        $stats['total'] = (int) db()->query("SELECT COUNT(*) FROM partners")->fetchColumn();
        $rows = db()->query("SELECT status, COUNT(*) AS n FROM partners GROUP BY status")->fetchAll();
        foreach ($rows as $r) {
            $s = $r['status'] ?? 'cold';
            if (isset($stats[$s])) $stats[$s] = (int)$r['n'];
        }
    } catch (Throwable $_) {}
    return $stats;
}
}

/* ════════════════════════════════════════════════════════════════════
   PITCH MESSAGES — short (WhatsApp click-to-send) and long (formal)
   Auto-routes between clinical and educational variants.
   Available in English ('en') and Hindi ('hi').
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('partner_pitch_message')) {
function partner_pitch_message(array $partner, string $lang = 'en'): string {
    $name   = $partner['contact_person'] ?: 'Sir/Madam';
    $center = $partner['name'] ?: 'your centre';
    $code   = $partner['referral_code'] ?: 'PENDING';
    $link   = partner_referral_link($code);
    $cat    = $partner['category'] ?? 'other';
    $is_edu = partner_is_educational($cat);

    if ($lang === 'hi') {
        if ($is_edu) {
            return "नमस्ते {$name} जी,\n\n"
                 . "मैं *Empower Students* से हूँ — *डॉ. पी. के. झा* (न्यूरोसर्जन, AIIMS से प्रशिक्षित, 30+ वर्ष) के दूरदर्शी नेतृत्व में बनाया गया, और बाल-विकास, मनोविज्ञान, विशेष शिक्षा एवं AI के विशेषज्ञों की टीम द्वारा निर्मित प्लेटफ़ॉर्म।\n\n"
                 . "हर अभिभावक यही जानना चाहता है: *क्या मेरा बच्चा सही स्तर पर है? उसकी छुपी प्रतिभाएँ क्या हैं? अगली कक्षा के लिए तैयार है क्या?* डॉ. झा के क्लिनिकल अनुभव में यही चिंता वयस्कों में *तनाव-जनित रोगों — रक्तचाप, अनिद्रा, चिंता, सिरदर्द —* का एक बड़ा कारण है। बच्चे के बारे में स्पष्ट उत्तर ही इस चक्र को तोड़ते हैं।\n\n"
                 . "हमारा 13-मॉड्यूल मूल्यांकन यही उत्तर देता है — और हम *{$center}* को साझेदार बनाना चाहते हैं।\n\n"
                 . "🤝 *आपसी सहयोग:*\n"
                 . "• आपके छात्रों के लिए मुफ़्त: कक्षा-स्तर बनाम वास्तविक स्तर, प्रतिभा खोज, फ़ोकस जाँच, अभिभावक डैशबोर्ड\n"
                 . "• अभिभावक देखें: संरचित रिपोर्ट, AI-सहायता प्राप्त अंतर्दृष्टि\n"
                 . "• पार्टनर-अटैच्ड पेड मूल्यांकन पर 20% रेवेन्यू शेयर\n\n"
                 . "📲 आपकी पार्टनर लिंक: {$link}\n\n"
                 . "10 मिनट की बात कर सकते हैं?\n"
                 . "📞 +91-9311696923\n\n"
                 . "सादर,\nTeam Empower Students";
        }
        // Clinical — Hindi
        return "नमस्ते {$name} जी,\n\n"
             . "मैं *Empower Students* से हूँ — *डॉ. पी. के. झा* (न्यूरोसर्जन, AIIMS से प्रशिक्षित, 30+ वर्ष) के दूरदर्शी नेतृत्व में बनाया गया, बहु-विषयक विशेषज्ञों की टीम द्वारा निर्मित प्लेटफ़ॉर्म।\n\n"
             . "30+ वर्षों के क्लिनिकल अनुभव में डॉ. झा ने देखा कि वयस्कों में *तनाव-जनित रोग — रक्तचाप, चिंता, अनिद्रा, सिरदर्द —* का एक बड़ा हिस्सा एक ही जड़ से आता है: बच्चे की चिंता। हम इसी जड़ पर काम कर रहे हैं।\n\n"
             . "दिल्ली NCR (2.5+ करोड़ आबादी) में बाल विकास और थेरेपी की माँग आज की सेवाओं से कहीं अधिक है। हम AI-सहायित इकोसिस्टम बना रहे हैं — और *{$center}* को साझेदार बनाना चाहते हैं।\n\n"
             . "🤝 *आपसी सहयोग:*\n"
             . "• आपको: मुफ़्त डिजिटल प्लेटफ़ॉर्म — मूल्यांकन, प्रोफ़ाइलिंग, प्रगति निगरानी, अभिभावक मार्गदर्शन, AI क्लिनिकल अंतर्दृष्टि\n"
             . "• हमें: इन उपकरणों का वास्तविक उपयोग + पारस्परिक रेफ़रल (आपके क्षेत्र में OT/Speech ढूँढते अभिभावक आपके पास)\n"
             . "• पार्टनर-अटैच्ड पेड मूल्यांकन पर 20% रेवेन्यू शेयर\n\n"
             . "📲 आपकी पार्टनर लिंक: {$link}\n\n"
             . "10 मिनट की बात कर सकते हैं?\n"
             . "📞 +91-9311696923\n\n"
             . "सादर,\nTeam Empower Students";
    }

    // ── English ──
    if ($is_edu) {
        return "Hello {$name},\n\n"
             . "I'm reaching out from *Empower Students* — a structured child development & evaluation platform conceived under the visionary leadership of *Dr. P. K. Jha* (Neurosurgeon, AIIMS-trained, 30+ years) and built by a team of experts across paediatrics, child psychology, special education and AI.\n\n"
             . "Most parents you work with worry about the same things: *Is my child at the right level? What are their hidden talents? Are they ready for the next class?* In Dr. Jha's clinical experience, this very anxiety is one of the largest hidden drivers of stress-related illness in parents — and it can only be broken with clear, structured answers about the child.\n\n"
             . "We've built a 13-module evaluation that delivers exactly those answers — and we'd like *{$center}* to be a partner.\n\n"
             . "🤝 *Mutual collaboration:*\n"
             . "• Free for your students: real maths/reading level (vs class), talent & aptitude discovery, mind power & focus check, parent progress dashboards\n"
             . "• Adds value parents see: structured reports, monitoring, AI-assisted insights\n"
             . "• 20% revenue share on partner-attributed paid evaluations\n"
             . "• You stay focused on teaching — we handle evaluation & reporting\n\n"
             . "📲 Your unique partner link: {$link}\n\n"
             . "Could we have a 10-min call?\n"
             . "📞 +91-9311696923\n\n"
             . "Warm regards,\nTeam Empower Students";
    }
    // Clinical — English
    return "Hello {$name},\n\n"
         . "I'm reaching out from *Empower Students* — a structured child development & evaluation platform conceived under the visionary leadership of *Dr. P. K. Jha* (Neurosurgeon, AIIMS-trained, 30+ years) and built by a multidisciplinary team of experts.\n\n"
         . "In 30+ years of clinical practice, Dr. Jha has observed that a large share of *stress-related illness in adult patients — hypertension, anxiety, sleep disorders, headaches* — traces back to one root cause: worry about their children. We're addressing this at the source.\n\n"
         . "In Delhi NCR (2.5+ crore population), demand for child development & therapy services far exceeds supply. We're building an AI-assisted ecosystem to bridge this gap — and we'd like *{$center}* to be a partner.\n\n"
         . "🤝 *Mutual collaboration:*\n"
         . "• You get: free digital platform — evaluation, profiling, progress monitoring, parent guidance, AI-assisted clinical insights\n"
         . "• We get: real-world application of these tools + mutual referrals (parents from your area looking for OT/speech routed to you)\n"
         . "• 20% revenue share on partner-attributed paid evaluations\n\n"
         . "📲 Your unique partner link: {$link}\n\n"
         . "Could we have a 10-min call?\n"
         . "📞 +91-9311696923\n\n"
         . "Warm regards,\nTeam Empower Students";
}
}

/**
 * LONG pitch — formal proposal version. Auto-routes by category.
 * Used for the 📋 Long button.
 */
if (!function_exists('partner_pitch_long')) {
function partner_pitch_long(array $partner): string {
    $name   = $partner['contact_person'] ?: 'Friends';
    $center = $partner['name'] ?: 'your centre';
    $code   = $partner['referral_code'] ?: 'PENDING';
    $link   = partner_referral_link($code);
    $cat    = $partner['category'] ?? 'other';

    if (partner_is_educational($cat)) {
        return "Subject: Collaboration Proposal — Structured Child Evaluation, Talent Discovery & Parent Reporting (EmpowerStudents.in)\n\n"
             . "Dear {$name},\n\n"
             . "Every child — whether typically developing or with extra needs — benefits from structured evaluation, growth planning, and continuous monitoring. *{$center}* sees children every day; we believe a systematic platform alongside your teaching can multiply the impact for the families you serve.\n\n"
             . "*The clinical insight behind Empower Students*\n"
             . "In over 30 years of neurosurgical and clinical practice, Dr. P. K. Jha (AIIMS-trained) has observed a striking pattern: a very large proportion of stress-related illness in adults — hypertension, sleep disorders, anxiety, headaches, even cardiovascular and neurological symptoms — traces back to one primary source of worry: *their children*. \"Is my child performing? Is something wrong? Will they have a future?\" This anxiety is silent, chronic, and damaging — both to the parent's health and to the child's growth.\n\n"
             . "Empower Students was conceived under Dr. Jha's visionary leadership and built by a team of experts — paediatricians, child psychologists, special educators, speech and OT therapists, AI engineers, and educators — to address this at its root. If parents have clear, structured answers about their child's real level, hidden talents, and developmental trajectory, the cycle of anxiety breaks. Children get directed support; parents get peace of mind; teachers and centres get a structured tool to communicate progress.\n\n"
             . "*What the platform offers*\n"
             . "A 13-module child evaluation covering academic level (vs class grade), mind power & focus, talent & aptitude discovery, behavioural and emotional health, learning style, and parent guidance — with AI-assisted reporting in English & Hindi.\n\n"
             . "*What we propose for {$center}*\n"
             . "1. Free access to the platform for all your students — they take the structured evaluation; you and the parents receive professionally-designed reports.\n"
             . "2. Early identification of children needing additional support, with discreet referral pathway to specialists where appropriate.\n"
             . "3. *20% revenue share* on partner-attributed paid services — your code {$code} attaches automatically to any parent who registers via your link.\n"
             . "4. Branding option: reports can carry *{$center}*'s logo if desired.\n\n"
             . "*Your unique partner link:* {$link}\n\n"
             . "Would you have 10 minutes for a brief call this week?\n\n"
             . "📞 +91-9311696923\n\n"
             . "Warm regards,\n"
             . "Team Empower Students\n"
             . "EmpowerStudents.in";
    }

    return "Subject: Collaboration Proposal — Structured Child Development & Parent Support Initiative (EmpowerStudents.in)\n\n"
         . "Dear {$name},\n\n"
         . "Every child — whether typically developing or with special needs — requires comprehensive evaluation, structured planning, and continuous monitoring to understand both their challenges and their strengths. Equally important is parental evaluation, guidance, and counseling, especially in today's rapidly changing parenting environment.\n\n"
         . "*The clinical insight behind Empower Students*\n"
         . "In over 30 years of neurosurgical and clinical practice, Dr. P. K. Jha (AIIMS-trained) has observed a striking pattern: a very large proportion of stress-related illness in adult patients — hypertension, sleep disorders, anxiety, chronic headaches, even cardiovascular and neurological symptoms — traces back to one primary source of worry: *their children*. \"Is my child developing normally? Is something wrong? Will they have a future?\" This silent, chronic anxiety harms both the parent's health and the child's growth — yet it is rarely addressed at its root.\n\n"
         . "Under Dr. Jha's visionary leadership, *Empower Students* was conceived and built by a multidisciplinary team of experts — paediatricians, child psychologists, special educators, speech & occupational therapists, behavioural specialists, AI engineers, and academics — to break this cycle. Parents who receive structured, evidence-based answers about their child's real level, talents, and developmental trajectory recover their peace of mind; children get directed support earlier; partner centres like *{$center}* gain a digital backbone that complements clinical work.\n\n"
         . "*Why this matters in Delhi NCR*\n"
         . "With a population of 2.5+ crore, demand for OT, speech therapy, special education, autism services, and child psychology far exceeds existing supply. Most centres are at capacity. A digital evaluation + monitoring layer multiplies your team's reach without adding to clinical hours — and lets you serve families who would otherwise wait months.\n\n"
         . "*Proposed collaboration*\n"
         . "1. *{$center}* receives free access to the digital platform — evaluation, profiling, progress dashboards, AI-assisted insights, parent communication.\n"
         . "2. Mutual referral arrangement — parents in your area searching for OT/speech/special-ed are routed to *{$center}*; complex cases needing neurosurgical or higher developmental input are routed to Dr. Jha's clinical team.\n"
         . "3. *20% revenue share* on partner-attributed paid evaluations — your unique code {$code} attaches to any parent who registers via your link.\n"
         . "4. Branding option: reports can carry *{$center}*'s logo for the families you refer.\n\n"
         . "*Your unique partner link:* {$link}\n\n"
         . "Would 10 minutes this week work for an introductory call?\n\n"
         . "📞 +91-9311696923\n\n"
         . "Warm regards,\n"
         . "Team Empower Students\n"
         . "EmpowerStudents.in";
}
}

/**
 * Build the WhatsApp click-to-send URL for a given partner + language.
 * Uses wa.me/<number>?text=... format.
 */
if (!function_exists('partner_whatsapp_url')) {
function partner_whatsapp_url(array $partner, string $lang = 'en'): string {
    $phone = preg_replace('/\D/', '', (string)($partner['whatsapp'] ?: $partner['phone'] ?: ''));
    if ($phone === '') return '';
    if (strlen($phone) === 10) $phone = '91' . $phone;       // assume India
    if (strlen($phone) === 11 && $phone[0] === '0') $phone = '91' . substr($phone, 1);
    $msg = partner_pitch_message($partner, $lang);
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
}
}

/* ════════════════════════════════════════════════════════════════════
   ATTRIBUTION & COMMISSIONS
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('partner_attribute_parent')) {
function partner_attribute_parent(int $parent_id, int $partner_id): bool {
    $st = db()->prepare("SELECT partner_id FROM parents WHERE id = ?");
    $st->execute([$parent_id]);
    $cur = $st->fetchColumn();
    if ($cur) return false;       // first-touch wins
    db()->prepare("UPDATE parents SET partner_id = ? WHERE id = ?")->execute([$partner_id, $parent_id]);
    return true;
}
}

/**
 * Record a commission for a wallet charge.
 * Idempotent on (source_type, source_id) — same ledger row can't double-pay.
 *
 * Called from wallet.php whenever a parent is charged. Silent no-op if parent
 * has no partner.
 */
if (!function_exists('partner_record_commission')) {
function partner_record_commission(int $parent_id, int $source_id, string $source_type, int $gross_credits): void {
    if ($gross_credits <= 0) return;

    $st = db()->prepare("SELECT partner_id FROM parents WHERE id = ? AND partner_id IS NOT NULL");
    $st->execute([$parent_id]);
    $partner_id = (int) $st->fetchColumn();
    if (!$partner_id) return;

    // Idempotency guard
    $check = db()->prepare("SELECT 1 FROM partner_commissions WHERE source_type = ? AND source_id = ? LIMIT 1");
    $check->execute([$source_type, $source_id]);
    if ($check->fetchColumn()) return;

    $share = PARTNER_REVENUE_SHARE_PCT;
    $commission = (int) round($gross_credits * $share / 100);
    if ($commission <= 0) return;

    db()->prepare("INSERT INTO partner_commissions
                   (partner_id, parent_id, source_type, source_id, gross_amount, share_pct, commission, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$partner_id, $parent_id, $source_type, $source_id, $gross_credits, $share, $commission]);
}
}

if (!function_exists('partner_commission_summary')) {
function partner_commission_summary(int $partner_id): array {
    $st = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN status='pending' THEN commission END), 0) AS pending,
        COALESCE(SUM(CASE WHEN status='paid'    THEN commission END), 0) AS paid,
        COUNT(CASE WHEN status='pending' THEN 1 END)                     AS pending_n,
        COUNT(*)                                                          AS total
        FROM partner_commissions WHERE partner_id = ?");
    $st->execute([$partner_id]);
    return $st->fetch() ?: ['pending'=>0,'paid'=>0,'pending_n'=>0,'total'=>0];
}
}

/**
 * fresh-v8i: returns the effective commission percentage (integer 0-100)
 * for this partner. Reads existing `revenue_share` column (REAL, 0.0-1.0).
 * Falls back to PARTNER_REVENUE_SHARE_PCT when revenue_share is null/0.
 */
if (!function_exists('partner_effective_commission_pct')) {
function partner_effective_commission_pct(array $partner): int {
    $rs = $partner['revenue_share'] ?? null;
    if ($rs !== null && $rs !== '' && (float)$rs > 0) {
        return (int) round((float)$rs * 100);
    }
    return (int) (defined('PARTNER_REVENUE_SHARE_PCT') ? PARTNER_REVENUE_SHARE_PCT : 20);
}
}

/**
 * fresh-v8h/v8i: list every parent attributed to this partner with their activity.
 * Returns array of dicts: parent_id, name, whatsapp, credits, created_at,
 *   children_count, reflect_count, reflect_done,
 *   home_course_id, home_course_day, total_spent (wallet drawdown),
 *   total_topup (real Cashfree money in).
 */
/**
 * fresh-v9: record a commission row for a successful wallet top-up.
 * Idempotent on (partner_id, source_type='topup', source_id=$ledger_id).
 *
 * Returns ['status' => one_of(created|already|no_partner|invalid), ...].
 */
if (!function_exists('partner_record_topup_commission')) {
function partner_record_topup_commission(int $parent_id, int $topup_amount, int $ledger_id): array {
    if ($topup_amount <= 0 || $ledger_id <= 0 || $parent_id <= 0) {
        return ['status' => 'invalid'];
    }

    // 1. Lookup parent's partner attribution
    $pp = db()->prepare("SELECT partner_id FROM parents WHERE id = ?");
    $pp->execute([$parent_id]);
    $partner_id_for_parent = $pp->fetchColumn();
    if (!$partner_id_for_parent) {
        return ['status' => 'no_partner'];
    }
    $partner_id = (int)$partner_id_for_parent;

    // 2. Lookup partner record (for revenue_share)
    $ps = db()->prepare("SELECT id, revenue_share FROM partners WHERE id = ?");
    $ps->execute([$partner_id]);
    $partner = $ps->fetch();
    if (!$partner) return ['status' => 'no_partner'];

    // 3. Idempotency check — already recorded?
    $idem = db()->prepare("SELECT id FROM partner_commissions
                          WHERE partner_id = ? AND source_type = 'topup' AND source_id = ?
                          LIMIT 1");
    $idem->execute([$partner_id, $ledger_id]);
    if ($idem->fetchColumn()) {
        return ['status' => 'already', 'partner_id' => $partner_id];
    }

    // 4. Calculate commission at partner's effective rate
    $pct = partner_effective_commission_pct($partner);
    $commission = (int) round($topup_amount * $pct / 100);

    // 5. Insert
    db()->prepare("INSERT INTO partner_commissions
                   (partner_id, parent_id, child_id, source_type, source_id,
                    gross_amount, share_pct, commission, status, notes)
                   VALUES (?, ?, NULL, 'topup', ?, ?, ?, ?, 'pending', ?)")
       ->execute([
           $partner_id, $parent_id, $ledger_id,
           $topup_amount, $pct, $commission,
           'Auto: Cashfree top-up of Rs.' . $topup_amount . ' (ledger #' . $ledger_id . ')'
       ]);

    // 6. Stamp partner.last_referral_at if column exists
    try {
        db()->prepare("UPDATE partners SET last_referral_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$partner_id]);
    } catch (Throwable $_) {}

    return [
        'status'      => 'created',
        'partner_id'  => $partner_id,
        'commission'  => $commission,
        'pct'         => $pct,
        'commission_id' => (int)db()->lastInsertId(),
    ];
}
}

/* ════════════════════════════════════════════════════════════════════
   fresh-v10: PROMO CODES — partners grant pre-approved wallet credits.
   - Codes are per-partner, per-code limits (max_uses, expiry, amount)
   - Redemption at signup only (NOT at later top-up)
   - Granted credits use service_key='promo_grant' so they do NOT
     trigger partner_record_topup_commission (only wallet_topup does)
   ════════════════════════════════════════════════════════════════════ */

if (!function_exists('promo_ensure_schema')) {
function promo_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS promo_codes (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                partner_id      INTEGER NOT NULL,
                code            TEXT UNIQUE NOT NULL,
                credit_amount   INTEGER NOT NULL,
                max_uses        INTEGER NOT NULL DEFAULT 5,
                uses_count      INTEGER NOT NULL DEFAULT 0,
                expires_at      TEXT,
                status          TEXT NOT NULL DEFAULT 'active',  -- active | killed | expired
                notes           TEXT,
                created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
                created_by      TEXT
            )
        ");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_promo_codes_partner ON promo_codes(partner_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_promo_codes_code    ON promo_codes(code)");

        db()->exec("
            CREATE TABLE IF NOT EXISTS promo_redemptions (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                promo_code_id   INTEGER NOT NULL,
                parent_id       INTEGER NOT NULL,
                credit_granted  INTEGER NOT NULL,
                ledger_id       INTEGER,
                redeemed_at     TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_promo_redemptions_parent
                    ON promo_redemptions(parent_id)");  /* enforces 1-redemption-per-parent */
    } catch (Throwable $e) {
        error_log('promo_ensure_schema: ' . $e->getMessage());
    }
}
}

/**
 * Generate a unique 6-character uppercase code for this partner.
 * Auto-prefix with partner's first 3 referral_code chars when sensible.
 */
if (!function_exists('promo_generate_code')) {
function promo_generate_code(int $partner_id): string {
    promo_ensure_schema();
    $st = db()->prepare("SELECT referral_code FROM partners WHERE id = ?");
    $st->execute([$partner_id]);
    $ref = strtoupper(substr((string)$st->fetchColumn(), 0, 3));
    if (!preg_match('/^[A-Z]{3}$/', $ref)) $ref = 'PRO';

    for ($i = 0; $i < 12; $i++) {
        $suffix = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        $code = $ref . $suffix;  // e.g. DRPAB12, GENXYZ
        $chk = db()->prepare("SELECT 1 FROM promo_codes WHERE code = ?");
        $chk->execute([$code]);
        if (!$chk->fetchColumn()) return $code;
    }
    // fallback
    return $ref . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}
}

/**
 * Create a new promo code. Returns ['ok' => bool, 'code' => string, 'id' => int, 'error' => ?string].
 */
if (!function_exists('promo_create')) {
function promo_create(int $partner_id, int $credit_amount, int $max_uses, ?string $expires_at, string $created_by = 'partner', ?string $notes = null): array {
    promo_ensure_schema();

    if ($credit_amount < 1 || $credit_amount > 10000) {
        return ['ok' => false, 'error' => 'Credit amount must be between ₹1 and ₹10,000'];
    }
    if ($max_uses < 1 || $max_uses > 100) {
        return ['ok' => false, 'error' => 'Max uses must be between 1 and 100'];
    }
    if ($expires_at) {
        $exp_ts = strtotime($expires_at);
        if ($exp_ts === false || $exp_ts < time()) {
            return ['ok' => false, 'error' => 'Expiry must be in the future'];
        }
    }

    $code = promo_generate_code($partner_id);
    try {
        db()->prepare("INSERT INTO promo_codes
                       (partner_id, code, credit_amount, max_uses, expires_at, status, notes, created_by)
                       VALUES (?, ?, ?, ?, ?, 'active', ?, ?)")
           ->execute([$partner_id, $code, $credit_amount, $max_uses, $expires_at, $notes, $created_by]);
        return ['ok' => true, 'code' => $code, 'id' => (int)db()->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
}

/**
 * Look up a code (case-insensitive). Returns the row or null.
 * Returns row with extra computed key 'is_redeemable' (bool).
 */
if (!function_exists('promo_lookup')) {
function promo_lookup(string $code): ?array {
    promo_ensure_schema();
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $st = db()->prepare("SELECT * FROM promo_codes WHERE code = ? LIMIT 1");
    $st->execute([$code]);
    $r = $st->fetch();
    if (!$r) return null;

    $r['is_redeemable'] = (
        $r['status'] === 'active'
        && (int)$r['uses_count'] < (int)$r['max_uses']
        && (!$r['expires_at'] || strtotime($r['expires_at']) > time())
    );
    return $r;
}
}

/**
 * Redeem a code for a parent. Idempotent — one redemption per parent.
 * Returns ['ok' => bool, 'credit_granted' => int, 'partner_id' => int, 'error' => ?string].
 */
if (!function_exists('promo_redeem')) {
function promo_redeem(string $code, int $parent_id): array {
    promo_ensure_schema();

    if ($parent_id <= 0) return ['ok' => false, 'error' => 'Invalid parent'];

    // Has this parent already redeemed any code?
    $chk = db()->prepare("SELECT id FROM promo_redemptions WHERE parent_id = ? LIMIT 1");
    $chk->execute([$parent_id]);
    if ($chk->fetchColumn()) {
        return ['ok' => false, 'error' => 'You have already redeemed a promo code.'];
    }

    $row = promo_lookup($code);
    if (!$row) return ['ok' => false, 'error' => 'Invalid code.'];
    if (!$row['is_redeemable']) {
        if ((int)$row['uses_count'] >= (int)$row['max_uses']) {
            return ['ok' => false, 'error' => 'This code has reached its usage limit.'];
        }
        if ($row['expires_at'] && strtotime($row['expires_at']) <= time()) {
            return ['ok' => false, 'error' => 'This code has expired.'];
        }
        return ['ok' => false, 'error' => 'This code is no longer active.'];
    }

    // Wallet_post already runs in its own transaction. We do the steps in safe order:
    // 1. Reserve a redemption row first (UNIQUE INDEX prevents race / double-redeem)
    // 2. wallet_post (atomic on its own)
    // 3. Bump uses_count and update redemption with ledger_id
    // If step 2 fails, we delete the reservation in step 4.
    $credit = (int)$row['credit_amount'];
    try {
        // Step 1: reserve (will throw if parent_id already exists due to UNIQUE INDEX)
        db()->prepare("INSERT INTO promo_redemptions
                       (promo_code_id, parent_id, credit_granted, ledger_id)
                       VALUES (?, ?, ?, NULL)")
           ->execute([(int)$row['id'], $parent_id, $credit]);
        $redemption_id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'You have already redeemed a promo code.'];
    }

    try {
        // Step 2: grant the credit (wallet_post handles its own transaction)
        if (!function_exists('wallet_post')) {
            require_once __DIR__ . '/wallet.php';
        }
        $new_bal = wallet_post(
            $parent_id,
            $credit,
            'promo_grant',
            null,  // ref_id null — promo_code id is encoded in reason/redemption tables
            'Promo code redemption: ' . $row['code'] . ' (promo_code_id=' . (int)$row['id'] . ')',
            'promo'
        );

        // Step 3: find the ledger_id we just wrote + update redemption + bump uses
        $lst = db()->prepare("SELECT id FROM wallet_ledger
                              WHERE parent_id = ? AND service_key = 'promo_grant'
                                AND created_by = 'promo'
                              ORDER BY id DESC LIMIT 1");
        $lst->execute([$parent_id]);
        $ledger_id = (int)$lst->fetchColumn();

        db()->prepare("UPDATE promo_redemptions SET ledger_id = ? WHERE id = ?")
           ->execute([$ledger_id, $redemption_id]);

        db()->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = ?")
           ->execute([(int)$row['id']]);

        // Auto-expire if max_uses hit
        db()->prepare("UPDATE promo_codes SET status = 'expired'
                       WHERE id = ? AND uses_count >= max_uses")
           ->execute([(int)$row['id']]);

        return [
            'ok'             => true,
            'credit_granted' => $credit,
            'partner_id'     => (int)$row['partner_id'],
            'new_balance'    => $new_bal,
            'code'           => $row['code'],
        ];
    } catch (Throwable $e) {
        // Roll back the reservation
        db()->prepare("DELETE FROM promo_redemptions WHERE id = ?")
           ->execute([$redemption_id]);
        error_log('promo_redeem: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not apply code. Please try again.'];
    }
}
}

/**
 * List codes for a partner. Returns array of rows with extra fields:
 *   uses_remaining, is_expired_by_date, status_display.
 */
if (!function_exists('promo_list_for_partner')) {
function promo_list_for_partner(int $partner_id): array {
    promo_ensure_schema();
    $st = db()->prepare("SELECT * FROM promo_codes WHERE partner_id = ?
                         ORDER BY id DESC");
    $st->execute([$partner_id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['uses_remaining'] = max(0, (int)$r['max_uses'] - (int)$r['uses_count']);
        $r['is_expired_by_date'] = $r['expires_at'] && strtotime($r['expires_at']) <= time();
        if ($r['status'] === 'killed') {
            $r['status_display'] = 'killed';
        } elseif ($r['is_expired_by_date']) {
            $r['status_display'] = 'expired (date)';
        } elseif ($r['uses_remaining'] <= 0) {
            $r['status_display'] = 'used up';
        } else {
            $r['status_display'] = 'active';
        }
    }
    return $rows;
}
}

if (!function_exists('promo_kill')) {
function promo_kill(int $code_id, int $partner_id): bool {
    try {
        $st = db()->prepare("UPDATE promo_codes SET status = 'killed'
                             WHERE id = ? AND partner_id = ?");
        $st->execute([$code_id, $partner_id]);
        return $st->rowCount() > 0;
    } catch (Throwable $_) { return false; }
}
}

if (!function_exists('partner_get_referred_parents')) {
function partner_get_referred_parents(int $partner_id): array {
    $st = db()->prepare("SELECT id, name, whatsapp, credits, created_at
                         FROM parents WHERE partner_id = ?
                         ORDER BY id DESC");
    $st->execute([$partner_id]);
    $parents = $st->fetchAll();
    if (!$parents) return [];

    $out = [];
    foreach ($parents as $p) {
        $pid = (int)$p['id'];

        // Children count
        $cs = db()->prepare("SELECT COUNT(*) FROM children WHERE parent_id = ?");
        $cs->execute([$pid]);
        $children_n = (int)$cs->fetchColumn();

        // Parent reflection count
        $rc = 0; $rc_done = 0;
        try {
            $rcs = db()->prepare("SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END),0) AS done
                FROM parent_reflect_sessions WHERE parent_id = ?");
            $rcs->execute([$pid]);
            $rcr = $rcs->fetch();
            $rc = (int)($rcr['total'] ?? 0);
            $rc_done = (int)($rcr['done'] ?? 0);
        } catch (Throwable $_) {}

        // Active home_course
        $hc_id = null; $hc_day = null;
        try {
            $hcs = db()->prepare("SELECT id FROM home_courses
                                  WHERE parent_id = ? AND status = 'active'
                                  ORDER BY id DESC LIMIT 1");
            $hcs->execute([$pid]);
            $hcr = $hcs->fetch();
            if ($hcr) {
                $hc_id = (int)$hcr['id'];
                $hds = db()->prepare("SELECT COUNT(*) FROM home_course_days
                                      WHERE course_id = ? AND completed_at IS NOT NULL");
                $hds->execute([$hc_id]);
                $hc_day = (int)$hds->fetchColumn() + 1;
                if ($hc_day > 7) $hc_day = 7;
            }
        } catch (Throwable $_) {}

        // Total spent (sum of negative ledger amounts — wallet drawdown)
        $sp = 0;
        try {
            $sps = db()->prepare("SELECT COALESCE(SUM(ABS(amount)),0)
                                  FROM wallet_ledger WHERE parent_id = ? AND amount < 0");
            $sps->execute([$pid]);
            $sp = (int)$sps->fetchColumn();
        } catch (Throwable $_) {}

        // fresh-v8i: total real-money inflow (Cashfree top-ups only)
        $tu = 0;
        try {
            $tus = db()->prepare("SELECT COALESCE(SUM(amount), 0)
                                  FROM wallet_ledger
                                  WHERE parent_id = ?
                                    AND amount > 0
                                    AND service_key = 'wallet_topup'
                                    AND created_by = 'cashfree'");
            $tus->execute([$pid]);
            $tu = (int)$tus->fetchColumn();
        } catch (Throwable $_) {}

        $out[] = [
            'parent_id'         => $pid,
            'name'              => (string)($p['name'] ?? ''),
            'whatsapp'          => (string)($p['whatsapp'] ?? ''),
            'credits'           => (int)$p['credits'],
            'created_at'        => (string)$p['created_at'],
            'children_count'    => $children_n,
            'reflect_count'     => $rc,
            'reflect_done'      => $rc_done,
            'home_course_id'    => $hc_id,
            'home_course_day'   => $hc_day,
            'total_spent'       => $sp,
            'total_topup'       => $tu,
        ];
    }
    return $out;
}
}

/* ════════════════════════════════════════════════════════════════════
   ?ref=CODE CAPTURE
   ════════════════════════════════════════════════════════════════════ */
if (!defined('PARTNER_REF_COOKIE'))   define('PARTNER_REF_COOKIE',   'es_ref');
if (!defined('PARTNER_REF_TTL_DAYS')) define('PARTNER_REF_TTL_DAYS', 30);

if (!function_exists('partner_capture_from_url')) {
function partner_capture_from_url(): void {
    if (empty($_GET['ref'])) return;
    $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$_GET['ref']));
    if ($code === '') return;
    $partner = partner_by_code($code);
    if (!$partner) return;

    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['partner_ref_code'])) {
        $_SESSION['partner_ref_code'] = $code;
        $_SESSION['partner_ref_id']   = (int)$partner['id'];
    }
    if (empty($_COOKIE[PARTNER_REF_COOKIE])) {
        @setcookie(PARTNER_REF_COOKIE, $code, [
            'expires'  => time() + (PARTNER_REF_TTL_DAYS * 86400),
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
}

if (!function_exists('partner_capture_current_id')) {
function partner_capture_current_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['partner_ref_id'])) return (int)$_SESSION['partner_ref_id'];
    if (!empty($_COOKIE[PARTNER_REF_COOKIE])) {
        $partner = partner_by_code((string)$_COOKIE[PARTNER_REF_COOKIE]);
        if ($partner) {
            $_SESSION['partner_ref_id']   = (int)$partner['id'];
            $_SESSION['partner_ref_code'] = $partner['referral_code'];
            return (int)$partner['id'];
        }
    }
    return null;
}
}

if (!function_exists('partner_capture_attribute_session_parent')) {
function partner_capture_attribute_session_parent(int $parent_id): void {
    $pid = partner_capture_current_id();
    if (!$pid) return;
    partner_attribute_parent($parent_id, $pid);
}
}


/* ── Ensure parent_invites table exists ───────────────────────────────────── */
if (!function_exists('_invite_ensure_table')) {
    function _invite_ensure_table(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS parent_invites (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                partner_id        INTEGER NOT NULL,
                parent_name       TEXT NOT NULL,
                whatsapp_clean    TEXT NOT NULL,
                invite_token      TEXT UNIQUE NOT NULL,
                credit_amount     REAL DEFAULT 2000,
                status            TEXT DEFAULT 'pending',
                created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by        TEXT DEFAULT 'admin',
                claimed_at        DATETIME,
                claimed_parent_id INTEGER,
                expires_at        DATETIME
            )");
        } catch (Throwable $_) {}
    }
}

/* ── Count invites created today for a partner (daily limit check) ─────────  */
if (!function_exists('invite_count_today')) {
    function invite_count_today(int $partner_id): int {
        _invite_ensure_table();
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM parent_invites
                WHERE partner_id = ? AND DATE(created_at) = DATE('now','localtime')");
            $st->execute([$partner_id]);
            return (int)$st->fetchColumn();
        } catch (Throwable $_) { return 0; }
    }
}

/* ── Create a new invite (5/day limit enforced) ─────────────────────────────  */
if (!function_exists('invite_create')) {
    /**
     * @return array  Full invite row on success, or ['error' => string] on failure.
     */
    function invite_create(int $partner_id, string $parent_name, string $whatsapp_clean): array {
        _invite_ensure_table();
        if (invite_count_today($partner_id) >= 5) {
            return ['error' => 'Daily limit of 5 invites reached.'];
        }
        $token     = bin2hex(random_bytes(16));   // 32-char hex
        $expires   = date('Y-m-d H:i:s', strtotime('+7 days'));
        try {
            db()->prepare("INSERT INTO parent_invites
                (partner_id, parent_name, whatsapp_clean, invite_token, credit_amount, status, created_by, expires_at)
                VALUES (?, ?, ?, ?, 2000, 'pending', 'admin', ?)")
               ->execute([$partner_id, trim($parent_name), $whatsapp_clean, $token, $expires]);
            $id = (int)db()->lastInsertId();
            return invite_get_by_id($id) ?? ['error' => 'Could not retrieve created invite.'];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

/* ── Fetch invite by token ──────────────────────────────────────────────────  */
if (!function_exists('invite_get_by_token')) {
    function invite_get_by_token(string $token): ?array {
        _invite_ensure_table();
        try {
            $st = db()->prepare("SELECT * FROM parent_invites WHERE invite_token = ?");
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $_) { return null; }
    }
}

/* ── Fetch invite by id ─────────────────────────────────────────────────────  */
if (!function_exists('invite_get_by_id')) {
    function invite_get_by_id(int $id): ?array {
        _invite_ensure_table();
        try {
            $st = db()->prepare("SELECT * FROM parent_invites WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $_) { return null; }
    }
}
