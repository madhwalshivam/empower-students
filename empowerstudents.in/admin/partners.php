<?php
/**
 * admin/partners.php — production partner management page
 *
 * Features:
 *   • List view with status / area / category / clinical|educational filters
 *   • Stat cards (cold, messaged, interested, partner)
 *   • + Add partner (full contact form)
 *   • 📥 Bulk CSV import (uses partners_template.csv format)
 *   • 📲 Pitch EN / 📲 HI buttons — opens WhatsApp click-to-send (auto-routes
 *     clinical vs educational pitch by category)
 *   • 📋 Long button — full professional pitch text
 *   • Detail view (?id=N): edit form + conversation log + status update
 *   • Mark-messaged via fetch keepalive (when admin clicks pitch)
 *
 * RECONSTRUCTED after the file was accidentally overwritten. Schema unchanged
 * so all existing partner records continue to display.
 */
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../includes/partners.php';

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check(): bool {
        return !empty($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
    }
}

/* ════════════════════════════════════════════════════════════════════
   POST HANDLERS
   ════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── 1. Update status (called from list via fetch keepalive when pitch clicked) ── */
    if ($action === 'update_status') {
        if (!csrf_check()) { http_response_code(403); exit('csrf'); }
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id > 0 && in_array($status, partner_status_options(), true)) {
            $upd = "UPDATE partners SET status = ?, updated_at = CURRENT_TIMESTAMP";
            $params = [$status];
            if ($status === 'messaged') {
                $upd .= ", messaged_at = COALESCE(messaged_at, CURRENT_TIMESTAMP)";
            }
            $upd .= " WHERE id = ?";
            $params[] = $id;
            db()->prepare($upd)->execute($params);
        }
        // Background fetch — no redirect
        if (!empty($_POST['ajax'])) { echo 'ok'; exit; }
        header('Location: /admin/partners.php?id=' . $id); exit;
    }

    /* ── 2. Add a new partner ── */
    if ($action === 'create') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('Name is required.', 'rose');
            header('Location: /admin/partners.php?action=new'); exit;
        }
        $code = generate_partner_code();
        db()->prepare("INSERT INTO partners
            (name, contact_person, phone, whatsapp, email, address, area, pincode,
             category, website, google_maps_url, rating, review_count,
             referral_code, source, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $name,
               trim((string)($_POST['contact_person'] ?? '')) ?: null,
               trim((string)($_POST['phone'] ?? '')) ?: null,
               trim((string)($_POST['whatsapp'] ?? '')) ?: null,
               trim((string)($_POST['email'] ?? '')) ?: null,
               trim((string)($_POST['address'] ?? '')) ?: null,
               trim((string)($_POST['area'] ?? '')) ?: null,
               trim((string)($_POST['pincode'] ?? '')) ?: null,
               $_POST['category'] ?? 'other',
               trim((string)($_POST['website'] ?? '')) ?: null,
               trim((string)($_POST['google_maps_url'] ?? '')) ?: null,
               isset($_POST['rating']) && $_POST['rating'] !== '' ? (float)$_POST['rating'] : null,
               isset($_POST['review_count']) && $_POST['review_count'] !== '' ? (int)$_POST['review_count'] : null,
               $code,
               'manual',
               trim((string)($_POST['notes'] ?? '')) ?: null,
           ]);
        $new_id = (int)db()->lastInsertId();
        flash("Partner created with code <strong>$code</strong>", 'emerald');
        header('Location: /admin/partners.php?id=' . $new_id); exit;
    }

    /* ── 3. Edit an existing partner ── */
    if ($action === 'update') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { header('Location: /admin/partners.php'); exit; }
        db()->prepare("UPDATE partners SET
            name=?, contact_person=?, phone=?, whatsapp=?, email=?, address=?, area=?, pincode=?,
            category=?, website=?, google_maps_url=?, rating=?, review_count=?, notes=?, status=?,
            revenue_share=?,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = ?")
           ->execute([
               trim((string)$_POST['name']),
               trim((string)($_POST['contact_person'] ?? '')) ?: null,
               trim((string)($_POST['phone'] ?? '')) ?: null,
               trim((string)($_POST['whatsapp'] ?? '')) ?: null,
               trim((string)($_POST['email'] ?? '')) ?: null,
               trim((string)($_POST['address'] ?? '')) ?: null,
               trim((string)($_POST['area'] ?? '')) ?: null,
               trim((string)($_POST['pincode'] ?? '')) ?: null,
               $_POST['category'] ?? 'other',
               trim((string)($_POST['website'] ?? '')) ?: null,
               trim((string)($_POST['google_maps_url'] ?? '')) ?: null,
               isset($_POST['rating']) && $_POST['rating'] !== '' ? (float)$_POST['rating'] : null,
               isset($_POST['review_count']) && $_POST['review_count'] !== '' ? (int)$_POST['review_count'] : null,
               trim((string)($_POST['notes'] ?? '')) ?: null,
               $_POST['status'] ?? 'cold',
               /* fresh-v8i: store revenue_share (REAL 0.0-1.0) from integer % input */
               (isset($_POST['commission_pct']) && $_POST['commission_pct'] !== ''
                   && (int)$_POST['commission_pct'] > 0)
                   ? round((int)$_POST['commission_pct'] / 100.0, 2) : null,
               $id,
           ]);
        flash('Partner updated.', 'emerald');
        header('Location: /admin/partners.php?id=' . $id); exit;
    }

    /* ── 4. Delete a partner (admin can clean up duplicates) ── */
    if ($action === 'delete') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM partner_messages WHERE partner_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM partners WHERE id = ?")->execute([$id]);
            flash('Partner deleted.', 'emerald');
        }
        header('Location: /admin/partners.php'); exit;
    }

    /* ── 5. Log an outbound / inbound message in the conversation timeline ── */
    /* fresh-v11: generate password setup link + optional activate */
    if ($action === 'partner_setup_link') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $activate_too = !empty($_POST['activate']);
        if ($id <= 0) { flash('Bad request', 'rose'); header('Location: /admin/partners.php'); exit; }

        @require_once __DIR__ . '/../includes/partner_auth.php';
        if (function_exists('partner_generate_setup_token')) {
            $token = partner_generate_setup_token($id);
            if ($activate_too) {
                db()->prepare("UPDATE partners SET status = 'active', updated_at = CURRENT_TIMESTAMP
                               WHERE id = ? AND status IN ('pending','cold','messaged','interested')")
                   ->execute([$id]);
            }
            flash('Password setup link generated' . ($activate_too ? ' and partner activated' : ''), 'emerald');
        } else {
            flash('partner_generate_setup_token() missing', 'rose');
        }
        header('Location: /admin/partners.php?id=' . $id . '#login_access');
        exit;
    }

    if ($action === 'log_message') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $direction = in_array($_POST['direction'] ?? '', ['out', 'in'], true) ? $_POST['direction'] : 'out';
        $channel = in_array($_POST['channel'] ?? '', ['whatsapp', 'call', 'email', 'visit'], true) ? $_POST['channel'] : 'whatsapp';
        $msg = trim((string)($_POST['message'] ?? ''));
        if ($id > 0 && $msg !== '') {
            db()->prepare("INSERT INTO partner_messages (partner_id, direction, channel, message, logged_by) VALUES (?,?,?,?,?)")
                ->execute([$id, $direction, $channel, $msg, admin_user()]);
            // If this is the first OUT message, bump status to messaged
            if ($direction === 'out') {
                db()->prepare("UPDATE partners SET status = CASE WHEN status='cold' THEN 'messaged' ELSE status END,
                                                  messaged_at = COALESCE(messaged_at, CURRENT_TIMESTAMP)
                               WHERE id = ?")->execute([$id]);
            }
            // If IN, mark responded_at
            if ($direction === 'in') {
                db()->prepare("UPDATE partners SET responded_at = COALESCE(responded_at, CURRENT_TIMESTAMP) WHERE id = ?")
                    ->execute([$id]);
            }
        }
        header('Location: /admin/partners.php?id=' . $id); exit;
    }

    /* ── 6. Bulk CSV import — uses partners_template.csv format ── */
    if ($action === 'csv_import') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            flash('No file uploaded.', 'rose');
            header('Location: /admin/partners.php'); exit;
        }
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            flash('Could not read uploaded file.', 'rose');
            header('Location: /admin/partners.php'); exit;
        }
        $header = fgetcsv($fh);
        if (!$header) {
            flash('Empty CSV.', 'rose');
            fclose($fh); header('Location: /admin/partners.php'); exit;
        }
        $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
        $imported = 0; $skipped = 0;

        while (($row = fgetcsv($fh)) !== false) {
            if (!array_filter($row)) { $skipped++; continue; }
            $r = array_combine($header, array_pad($row, count($header), ''));
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') { $skipped++; continue; }

            // Duplicate check by name + phone (whichever is non-empty)
            $phone = trim((string)($r['phone'] ?? ''));
            $dup = db()->prepare("SELECT 1 FROM partners WHERE name = ? AND COALESCE(phone, '') = ? LIMIT 1");
            $dup->execute([$name, $phone]);
            if ($dup->fetchColumn()) { $skipped++; continue; }

            $code = generate_partner_code();
            db()->prepare("INSERT INTO partners
                (name, contact_person, phone, whatsapp, email, address, area, pincode,
                 category, website, google_maps_url, rating, review_count,
                 referral_code, source, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $name,
                   trim((string)($r['contact_person'] ?? '')) ?: null,
                   $phone ?: null,
                   trim((string)($r['whatsapp'] ?? '')) ?: null,
                   trim((string)($r['email'] ?? '')) ?: null,
                   trim((string)($r['address'] ?? '')) ?: null,
                   trim((string)($r['area'] ?? '')) ?: null,
                   trim((string)($r['pincode'] ?? '')) ?: null,
                   trim((string)($r['category'] ?? '')) ?: 'other',
                   trim((string)($r['website'] ?? '')) ?: null,
                   trim((string)($r['google_maps_url'] ?? '')) ?: null,
                   isset($r['rating']) && $r['rating'] !== '' ? (float)$r['rating'] : null,
                   isset($r['review_count']) && $r['review_count'] !== '' ? (int)$r['review_count'] : null,
                   $code,
                   'csv',
                   trim((string)($r['notes'] ?? '')) ?: null,
               ]);
            $imported++;
        }
        fclose($fh);
        flash("Imported $imported partners. Skipped $skipped (duplicates or missing name).", 'emerald');
        header('Location: /admin/partners.php'); exit;
    }

    /* ── 7. Create parent invite (magic link, ₹2000 credit) ── */
    if ($action === 'create_invite') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        $inv_partner_id = (int)($_POST['partner_id'] ?? 0);
        if ($inv_partner_id <= 0) { flash('Bad request', 'rose'); header('Location: /admin/partners.php'); exit; }

        @require_once __DIR__ . '/../includes/partners.php';
        if (!function_exists('invite_count_today')) {
            flash('invite helpers not loaded', 'rose');
            header('Location: /admin/partners.php?id=' . $inv_partner_id); exit;
        }

        $parent_name  = trim((string)($_POST['parent_name'] ?? ''));
        $wa_raw       = preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? ''));
        if (strlen($wa_raw) === 10) $wa_raw = '91' . $wa_raw;

        if ($parent_name === '' || strlen($wa_raw) < 10) {
            flash('Parent name and valid WhatsApp number required.', 'rose');
            header('Location: /admin/partners.php?id=' . $inv_partner_id . '#invitePanel'); exit;
        }
        if (invite_count_today($inv_partner_id) >= 5) {
            flash('Daily limit of 5 invites reached for today.', 'rose');
            header('Location: /admin/partners.php?id=' . $inv_partner_id . '#invitePanel'); exit;
        }

        $result = invite_create($inv_partner_id, $parent_name, $wa_raw);
        if (!empty($result['error'])) {
            flash('Could not create invite: ' . $result['error'], 'rose');
        } else {
            flash("Invite created for <strong>" . htmlspecialchars($parent_name) . "</strong>.", 'emerald');
        }
        header('Location: /admin/partners.php?id=' . $inv_partner_id . '#invitePanel'); exit;
    }

    /* ── 8. Cancel a pending invite ── */
    if ($action === 'cancel_invite') {
        if (!csrf_check()) { flash('Bad CSRF', 'rose'); header('Location: /admin/partners.php'); exit; }
        $inv_id         = (int)($_POST['invite_id'] ?? 0);
        $inv_partner_id = (int)($_POST['partner_id'] ?? 0);
        if ($inv_id > 0) {
            db()->prepare("UPDATE parent_invites SET status='cancelled' WHERE id=? AND status='pending'")
               ->execute([$inv_id]);
            flash('Invite cancelled.', 'emerald');
        }
        header('Location: /admin/partners.php?id=' . $inv_partner_id . '#invitePanel'); exit;
    }
}

