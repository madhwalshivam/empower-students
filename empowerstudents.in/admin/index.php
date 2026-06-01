<?php
require __DIR__ . '/_admin.php';

// ── Ensure leads table exists (so a fresh install doesn't crash) ──────
db()->exec("CREATE TABLE IF NOT EXISTS leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_name TEXT NOT NULL, phone TEXT NOT NULL,
    child_age TEXT, concern TEXT, message TEXT, source TEXT,
    utm_source TEXT, utm_medium TEXT, utm_campaign TEXT,
    utm_content TEXT, utm_term TEXT,
    referrer TEXT, user_agent TEXT, ip TEXT,
    status TEXT DEFAULT 'new', notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);");

// ── Date helpers ──────────────────────────────────────────────────────
$today_start  = date('Y-m-d 00:00:00');
$week_start   = date('Y-m-d 00:00:00', strtotime('-6 days'));
$month_start  = date('Y-m-d 00:00:00', strtotime('-29 days'));

// ── Lead funnel stats ─────────────────────────────────────────────────
$lead_stats = [
    'new'       => (int) db()->query("SELECT COUNT(*) FROM leads WHERE status='new'")->fetchColumn(),
    'today'     => (int) db()->prepare("SELECT COUNT(*) FROM leads WHERE created_at >= ?")->execute([$today_start]) ? (int)db()->query("SELECT COUNT(*) FROM leads WHERE created_at >= '$today_start'")->fetchColumn() : 0,
    'week'      => (int) db()->query("SELECT COUNT(*) FROM leads WHERE created_at >= '$week_start'")->fetchColumn(),
    'month'     => (int) db()->query("SELECT COUNT(*) FROM leads WHERE created_at >= '$month_start'")->fetchColumn(),
    'total'     => (int) db()->query("SELECT COUNT(*) FROM leads")->fetchColumn(),
    'contacted' => (int) db()->query("SELECT COUNT(*) FROM leads WHERE status='contacted'")->fetchColumn(),
    'booked'    => (int) db()->query("SELECT COUNT(*) FROM leads WHERE status='booked'")->fetchColumn(),
    'converted' => (int) db()->query("SELECT COUNT(*) FROM leads WHERE status='converted'")->fetchColumn(),
    'lost'      => (int) db()->query("SELECT COUNT(*) FROM leads WHERE status='lost'")->fetchColumn(),
];
$conversion_rate = $lead_stats['total'] > 0
    ? round(($lead_stats['converted'] / $lead_stats['total']) * 100, 1)
    : 0;

// ── Existing site stats (preserved from previous dashboard) ──────────
$stats = [
    'parents'      => (int) db()->query("SELECT COUNT(*) FROM parents")->fetchColumn(),
    'children'     => (int) db()->query("SELECT COUNT(*) FROM children")->fetchColumn(),
    'assessments'  => (int) db()->query("SELECT COUNT(*) FROM assessments WHERE status='done'")->fetchColumn(),
    'reports'      => (int) db()->query("SELECT COUNT(*) FROM reports")->fetchColumn(),
    'paid_orders'  => (int) db()->query("SELECT COUNT(*) FROM payment_orders WHERE status='success'")->fetchColumn(),
    'revenue_inr'  => (int) db()->query("SELECT COALESCE(SUM(amount),0) FROM payment_orders WHERE status='success'")->fetchColumn(),
];

