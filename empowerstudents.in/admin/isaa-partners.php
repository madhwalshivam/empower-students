<?php
/**
 * admin/isaa-partners.php
 *
 * ISAA-specific partner controls. This is a SEPARATE page from the existing
 * admin/partners.php (which is the partner CRM / referral flow) so we don't
 * risk regressing the existing pitch / outreach functionality.
 *
 * Features:
 *   • List all partners with their ISAA capability + login status
 *   • Send password setup link (generates token, builds WhatsApp click-to-send)
 *   • Toggle can_administer_isaa
 */

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../includes/catalogue.php';     // loads isaa_schema cascade
require_once __DIR__ . '/../includes/partner_auth.php';

// Determine canonical site URL for building the magic link
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'empowerstudents.in');

$flash_ok    = '';
$flash_error = '';
$show_link   = '';   // when admin clicks "Generate setup link", we show the WhatsApp URL inline

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $action     = (string)($_POST['action'] ?? '');
    $partner_id = (int)($_POST['partner_id'] ?? 0);
    if ($partner_id <= 0) {
        $flash_error = 'No partner selected.';
    } else {
        $st = db()->prepare("SELECT * FROM partners WHERE id = ?");
        $st->execute([$partner_id]);
        $partner = $st->fetch();
        if (!$partner) {
            $flash_error = 'Partner not found.';
        } elseif ($action === 'generate_setup_link') {
            $token = partner_generate_setup_token($partner_id);
            $wa_url = partner_setup_whatsapp_url(
                (string)$partner['whatsapp'], (string)$partner['name'],
                $token, $base_url
            );
            $direct = rtrim($base_url, '/') . '/partner-set-password.php?token=' . urlencode($token);
            $show_link = "<strong>Setup link generated.</strong><br>"
                       . "Direct URL: <code class='text-xs break-all'>" . e($direct) . "</code><br>"
                       . "<a href='" . e($wa_url) . "' target='_blank' class='brand-grad text-white px-4 py-2 rounded-lg inline-block mt-2'>📲 Send via WhatsApp</a>"
                       . "<p class='text-xs text-slate-500 mt-2'>Valid for 24 hours. After expiry, generate a fresh link.</p>";
            $flash_ok = "Magic link generated for " . e($partner['name']) . " (" . e($partner['whatsapp']) . ").";
        } elseif ($action === 'toggle_isaa') {
            $new = ((int)$partner['can_administer_isaa'] === 1) ? 0 : 1;
            db()->prepare("UPDATE partners SET can_administer_isaa = ? WHERE id = ?")
               ->execute([$new, $partner_id]);
            $flash_ok = $partner['name'] . ($new ? ' is now ISAA-certified.' : '\'s ISAA capability has been removed.');
        } elseif ($action === 'clear_password') {
            db()->prepare("UPDATE partners SET password_hash = NULL WHERE id = ?")
               ->execute([$partner_id]);
            $flash_ok = "Password cleared for {$partner['name']}. They must use a fresh setup link.";
        } else {
            $flash_error = 'Unknown action.';
        }
    }
}

// List partners with ISAA-relevant info
$rows = db()->query("SELECT p.*,
                            (SELECT COUNT(*) FROM children c WHERE c.registered_by_partner_id = p.id) AS children_count,
                            (SELECT COUNT(*) FROM isaa_assessments a WHERE a.partner_id = p.id AND a.status = 'submitted') AS isaa_done
                     FROM partners p
                     ORDER BY p.status ASC, p.name ASC")->fetchAll();

$page_title = 'ISAA Partners — Admin';
admin_layout_open($page_title);
?>

  <div class="flex items-baseline justify-between gap-3 mb-4 flex-wrap">
    <h1 class="text-2xl font-bold text-slate-900">ISAA Partners</h1>
    <a href="/admin/index.php" class="text-sm text-slate-500 hover:text-indigo-600 hover:underline">← Admin home</a>
  </div>

  <p class="text-slate-600 text-sm mb-4">
    Manage partner login + ISAA assessment capability. To onboard a new partner, first add them in
    <a href="/admin/partners.php" class="text-indigo-600 hover:underline">Partners (CRM)</a>,
    then return here to send their password setup link and toggle ISAA capability.
  </p>

  <?php if ($flash_ok): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-sm mb-3"><?= $flash_ok ?></div>
  <?php endif; ?>
  <?php if ($flash_error): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-3"><?= e($flash_error) ?></div>
  <?php endif; ?>
  <?php if ($show_link): ?>
    <div class="bg-amber-50 border border-amber-300 rounded-lg p-4 text-sm mb-4 text-amber-900"><?= $show_link ?></div>
  <?php endif; ?>

  <div class="overflow-x-auto bg-white rounded-2xl border border-slate-200">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
        <tr>
          <th class="text-left px-3 py-2">Name</th>
          <th class="text-left px-3 py-2">WhatsApp</th>
          <th class="text-left px-3 py-2">Status</th>
          <th class="text-left px-3 py-2">Login</th>
          <th class="text-left px-3 py-2">ISAA</th>
          <th class="text-left px-3 py-2">Stats</th>
          <th class="text-left px-3 py-2">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $has_pwd = !empty($r['password_hash']);
          $has_token = !empty($r['password_setup_token']);
          $can_isaa = ((int)$r['can_administer_isaa'] === 1);
        ?>
          <tr class="border-t border-slate-100">
            <td class="px-3 py-2 font-semibold text-slate-900"><?= e($r['name']) ?>
              <div class="text-xs text-slate-400 font-normal"><?= e($r['referral_code']) ?></div>
            </td>
            <td class="px-3 py-2 font-mono text-xs"><?= e($r['whatsapp'] ?: '—') ?></td>
            <td class="px-3 py-2">
              <span class="text-xs px-2 py-0.5 rounded <?= $r['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                <?= e($r['status']) ?>
              </span>
            </td>
            <td class="px-3 py-2 text-xs">
              <?php if ($has_pwd): ?>
                <span class="text-emerald-700">✓ Active</span>
              <?php elseif ($has_token): ?>
                <span class="text-amber-700">⏳ Link sent</span>
              <?php else: ?>
                <span class="text-slate-400">— No password</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2 text-xs">
              <?php if ($can_isaa): ?>
                <span class="text-emerald-700 font-semibold">✓ Certified</span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2 text-xs text-slate-600">
              <?= (int)$r['children_count'] ?> kids · <?= (int)$r['isaa_done'] ?> ISAA done
            </td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap gap-1">
                <form method="post" class="m-0 inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="partner_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="generate_setup_link">
                  <button class="text-xs bg-indigo-600 text-white px-2 py-1 rounded hover:bg-indigo-700"
                          title="Generate fresh password-setup link (24h validity) and get WhatsApp share URL">
                    🔗 Send link
                  </button>
                </form>
                <form method="post" class="m-0 inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="partner_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="toggle_isaa">
                  <button class="text-xs <?= $can_isaa ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' ?> px-2 py-1 rounded">
                    <?= $can_isaa ? '✕ Remove ISAA' : '✓ Allow ISAA' ?>
                  </button>
                </form>
                <?php if ($has_pwd): ?>
                  <form method="post" class="m-0 inline" onsubmit="return confirm('Clear this partner\'s password? They\'ll need a fresh setup link to log in again.')">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="partner_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="action" value="clear_password">
                    <button class="text-xs text-slate-500 hover:text-rose-600">🔄 Reset</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500 italic">
            No partners yet. Add them via the <a href="/admin/partners.php" class="text-indigo-600 hover:underline">Partners CRM</a>.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php admin_layout_close(); ?>