/* ════════════════════════════════════════════════════════════════════
   QUERIES
   ════════════════════════════════════════════════════════════════════ */
$action_view = $_GET['action'] ?? '';
$detail_id   = (int)($_GET['id'] ?? 0);
$detail = null; $messages = [];
if ($detail_id > 0) {
    $st = db()->prepare("SELECT * FROM partners WHERE id = ?");
    $st->execute([$detail_id]);
    $detail = $st->fetch() ?: null;
    if ($detail) {
        $mst = db()->prepare("SELECT * FROM partner_messages WHERE partner_id = ? ORDER BY created_at DESC");
        $mst->execute([$detail_id]);
        $messages = $mst->fetchAll();
    }
}

/* fresh-v12: load invites for detail view */
$invites = [];
$invite_today_count = 0;
if ($detail_id > 0) {
    @require_once __DIR__ . '/../includes/partners.php';
    // Ensure parent_invites table exists
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

    try {
        $ist = db()->prepare("SELECT * FROM parent_invites WHERE partner_id = ? ORDER BY created_at DESC LIMIT 50");
        $ist->execute([$detail_id]);
        $invites = $ist->fetchAll();
    } catch (Throwable $_) { $invites = []; }

    try {
        $ict = db()->prepare("SELECT COUNT(*) FROM parent_invites WHERE partner_id = ? AND DATE(created_at) = DATE('now','localtime')");
        $ict->execute([$detail_id]);
        $invite_today_count = (int)$ict->fetchColumn();
    } catch (Throwable $_) {}
}

