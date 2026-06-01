<?php
require_once __DIR__ . '/includes/auth.php';
require_parent();
$page_title = 'Add child';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } else {
        $name   = trim($_POST['name'] ?? '');
        $dob    = trim($_POST['dob'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $school = trim($_POST['school'] ?? '');
        $cls    = trim($_POST['class_grade'] ?? '');
        $mt     = trim($_POST['mother_tongue'] ?? '');
        $langs  = trim($_POST['languages'] ?? '');
        $dx     = trim($_POST['diagnosis'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');

        if ($name === '' || $dob === '') {
            $error = 'Name and date of birth are required.';
        } else {
            $st = db()->prepare("INSERT INTO children
                (parent_id, name, gender, dob, school, class_grade, mother_tongue, languages, diagnosis, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                current_parent()['id'], $name, $gender ?: null, $dob,
                $school ?: null, $cls ?: null, $mt ?: null, $langs ?: null,
                $dx ?: null, $notes ?: null,
            ]);
            $cid = (int) db()->lastInsertId();
            header('Location: /child.php?id=' . $cid);
            exit;
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="max-w-xl mx-auto bg-white rounded-2xl shadow-sm border border-slate-100 p-6 sm:p-8">
  <h1 class="text-2xl font-bold mb-1" data-i18n="addc.title">Add a child</h1>
  <p class="text-sm text-slate-500 mb-6" data-i18n="addc.intro">Just the basics. You can add more details later.</p>

  <?php if ($error): ?>
    <div class="bg-rose-50 text-rose-800 border border-rose-200 rounded-lg px-3 py-2 text-sm mb-4"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" class="space-y-4">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div>
      <label class="block text-sm font-medium mb-1" data-i18n="addc.name">Child&rsquo;s name *</label>
      <input name="name" required maxlength="80"
             class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-sm font-medium mb-1" data-i18n="addc.dob">Date of birth *</label>
        <input type="date" name="dob" required
               class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1" data-i18n="addc.gender">Gender</label>
        <select name="gender" class="w-full border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
          <option value="">—</option>
          <option data-i18n="addc.gender.m">Male</option>
          <option data-i18n="addc.gender.f">Female</option>
          <option data-i18n="addc.gender.o">Other</option>
        </select>
      </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-sm font-medium mb-1" data-i18n="addc.school">School</label>
        <input name="school" maxlength="120"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Class / grade</label>
        <input name="class_grade" maxlength="40" placeholder="e.g. KG, Class 3"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
      </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-sm font-medium mb-1">Mother tongue</label>
        <input name="mother_tongue" maxlength="40" placeholder="Hindi, Bengali, Tamil…"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Languages spoken</label>
        <input name="languages" maxlength="80" placeholder="e.g. Hindi, English"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium mb-1" data-i18n="addc.diagnosis">Known diagnosis (if any)</label>
      <input name="diagnosis" maxlength="200" placeholder="e.g. ASD, ADHD, GDD, none" data-i18n-placeholder="addc.diag.ph"
             class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1" data-i18n="addc.notes">Anything else we should know?</label>
      <textarea name="notes" rows="3"
                class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none"></textarea>
    </div>
    <div class="flex gap-3">
      <a href="/dashboard.php" class="flex-1 text-center border border-slate-300 px-4 py-2.5 rounded-lg hover:bg-slate-50" data-i18n="addc.cancel">Cancel</a>
      <button class="flex-1 brand-grad text-white font-semibold py-2.5 rounded-lg hover:opacity-90" data-i18n="addc.submit">Save &amp; continue</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
