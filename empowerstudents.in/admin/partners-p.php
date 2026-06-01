<?php
// admin/partners.php  v12 – partner management + parent-invite panel
// PHP 7.4 / GoDaddy shared hosting

define('DB_PATH', __DIR__ . '/../db/empowerstudents.db');
require_once __DIR__ . '/../includes/partners.php';

session_start();
$admin_key = 'es2026admin';
if (($_GET['key'] ?? '') !== $admin_key && ($_SESSION['admin'] ?? '') !== $admin_key) {
    die('Unauthorised');
}
$_SESSION['admin'] = $admin_key;

$db   = get_partner_db();
$msg  = '';
$mode = $_GET['mode'] ?? 'list';
$pid  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create / update partner
    if ($action === 'save_partner') {
        $name    = trim($_POST['name']    ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $email   = trim($_POST['email']   ?? '');
        $code    = strtoupper(trim($_POST['code'] ?? ''));
        $rev     = (float)($_POST['revenue_share'] ?? 0.10);
        $status  = $_POST['status'] ?? 'active';
        $edit_id = (int)($_POST['edit_id'] ?? 0);
        if ($name && $code) {
            if ($edit_id) {
                update_partner($edit_id, $name, $contact, $phone, $email, $code, $rev, $status);
                $msg = "✅ Partner updated.";
                $pid = $edit_id;
            } else {
                $new_id = create_partner($name, $contact, $phone, $email, $code, $rev);
                $msg    = "✅ Partner #{$new_id} created.";
                $pid    = $new_id;
            }
        } else {
            $msg = '❌ Name and code required.';
        }
    }

    // Create invite
    if ($action === 'create_invite' && $pid) {
        $pname    = trim($_POST['parent_name'] ?? '');
        $raw_wa   = trim($_POST['whatsapp']    ?? '');
        $wa_clean = preg_replace('/\D/', '', $raw_wa);
        if (strlen($wa_clean) === 10) { $wa_clean = '91' . $wa_clean; }
        if (!$pname || strlen($wa_clean) < 10) {
            $msg = '❌ Parent name and valid WhatsApp number required.';
        } else {
            $result = create_invite($pid, $pname, $wa_clean);
            if (isset($result['error'])) {
                $msg = '❌ ' . htmlspecialchars($result['error']);
            } else {
                $msg = "✅ Invite created for {$pname}.";
            }
        }
    }

    // Cancel invite
    if ($action === 'cancel_invite') {
        $inv_id = (int)($_POST['invite_id'] ?? 0);
        if ($inv_id) {
            $db->prepare("UPDATE parent_invites SET status='cancelled' WHERE id=? AND status='pending'")
               ->execute([$inv_id]);
            $msg = '🚫 Invite cancelled.';
        }
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$partners = get_all_partners();
$partner  = $pid ? get_partner($pid) : null;
$invites  = $pid ? get_partner_invites($pid) : [];

// Base URL for invite links
$base_url = 'https://empowerstudents.in';
// Partner p-page URL
$p_url    = $partner ? "{$base_url}/p/" . urlencode($partner['referral_code']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Partner Admin – EmpowerStudents</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#1a202c;font-size:14px}
.wrap{max-width:1100px;margin:0 auto;padding:20px}
h1{font-size:22px;font-weight:700;margin-bottom:16px;color:#2d3748}
h2{font-size:16px;font-weight:600;margin-bottom:12px;color:#4a5568}
.card{background:#fff;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.msg{padding:10px 14px;border-radius:6px;margin-bottom:14px;font-weight:500;
     background:#e6fffa;border:1px solid #38b2ac;color:#234e52}
.msg.err{background:#fff5f5;border-color:#fc8181;color:#742a2a}
table{width:100%;border-collapse:collapse}
th,td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left}
th{background:#edf2f7;font-weight:600;font-size:12px;color:#718096;text-transform:uppercase}
tr:hover td{background:#f7fafc}
.btn{display:inline-block;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:600;
     cursor:pointer;border:none;text-decoration:none}
.btn-blue{background:#4299e1;color:#fff}
.btn-blue:hover{background:#3182ce}
.btn-green{background:#48bb78;color:#fff}
.btn-green:hover{background:#38a169}
.btn-red{background:#fc8181;color:#fff}
.btn-red:hover{background:#f56565}
.btn-sm{padding:4px 10px;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.badge-green{background:#c6f6d5;color:#22543d}
.badge-yellow{background:#fefcbf;color:#744210}
.badge-red{background:#fed7d7;color:#742a2a}
.badge-gray{background:#e2e8f0;color:#4a5568}
input,select{width:100%;padding:8px;border:1px solid #cbd5e0;border-radius:6px;font-size:13px;margin-bottom:10px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
label{font-size:12px;font-weight:600;color:#718096;display:block;margin-bottom:3px}
.invite-link{font-family:monospace;font-size:11px;background:#f7fafc;padding:6px 8px;
             border-radius:4px;border:1px solid #e2e8f0;word-break:break-all;margin-bottom:6px}
.wa-btn{background:#25d366;color:#fff;border-radius:6px;padding:5px 12px;font-size:12px;
        font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.copy-btn{background:#718096;color:#fff;border-radius:6px;padding:5px 12px;font-size:12px;
          font-weight:600;cursor:pointer;border:none}
.tabs{display:flex;gap:8px;margin-bottom:16px;border-bottom:2px solid #e2e8f0;padding-bottom:0}
.tab{padding:8px 16px;cursor:pointer;border-radius:6px 6px 0 0;font-weight:600;font-size:13px;
     border:1px solid transparent;text-decoration:none;color:#718096}
.tab.active{background:#fff;border-color:#e2e8f0;border-bottom-color:#fff;color:#2d3748}
.back{color:#4299e1;text-decoration:none;font-size:13px;display:inline-block;margin-bottom:12px}
.limit-warn{color:#d69e2e;font-size:12px;font-weight:600}
</style>
</head>
<body>
<div class="wrap">
<h1>🤝 Partner Admin – EmpowerStudents</h1>

<?php if ($msg): ?>
<div class="msg <?php echo strpos($msg,'❌')!==false?'err':'' ?>"><?php echo $msg ?></div>
<?php endif ?>

<?php if (!$partner): ?>
<!-- ── PARTNER LIST ─────────────────────────────────────────────────────── -->
<div class="card">
  <h2>All Partners</h2>
  <table>
    <tr><th>#</th><th>Name</th><th>Code</th><th>Rev%</th><th>Status</th><th>Actions</th></tr>
    <?php foreach($partners as $p): ?>
    <tr>
      <td><?php echo $p['id'] ?></td>
      <td><?php echo htmlspecialchars($p['name']) ?><br>
          <small style="color:#718096"><?php echo htmlspecialchars($p['contact_name']??'') ?></small></td>
      <td><code><?php echo htmlspecialchars($p['referral_code']??'') ?></code></td>
      <td><?php echo round(($p['revenue_share']??0.10)*100) ?>%</td>
      <td>
        <?php if(($p['status']??'active')==='active'): ?>
          <span class="badge badge-green">Active</span>
        <?php else: ?>
          <span class="badge badge-gray">Inactive</span>
        <?php endif ?>
      </td>
      <td>
        <a class="btn btn-blue btn-sm"
           href="?key=<?php echo $admin_key ?>&id=<?php echo $p['id'] ?>">Manage</a>
      </td>
    </tr>
    <?php endforeach ?>
    <?php if(!$partners): ?>
    <tr><td colspan="6" style="text-align:center;color:#a0aec0;padding:20px">No partners yet.</td></tr>
    <?php endif ?>
  </table>
</div>

<!-- Add partner -->
<div class="card">
  <h2>➕ Add Partner</h2>
  <form method="POST">
    <input type="hidden" name="action" value="save_partner">
    <div class="grid2">
      <div><label>Organisation Name</label><input name="name" placeholder="ABC Therapy Centre" required></div>
      <div><label>Contact Person</label><input name="contact" placeholder="Dr. Sharma"></div>
      <div><label>Phone</label><input name="phone" placeholder="9XXXXXXXXX"></div>
      <div><label>Email</label><input name="email" type="email" placeholder="abc@example.com"></div>
      <div><label>Referral Code (UPPER)</label><input name="code" placeholder="ABC10" style="text-transform:uppercase"></div>
      <div><label>Revenue Share (%)</label>
        <input name="revenue_share" type="number" step="0.01" min="0" max="1" value="0.10" placeholder="0.10">
        <small style="color:#718096">Enter as decimal: 0.10 = 10%</small>
      </div>
    </div>
    <button class="btn btn-green" type="submit">Create Partner</button>
  </form>
</div>

<?php else: ?>
<!-- ── PARTNER DETAIL ───────────────────────────────────────────────────── -->
<a class="back" href="?key=<?php echo $admin_key ?>">← All Partners</a>

<div class="card">
  <h2>Partner #<?php echo $partner['id'] ?> – <?php echo htmlspecialchars($partner['name']) ?></h2>

  <?php $today_count = count_today_invites($pid); ?>
  <p style="margin-bottom:14px;color:#718096">
    Referral Code: <strong><?php echo htmlspecialchars($partner['referral_code']) ?></strong> &nbsp;|&nbsp;
    Rev Share: <strong><?php echo round(($partner['revenue_share']??0.10)*100) ?>%</strong> &nbsp;|&nbsp;
    Status: <strong><?php echo $partner['status']??'active' ?></strong> &nbsp;|&nbsp;
    Invites today: <strong><?php echo $today_count ?>/5</strong>
    <?php if($today_count >= 5): ?><span class="limit-warn"> ⚠ Daily limit reached</span><?php endif ?>
  </p>

  <!-- Edit partner form -->
  <details style="margin-bottom:16px">
    <summary style="cursor:pointer;color:#4299e1;font-weight:600">✏️ Edit Partner Details</summary>
    <form method="POST" style="margin-top:12px">
      <input type="hidden" name="action"  value="save_partner">
      <input type="hidden" name="edit_id" value="<?php echo $partner['id'] ?>">
      <div class="grid3">
        <div><label>Org Name</label><input name="name"    value="<?php echo htmlspecialchars($partner['name']) ?>" required></div>
        <div><label>Contact</label><input  name="contact" value="<?php echo htmlspecialchars($partner['contact_name']??'') ?>"></div>
        <div><label>Phone</label><input    name="phone"   value="<?php echo htmlspecialchars($partner['phone']??'') ?>"></div>
        <div><label>Email</label><input    name="email"   value="<?php echo htmlspecialchars($partner['email']??'') ?>"></div>
        <div><label>Code</label><input     name="code"    value="<?php echo htmlspecialchars($partner['referral_code']??'') ?>"></div>
        <div><label>Rev Share (decimal)</label>
             <input name="revenue_share" type="number" step="0.01" min="0" max="1"
                    value="<?php echo $partner['revenue_share']??0.10 ?>"></div>
      </div>
      <div style="margin-bottom:10px">
        <label>Status</label>
        <select name="status" style="width:auto">
          <option value="active"   <?php echo ($partner['status']??'active')==='active'  ?'selected':'' ?>>Active</option>
          <option value="inactive" <?php echo ($partner['status']??'active')==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <button class="btn btn-blue" type="submit">Save Changes</button>
    </form>
  </details>

  <!-- Create Invite -->
  <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;padding:16px;margin-bottom:16px">
    <h2>📨 Create Parent Invite</h2>
    <p style="color:#718096;font-size:12px;margin-bottom:12px">
      Parent gets a magic link → pre-filled signup → ₹2,000 wallet credit (no commission).
      Limit: 5/day · Expires in 7 days · Single use.
    </p>
    <?php if($today_count >= 5): ?>
      <p class="limit-warn">⚠ Daily limit of 5 invites reached for today.</p>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="action"  value="create_invite">
      <div class="grid2">
        <div><label>Parent Name</label>
             <input name="parent_name" placeholder="Priya Sharma" required></div>
        <div><label>WhatsApp Number</label>
             <input name="whatsapp" placeholder="9XXXXXXXXX" required></div>
      </div>
      <button class="btn btn-green" type="submit">Generate Invite Link</button>
    </form>
    <?php endif ?>
  </div>

  <!-- Invite history -->
  <h2>📋 Invite History (last 50)</h2>
  <?php if(!$invites): ?>
    <p style="color:#a0aec0">No invites yet for this partner.</p>
  <?php else: ?>
  <table>
    <tr><th>Parent</th><th>WhatsApp</th><th>Created</th><th>Expires</th><th>Status</th><th>Link</th><th></th></tr>
    <?php foreach($invites as $inv):
      $link = "https://empowerstudents.in/p/" . urlencode($partner['referral_code'])
              . "?invite=" . $inv['invite_token'];
      $wa_msg = urlencode("Hi " . $inv['parent_name'] . "! Dr. Jha has created a special link for you to evaluate your child. Your ₹2,000 credit is waiting! 👉 " . $link);
      $wa_link = "https://api.whatsapp.com/send?phone=" . $inv['whatsapp_clean'] . "&text=" . $wa_msg;
      $badge_class = [
        'pending'   => 'badge-yellow',
        'claimed'   => 'badge-green',
        'expired'   => 'badge-gray',
        'cancelled' => 'badge-red',
      ][$inv['status']] ?? 'badge-gray';
    ?>
    <tr>
      <td><?php echo htmlspecialchars($inv['parent_name']) ?></td>
      <td><?php echo htmlspecialchars($inv['whatsapp_clean']) ?></td>
      <td><?php echo substr($inv['created_at'],0,10) ?></td>
      <td><?php echo substr($inv['expires_at'],0,10) ?></td>
      <td><span class="badge <?php echo $badge_class ?>"><?php echo ucfirst($inv['status']) ?></span></td>
      <td>
        <?php if($inv['status']==='pending'): ?>
        <div class="invite-link"><?php echo htmlspecialchars($link) ?></div>
        <button class="copy-btn" onclick="copyLink('<?php echo addslashes($link) ?>')">📋 Copy</button>
        &nbsp;
        <a class="wa-btn" href="<?php echo $wa_link ?>" target="_blank">📲 WhatsApp</a>
        <?php else: ?>
        <span style="color:#a0aec0;font-size:12px"><?php echo ucfirst($inv['status']) ?></span>
        <?php endif ?>
      </td>
      <td>
        <?php if($inv['status']==='pending'): ?>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Cancel this invite?')">
          <input type="hidden" name="action"    value="cancel_invite">
          <input type="hidden" name="invite_id" value="<?php echo $inv['id'] ?>">
          <button class="btn btn-red btn-sm" type="submit">Cancel</button>
        </form>
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
</div>
<?php endif ?>

</div><!-- /wrap -->
<script>
function copyLink(url) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function(){
            alert('Link copied!');
        });
    } else {
        var t = document.createElement('textarea');
        t.value = url;
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        document.body.removeChild(t);
        alert('Link copied!');
    }
}
</script>
</body>
</html>