$filter_status = $_GET['status']   ?? '';
$filter_area   = $_GET['area']     ?? '';
$filter_cat    = $_GET['category'] ?? '';
$filter_group  = $_GET['group']    ?? '';   // 'clinical' | 'educational' | ''
$where = '1=1'; $params = [];
if (in_array($filter_status, partner_status_options(), true)) {
    $where .= ' AND status = ?'; $params[] = $filter_status;
}
if ($filter_area !== '') { $where .= ' AND area = ?'; $params[] = $filter_area; }
if ($filter_cat !== '')  { $where .= ' AND category = ?'; $params[] = $filter_cat; }
if ($filter_group === 'clinical') {
    $where .= " AND category IN ('ot','speech','special_ed','autism','multi')";
} elseif ($filter_group === 'educational') {
    $where .= " AND category IN ('tutor','tuition_centre','school')";
}
$st = db()->prepare("SELECT * FROM partners WHERE $where ORDER BY (status='cold') DESC, id DESC LIMIT 500");
$st->execute($params);
$partners = $st->fetchAll();

$stats = partner_stats();

$areas_st = db()->query("SELECT DISTINCT area FROM partners WHERE area IS NOT NULL AND area != '' ORDER BY area");
$areas = $areas_st->fetchAll(PDO::FETCH_COLUMN);

// Category and group counts for filter pills
$group_counts = ['clinical' => 0, 'educational' => 0, 'other' => 0];
try {
    foreach (db()->query("SELECT category, COUNT(*) c FROM partners GROUP BY category")->fetchAll() as $r) {
        $c = $r['category'] ?? 'other';
        if (in_array($c, ['ot','speech','special_ed','autism','multi'], true))      $group_counts['clinical']    += (int)$r['c'];
        elseif (in_array($c, ['tutor','tuition_centre','school'], true))            $group_counts['educational'] += (int)$r['c'];
        else                                                                         $group_counts['other']       += (int)$r['c'];
    }
} catch (Throwable $_) {}

admin_layout_open('Partners');
admin_render_flash();

$cats = partner_categories();
?>

<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-bold">🤝 Partners</h1>
    <p class="text-sm text-slate-500">Tutors, centres, therapy partners — track outreach, messages, and referrals.</p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <button type="button" onclick="document.getElementById('csv_panel').classList.toggle('hidden')"
            class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-full text-sm font-bold">
      📥 Bulk CSV import
    </button>
    <a href="?action=new" class="bg-indigo-600 text-white px-4 py-2 rounded-full text-sm font-bold hover:bg-indigo-700">+ Add partner</a>
  </div>
</div>

<!-- ── CSV import panel ── -->
<div id="csv_panel" class="<?= $action_view === 'csv' ? '' : 'hidden' ?> bg-white border-2 border-dashed border-indigo-200 rounded-2xl p-5 mb-5">
  <h3 class="font-bold text-sm mb-3">📥 Bulk CSV import</h3>
  <p class="text-xs text-slate-500 mb-3">
    Format: <code class="bg-slate-100 px-2 py-0.5 rounded">name, contact_person, phone, whatsapp, email, address, area, pincode, category, website, google_maps_url, rating, review_count, notes</code>.
    <a href="/admin/partners_template.csv" class="text-indigo-600 hover:underline">Download template</a>.
    Categories: <?= implode(', ', array_keys($cats)) ?>. Duplicates (same name + phone) are skipped.
  </p>
  <form method="post" enctype="multipart/form-data" class="flex flex-wrap gap-3 items-center">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="csv_import">
    <input type="file" name="csv" accept=".csv" required class="text-sm">
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-full text-sm font-bold">Import</button>
  </form>
</div>

<?php if ($action_view === 'new'): /* ═══ NEW PARTNER FORM ═══ */ ?>
  <div class="bg-white border border-slate-200 rounded-2xl p-6 max-w-3xl">
    <h2 class="font-bold text-lg mb-4">Add a new partner</h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label class="text-xs font-bold text-slate-600 block mb-1">Name *</label>
          <input type="text" name="name" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="Sunrise Speech &amp; Hearing Clinic">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Category</label>
          <select name="category" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($cats as $val => $label): ?>
              <option value="<?= e($val) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Contact person</label>
          <input type="text" name="contact_person" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Phone</label>
          <input type="tel" name="phone" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">WhatsApp</label>
          <input type="tel" name="whatsapp" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="if different from phone">
        </div>
        <div class="sm:col-span-2">
          <label class="text-xs font-bold text-slate-600 block mb-1">Email</label>
          <input type="email" name="email" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-2">
          <label class="text-xs font-bold text-slate-600 block mb-1">Address</label>
          <input type="text" name="address" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Area</label>
          <input type="text" name="area" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="Noida / Gurgaon / etc.">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Pincode</label>
          <input type="text" name="pincode" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Website</label>
          <input type="url" name="website" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Google Maps URL</label>
          <input type="url" name="google_maps_url" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Rating</label>
          <input type="number" step="0.1" min="0" max="5" name="rating" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="text-xs font-bold text-slate-600 block mb-1">Review count</label>
          <input type="number" min="0" name="review_count" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-2">
          <label class="text-xs font-bold text-slate-600 block mb-1">Notes</label>
          <textarea name="notes" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"></textarea>
        </div>
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <a href="/admin/partners.php" class="px-4 py-2 text-slate-500 text-sm">Cancel</a>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-full text-sm font-bold">Create partner</button>
      </div>
    </form>
  </div>