// ── Paid features stats (Care Pack model) ─────────────────────────
$paid = [
    'care_packs'           => 0,    // total Care Packs sold
    'care_packs_30d'       => 0,    // last 30 days
    'care_pack_revenue'    => 0,
    'topup_count'          => 0,    // tracker top-ups (recurring revenue indicator)
    'topup_revenue'        => 0,
    'standalone_revenue'   => 0,    // for parents who bought growth_plan or personal_course solo
    'lessons_completed'    => 0,
    'tracker_active'       => 0,    // care packs with days_remaining > 0
    'daily_logs_today'     => 0,
    'daily_logs_total'     => 0,
    'avg_course_progress'  => 0,
    'tracker_days_used'    => 0,    // total days consumed across all packs
];
try {
    $paid['care_packs']         = (int) db()->query("SELECT COUNT(*) FROM care_packs")->fetchColumn();
    $paid['care_packs_30d']     = (int) db()->query("SELECT COUNT(*) FROM care_packs WHERE purchased_at >= '$month_start'")->fetchColumn();
    $paid['care_pack_revenue']  = (int) db()->query("SELECT COALESCE(-SUM(amount),0) FROM wallet_ledger WHERE service_key='care_pack' AND amount<0")->fetchColumn();

    $paid['topup_count']        = (int) db()->query("SELECT COUNT(*) FROM tracker_topups")->fetchColumn();
    $paid['topup_revenue']      = (int) db()->query("SELECT COALESCE(-SUM(amount),0) FROM wallet_ledger WHERE service_key='tracker_topup' AND amount<0")->fetchColumn();

    $paid['standalone_revenue'] = (int) db()->query("SELECT COALESCE(-SUM(amount),0) FROM wallet_ledger WHERE service_key IN ('growth_plan','personal_course') AND amount<0")->fetchColumn();

    $paid['lessons_completed']  = (int) db()->query("SELECT COUNT(*) FROM personal_lessons WHERE completed_at IS NOT NULL")->fetchColumn();
    $paid['avg_course_progress'] = (int) db()->query("SELECT COALESCE(ROUND(AVG(progress_pct)),0) FROM personal_courses")->fetchColumn();

    $paid['tracker_active']     = (int) db()->query("SELECT COUNT(*) FROM care_packs WHERE tracker_days_remaining > 0")->fetchColumn();
    $paid['daily_logs_today']   = (int) db()->query("SELECT COUNT(*) FROM daily_logs WHERE log_date='" . date('Y-m-d') . "'")->fetchColumn();
    $paid['daily_logs_total']   = (int) db()->query("SELECT COUNT(*) FROM daily_logs")->fetchColumn();

    // Tracker days consumed = (initial 30 per pack + 30 per topup) - sum of days_remaining
    $granted = (30 * $paid['care_packs']) + (30 * $paid['topup_count']);
    $remaining = (int) db()->query("SELECT COALESCE(SUM(tracker_days_remaining),0) FROM care_packs")->fetchColumn();
    $paid['tracker_days_used'] = max(0, $granted - $remaining);
} catch (Throwable $e) {
    // Paid tables not yet created — they auto-create on first paid page request
}

$paid_total_revenue = $paid['care_pack_revenue'] + $paid['topup_revenue'] + $paid['standalone_revenue'];

// ── Partner stats (graceful if tables don't exist yet) ────────────
$partner_stats = [
    'active_count'      => 0,
    'owed_total'        => 0.0,
    'owed_partners'     => 0,    // how many distinct partners are owed money
    'paid_lifetime'     => 0.0,
    'attributed_parents'=> 0,
];
try {
    $partner_stats['active_count'] = (int) db()->query("SELECT COUNT(*) FROM partners WHERE status='active'")->fetchColumn();
    $row = db()->query("SELECT COALESCE(SUM(partner_amount),0) AS owed, COUNT(DISTINCT partner_id) AS partners FROM partner_payouts WHERE status='pending'")->fetch();
    $partner_stats['owed_total']    = (float) ($row['owed'] ?? 0);
    $partner_stats['owed_partners'] = (int)   ($row['partners'] ?? 0);
    $partner_stats['paid_lifetime'] = (float) db()->query("SELECT COALESCE(SUM(partner_amount),0) FROM partner_payouts WHERE status='paid'")->fetchColumn();
    $partner_stats['attributed_parents'] = (int) db()->query("SELECT COUNT(*) FROM parents WHERE partner_id IS NOT NULL")->fetchColumn();
} catch (Throwable $e) {
    // Partner tables not yet created — module not deployed yet
}

