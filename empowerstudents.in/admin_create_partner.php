<?php
/**
 * admin_create_partner.php?key=nci2026admin
 *
 * Quick admin form to create or edit a pediatrician partner.
 * Until Part 2 (self-service signup) ships, use this to add referring
 * pediatricians one at a time.
 *
 * Form fields:
 *   referral_code, name, contact_name, phone, whatsapp, email, city,
 *   revenue_share, doctor_credentials, clinic_address, custom_message,
 *   clinic_image_path, doctor_image_path
 *
 * Visit:
 *   /admin_create_partner.php?key=nci2026admin                          (new partner)
 *   /admin_create_partner.php?key=nci2026admin&code=DRTEST              (edit existing)
 *
 * DELETE after self-service portal ships.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();

if (($_GET['key'] ?? $_POST['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403);
    echo "forbidden\n"; exit;
}

// Ensure partners table exists (defensive — usually partner_schema.php's IIFE creates it
// but we don't include that file to avoid function-redeclare conflicts on prod)
try {
    db()->exec("CREATE TABLE IF NOT EXISTS partners (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        name            TEXT NOT NULL,
        contact_name    TEXT,
        phone           TEXT,
        whatsapp        TEXT,
        email           TEXT,
        city            TEXT,
        referral_code   TEXT UNIQUE NOT NULL,
        revenue_share   REAL DEFAULT 0.30,
        bank_name       TEXT,
        bank_account    TEXT,
        bank_ifsc       TEXT,
        upi_id          TEXT,
        status          TEXT DEFAULT 'active',
        notes           TEXT,
        created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
        last_referral_at TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_partners_code ON partners(referral_code)");
} catch (Throwable $_) {}

// Ensure branding columns
foreach (['clinic_image_path','doctor_image_path','clinic_address','doctor_credentials','custom_message'] as $col) {
    try {
        $cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array($col, $names, true)) {
            db()->exec("ALTER TABLE partners ADD COLUMN $col TEXT");
        }
    } catch (Throwable $_) {}
}

// Local helper to avoid redeclare conflicts with production partner_by_code()
$admin_partner_by_code = function (string $code): ?array {
    $st = db()->prepare("SELECT * FROM partners WHERE referral_code = ? LIMIT 1");
    $st->execute([strtoupper($code)]);
    $row = $st->fetch();
    return $row ?: null;
};

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_POST;
    $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', (string)($f['referral_code'] ?? '')));
    if ($code === '' || strlen($code) < 3) {
        $err = 'Referral code must be at least 3 chars (letters/numbers only).';
    } else {
        $name = trim((string)($f['name'] ?? ''));
        if ($name === '') $err = 'Clinic name required.';
    }

    if (!$err) {
        $fields = [
            'name'               => trim((string)($f['name'] ?? '')),
            'contact_name'       => trim((string)($f['contact_name'] ?? '')),
            'phone'              => trim((string)($f['phone'] ?? '')),
            'whatsapp'           => trim((string)($f['whatsapp'] ?? '')),
            'email'              => trim((string)($f['email'] ?? '')),
            'city'               => trim((string)($f['city'] ?? '')),
            'referral_code'      => $code,
            'revenue_share'      => max(0, min(1, (float)($f['revenue_share'] ?? 0.5))),
            'status'             => 'active',
            'doctor_credentials' => trim((string)($f['doctor_credentials'] ?? '')),
            'clinic_address'     => trim((string)($f['clinic_address'] ?? '')),
            'custom_message'     => trim((string)($f['custom_message'] ?? '')),
            'clinic_image_path'  => trim((string)($f['clinic_image_path'] ?? '')),
            'doctor_image_path'  => trim((string)($f['doctor_image_path'] ?? '')),
        ];

        $existing = $admin_partner_by_code($code);
        if ($existing) {
            $set = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                if ($k === 'referral_code') continue;
                $set[] = "$k = ?"; $vals[] = $v;
            }
            $vals[] = (int)$existing['id'];
            db()->prepare("UPDATE partners SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
            $msg = "✓ Updated partner #{$existing['id']} ($code).";
        } else {
            $cols_list = array_keys($fields);
            $placeholders = implode(', ', array_fill(0, count($cols_list), '?'));
            db()->prepare("INSERT INTO partners (" . implode(', ', $cols_list) . ") VALUES ($placeholders)")
                ->execute(array_values($fields));
            $new_id = db()->lastInsertId();
            $msg = "✓ Created partner #$new_id ($code). Landing URL: /p.php?code=$code";
        }
    }
}

// Load partner being edited (if ?code=XYZ)
$edit_code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', (string)($_GET['code'] ?? '')));
$edit = $edit_code !== '' ? $admin_partner_by_code($edit_code) : null;

$v = function($key, $default = '') use ($edit) {
    if ($edit && isset($edit[$key])) return htmlspecialchars((string)$edit[$key]);
    return htmlspecialchars((string)$default);
};

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin · Create/Edit Partner</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:system-ui,sans-serif}</style>
</head>
<body class="bg-slate-50">

<div class="max-w-3xl mx-auto p-6">
  <h1 class="text-2xl font-bold mb-1">Admin · Create / Edit Partner</h1>
  <p class="text-sm text-slate-600 mb-4">Add a referring pediatrician. Their landing URL will be <code class="bg-slate-200 px-1.5 py-0.5 rounded text-xs">/p.php?code=THEIR_CODE</code></p>

  <?php if ($msg): ?>
    <div class="bg-emerald-50 border-2 border-emerald-300 rounded-lg p-3 mb-4 text-sm text-emerald-900"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="bg-rose-50 border-2 border-rose-300 rounded-lg p-3 mb-4 text-sm text-rose-900"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-xl p-5 shadow-sm border border-slate-200 space-y-4">
    <input type="hidden" name="key" value="nci2026admin">

    <div>
      <label class="block text-sm font-semibold text-slate-700 mb-1">Referral code <span class="text-rose-600">*</span></label>
      <input name="referral_code" required value="<?= $v('referral_code') ?>"
             placeholder="DRSHARMA" pattern="[A-Z0-9_-]{3,}" maxlength="20"
             class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      <p class="text-xs text-slate-500 mt-1">URL becomes /p.php?code=DRSHARMA · letters & numbers only, 3+ chars</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic name <span class="text-rose-600">*</span></label>
        <input name="name" required value="<?= $v('name') ?>" placeholder="Sunrise Children's Clinic" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      </div>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Doctor name</label>
        <input name="contact_name" value="<?= $v('contact_name') ?>" placeholder="Dr Anita Sharma" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      </div>
    </div>

    <div>
      <label class="block text-sm font-semibold text-slate-700 mb-1">Doctor credentials</label>
      <input name="doctor_credentials" value="<?= $v('doctor_credentials', 'MBBS, MD (Pediatrics) · X yrs experience') ?>" placeholder="MBBS, MD (Pediatrics) · 15 yrs experience" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Phone</label>
        <input name="phone" value="<?= $v('phone') ?>" placeholder="+91xxx" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      </div>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">WhatsApp</label>
        <input name="whatsapp" value="<?= $v('whatsapp') ?>" placeholder="+91xxx" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      </div>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Email</label>
        <input name="email" type="email" value="<?= $v('email') ?>" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">City</label>
        <input name="city" value="<?= $v('city') ?>" placeholder="Gurgaon" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      </div>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic address</label>
        <input name="clinic_address" value="<?= $v('clinic_address') ?>" placeholder="Sector 14, Gurgaon" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
      </div>
    </div>

    <div>
      <label class="block text-sm font-semibold text-slate-700 mb-1">
        Revenue share <span class="text-slate-500 font-normal">(0.50 = 50%)</span>
      </label>
      <input name="revenue_share" type="number" step="0.05" min="0" max="1" value="<?= $v('revenue_share', '0.5') ?>"
             class="w-32 p-2.5 border border-slate-300 rounded-lg text-sm">
      <p class="text-xs text-slate-500 mt-1">Partner gets this fraction of every paid charge from referred parents.</p>
    </div>

    <div class="border-t border-slate-200 pt-4">
      <p class="text-sm font-bold text-slate-900 mb-3">Branding (used on /p/CODE landing page)</p>

      <div class="space-y-3">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic photo URL</label>
          <input name="clinic_image_path" value="<?= $v('clinic_image_path') ?>" placeholder="/uploads/partners/sunrise_clinic.jpg" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
          <p class="text-xs text-slate-500 mt-1">Upload via FTP to <code>/uploads/partners/</code> first, then paste the path here.</p>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Doctor photo URL</label>
          <input name="doctor_image_path" value="<?= $v('doctor_image_path') ?>" placeholder="/uploads/partners/dr_anita.jpg" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Custom doctor's message <span class="text-slate-500 font-normal">(optional, 1-2 lines)</span></label>
          <textarea name="custom_message" rows="2" class="w-full p-2.5 border border-slate-300 rounded-lg text-sm" placeholder="I recommend this evaluation to every parent who asks me about their child's behaviour."><?= $v('custom_message') ?></textarea>
        </div>
      </div>
    </div>

    <div class="flex items-center gap-3 pt-3 border-t border-slate-200">
      <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-sm">
        <?= $edit ? 'Save changes' : 'Create partner' ?>
      </button>
      <a href="/admin_create_partner.php?key=nci2026admin" class="text-sm text-slate-600 hover:text-slate-900">+ New blank form</a>
    </div>
  </form>

  <!-- Existing partners list -->
  <div class="mt-6 bg-white rounded-xl p-5 shadow-sm border border-slate-200">
    <h2 class="text-sm font-bold text-slate-900 mb-3">Existing partners</h2>
    <?php
    $list = db()->query("SELECT id, name, contact_name, referral_code, revenue_share, status, city,
                          (SELECT COUNT(*) FROM parents WHERE partner_id = partners.id) AS parent_count,
                          (SELECT COALESCE(SUM(partner_amount), 0) FROM partner_payouts WHERE partner_id = partners.id) AS earned
                        FROM partners ORDER BY id DESC")->fetchAll();
    ?>
    <?php if (empty($list)): ?>
      <p class="text-sm text-slate-500 italic">No partners yet. Create the first one above.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <th class="text-left py-2">Code</th>
              <th class="text-left py-2">Clinic</th>
              <th class="text-left py-2">Doctor</th>
              <th class="text-right py-2">Share</th>
              <th class="text-right py-2">Parents</th>
              <th class="text-right py-2">Earned</th>
              <th class="py-2"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $p): ?>
              <tr class="border-b border-slate-100">
                <td class="py-2 font-mono text-xs"><?= htmlspecialchars($p['referral_code']) ?></td>
                <td class="py-2"><?= htmlspecialchars($p['name']) ?><?php if ($p['city']): ?><br><span class="text-xs text-slate-500"><?= htmlspecialchars($p['city']) ?></span><?php endif; ?></td>
                <td class="py-2 text-xs"><?= htmlspecialchars($p['contact_name'] ?: '—') ?></td>
                <td class="py-2 text-right text-xs"><?= (int)round((float)$p['revenue_share'] * 100) ?>%</td>
                <td class="py-2 text-right"><?= (int)$p['parent_count'] ?></td>
                <td class="py-2 text-right">₹<?= number_format((float)$p['earned']) ?></td>
                <td class="py-2 text-right">
                  <a href="/admin_create_partner.php?key=nci2026admin&code=<?= urlencode($p['referral_code']) ?>" class="text-xs text-emerald-600 hover:text-emerald-700 underline">Edit</a> ·
                  <a href="/p.php?code=<?= urlencode($p['referral_code']) ?>" target="_blank" class="text-xs text-emerald-600 hover:text-emerald-700 underline">View page</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <p class="text-xs text-slate-500 mt-4">⚠ DELETE this file once you have a self-service partner portal.</p>
</div>

</body>
</html>
