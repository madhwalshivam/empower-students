<?php
/**
 * partner-add-family.php
 *
 * Lets an active partner register a new parent + child in one form.
 * If parent's WhatsApp number already exists, links to that parent.
 * Child is auto-flagged with registered_by_partner_id.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/partner_auth.php';

$partner = require_partner();

$page_title = 'Add new family — EmpowerStudents Partner';
$page_description = 'Register a parent and child under your partner account.';

$flash_error = '';
$prefill = [
    'parent_name'     => '',
    'parent_whatsapp' => '',
    'parent_email'    => '',
    'child_name'      => '',
    'child_dob'       => '',
    'child_gender'    => '',
    'child_school'    => '',
    'child_class'     => '',
    'child_mt'        => '',
    'child_diagnosis' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    foreach ($prefill as $k => $_v) $prefill[$k] = trim((string)($_POST[$k] ?? ''));

    $pwa  = preg_replace('/\D/', '', $prefill['parent_whatsapp']);
    $pname = $prefill['parent_name'];
    $cname = $prefill['child_name'];
    $cdob  = $prefill['child_dob'];

    if ($pname === '' || strlen($pwa) < 10) {
        $flash_error = 'Parent name and WhatsApp number (10+ digits) are required.';
    } elseif ($cname === '' || $cdob === '') {
        $flash_error = 'Child name and date of birth are required.';
    } else {
        try {
            db()->beginTransaction();

            // Find or create parent (existing parents table)
            $st = db()->prepare("SELECT id FROM parents WHERE whatsapp = ?");
            $st->execute([$pwa]);
            $parent_id = (int) ($st->fetchColumn() ?: 0);

            if (!$parent_id) {
                db()->prepare("INSERT INTO parents (whatsapp, name, email)
                               VALUES (?, ?, ?)")
                   ->execute([$pwa, $pname, $prefill['parent_email'] ?: null]);
                $parent_id = (int) db()->lastInsertId();
            }

            // Insert child, flagged with this partner
            db()->prepare("INSERT INTO children
                (parent_id, registered_by_partner_id, name, gender, dob, school, class_grade, mother_tongue, diagnosis)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $parent_id,
                   (int)$partner['id'],
                   $cname,
                   $prefill['child_gender'] ?: null,
                   $cdob,
                   $prefill['child_school'] ?: null,
                   $prefill['child_class'] ?: null,
                   $prefill['child_mt'] ?: null,
                   $prefill['child_diagnosis'] ?: null,
               ]);
            $child_id = (int) db()->lastInsertId();

            db()->commit();

            $_SESSION['flash_ok'] = "Registered: {$cname} (under parent {$pname}). The parent can sign in at empowerstudents.in with WhatsApp {$pwa}.";
            header('Location: /partner-dashboard.php');
            exit;
        } catch (Throwable $e) {
            try { db()->rollBack(); } catch (Throwable $e2) {}
            error_log('[partner-add-family] ' . $e->getMessage());
            $flash_error = 'Could not register the family. Please try again.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<main class="max-w-2xl mx-auto px-4 py-8">
  <div class="bg-white rounded-2xl border border-slate-200 p-6 md:p-8">
    <div class="flex items-baseline justify-between gap-3 mb-4 flex-wrap">
      <h1 class="text-2xl font-bold text-slate-900">Add new family</h1>
      <a href="/partner-dashboard.php" class="text-sm text-slate-500 hover:text-indigo-600 hover:underline">← Dashboard</a>
    </div>

    <p class="text-slate-600 text-sm mb-6">
      Register a parent and child under your partner account. The child will be flagged as yours,
      so any ISAA assessment booked for them will be assigned to you automatically.
    </p>

    <?php if ($flash_error): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4">
        <?= e($flash_error) ?>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-6">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <fieldset>
        <legend class="text-sm font-semibold text-slate-700 mb-2">Parent details</legend>
        <div class="space-y-3">
          <div>
            <label class="block text-xs text-slate-600 mb-1">Parent's full name *</label>
            <input type="text" name="parent_name" required value="<?= e($prefill['parent_name']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
          </div>
          <div>
            <label class="block text-xs text-slate-600 mb-1">Parent's WhatsApp number *</label>
            <input type="tel" name="parent_whatsapp" required value="<?= e($prefill['parent_whatsapp']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                   placeholder="9876543210">
            <p class="text-xs text-slate-500 mt-1">If a parent with this number already exists, we'll link to them.</p>
          </div>
          <div>
            <label class="block text-xs text-slate-600 mb-1">Parent's email (optional)</label>
            <input type="email" name="parent_email" value="<?= e($prefill['parent_email']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend class="text-sm font-semibold text-slate-700 mb-2">Child details</legend>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2">
            <label class="block text-xs text-slate-600 mb-1">Child's full name *</label>
            <input type="text" name="child_name" required value="<?= e($prefill['child_name']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
          </div>
          <div>
            <label class="block text-xs text-slate-600 mb-1">Date of birth *</label>
            <input type="date" name="child_dob" required value="<?= e($prefill['child_dob']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
          </div>
          <div>
            <label class="block text-xs text-slate-600 mb-1">Gender</label>
            <select name="child_gender"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
              <option value="">—</option>
              <option value="male"   <?= $prefill['child_gender'] === 'male' ? 'selected' : '' ?>>Male</option>
              <option value="female" <?= $prefill['child_gender'] === 'female' ? 'selected' : '' ?>>Female</option>
              <option value="other"  <?= $prefill['child_gender'] === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
          <div>
            <label class="block text-xs text-slate-600 mb-1">School</label>
            <input type="text" name="child_school" value="<?= e($prefill['child_school']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
          </div>
          <div>
            <label class="block text-xs text-slate-600 mb-1">Class / grade</label>
            <input type="text" name="child_class" value="<?= e($prefill['child_class']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                   placeholder="e.g. 5th, Nursery">
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs text-slate-600 mb-1">Mother tongue</label>
            <input type="text" name="child_mt" value="<?= e($prefill['child_mt']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                   placeholder="Hindi, English, Marathi…">
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs text-slate-600 mb-1">Existing diagnosis (if any)</label>
            <input type="text" name="child_diagnosis" value="<?= e($prefill['child_diagnosis']) ?>"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                   placeholder="e.g. ASD (suspected), Speech delay">
          </div>
        </div>
      </fieldset>

      <div class="flex items-center gap-3">
        <button class="brand-grad text-white font-semibold px-6 py-2.5 rounded-lg hover:opacity-90">
          Register family
        </button>
        <a href="/partner-dashboard.php" class="text-sm text-slate-500 hover:underline">Cancel</a>
      </div>
    </form>
  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