<?php elseif ($detail): /* ═══ DETAIL VIEW ═══ */ ?>
  <a href="/admin/partners.php" class="text-sm text-indigo-600 hover:underline">← All partners</a>

  <?php
    $whatsapp_en = partner_whatsapp_url($detail, 'en');
    $whatsapp_hi = partner_whatsapp_url($detail, 'hi');
    $long_pitch  = partner_pitch_long($detail);
    $comm_summary = partner_commission_summary((int)$detail['id']);
  ?>

  <div class="grid lg:grid-cols-3 gap-5 mt-3">
    <!-- ── LEFT: edit form ── -->
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl p-6">
      <div class="flex items-start justify-between gap-3 mb-4 flex-wrap">
        <div>
          <h2 class="font-bold text-xl"><?= e($detail['name']) ?></h2>
          <p class="text-sm text-slate-500 font-mono">/p/<?= e($detail['referral_code']) ?>
            <?php if ($detail['rating']): ?>
              · ⭐ <?= number_format((float)$detail['rating'], 1) ?>
              <?php if ($detail['review_count']): ?>(<?= (int)$detail['review_count'] ?>)<?php endif; ?>
            <?php endif; ?>
          </p>
        </div>
        <span class="<?= partner_status_classes($detail['status'] ?? 'cold', 'pill') ?> text-xs font-bold px-3 py-1 rounded-full uppercase"><?= e($detail['status']) ?></span>
      </div>

      <!-- Pitch action bar -->
      <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 mb-5 flex flex-wrap gap-2 items-center">
        <span class="text-xs font-bold text-slate-500 uppercase mr-2">Pitch:</span>
        <?php if ($whatsapp_en): ?>
          <a href="<?= e($whatsapp_en) ?>" target="_blank" rel="noopener"
             onclick="markMessaged(<?= (int)$detail['id'] ?>)"
             class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-full text-xs font-bold inline-flex items-center gap-1"
             title="<?= partner_is_educational($detail['category'] ?? 'other') ? 'Educational pitch' : 'Clinical pitch' ?>">
            📲 Pitch EN
          </a>
          <a href="<?= e($whatsapp_hi) ?>" target="_blank" rel="noopener"
             onclick="markMessaged(<?= (int)$detail['id'] ?>)"
             class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-full text-xs font-bold">
            📲 Pitch HI
          </a>
        <?php else: ?>
          <span class="text-xs text-rose-500 italic">No phone — add one to enable WhatsApp pitch</span>
        <?php endif; ?>
        <button type="button" onclick="document.getElementById('long_pitch').classList.toggle('hidden')"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-full text-xs font-bold">
          📋 Long
        </button>
        <?php if ($detail['google_maps_url']): ?>
          <a href="<?= e($detail['google_maps_url']) ?>" target="_blank" rel="noopener"
             class="bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 px-3 py-1.5 rounded-full text-xs font-bold">📍 Maps</a>
        <?php endif; ?>
        <?php if ($detail['website']): ?>
          <a href="<?= e($detail['website']) ?>" target="_blank" rel="noopener"
             class="bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 px-3 py-1.5 rounded-full text-xs font-bold">🔗 Website</a>
        <?php endif; ?>
      </div>

      <!-- Long pitch (toggleable, copy-paste) -->
      <div id="long_pitch" class="hidden mb-5">
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
          <div class="flex items-center justify-between mb-2">
            <h4 class="font-bold text-sm">📋 Long pitch (copy-paste)</h4>
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('long_pitch_text').value); this.textContent='✓ Copied'"
                    class="text-xs bg-amber-600 hover:bg-amber-700 text-white px-2 py-1 rounded">Copy</button>
          </div>
          <textarea id="long_pitch_text" readonly rows="14"
                    class="w-full border border-amber-300 bg-white rounded-lg px-3 py-2 text-xs font-mono"><?= e($long_pitch) ?></textarea>
        </div>
      </div>

      <!-- fresh-v11: Partner login access -->
      <?php
        $has_password = !empty($detail['password_hash'] ?? null);
        $has_token    = !empty($detail['password_setup_token'] ?? null);
        $is_pending   = ($detail['status'] !== 'active');
        $setup_url    = $has_token
          ? 'https://empowerstudents.in/partner-set-password.php?token=' . urlencode($detail['password_setup_token'] ?? '')
          : '';
        $last_login   = $detail['last_login_at'] ?? null;
      ?>
      <div id="login_access" class="bg-white border-2 border-indigo-200 rounded-xl p-4 mb-5">
        <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
          <h3 class="font-bold text-sm">🔑 Partner login access</h3>
          <?php if ($has_password): ?>
            <span class="text-xs bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full font-semibold">✓ Password set</span>
          <?php elseif ($has_token): ?>
            <span class="text-xs bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-semibold">⏳ Awaiting password setup</span>
          <?php else: ?>
            <span class="text-xs bg-slate-200 text-slate-700 px-2 py-0.5 rounded-full font-semibold">No login yet</span>
          <?php endif; ?>
        </div>

        <?php if ($has_password): ?>
          <p class="text-xs text-slate-600 mb-2">
            Partner can sign in at <code class="text-indigo-700">/partner-login.php</code> using WhatsApp <strong><?= e($detail['whatsapp']) ?></strong> + their password.
            <?php if ($last_login): ?>
              · Last login: <?= e(date('d M Y H:i', strtotime($last_login))) ?>
            <?php endif; ?>
          </p>
          <form method="post" class="inline"
                onsubmit="return confirm('Re-issue a NEW password setup link? The partner will need to set a new password — the old one will stop working once they click the new link.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="partner_setup_link">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <button type="submit" class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded font-semibold">
              ↻ Re-issue setup link
            </button>
          </form>
        <?php else: ?>
          <?php if ($has_token): ?>
            <div class="bg-amber-50 border border-amber-200 rounded p-3 mb-3">
              <p class="text-xs font-bold text-amber-900 mb-2">Setup link is active:</p>
              <div class="flex gap-2 items-stretch">
                <input type="text" readonly id="setup_url_<?= (int)$detail['id'] ?>"
                       value="<?= e($setup_url) ?>"
                       class="flex-1 border border-amber-300 bg-white rounded px-2 py-1 text-xs font-mono">
                <button type="button"
                        onclick="navigator.clipboard.writeText(document.getElementById('setup_url_<?= (int)$detail['id'] ?>').value); this.textContent='✓ Copied'"
                        class="text-xs bg-amber-600 hover:bg-amber-700 text-white px-3 py-1 rounded font-semibold">
                  📋 Copy
                </button>
              </div>
              <?php
                $wa_clean = preg_replace('/[^0-9]/', '', (string)$detail['whatsapp']);
                if ($wa_clean) {
                    $msg = "Hello " . ($detail['contact_person'] ?: $detail['name']) . ",\n\n"
                         . "You can now access your partner dashboard on EmpowerStudents.\n\n"
                         . "Step 1: Set your password using this one-time link:\n" . $setup_url . "\n\n"
                         . "Step 2: Sign in at https://empowerstudents.in/partner-login.php with WhatsApp " . $detail['whatsapp'] . " + your new password.\n\n"
                         . "Warm regards,\nTeam EmpowerStudents";
                    $msg_enc = rawurlencode($msg);
                    echo '<a href="https://wa.me/' . $wa_clean . '?text=' . $msg_enc . '" target="_blank" rel="noopener"
                            class="mt-2 inline-block text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded font-semibold">
                          💬 WhatsApp this to partner
                        </a>';
                }
              ?>
              <p class="text-[10px] text-amber-700 mt-2 italic">Link is valid for 24 hours.</p>
            </div>
          <?php endif; ?>

          <form method="post" class="flex flex-wrap gap-2 items-center">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="partner_setup_link">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <?php if ($is_pending): ?>
              <label class="text-xs text-slate-600 flex items-center gap-1">
                <input type="checkbox" name="activate" value="1" checked> Activate partner too (status → active)
              </label>
            <?php endif; ?>
            <button type="submit"
                    class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded font-semibold">
              <?= $has_token ? '↻ Re-generate setup link' : '🔑 Generate password setup link' ?>
            </button>
          </form>

          <?php if (empty($detail['whatsapp'])): ?>
            <p class="text-xs text-rose-600 mt-2">⚠ This partner has no WhatsApp number — they won't be able to log in. Add a WhatsApp number first.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Edit form -->
      <form method="post" class="space-y-3 text-sm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">

        <div class="grid sm:grid-cols-2 gap-3">
          <div class="sm:col-span-2">
            <label class="text-xs font-bold text-slate-600 block mb-1">Name</label>
            <input type="text" name="name" value="<?= e($detail['name']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Category</label>
            <select name="category" class="w-full border border-slate-300 rounded-lg px-3 py-2">
              <?php foreach ($cats as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($detail['category'] === $val) ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Status</label>
            <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2">
              <?php foreach (partner_status_options() as $s): ?>
                <option value="<?= $s ?>" <?= $detail['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">
              Commission %
              <span class="text-slate-400 font-normal">(blank = default <?= (int)(defined('PARTNER_REVENUE_SHARE_PCT') ? PARTNER_REVENUE_SHARE_PCT : 20) ?>%)</span>
            </label>
            <input type="number" min="0" max="100" name="commission_pct"
                   value="<?= !empty($detail['revenue_share']) && (float)$detail['revenue_share'] > 0
                              ? (int)round((float)$detail['revenue_share'] * 100) : '' ?>"
                   placeholder="<?= (int)(defined('PARTNER_REVENUE_SHARE_PCT') ? PARTNER_REVENUE_SHARE_PCT : 20) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Contact person</label>
            <input type="text" name="contact_person" value="<?= e($detail['contact_person']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Email</label>
            <input type="email" name="email" value="<?= e($detail['email']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Phone</label>
            <input type="tel" name="phone" value="<?= e($detail['phone']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">WhatsApp</label>
            <input type="tel" name="whatsapp" value="<?= e($detail['whatsapp']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div class="sm:col-span-2">
            <label class="text-xs font-bold text-slate-600 block mb-1">Address</label>
            <input type="text" name="address" value="<?= e($detail['address']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Area</label>
            <input type="text" name="area" value="<?= e($detail['area']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Pincode</label>
            <input type="text" name="pincode" value="<?= e($detail['pincode']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Website</label>
            <input type="url" name="website" value="<?= e($detail['website']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Google Maps URL</label>
            <input type="url" name="google_maps_url" value="<?= e($detail['google_maps_url']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Rating</label>
            <input type="number" step="0.1" min="0" max="5" name="rating" value="<?= e($detail['rating']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="text-xs font-bold text-slate-600 block mb-1">Review count</label>
            <input type="number" min="0" name="review_count" value="<?= e($detail['review_count']) ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2">
          </div>
          <div class="sm:col-span-2">
            <label class="text-xs font-bold text-slate-600 block mb-1">Notes</label>
            <textarea name="notes" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"><?= e($detail['notes']) ?></textarea>
          </div>
        </div>

        <div class="flex justify-between pt-2">
          <button type="button" onclick="if(confirm('Delete this partner permanently? Cannot be undone.')) document.getElementById('del_form').submit()"
                  class="text-rose-600 hover:underline text-sm">Delete partner</button>
          <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-full text-sm font-bold">Save changes</button>
        </div>
      </form>
      <form id="del_form" method="post" class="hidden">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
      </form>
    </div>

    <!-- ── RIGHT: stats + log message ── -->
    <div class="space-y-4">
      <?php $effective_pct = function_exists('partner_effective_commission_pct')
              ? partner_effective_commission_pct($detail)
              : (int)PARTNER_REVENUE_SHARE_PCT; ?>
      <div class="bg-gradient-to-br from-indigo-500 to-violet-600 rounded-2xl p-5 text-white">
        <div class="text-xs uppercase opacity-90 font-bold">
          Commissions @ <?= $effective_pct ?>%
          <?php if (!empty($detail['revenue_share']) && (float)$detail['revenue_share'] > 0): ?>
            <span class="bg-white/20 px-2 py-0.5 rounded-full text-[10px] ml-1">custom</span>
          <?php endif; ?>
        </div>
        <div class="text-3xl font-bold mt-1">₹<?= number_format((float)$comm_summary['pending']) ?></div>
        <div class="text-xs opacity-90 mt-1">pending across <?= (int)$comm_summary['pending_n'] ?> charges · ₹<?= number_format((float)$comm_summary['paid']) ?> paid lifetime</div>
      </div>

      <?php /* fresh-v8h: referred parents section */
        $referred = function_exists('partner_get_referred_parents')
                  ? partner_get_referred_parents((int)$detail['id']) : [];
      ?>
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-bold text-sm">👥 Referred parents
            <span class="text-xs font-medium text-slate-500">(<?= count($referred) ?>)</span>
          </h3>
        </div>
        <?php if (!$referred): ?>
          <p class="text-xs text-slate-500 italic">No parents have signed up via this referral code yet.
            Share <span class="font-mono">?ref=<?= e($detail['referral_code'] ?: 'PENDING') ?></span> on any landing-page link.
          </p>
        <?php else: ?>
          <ul class="space-y-3 text-sm">
            <?php foreach ($referred as $rp):
              $wa_clean = preg_replace('/[^0-9]/', '', (string)$rp['whatsapp']);
              if (substr($wa_clean, 0, 2) !== '91' && strlen($wa_clean) === 10) $wa_clean = '91' . $wa_clean;
              $wa_url = $wa_clean ? 'https://wa.me/' . $wa_clean : '';
              /* Expected commission from this parent based on Cashfree top-ups */
              $expected_comm = (int) round($rp['total_topup'] * $effective_pct / 100);
            ?>
              <li class="border-l-2 border-indigo-300 pl-3 pb-2">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                  <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800">
                      <?= e($rp['name'] ?: 'Parent #' . $rp['parent_id']) ?>
                    </div>
                    <div class="text-xs text-slate-500"><?= e($rp['whatsapp']) ?></div>
                  </div>
                  <?php if ($wa_url): ?>
                    <a href="<?= e($wa_url) ?>" target="_blank" rel="noopener"
                       class="text-xs bg-emerald-100 hover:bg-emerald-200 text-emerald-800 font-semibold px-2 py-1 rounded">
                      💬 WhatsApp
                    </a>
                  <?php endif; ?>
                </div>
                <div class="text-xs text-slate-600 mt-1.5 flex flex-wrap gap-x-3 gap-y-1">
                  <span>📅 <?= e(date('d M', strtotime($rp['created_at']))) ?></span>
                  <span>👶 <?= (int)$rp['children_count'] ?> child<?= $rp['children_count'] === 1 ? '' : 'ren' ?></span>
                  <?php if ($rp['reflect_done'] > 0): ?>
                    <span class="text-emerald-700">💜 <?= (int)$rp['reflect_done'] ?>/<?= (int)$rp['reflect_count'] ?> reflection<?= $rp['reflect_done'] === 1 ? '' : 's' ?></span>
                  <?php elseif ($rp['reflect_count'] > 0): ?>
                    <span class="text-amber-700">💜 <?= (int)$rp['reflect_count'] ?> started</span>
                  <?php endif; ?>
                  <?php if ($rp['home_course_id']): ?>
                    <span class="text-emerald-700">📚 Course Day <?= (int)$rp['home_course_day'] ?>/7</span>
                  <?php endif; ?>
                </div>
                <div class="text-xs mt-1.5 flex flex-wrap gap-x-3 gap-y-1">
                  <?php if ($rp['total_topup'] > 0): ?>
                    <span class="text-emerald-700 font-semibold">💵 ₹<?= number_format($rp['total_topup']) ?> top-up</span>
                    <span class="text-violet-700 font-semibold">→ ₹<?= number_format($expected_comm) ?> commission</span>
                  <?php else: ?>
                    <span class="text-slate-400">No real-money payment yet</span>
                  <?php endif; ?>
                  <span class="text-slate-500">Wallet ₹<?= number_format($rp['credits']) ?></span>
                </div>
                <div class="text-xs mt-1">
                  <a href="/admin/parent.php?id=<?= (int)$rp['parent_id'] ?>"
                     class="text-indigo-600 hover:underline">View parent profile →</a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php
            $total_topup = array_sum(array_column($referred, 'total_topup'));
            $total_expected_comm = (int) round($total_topup * $effective_pct / 100);
            $total_active_courses = 0;
            foreach ($referred as $rp) if ($rp['home_course_id']) $total_active_courses++;
          ?>
          <div class="mt-4 pt-3 border-t border-slate-200 text-xs text-slate-700 grid grid-cols-3 gap-2">
            <div>
              <div class="text-slate-500 uppercase tracking-wide font-semibold">Top-ups</div>
              <div class="font-bold text-base text-emerald-700">₹<?= number_format($total_topup) ?></div>
            </div>
            <div>
              <div class="text-slate-500 uppercase tracking-wide font-semibold">Expected @ <?= $effective_pct ?>%</div>
              <div class="font-bold text-base text-violet-700">₹<?= number_format($total_expected_comm) ?></div>
            </div>
            <div>
              <div class="text-slate-500 uppercase tracking-wide font-semibold">Active courses</div>
              <div class="font-bold text-base text-emerald-700"><?= $total_active_courses ?>/<?= count($referred) ?></div>
            </div>
          </div>
          <p class="text-[10px] text-slate-500 mt-2 italic">
            Expected commissions are calculated on Cashfree top-ups (real money). Wallet drawdowns/admin grants don't count.
            Actual commission rows are created when revenue flows in.
          </p>
        <?php endif; ?>
      </div>

      <!-- fresh-v12: Parent invite panel -->
      <div id="invitePanel" class="bg-white border border-slate-200 rounded-2xl p-5">
        <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
          <h3 class="font-bold text-sm">📨 Parent Invites
            <span class="text-xs font-medium text-slate-500">(<?= count($invites) ?> total · <?= $invite_today_count ?>/5 today)</span>
          </h3>
          <?php if ($invite_today_count >= 5): ?>
            <span class="text-xs bg-rose-100 text-rose-700 px-2 py-0.5 rounded-full font-semibold">⚠ Daily limit reached</span>
          <?php endif; ?>
        </div>

        <p class="text-xs text-slate-500 mb-3">Magic link → parent pre-filled signup → ₹2,000 wallet credit. No commission fires. Expires 7 days, single-use.</p>

        <?php if ($invite_today_count < 5): ?>
        <form method="post" class="flex flex-wrap gap-2 items-end mb-4">
          <input type="hidden" name="csrf"       value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action"     value="create_invite">
          <input type="hidden" name="partner_id" value="<?= (int)$detail['id'] ?>">
          <div class="flex-1 min-w-[160px]">
            <label class="text-xs font-bold text-slate-600 block mb-1">Parent Name</label>
            <input type="text" name="parent_name" placeholder="Priya Sharma" required
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div class="flex-1 min-w-[160px]">
            <label class="text-xs font-bold text-slate-600 block mb-1">WhatsApp (10-digit)</label>
            <input type="tel" name="whatsapp" placeholder="9XXXXXXXXX" maxlength="12" required
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-full text-sm font-bold flex-shrink-0">
            Generate Link
          </button>
        </form>
        <?php endif; ?>

        <?php if ($invites): ?>
          <div class="space-y-3 text-sm">
            <?php foreach ($invites as $inv):
              $inv_link = 'https://empowerstudents.in/p-invite.php?token=' . urlencode($inv['invite_token']);
              $wa_clean = $inv['whatsapp_clean'];
              $wa_msg   = rawurlencode("नमस्ते " . $inv['parent_name'] . "! आपके लिए ₹2,000 का special gift है। इस link से join करें: " . $inv_link);
              $wa_href  = 'https://wa.me/' . $wa_clean . '?text=' . $wa_msg;
              $badge = [
                'pending'   => 'bg-amber-100 text-amber-800',
                'claimed'   => 'bg-emerald-100 text-emerald-800',
                'expired'   => 'bg-slate-200 text-slate-600',
                'cancelled' => 'bg-rose-100 text-rose-700',
              ][$inv['status']] ?? 'bg-slate-100 text-slate-600';
            ?>
              <div class="border border-slate-200 rounded-xl p-3 bg-slate-50">
                <div class="flex items-start justify-between gap-2 flex-wrap">
                  <div>
                    <span class="font-semibold text-slate-800"><?= e($inv['parent_name']) ?></span>
                    <span class="text-xs text-slate-500 ml-2"><?= e($inv['whatsapp_clean']) ?></span>
                  </div>
                  <span class="<?= $badge ?> text-xs font-bold px-2 py-0.5 rounded-full"><?= ucfirst($inv['status']) ?></span>
                </div>
                <div class="text-xs text-slate-500 mt-1">
                  Created <?= e(date('d M H:i', strtotime($inv['created_at']))) ?> ·
                  Expires <?= e(date('d M', strtotime($inv['expires_at']))) ?>
                  · ₹<?= number_format((float)$inv['credit_amount'], 0) ?> credit
                </div>
                <?php if ($inv['status'] === 'pending'): ?>
                  <div class="flex flex-wrap gap-2 mt-2 items-center">
                    <input type="text" readonly value="<?= e($inv_link) ?>"
                           class="flex-1 min-w-0 border border-slate-300 rounded px-2 py-1 text-xs font-mono bg-white"
                           onclick="this.select()">
                    <button type="button" onclick="copyInviteLink(this, <?= json_encode($inv_link) ?>)"
                            class="text-xs bg-slate-600 hover:bg-slate-700 text-white px-2 py-1 rounded font-semibold flex-shrink-0">📋 Copy</button>
                    <a href="<?= e($wa_href) ?>" target="_blank" rel="noopener"
                       class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded font-semibold flex-shrink-0">💬 WhatsApp</a>
                    <form method="post" class="inline" onsubmit="return confirm('Cancel this invite?')">
                      <input type="hidden" name="csrf"       value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action"     value="cancel_invite">
                      <input type="hidden" name="invite_id"  value="<?= (int)$inv['id'] ?>">
                      <input type="hidden" name="partner_id" value="<?= (int)$detail['id'] ?>">
                      <button type="submit" class="text-xs text-rose-600 hover:underline flex-shrink-0">Cancel</button>
                    </form>
                  </div>
                <?php elseif ($inv['status'] === 'claimed'): ?>
                  <div class="text-xs text-emerald-700 mt-1">✓ Claimed <?= $inv['claimed_at'] ? e(date('d M H:i', strtotime($inv['claimed_at']))) : '' ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-xs text-slate-400 italic">No invites yet for this partner.</p>
        <?php endif; ?>
      </div>

      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <h3 class="font-bold text-sm mb-3">📝 Log a message</h3>
        <form method="post" class="space-y-2 text-sm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="log_message">
          <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
          <div class="flex gap-2">
            <select name="direction" class="flex-1 border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="out">📤 Out</option>
              <option value="in">📥 In</option>
            </select>
            <select name="channel" class="flex-1 border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="whatsapp">WhatsApp</option>
              <option value="call">Call</option>
              <option value="email">Email</option>
              <option value="visit">Visit</option>
            </select>
          </div>
          <textarea name="message" required rows="3" placeholder="What was said?" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"></textarea>
          <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-full text-sm font-bold">Log</button>
        </form>
      </div>

      <?php if ($messages): ?>
        <div class="bg-white border border-slate-200 rounded-2xl p-5">
          <h3 class="font-bold text-sm mb-3">📜 Conversation log</h3>
          <ul class="space-y-3 text-sm">
            <?php foreach ($messages as $m): ?>
              <li class="border-l-2 <?= $m['direction'] === 'out' ? 'border-indigo-400' : 'border-emerald-400' ?> pl-3">
                <div class="text-xs text-slate-500">
                  <?= $m['direction'] === 'out' ? '📤 OUT' : '📥 IN' ?> ·
                  <?= e(ucfirst($m['channel'] ?? '')) ?> ·
                  <?= e(date('d M H:i', strtotime($m['created_at']))) ?>
                </div>
                <div class="whitespace-pre-wrap mt-1 text-slate-700"><?= e($m['message']) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: /* ═══ LIST VIEW ═══ */ ?>

  <!-- Stat cards -->
  <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-5">
    <div class="bg-white border border-slate-200 rounded-2xl p-3 text-center">
      <div class="text-xs uppercase text-slate-500 font-semibold">Total</div>
      <div class="text-2xl font-bold text-slate-700"><?= (int)$stats['total'] ?></div>
    </div>
    <a href="?status=cold" class="bg-rose-50 border border-rose-200 rounded-2xl p-3 text-center hover:border-rose-400">
      <div class="text-xs uppercase text-rose-700 font-semibold">Cold</div>
      <div class="text-2xl font-bold text-rose-600"><?= (int)$stats['cold'] ?></div>
    </a>
    <a href="?status=messaged" class="bg-sky-50 border border-sky-200 rounded-2xl p-3 text-center hover:border-sky-400">
      <div class="text-xs uppercase text-sky-700 font-semibold">Messaged</div>
      <div class="text-2xl font-bold text-sky-600"><?= (int)$stats['messaged'] ?></div>
    </a>
    <a href="?status=interested" class="bg-amber-50 border border-amber-200 rounded-2xl p-3 text-center hover:border-amber-400">
      <div class="text-xs uppercase text-amber-700 font-semibold">Interested</div>
      <div class="text-2xl font-bold text-amber-600"><?= (int)$stats['interested'] ?></div>
    </a>
    <a href="?status=partner" class="bg-emerald-50 border border-emerald-200 rounded-2xl p-3 text-center hover:border-emerald-400">
      <div class="text-xs uppercase text-emerald-700 font-semibold">Partners</div>
      <div class="text-2xl font-bold text-emerald-600"><?= (int)$stats['partner'] ?></div>
    </a>
    <div class="bg-slate-50 border border-slate-200 rounded-2xl p-3 text-center">
      <div class="text-xs uppercase text-slate-500 font-semibold">Closed</div>
      <div class="text-2xl font-bold text-slate-500"><?= (int)$stats['declined'] + (int)$stats['unreachable'] ?></div>
    </div>
  </div>

  <!-- Group filter pills (clinical / educational / other) -->
  <div class="flex flex-wrap gap-2 mb-3 text-xs">
    <a href="/admin/partners.php" class="<?= $filter_group === '' ? 'bg-slate-700 text-white' : 'bg-white border border-slate-300 text-slate-600 hover:bg-slate-50' ?> px-3 py-1.5 rounded-full font-semibold">All</a>
    <a href="?group=clinical" class="<?= $filter_group === 'clinical' ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-300 text-slate-600 hover:bg-slate-50' ?> px-3 py-1.5 rounded-full font-semibold">🩺 Clinical (<?= $group_counts['clinical'] ?>)</a>
    <a href="?group=educational" class="<?= $filter_group === 'educational' ? 'bg-emerald-600 text-white' : 'bg-white border border-slate-300 text-slate-600 hover:bg-slate-50' ?> px-3 py-1.5 rounded-full font-semibold">📚 Educational (<?= $group_counts['educational'] ?>)</a>
    <a href="?category=other" class="<?= $filter_cat === 'other' ? 'bg-slate-600 text-white' : 'bg-white border border-slate-300 text-slate-600 hover:bg-slate-50' ?> px-3 py-1.5 rounded-full font-semibold">Other (<?= $group_counts['other'] ?>)</a>
    <?php if ($filter_status || $filter_area || $filter_cat || $filter_group): ?>
      <a href="/admin/partners.php" class="text-xs text-rose-500 hover:underline self-center ml-2">× Clear filters</a>
    <?php endif; ?>
  </div>

  <!-- Partner table -->
  <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
    <?php if (!$partners): ?>
      <div class="p-12 text-center">
        <div class="text-5xl mb-3">🤝</div>
        <p class="text-slate-600 font-bold">No partners match these filters.</p>
        <a href="/admin/partners.php" class="text-indigo-600 hover:underline text-sm">Clear filters</a>
      </div>
    <?php else: ?>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500 text-left">
          <tr>
            <th class="px-4 py-3">Partner</th>
            <th class="px-4 py-3">Code</th>
            <th class="px-4 py-3">Category</th>
            <th class="px-4 py-3">Area</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($partners as $p):
            $wa_en = partner_whatsapp_url($p, 'en');
            $wa_hi = partner_whatsapp_url($p, 'hi');
            $is_edu = partner_is_educational($p['category'] ?? 'other');
          ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-3">
                <a href="?id=<?= (int)$p['id'] ?>" class="font-bold hover:underline"><?= e($p['name']) ?></a>
                <div class="text-xs text-slate-500">
                  <?= e($p['contact_person'] ?: '—') ?>
                  <?php if ($p['rating']): ?> · ⭐ <?= number_format((float)$p['rating'], 1) ?><?php endif; ?>
                </div>
              </td>
              <td class="px-4 py-3 font-mono text-xs"><?= e($p['referral_code']) ?></td>
              <td class="px-4 py-3">
                <span class="<?= $is_edu ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-100 text-indigo-700' ?> px-2 py-0.5 rounded text-xs font-semibold">
                  <?= e($cats[$p['category']] ?? $p['category'] ?? 'other') ?>
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-slate-600"><?= e($p['area'] ?: '—') ?></td>
              <td class="px-4 py-3">
                <span class="<?= partner_status_classes($p['status'] ?? 'cold', 'tag') ?> text-xs font-bold px-2 py-0.5 rounded uppercase"><?= e($p['status']) ?></span>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <?php if ($wa_en): ?>
                  <a href="<?= e($wa_en) ?>" target="_blank" rel="noopener"
                     onclick="markMessaged(<?= (int)$p['id'] ?>)"
                     title="<?= $is_edu ? 'Educational pitch' : 'Clinical pitch' ?>"
                     class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs font-bold inline-block">📲 EN</a>
                  <a href="<?= e($wa_hi) ?>" target="_blank" rel="noopener"
                     onclick="markMessaged(<?= (int)$p['id'] ?>)"
                     class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs font-bold inline-block">HI</a>
                <?php else: ?>
                  <span class="text-slate-400 text-xs italic">no contact</span>
                <?php endif; ?>
                <a href="?id=<?= (int)$p['id'] ?>" class="bg-indigo-600 text-white px-2 py-1 rounded text-xs font-bold inline-block">📋 Long</a>
                <?php if (($p['status'] ?? '') === 'active'): ?>
                  <a href="/partner-dashboard.php?_preview=<?= (int)$p['id'] ?>"
                     target="_blank"
                     class="bg-violet-600 hover:bg-violet-700 text-white px-2 py-1 rounded text-xs font-bold inline-block">👁 Account</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<script>
const CSRF = '<?= e(csrf_token()) ?>';
function markMessaged(id) {
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('action', 'update_status');
  fd.append('id', id);
  fd.append('status', 'messaged');
  fd.append('ajax', '1');
  fetch('/admin/partners.php', { method: 'POST', body: fd, keepalive: true });
}
function copyInviteLink(btn, url) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(function() {
      var orig = btn.textContent;
      btn.textContent = '✓ Copied';
      setTimeout(function(){ btn.textContent = orig; }, 2000);
    });
  } else {
    var t = document.createElement('textarea');
    t.value = url; document.body.appendChild(t); t.select();
    document.execCommand('copy'); document.body.removeChild(t);
    btn.textContent = '✓ Copied';
    setTimeout(function(){ btn.textContent = '📋 Copy'; }, 2000);
  }
}
</script>

<?php admin_layout_close(); ?>
