<?php
/**
 * partner-dashboard.php
 *
 * Phase 1: Welcome screen with key stats and an "Add new family" CTA.
 * Phase 2 will add: list of registered children, pending ISAA assessments,
 * "Conduct ISAA" button.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/partner_auth.php';

// Admin preview: ?_preview=ID sets partner session without OTP
if (!empty($_GET['_preview']) && !empty($_SESSION['admin_id'])) {
    $pst = db()->prepare("SELECT id, status FROM partners WHERE id = ?");
    $pst->execute([(int)$_GET['_preview']]);
    $prev = $pst->fetch(PDO::FETCH_ASSOC);
    if ($prev && $prev['status'] === 'active') {
        $_SESSION['partner_id'] = (int)$prev['id'];
    }
}

$partner = require_partner();


/* ── Referral link stats ── */
$referral_link = 'https://empowerstudents.in/p.php?ref=' . $partner['referral_code'];
$referred_count = 0;
$referred_parents = [];
try {
    $rct = db()->prepare("SELECT COUNT(*) FROM parents WHERE partner_id=?");
    $rct->execute([(int)$partner['id']]);
    $referred_count = (int)$rct->fetchColumn();
    $rps = db()->prepare("SELECT id, name, whatsapp, credits, created_at FROM parents
        WHERE partner_id=? ORDER BY id DESC LIMIT 20");
    $rps->execute([(int)$partner['id']]);
    $referred_parents = $rps->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_) {}

$is_admin_preview = !empty($_GET['_preview']) && !empty($_SESSION['admin_id']);

/* ── Account state for admin preview ── */
$invite_count   = 0;
$invite_pending = 0;
if ($is_admin_preview) {
    try {
        $ist = db()->prepare("SELECT COUNT(*) FROM parent_invites WHERE partner_id=?");
        $ist->execute([(int)$partner['id']]);
        $invite_count = (int)$ist->fetchColumn();
        $ipt = db()->prepare("SELECT COUNT(*) FROM parent_invites WHERE partner_id=? AND status='pending'");
        $ipt->execute([(int)$partner['id']]);
        $invite_pending = (int)$ipt->fetchColumn();
    } catch (Throwable $_) {}
}

$page_title = 'Partner Dashboard — EmpowerStudents';
$page_description = 'Your partner dashboard.';

// Stats — all wrapped in try/catch; tables may not exist yet
$child_count      = 0;
$pending_isaa     = 0;
$done_isaa        = 0;
$earnings_credits = 0.0;
$children_rows    = [];

try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM children WHERE registered_by_partner_id = ?");
    $stmt->execute([(int)$partner['id']]);
    $child_count = (int)$stmt->fetchColumn();
} catch (Throwable $_) {}

try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM isaa_assessments
                           WHERE partner_id = ? AND status IN ('paid','in_progress')");
    $stmt->execute([(int)$partner['id']]);
    $pending_isaa = (int)$stmt->fetchColumn();
} catch (Throwable $_) {}

try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM isaa_assessments
                           WHERE partner_id = ? AND status = 'submitted'");
    $stmt->execute([(int)$partner['id']]);
    $done_isaa = (int)$stmt->fetchColumn();
} catch (Throwable $_) {}

try {
    $stmt = db()->prepare("SELECT COALESCE(SUM(partner_amount), 0) FROM partner_payouts WHERE partner_id = ?");
    $stmt->execute([(int)$partner['id']]);
    $earnings_credits = (float)$stmt->fetchColumn();
} catch (Throwable $_) {}

