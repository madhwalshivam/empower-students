<?php
require_once __DIR__ . '/config.php';

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
    }
    return $pdo;
}

function db_init() {
    $sql = "
    CREATE TABLE IF NOT EXISTS parents (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        whatsapp      TEXT UNIQUE NOT NULL,
        name          TEXT,
        email         TEXT,
        city          TEXT,
        credits       INTEGER DEFAULT 0,           -- denormalised current balance
        is_vip        INTEGER DEFAULT 0,
        is_blocked    INTEGER DEFAULT 0,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        last_login    TEXT
    );

    CREATE TABLE IF NOT EXISTS otps (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        whatsapp      TEXT NOT NULL,
        code_hash     TEXT NOT NULL,
        attempts      INTEGER DEFAULT 0,
        sent_at       TEXT DEFAULT CURRENT_TIMESTAMP,
        expires_at    TEXT NOT NULL,
        used_at       TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_otps_wa ON otps(whatsapp);

    CREATE TABLE IF NOT EXISTS children (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id     INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
        name          TEXT NOT NULL,
        gender        TEXT,
        dob           TEXT NOT NULL,           -- YYYY-MM-DD
        school        TEXT,
        class_grade   TEXT,
        mother_tongue TEXT,
        languages     TEXT,
        diagnosis     TEXT,                    -- known dx, free text
        notes         TEXT,
        photo         TEXT,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS assessments (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        module        TEXT NOT NULL,           -- behavior, math, speech, ...
        age_band      TEXT,
        status        TEXT DEFAULT 'in_progress',
        score         REAL,
        level_reached TEXT,
        ai_summary    TEXT,
        flags         TEXT,                    -- JSON of red-flags
        raw_json      TEXT,                    -- full payload of items + answers
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        completed_at  TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_assess_child ON assessments(child_id);
    CREATE INDEX IF NOT EXISTS idx_assess_mod   ON assessments(module);

    CREATE TABLE IF NOT EXISTS audio_recordings (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        assessment_id INTEGER NOT NULL REFERENCES assessments(id) ON DELETE CASCADE,
        prompt        TEXT,
        transcript    TEXT,
        file_path     TEXT,
        duration_ms   INTEGER,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS specialists (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        role          TEXT NOT NULL,           -- 'OT','Speech Therapist','Psychologist',...
        qualifications TEXT,
        bio           TEXT,
        photo         TEXT,                    -- filename in /assets/images
        order_no      INTEGER DEFAULT 100,
        active        INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS reports (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        ai_text       TEXT,
        diet_text     TEXT,
        parent_index  REAL,
        red_flags     TEXT,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS admins (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT UNIQUE NOT NULL,
        pass_hash     TEXT NOT NULL,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- ── Wallet / credit system ──
    CREATE TABLE IF NOT EXISTS wallet_ledger (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id     INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
        amount        INTEGER NOT NULL,            -- signed: + topup, - charge
        balance_after INTEGER,
        service_key   TEXT,                        -- 'health', 'speech', 'wallet_topup', etc.
        ref_id        INTEGER,                     -- assessment_id / payment_orders.id / NULL
        reason        TEXT DEFAULT '',
        created_by    TEXT DEFAULT 'system',       -- 'system' | 'admin:<user>' | 'cashfree'
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS idx_wl_parent  ON wallet_ledger(parent_id);
    CREATE INDEX IF NOT EXISTS idx_wl_service ON wallet_ledger(service_key);

    CREATE TABLE IF NOT EXISTS payment_orders (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id        TEXT UNIQUE NOT NULL,
        parent_id       INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
        amount          INTEGER NOT NULL,           -- ₹ paid (= credits to add)
        currency        TEXT DEFAULT 'INR',
        status          TEXT DEFAULT 'pending',     -- pending|success|failed|abandoned
        cf_payment_id   TEXT,
        payment_method  TEXT,
        raw_response    TEXT,
        credited        INTEGER DEFAULT 0,          -- 1 once wallet has been credited (idempotency)
        created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
        completed_at    TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_po_parent ON payment_orders(parent_id);
    CREATE INDEX IF NOT EXISTS idx_po_status ON payment_orders(status);

    CREATE TABLE IF NOT EXISTS service_prices (
        service_key   TEXT PRIMARY KEY,
        label         TEXT,
        price         INTEGER DEFAULT 0,
        audience      TEXT DEFAULT 'parent',
        is_active     INTEGER DEFAULT 1,
        updated_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS parent_feedback (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id     INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
        child_id      INTEGER REFERENCES children(id) ON DELETE SET NULL,
        author        TEXT,                        -- admin username
        body          TEXT NOT NULL,
        seen_by_parent INTEGER DEFAULT 0,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS idx_fb_parent ON parent_feedback(parent_id);
    ";
    db()->exec($sql);

    // Seed specialists if empty
    $count = db()->query('SELECT COUNT(*) FROM specialists')->fetchColumn();
    if ((int)$count === 0) {
        $rows = [
            ['Senior Occupational Therapist',     'Occupational Therapist',
             'M.O.T. (Paeds)',
             'Sensory integration, fine-motor and ADL support for children with developmental delays, ASD and ADHD.',
             'ot.jpg', 10],
            ['Senior Speech-Language Pathologist','Speech Therapist',
             'M.A.S.L.P., R.C.I. licensed',
             'Articulation, fluency (stuttering), language delay, AAC and feeding therapy.',
             'speech.jpg', 20],
            ['Clinical Child Psychologist',       'Psychologist',
             'M.Phil. Clinical Psychology',
             'Developmental and IQ assessments, emotional regulation, behaviour therapy, parent counselling.',
             'psychologist.jpg', 30],
            ['Consultant Paediatric Neurologist', 'Neurologist',
             'DM Neurology',
             'Epilepsy, neurodevelopmental disorders, headache, ADHD/ASD medical evaluation.',
             'neurologist.jpg', 40],
            ['Consultant Paediatrician',          'Paediatrician',
             'MD Paediatrics',
             'Growth, immunisation, nutrition, common childhood illnesses.',
             'paeds.jpg', 50],
            ['Family Counsellor',                 'Counsellor',
             'M.A. Counselling Psychology',
             'Parent-child relationship, school refusal, exam stress, adolescence guidance.',
             'counsellor.jpg', 60],
            ['Special Educator',                  'Special Educator',
             'B.Ed (Special Ed.)',
             'Individualised learning plans for SLD (dyslexia, dyscalculia), ASD and intellectual disability.',
             'special_ed.jpg', 70],
        ];
        $st = db()->prepare('INSERT INTO specialists (name,role,qualifications,bio,photo,order_no) VALUES (?,?,?,?,?,?)');
        foreach ($rows as $r) { $st->execute($r); }
    }

    // Seed default admin if none
    $count = db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ((int)$count === 0) {
        $hash = password_hash('empower@2026', PASSWORD_DEFAULT);
        db()->prepare('INSERT INTO admins (username,pass_hash) VALUES (?,?)')->execute(['admin', $hash]);
    }

    // Seed service prices (insert-or-ignore — admin can edit later)
    global $SERVICE_PRICES;
    if (!empty($SERVICE_PRICES) && is_array($SERVICE_PRICES)) {
        $st = db()->prepare("INSERT OR IGNORE INTO service_prices (service_key, label, price, audience, is_active) VALUES (?, ?, ?, ?, 1)");
        foreach ($SERVICE_PRICES as $key => $row) {
            [$price, $audience, $label] = $row;
            $st->execute([$key, $label, (int)$price, $audience]);
        }
    }

    // Migration: ensure new columns exist on parents table when upgrading from older builds
    $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
    $names = array_column($cols, 'name');
    if (!in_array('credits', $names, true))    db()->exec("ALTER TABLE parents ADD COLUMN credits INTEGER DEFAULT 0");
    if (!in_array('is_vip', $names, true))     db()->exec("ALTER TABLE parents ADD COLUMN is_vip INTEGER DEFAULT 0");
    if (!in_array('is_blocked', $names, true)) db()->exec("ALTER TABLE parents ADD COLUMN is_blocked INTEGER DEFAULT 0");
}