// ── Recent Care Pack purchases (the action feed) ───────────────────
$recent_packs = [];
try {
    $recent_packs = db()->query("
        SELECT cp.purchased_at, cp.tracker_days_remaining,
               c.name AS child_name, c.id AS child_id,
               p.name AS parent_name, p.id AS parent_id,
               (SELECT progress_pct FROM personal_courses WHERE child_id=cp.child_id) AS course_progress
        FROM care_packs cp
        JOIN children c ON c.id = cp.child_id
        JOIN parents p ON p.id = cp.parent_id
        ORDER BY cp.purchased_at DESC
        LIMIT 8
    ")->fetchAll();
} catch (Throwable $e) { /* silent */ }

// ── Last 14 days of leads — for sparkline ──
$daily = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily[$d] = 0;
}
$rs = db()->query("SELECT DATE(created_at) AS d, COUNT(*) AS c
                   FROM leads
                   WHERE created_at >= '" . date('Y-m-d 00:00:00', strtotime('-13 days')) . "'
                   GROUP BY DATE(created_at)");
foreach ($rs as $r) {
    if (isset($daily[$r['d']])) $daily[$r['d']] = (int)$r['c'];
}
$max_daily = max(1, max($daily));

// ── New / uncontacted leads — top of dashboard ──
$new_leads = db()->query("SELECT * FROM leads WHERE status='new' ORDER BY id DESC LIMIT 10")->fetchAll();

// ── Top UTM campaigns (by lead count, last 30 days) ──
$utm_top = db()->query("
    SELECT
        COALESCE(NULLIF(utm_source, ''), 'direct')   AS src,
        COALESCE(NULLIF(utm_medium, ''), '—')        AS med,
        COALESCE(NULLIF(utm_campaign, ''), '—')      AS camp,
        COUNT(*)                                     AS leads,
        SUM(CASE WHEN status IN ('booked','converted') THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) AS converted
    FROM leads
    WHERE created_at >= '$month_start'
    GROUP BY src, med, camp
    ORDER BY leads DESC
    LIMIT 8
")->fetchAll();

// ── Concern breakdown — what parents are asking about ──
$concern_breakdown = db()->query("
    SELECT concern, COUNT(*) AS c
    FROM leads
    WHERE created_at >= '$month_start' AND concern IS NOT NULL AND concern != ''
    GROUP BY concern
    ORDER BY c DESC
")->fetchAll();
$concern_total = array_sum(array_column($concern_breakdown, 'c'));

$concern_labels = [
    'speech'        => 'Speech / Language',
    'behaviour'     => 'Behaviour / Emotional',
    'autism'        => 'Autism / Developmental',
    'learning'      => 'Learning Difficulty',
    'adhd'          => 'ADHD / Focus',
    'sensory_motor' => 'Sensory / Motor',
    'not_sure'      => 'Needs guidance',
];

// ── Partner applications — pending approvals ──────────────────────────────
$pending_applications = [];
try {
    db()->exec("CREATE TABLE IF NOT EXISTS partner_applications (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        clinic     TEXT,
        whatsapp   TEXT NOT NULL,
        city       TEXT,
        status     TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pending_applications = db()->query(
        "SELECT * FROM partner_applications WHERE status='pending' ORDER BY created_at DESC"
    )->fetchAll();
} catch (Throwable $_) {}

// Handle approve / reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id  = (int)($_POST['app_id'] ?? 0);
    $app_act = $_POST['app_action'] ?? '';
    if ($app_id > 0 && in_array($app_act, ['approve','reject'], true)) {
        try {
            if ($app_act === 'approve') {
                // Fetch application
                $app = db()->prepare("SELECT * FROM partner_applications WHERE id=?")->execute([$app_id])
                    ? db()->prepare("SELECT * FROM partner_applications WHERE id=?")->execute([$app_id]) && false
                    : null;
                $st = db()->prepare("SELECT * FROM partner_applications WHERE id=?");
                $st->execute([$app_id]);
                $app = $st->fetch(PDO::FETCH_ASSOC);
                if ($app) {
                    // Generate referral code
                    $wa_digits = preg_replace('/\D/', '', $app['whatsapp']);
                    $code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $app['name']), 0, 4)
                          . substr($wa_digits, -4));
                    // Check uniqueness
                    $ck = db()->prepare("SELECT COUNT(*) FROM partners WHERE referral_code=?");
                    $ck->execute([$code]);
                    if ((int)$ck->fetchColumn() > 0) $code .= rand(10,99);

                    db()->prepare("INSERT INTO partners
                        (name, contact_person, whatsapp, phone, area, referral_code, status, source)
                        VALUES (?,?,?,?,?,'$code','pending','application')")
                       ->execute([
                           $app['clinic'] ?: $app['name'],
                           $app['name'],
                           $wa_digits,
                           $wa_digits,
                           $app['city'] ?? '',
                       ]);
                }
            }
            db()->prepare("UPDATE partner_applications SET status=? WHERE id=?")
               ->execute([$app_act === 'approve' ? 'approved' : 'rejected', $app_id]);
        } catch (Throwable $_) {}
        header('Location: /admin/index.php'); exit;
    }
}

// ── Recent assessments (kept from old dashboard) ──
$recent = db()->query("SELECT a.*, c.name AS cname, c.parent_id
                       FROM assessments a JOIN children c ON c.id = a.child_id
                       WHERE a.status='done' ORDER BY a.id DESC LIMIT 8")->fetchAll();

admin_layout_open('Overview');
admin_render_flash();
?>

<style>
  .stat-card { transition: transform .15s ease, box-shadow .15s ease; }
  .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px -8px rgba(15,23,42,0.1); }
  .urgent-pulse {
    animation: urgent 2s ease-in-out infinite;
  }
  @keyframes urgent {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    50%      { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
  }
</style>

<!-- ════════════════════════════════════════════════════════════════════
     PARTNER APPLICATIONS — pending approvals
     ════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($pending_applications)): ?>
  <div class="bg-gradient-to-r from-indigo-500 to-violet-600 rounded-2xl p-5 mb-5 text-white shadow-lg">
    <div class="flex items-center justify-between flex-wrap gap-3 mb-3">
      <div>
        <div class="text-xs uppercase tracking-wider opacity-90">🤝 Action required</div>
        <h2 class="text-xl font-bold mt-1">
          <?= count($pending_applications) ?> partner application<?= count($pending_applications) === 1 ? '' : 's' ?> pending
          <span class="text-sm font-normal opacity-90 ml-2">— review and approve</span>
        </h2>
      </div>
      <a href="/admin/partners.php" class="bg-white text-indigo-600 px-4 py-2 rounded-full text-sm font-bold hover:bg-indigo-50">
        All partners →
      </a>
    </div>
    <div class="space-y-2">
      <?php foreach ($pending_applications as $app):
        $wa = preg_replace('/\D/', '', $app['whatsapp']);
        $wa_link = 'https://wa.me/' . $wa . '?text=' . rawurlencode(
            "Hello " . $app['name'] . "! Thank you for applying to be an EmpowerStudents partner. "
          . "Your application has been approved. You can now log in at empowerstudents.in/partner-login.php "
          . "using this WhatsApp number."
        );
        $age_ago = round((time() - strtotime($app['created_at'])) / 60);
        $age_str = $age_ago < 60 ? "{$age_ago}m ago" : ($age_ago < 1440 ? round($age_ago/60)."h ago" : round($age_ago/1440)."d ago");
      ?>
        <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-3 flex items-center justify-between flex-wrap gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <div class="bg-white/25 w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0">
              <?= e(mb_strtoupper(mb_substr($app['name'], 0, 1))) ?>
            </div>
            <div class="min-w-0">
              <div class="font-semibold"><?= e($app['name']) ?></div>
              <div class="text-xs opacity-90">
                <?= e($app['clinic'] ?: '—') ?>
                <?php if ($app['city']): ?> · <?= e($app['city']) ?><?php endif; ?>
                · <?= e($app['whatsapp']) ?> · <span class="opacity-75"><?= $age_str ?></span>
              </div>
            </div>
          </div>
          <div class="flex gap-2 flex-shrink-0 flex-wrap">
            <a href="<?= e($wa_link) ?>" target="_blank" rel="noopener"
               class="bg-emerald-500 hover:bg-emerald-600 px-3 py-1.5 rounded-full text-xs font-bold">
              💬 WhatsApp
            </a>
            <form method="post" style="display:inline">
              <input type="hidden" name="app_id"     value="<?= (int)$app['id'] ?>">
              <input type="hidden" name="app_action" value="approve">
              <button class="bg-white text-indigo-700 hover:bg-indigo-50 px-3 py-1.5 rounded-full text-xs font-bold">
                ✓ Approve
              </button>
            </form>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Reject this application?')">
              <input type="hidden" name="app_id"     value="<?= (int)$app['id'] ?>">
              <input type="hidden" name="app_action" value="reject">
              <button class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-full text-xs font-bold">
                ✕ Reject
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
  <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-5 flex items-center gap-3">
    <div class="text-2xl">✓</div>
    <div>
      <div class="font-semibold text-emerald-900">No pending partner applications</div>
      <div class="text-xs text-emerald-700">New applications from <a href="/partner-login.php" class="underline">partner-login.php</a> will appear here for review.</div>
    </div>
  </div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════
     LEAD FUNNEL (top stat row)
     ════════════════════════════════════════════════════════════════════ -->
<h2 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2">📞 Lead funnel</h2>
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">

  <a href="/admin/leads.php?filter=new" class="stat-card bg-white rounded-2xl border-2 <?= $lead_stats['new'] > 0 ? 'border-rose-300' : 'border-slate-200' ?> p-4 block">
    <div class="text-xs uppercase text-slate-500 font-semibold">New</div>
    <div class="text-3xl font-bold mt-1 <?= $lead_stats['new'] > 0 ? 'text-rose-600' : 'text-slate-400' ?>">
      <?= number_format($lead_stats['new']) ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">⏰ uncontacted</div>
  </a>

  <a href="/admin/leads.php?filter=contacted" class="stat-card bg-white rounded-2xl border border-slate-200 p-4 block">
    <div class="text-xs uppercase text-slate-500 font-semibold">Contacted</div>
    <div class="text-3xl font-bold mt-1 text-sky-600"><?= number_format($lead_stats['contacted']) ?></div>
    <div class="text-xs text-slate-400 mt-1">in conversation</div>
  </a>

  <a href="/admin/leads.php?filter=booked" class="stat-card bg-white rounded-2xl border border-slate-200 p-4 block">
    <div class="text-xs uppercase text-slate-500 font-semibold">Booked</div>
    <div class="text-3xl font-bold mt-1 text-violet-600"><?= number_format($lead_stats['booked']) ?></div>
    <div class="text-xs text-slate-400 mt-1">eval scheduled</div>
  </a>

  <a href="/admin/leads.php?filter=converted" class="stat-card bg-white rounded-2xl border border-slate-200 p-4 block">
    <div class="text-xs uppercase text-slate-500 font-semibold">Converted</div>
    <div class="text-3xl font-bold mt-1 text-emerald-600"><?= number_format($lead_stats['converted']) ?></div>
    <div class="text-xs text-slate-400 mt-1">paying patients</div>
  </a>

  <div class="stat-card bg-gradient-to-br from-indigo-500 to-violet-600 rounded-2xl p-4 text-white">
    <div class="text-xs uppercase font-semibold opacity-90">Conversion</div>
    <div class="text-3xl font-bold mt-1"><?= $conversion_rate ?>%</div>
    <div class="text-xs opacity-80 mt-1">lead → patient</div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════
     14-DAY LEAD TREND (sparkline)
     ════════════════════════════════════════════════════════════════════ -->
<div class="grid lg:grid-cols-3 gap-4 mb-5">
  <div class="bg-white rounded-2xl border border-slate-200 p-5 lg:col-span-2">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h2 class="font-semibold text-slate-800">Leads over the last 14 days</h2>
        <p class="text-xs text-slate-500">Today: <strong class="text-slate-800"><?= $lead_stats['today'] ?></strong>
          · This week: <strong class="text-slate-800"><?= $lead_stats['week'] ?></strong>
          · 30-day total: <strong class="text-slate-800"><?= $lead_stats['month'] ?></strong></p>
      </div>
      <a href="/admin/leads.php" class="text-xs text-indigo-600 font-semibold">All leads →</a>
    </div>

    <div class="flex items-end gap-1 h-32 mt-4">
      <?php foreach ($daily as $date => $count):
        $h = max(2, round(($count / $max_daily) * 100));
        $is_today = $date === date('Y-m-d');
        $color = $count === 0 ? 'bg-slate-100'
               : ($is_today ? 'bg-gradient-to-t from-indigo-500 to-violet-400'
                            : 'bg-gradient-to-t from-indigo-400 to-sky-300');
      ?>
        <div class="flex-1 flex flex-col items-center gap-1 group relative">
          <div class="text-[10px] text-slate-400 font-semibold opacity-0 group-hover:opacity-100 transition"><?= $count ?></div>
          <div class="<?= $color ?> w-full rounded-t-md transition hover:opacity-80" style="height: <?= $h ?>%" title="<?= e($date) ?>: <?= $count ?> lead<?= $count === 1 ? '' : 's' ?>"></div>
          <div class="text-[9px] text-slate-400 mt-0.5"><?= e(date('d', strtotime($date))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Concern breakdown -->
  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold text-slate-800 mb-1">What parents ask about</h2>
    <p class="text-xs text-slate-500 mb-4">Last 30 days · <?= $concern_total ?> leads</p>
    <?php if (empty($concern_breakdown)): ?>
      <p class="text-sm text-slate-400">Nothing yet.</p>
    <?php else: ?>
      <ul class="space-y-2.5">
        <?php foreach ($concern_breakdown as $row):
          $pct = $concern_total > 0 ? round(($row['c'] / $concern_total) * 100) : 0;
          $label = $concern_labels[$row['concern']] ?? $row['concern'];
        ?>
          <li>
            <div class="flex justify-between text-xs mb-1">
              <span class="font-medium text-slate-700"><?= e($label) ?></span>
              <span class="text-slate-500"><?= (int)$row['c'] ?> · <?= $pct ?>%</span>
            </div>
            <div class="bg-slate-100 rounded-full h-2 overflow-hidden">
              <div class="bg-gradient-to-r from-rose-400 to-orange-400 h-full rounded-full" style="width: <?= $pct ?>%"></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════
     UTM PERFORMANCE (which ad is delivering?)
     ════════════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-2xl border border-slate-200 p-5 mb-5">
  <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
    <div>
      <h2 class="font-semibold text-slate-800">🎯 Top campaigns (last 30 days)</h2>
      <p class="text-xs text-slate-500">Which ads are delivering leads — and which are converting to patients</p>
    </div>
  </div>
  <?php if (empty($utm_top)): ?>
    <div class="text-center py-8 text-sm text-slate-400">
      No UTM-tagged traffic yet. Once your Google &amp; Facebook ads go live, campaigns will appear here.
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-xs uppercase text-slate-500 text-left">
          <tr class="border-b border-slate-200">
            <th class="py-2 pr-3">Source</th>
            <th class="py-2 pr-3">Medium</th>
            <th class="py-2 pr-3">Campaign</th>
            <th class="py-2 pr-3 text-right">Leads</th>
            <th class="py-2 pr-3 text-right">Qualified</th>
            <th class="py-2 pr-3 text-right">Converted</th>
            <th class="py-2 text-right">Conv %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($utm_top as $row):
            $rate = $row['leads'] > 0 ? round(($row['converted'] / $row['leads']) * 100, 1) : 0;
            $src_color = [
              'google' => 'bg-blue-50 text-blue-700',
              'facebook' => 'bg-indigo-50 text-indigo-700',
              'instagram' => 'bg-pink-50 text-pink-700',
              'direct' => 'bg-slate-100 text-slate-600',
            ][$row['src']] ?? 'bg-slate-100 text-slate-600';
          ?>
            <tr class="border-b border-slate-100 hover:bg-slate-50">
              <td class="py-2 pr-3">
                <span class="<?= $src_color ?> px-2 py-0.5 rounded text-xs font-semibold"><?= e($row['src']) ?></span>
              </td>
              <td class="py-2 pr-3 text-slate-600"><?= e($row['med']) ?></td>
              <td class="py-2 pr-3 font-mono text-xs text-slate-700"><?= e($row['camp']) ?></td>
              <td class="py-2 pr-3 text-right font-semibold"><?= (int)$row['leads'] ?></td>
              <td class="py-2 pr-3 text-right text-violet-700"><?= (int)$row['qualified'] ?></td>
              <td class="py-2 pr-3 text-right text-emerald-700 font-semibold"><?= (int)$row['converted'] ?></td>
              <td class="py-2 text-right">
                <?php if ($rate >= 10): ?>
                  <span class="text-emerald-600 font-bold"><?= $rate ?>%</span>
                <?php elseif ($rate > 0): ?>
                  <span class="text-slate-700"><?= $rate ?>%</span>
                <?php else: ?>
                  <span class="text-slate-400">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-400 mt-3">
      💡 Scale campaigns with conversion ≥ 10%. Pause campaigns with leads but zero conversions after 50+ leads.
    </p>
  <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════
     PAID FEATURES — Care Pack model
     ════════════════════════════════════════════════════════════════════ -->
<h2 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2">💎 Care Pack revenue</h2>

<!-- Headline revenue strip -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
  <div class="stat-card bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl p-4 text-white">
    <div class="text-xs uppercase font-semibold opacity-90">Total paid revenue</div>
    <div class="text-3xl font-bold mt-1">₹<?= number_format($paid_total_revenue) ?></div>
    <div class="text-xs opacity-80 mt-1">Care Packs + top-ups + standalone</div>
  </div>

  <div class="stat-card bg-gradient-to-br from-rose-500 to-orange-600 rounded-2xl p-4 text-white">
    <div class="text-xs uppercase font-semibold opacity-90">Care Packs sold</div>
    <div class="text-3xl font-bold mt-1"><?= number_format($paid['care_packs']) ?></div>
    <div class="text-xs opacity-80 mt-1">+<?= $paid['care_packs_30d'] ?> in last 30 days</div>
  </div>

  <div class="stat-card bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs uppercase text-slate-500 font-semibold">Tracker top-ups</div>
    <div class="text-3xl font-bold mt-1 text-violet-600"><?= number_format($paid['topup_count']) ?></div>
    <div class="text-xs text-slate-500 mt-1">₹<?= number_format($paid['topup_revenue']) ?> recurring revenue</div>
  </div>

  <div class="stat-card bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs uppercase text-slate-500 font-semibold">Daily logs today</div>
    <div class="text-3xl font-bold mt-1 text-sky-600"><?= number_format($paid['daily_logs_today']) ?></div>
    <div class="text-xs text-slate-500 mt-1"><?= number_format($paid['daily_logs_total']) ?> all-time</div>
  </div>
</div>

<?php if ($partner_stats['active_count'] > 0 || $partner_stats['paid_lifetime'] > 0 || $partner_stats['owed_total'] > 0): ?>
<!-- ════════════════════════════════════════════════════════════════════
     PARTNERS — referral attribution + revenue share
     ════════════════════════════════════════════════════════════════════ -->
<h2 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2">🤝 Partners</h2>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
  <a href="/admin/partners.php" class="stat-card bg-gradient-to-br from-amber-500 to-orange-600 rounded-2xl p-4 text-white block <?= $partner_stats['owed_total'] > 0 ? 'ring-4 ring-orange-200' : '' ?>">
    <div class="text-xs uppercase font-semibold opacity-90">💸 Owed to partners</div>
    <div class="text-3xl font-bold mt-1">₹<?= number_format($partner_stats['owed_total'], 2) ?></div>
    <div class="text-xs opacity-90 mt-1">
      <?php if ($partner_stats['owed_total'] > 0): ?>
        across <?= $partner_stats['owed_partners'] ?> partner<?= $partner_stats['owed_partners'] === 1 ? '' : 's' ?> · pay out →
      <?php else: ?>
        all paid up ✓
      <?php endif; ?>
    </div>
  </a>

  <a href="/admin/partners.php" class="stat-card bg-white rounded-2xl border border-slate-200 p-4 block">
    <div class="text-xs uppercase text-slate-500 font-semibold">Active partners</div>
    <div class="text-3xl font-bold mt-1 text-emerald-600"><?= $partner_stats['active_count'] ?></div>
    <div class="text-xs text-slate-500 mt-1">tutors / centres</div>
  </a>

  <div class="stat-card bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs uppercase text-slate-500 font-semibold">Referred parents</div>
    <div class="text-3xl font-bold mt-1 text-violet-600"><?= number_format($partner_stats['attributed_parents']) ?></div>
    <div class="text-xs text-slate-500 mt-1">attributed via ?ref=</div>
  </div>

  <div class="stat-card bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs uppercase text-slate-500 font-semibold">Paid out lifetime</div>
    <div class="text-3xl font-bold mt-1 text-slate-700">₹<?= number_format($partner_stats['paid_lifetime'], 2) ?></div>
    <div class="text-xs text-slate-500 mt-1">total disbursed</div>
  </div>
</div>
<?php endif; ?>

<!-- Three feature engagement cards -->
<div class="grid md:grid-cols-3 gap-4 mb-5">

  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <div class="flex items-center gap-2 mb-3">
      <div class="text-2xl">🌱</div>
      <h3 class="font-bold">Growth Plans</h3>
    </div>
    <div class="space-y-1.5 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Generated (auto with pack)</span><strong><?= number_format($paid['care_packs']) ?></strong></div>
      <div class="flex justify-between border-t border-slate-100 pt-1.5 mt-1.5"><span class="text-slate-500">Care Pack revenue</span><strong>₹<?= number_format($paid['care_pack_revenue']) ?></strong></div>
    </div>
    <p class="text-xs text-slate-400 mt-3">Generated automatically when parent buys Care Pack</p>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <div class="flex items-center gap-2 mb-3">
      <div class="text-2xl">📚</div>
      <h3 class="font-bold">Personal Courses</h3>
    </div>
    <div class="space-y-1.5 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Total courses</span><strong><?= number_format($paid['care_packs']) ?></strong></div>
      <div class="flex justify-between"><span class="text-slate-500">Lessons completed</span><strong class="text-emerald-600"><?= $paid['lessons_completed'] ?></strong></div>
      <div class="flex justify-between border-t border-slate-100 pt-1.5 mt-1.5"><span class="text-slate-500">Avg progress</span><strong><?= $paid['avg_course_progress'] ?>%</strong></div>
    </div>
    <p class="text-xs text-slate-400 mt-3">5 lessons each, AI-generated per child</p>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <div class="flex items-center gap-2 mb-3">
      <div class="text-2xl">📊</div>
      <h3 class="font-bold">Daily Tracker</h3>
    </div>
    <div class="space-y-1.5 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Active (days left &gt; 0)</span><strong class="text-emerald-700"><?= $paid['tracker_active'] ?></strong></div>
      <div class="flex justify-between"><span class="text-slate-500">Days consumed</span><strong><?= $paid['tracker_days_used'] ?></strong></div>
      <div class="flex justify-between"><span class="text-slate-500">Top-ups</span><strong><?= $paid['topup_count'] ?></strong></div>
      <div class="flex justify-between border-t border-slate-100 pt-1.5 mt-1.5"><span class="text-slate-500">Top-up revenue</span><strong>₹<?= number_format($paid['topup_revenue']) ?></strong></div>
    </div>
    <p class="text-xs text-slate-400 mt-3">30 days included · 149 cr per top-up</p>
  </div>
</div>

<!-- Recent Care Pack purchases -->
<?php if (!empty($recent_packs)): ?>
  <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-5">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
      <div>
        <h3 class="font-semibold text-slate-800">🎁 Recent Care Pack purchases</h3>
        <p class="text-xs text-slate-500">Active packs and how parents are engaging</p>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-xs uppercase text-slate-500 text-left">
          <tr class="border-b border-slate-200">
            <th class="py-2 pr-3">When</th>
            <th class="py-2 pr-3">Parent</th>
            <th class="py-2 pr-3">Child</th>
            <th class="py-2 pr-3 text-right">Course progress</th>
            <th class="py-2 text-right">Tracker days left</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent_packs as $p):
            $cp = (int)$p['course_progress'];
          ?>
            <tr class="border-b border-slate-100 hover:bg-slate-50">
              <td class="py-2 pr-3 text-xs text-slate-500"><?= e(date('d M H:i', strtotime($p['purchased_at']))) ?></td>
              <td class="py-2 pr-3"><a href="/admin/parent.php?id=<?= (int)$p['parent_id'] ?>" class="hover:underline"><?= e($p['parent_name']) ?></a></td>
              <td class="py-2 pr-3"><?= e($p['child_name']) ?></td>
              <td class="py-2 pr-3 text-right">
                <div class="flex items-center justify-end gap-2">
                  <span class="text-xs <?= $cp >= 60 ? 'text-emerald-600 font-semibold' : 'text-slate-600' ?>"><?= $cp ?>%</span>
                  <div class="w-16 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                    <div class="<?= $cp >= 60 ? 'bg-emerald-500' : 'bg-amber-400' ?> h-full" style="width: <?= $cp ?>%"></div>
                  </div>
                </div>
              </td>
              <td class="py-2 text-right">
                <?php $td = (int)$p['tracker_days_remaining']; ?>
                <span class="<?= $td <= 7 ? 'text-rose-600 font-bold' : ($td <= 14 ? 'text-amber-600' : 'text-emerald-700') ?>"><?= $td ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-400 mt-3">
      💡 Parents with ≤7 tracker days left + high course progress are prime top-up candidates — consider a WhatsApp nudge.
    </p>
  </div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════
     SITE STATS (preserved from old dashboard)
     ════════════════════════════════════════════════════════════════════ -->
<h2 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2">📊 Platform activity</h2>
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
  <?php foreach ($stats as $k => $v):
    $label = ucfirst(str_replace('_', ' ', $k));
    $value = ($k === 'revenue_inr') ? '₹' . number_format($v) : number_format($v);
    $icon = [
      'parents' => '👪', 'children' => '🧒', 'assessments' => '📝',
      'reports' => '📋', 'paid_orders' => '💳', 'revenue_inr' => '💰',
    ][$k] ?? '•';
  ?>
    <div class="stat-card bg-white rounded-2xl border border-slate-200 p-4">
      <div class="text-xs uppercase text-slate-500 font-semibold flex items-center gap-1">
        <span><?= $icon ?></span> <?= e($label) ?>
      </div>
      <div class="text-2xl font-bold mt-1 text-slate-800"><?= e($value) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════
     RECENT ASSESSMENTS (shrunk; full activity moved to dedicated page)
     ════════════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-2xl border border-slate-200 p-5 mb-5">
  <div class="flex items-center justify-between mb-3">
    <h2 class="font-semibold text-slate-800">Recent platform assessments</h2>
    <a href="/admin/parents.php" class="text-xs text-indigo-600 font-semibold">All parents →</a>
  </div>
  <?php if (empty($recent)): ?>
    <p class="text-sm text-slate-400 py-4 text-center">No completed assessments yet.</p>
  <?php else: ?>
    <table class="w-full text-sm">
      <thead class="text-xs uppercase text-slate-500 text-left">
        <tr><th class="py-1 pr-3">Child</th><th class="pr-3">Module</th><th class="pr-3">Score</th><th class="pr-3">When</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr class="border-t border-slate-100">
          <td class="py-1.5 pr-3 truncate max-w-[140px] font-medium"><?= e($r['cname']) ?></td>
          <td class="pr-3"><?= e($r['module']) ?></td>
          <td class="pr-3"><?= $r['score'] !== null ? round((float)$r['score'], 1) : '—' ?></td>
          <td class="pr-3 text-xs text-slate-500"><?= e(substr($r['completed_at'] ?? $r['created_at'], 0, 16)) ?></td>
          <td><a href="/admin/parent.php?id=<?= (int)$r['parent_id'] ?>" class="text-indigo-600 text-xs">view →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<p class="text-xs text-slate-400 text-center mt-6">
  Lead emails are sent to <strong>drpankajjha@gmail.com</strong> ·
  Marketing campaigns: ₹500/day per platform (Google + Facebook + Instagram)
</p>

<?php admin_layout_close(); ?>