try {
    $ch = db()->prepare("SELECT c.*, p.name AS parent_name, p.whatsapp AS parent_whatsapp
                         FROM children c
                         LEFT JOIN parents p ON p.id = c.parent_id
                         WHERE c.registered_by_partner_id = ?
                         ORDER BY c.id DESC LIMIT 10");
    $ch->execute([(int)$partner['id']]);
    $children_rows = $ch->fetchAll();
} catch (Throwable $_) {}

require __DIR__ . '/includes/header.php';
?>

<main class="max-w-5xl mx-auto px-4 py-8">

  <?php if ($is_admin_preview): ?>
  <div class="bg-amber-100 border-2 border-amber-400 rounded-xl px-4 py-3 mb-4 flex items-center justify-between flex-wrap gap-2">
    <div class="text-sm font-bold text-amber-900">
      🔑 Admin preview — viewing as <span class="font-mono"><?= e($partner['referral_code']) ?></span>
    </div>
    <div class="flex gap-4 text-xs text-amber-800 flex-wrap">
      <span>Status: <strong><?= e($partner['status']) ?></strong></span>
      <span>Last login: <strong><?= $partner['last_login_at'] ? e(date('d M H:i', strtotime($partner['last_login_at']))) : 'Never' ?></strong></span>
      <span>Invites: <strong><?= $invite_count ?> total · <?= $invite_pending ?> pending</strong></span>
      <span>WhatsApp: <strong><?= e($partner['whatsapp'] ?: '—') ?></strong></span>
    </div>
    <a href="/admin/partners.php?id=<?= (int)$partner['id'] ?>"
       class="text-xs bg-amber-600 text-white px-3 py-1 rounded font-bold hover:bg-amber-700">
      ← Back to admin
    </a>
  </div>
  <?php endif; ?>
  <div class="bg-white rounded-2xl border border-slate-200 p-6 md:p-8">

    <!-- Header -->
    <div class="flex items-baseline justify-between gap-4 mb-6 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Welcome, <?= e($partner['name']) ?></h1>
        <p class="text-slate-600 text-sm">
          Partner code: <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs"><?= e($partner['referral_code']) ?></code>
          · Status: <span class="text-emerald-700 font-semibold"><?= e($partner['status']) ?></span>
          <?php if ((int)$partner['can_administer_isaa'] === 1): ?>
            · ✓ ISAA-certified
          <?php endif; ?>
        </p>
      </div>
      <a href="/partner-logout.php" class="text-sm text-slate-500 hover:text-rose-600 hover:underline">Sign out</a>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
      <div class="border border-slate-200 rounded-xl p-4 bg-slate-50">
        <p class="text-xs uppercase tracking-wider text-slate-500 mb-1">Registered families</p>
        <p class="text-2xl font-bold text-slate-900"><?= $child_count ?></p>
      </div>
      <div class="border border-amber-200 rounded-xl p-4 bg-amber-50">
        <p class="text-xs uppercase tracking-wider text-amber-700 mb-1">Pending ISAA</p>
        <p class="text-2xl font-bold text-amber-900"><?= $pending_isaa ?></p>
      </div>
      <div class="border border-emerald-200 rounded-xl p-4 bg-emerald-50">
        <p class="text-xs uppercase tracking-wider text-emerald-700 mb-1">Completed ISAA</p>
        <p class="text-2xl font-bold text-emerald-900"><?= $done_isaa ?></p>
      </div>
      <div class="border border-indigo-200 rounded-xl p-4 bg-indigo-50">
        <p class="text-xs uppercase tracking-wider text-indigo-700 mb-1">Earnings (₹)</p>
        <p class="text-2xl font-bold text-indigo-900"><?= number_format($earnings_credits, 0) ?></p>
      </div>
    </div>

    <!-- Phase 1 notice -->
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900 mb-6">
      <strong>You're in Phase 1.</strong>
      The ISAA assessment tool is launching soon. You can already register new families today;
      once the tool is live, you'll see a "Conduct ISAA" button next to each pending assessment.
    </div>

    <!-- Quick actions -->
    <div class="flex flex-wrap gap-3 mb-8">
      <a href="/partner-add-family.php"
         class="brand-grad text-white font-semibold px-5 py-2.5 rounded-lg hover:opacity-90">
        + Add new family
      </a>
      <?php if ((int)$partner['can_administer_isaa'] === 1 && $pending_isaa > 0): ?>
        <a href="/partner-isaa-queue.php"
           class="bg-amber-600 text-white font-semibold px-5 py-2.5 rounded-lg hover:bg-amber-700">
          🧠 Conduct pending ISAA (<?= $pending_isaa ?>)
        </a>
      <?php endif; ?>
    </div>

    <!-- ── Partner Referral Link ── -->
    <div class="bg-white border-2 border-emerald-300 rounded-2xl p-5 mb-6">
      <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
        <div>
          <h2 class="text-lg font-bold text-slate-900">🔗 Your Partner Referral Link</h2>
          <p class="text-sm text-slate-500 mt-1">
            Share this link with anyone. Every new parent who signs up via your link gets
            <strong class="text-emerald-700">₹2,000 free credits</strong> automatically —
            no code needed. All attributed to you.
          </p>
        </div>
        <div class="text-center bg-emerald-50 border border-emerald-200 rounded-xl px-5 py-3 flex-shrink-0">
          <div class="text-2xl font-bold text-emerald-700"><?= $referred_count ?></div>
          <div class="text-xs text-emerald-600 font-semibold">Parents referred</div>
        </div>
      </div>

      <!-- Link display -->
      <div class="flex gap-2 items-center flex-wrap mb-4">
        <input type="text" readonly value="<?= e($referral_link) ?>"
               class="flex-1 min-w-0 border-2 border-emerald-300 bg-emerald-50 rounded-xl px-4 py-3 text-sm font-mono font-bold"
               onclick="this.select()">
        <button onclick="navigator.clipboard.writeText('<?= e($referral_link) ?>').then(()=>{ this.textContent='✓ Copied!'; setTimeout(()=>{ this.textContent='📋 Copy link'; },2500); })"
                class="bg-slate-700 hover:bg-slate-800 text-white font-bold px-4 py-3 rounded-xl text-sm flex-shrink-0">
          📋 Copy link
        </button>
        <?php
          $wa_msg = "\xF0\x9F\x8C\x9F *EmpowerStudents.in*\n"
                  . "_Child Assessment & Development_\n\n"
                  . "Recommended by *" . $partner['name'] . "*\n\n"
                  . "Get *\xE2\x82\xB92,000 free credits* to assess your child\xe2\x80\x99s development!\n\n"
                  . "---\n\n"
                  . "*\xF0\x9F\x93\xB2 How to claim \xe2\x80\x94 2 easy steps:*\n\n"
                  . "*Step 1* \xe2\x80\x94 Open this link:\n"
                  . $referral_link . "\n\n"
                  . "*Step 2* \xe2\x80\x94 Enter your WhatsApp number and verify OTP.\n"
                  . "Your \xE2\x82\xB92,000 credit is added instantly!\n\n"
                  . "---\n\n"
                  . "*Use credits for:*\n"
                  . "\xe2\x80\xa2 Child development evaluation\n"
                  . "\xe2\x80\xa2 Behaviour & learning assessment\n"
                  . "\xe2\x80\xa2 7-day home guidance course\n\n"
                  . "Need help? WhatsApp: *+91-9311883132*\n\n"
                  . "_Team EmpowerStudents.in_";
          $wa_enc = rawurlencode($wa_msg);
          $wa_js  = json_encode($wa_msg);
        ?>
        <button onclick="navigator.clipboard.writeText(<?= $wa_js ?>).then(()=>{ this.textContent='✓ Copied!'; setTimeout(()=>{ this.textContent='📋 Copy message'; },2500); })"
                class="bg-slate-600 hover:bg-slate-700 text-white font-bold px-4 py-3 rounded-xl text-sm flex-shrink-0">
          📋 Copy message
        </button>
        <a href="https://wa.me/?text=<?= $wa_enc ?>" target="_blank"
           class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-3 rounded-xl text-sm flex-shrink-0">
          💬 WhatsApp
        </a>
      </div>

      <div class="bg-slate-50 rounded-xl p-3 text-xs text-slate-600 flex flex-wrap gap-4">
        <span>✅ Multi-use — share with anyone</span>
        <span>✅ Never expires</span>
        <span>✅ Auto-credits ₹2,000 on signup</span>
        <span>✅ All attributed to you</span>
        <span>✅ You earn commission on paid courses</span>
      </div>

      <?php if (!empty($referred_parents)): ?>
        <div class="mt-4 pt-4 border-t border-slate-100">
          <h3 class="text-sm font-bold text-slate-700 mb-2">👥 Parents referred (<?= count($referred_parents) ?>)</h3>
          <div class="overflow-x-auto">
            <table class="w-full text-xs">
              <thead>
                <tr class="text-[11px] uppercase tracking-wider text-slate-400 border-b">
                  <th class="py-1.5 pr-3 text-left">Name</th>
                  <th class="py-1.5 pr-3 text-left">WhatsApp</th>
                  <th class="py-1.5 pr-3 text-left">Joined</th>
                  <th class="py-1.5 text-right">Wallet</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($referred_parents as $rp): ?>
                  <tr class="border-b border-slate-100">
                    <td class="py-1.5 pr-3 font-semibold text-slate-800"><?= e($rp['name'] ?: '—') ?></td>
                    <td class="py-1.5 pr-3 text-slate-500"><?= e($rp['whatsapp'] ?? '—') ?></td>
                    <td class="py-1.5 pr-3 text-slate-500"><?= date('d M', strtotime($rp['created_at'])) ?></td>
                    <td class="py-1.5 text-right text-emerald-700 font-semibold">₹<?= number_format((int)($rp['credits'] ?? 0)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php else: ?>
        <p class="text-xs text-slate-400 italic mt-3">No parents referred yet. Share your link to get started!</p>
      <?php endif; ?>
    </div>

    <!-- Recent families -->
    <h2 class="text-lg font-bold text-slate-900 mb-3">Your registered families</h2>
    <?php if (empty($children_rows)): ?>
      <p class="text-sm text-slate-500 italic">
        No families registered under you yet. Click <strong>+ Add new family</strong> to register a parent and child.
      </p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm border border-slate-200 rounded-lg overflow-hidden">
          <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
            <tr>
              <th class="text-left px-3 py-2">Child</th>
              <th class="text-left px-3 py-2">DOB</th>
              <th class="text-left px-3 py-2">Parent</th>
              <th class="text-left px-3 py-2">WhatsApp</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($children_rows as $c): ?>
              <tr class="border-t border-slate-100">
                <td class="px-3 py-2 font-semibold text-slate-900"><?= e($c['name']) ?></td>
                <td class="px-3 py-2 text-slate-600"><?= e($c['dob']) ?></td>
                <td class="px-3 py-2 text-slate-700"><?= e($c['parent_name'] ?? '—') ?></td>
                <td class="px-3 py-2 text-slate-500 font-mono"><?= e($c['parent_whatsapp'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
