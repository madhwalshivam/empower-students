<?php
/**
 * parent-index.php — FREE 2-minute parenting self-check.
 *
 * Behind login. 10 Likert questions across 4 areas (parenting confidence,
 * child concern, couple stress, your wellbeing). Generates an instant
 * friendly report. Unlimited retakes — measures growth over time.
 *
 * Acts as the trust-builder before the ₹1,000 AI Parent Evaluation.
 *
 * Routes:
 *   GET  /parent-index.php          — show form (or latest result if just took)
 *   POST /parent-index.php          — score + save + show result inline
 *   GET  /parent-index.php?result=N — show a specific past result
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

// ── Schema ──────────────────────────────────────────
db()->exec("CREATE TABLE IF NOT EXISTS parent_index_results (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id        INTEGER NOT NULL,
    score_confidence INTEGER NOT NULL,
    score_concern    INTEGER NOT NULL,
    score_couple     INTEGER NOT NULL,
    score_self       INTEGER NOT NULL,
    score_overall    INTEGER NOT NULL,
    band             TEXT,
    answers_json     TEXT,
    created_at       TEXT DEFAULT CURRENT_TIMESTAMP
)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_pi_parent ON parent_index_results(parent_id, created_at DESC)");

// ── Question definitions ─────────────────────────────
// Each question: id, area (confidence|concern|couple|self), text, scale_low_label, scale_high_label
// "reverse" = true means we flip the score (because "very worried" = 1 but maps to low wellbeing score)
$QUESTIONS = [
    ['id' => 'q1', 'area' => 'confidence',
     'text' => "Most days, I feel I know what my child needs from me.",
     'low'  => 'Rarely',        'high' => 'Almost always', 'reverse' => false],

    ['id' => 'q2', 'area' => 'confidence',
     'text' => "When my child has a meltdown, I usually stay calm.",
     'low'  => 'Rarely',        'high' => 'Almost always', 'reverse' => false],

    ['id' => 'q3', 'area' => 'concern',
     'text' => "How worried are you right now about your child's development?",
     'low'  => 'Very worried',  'high' => 'Not at all',    'reverse' => false],

    ['id' => 'q4', 'area' => 'concern',
     'text' => "Compared to other kids their age, my child is…",
     'low'  => 'Very behind',   'high' => 'Doing well',    'reverse' => false],

    ['id' => 'q5', 'area' => 'concern',
     'text' => "Have a teacher or doctor flagged anything about your child?",
     'low'  => 'Major concern', 'high' => 'No concerns',   'reverse' => false],

    ['id' => 'q6', 'area' => 'couple',
     'text' => "My partner and I are on the same page about parenting.",
     'low'  => 'Very different','high' => 'Very aligned',  'reverse' => false],

    ['id' => 'q7', 'area' => 'couple',
     'text' => "How supported do you feel by your partner day-to-day?",
     'low'  => 'Alone',         'high' => 'Fully',         'reverse' => false],

    ['id' => 'q8', 'area' => 'self',
     'text' => "How often do you feel exhausted by parenting?",
     'low'  => 'Daily',         'high' => 'Rarely',        'reverse' => false],

    ['id' => 'q9', 'area' => 'self',
     'text' => "I have at least one person I can talk to about parenting worries.",
     'low'  => 'No one',        'high' => 'Several',       'reverse' => false],

    ['id' => 'q10','area' => 'self',
     'text' => "I get some time for myself each week.",
     'low'  => 'Never',         'high' => 'Plenty',        'reverse' => false],
];

$area_counts = ['confidence' => 0, 'concern' => 0, 'couple' => 0, 'self' => 0];
foreach ($QUESTIONS as $q) $area_counts[$q['area']]++;

function pi_score_to_band(int $score): array {
    if ($score >= 81) return ['key' => 'thriving',     'label' => 'Thriving',          'emoji' => '🌟', 'color' => 'emerald'];
    if ($score >= 56) return ['key' => 'doing_well',   'label' => 'Doing well',        'emoji' => '🌱', 'color' => 'green'];
    if ($score >= 31) return ['key' => 'some_strain',  'label' => 'Some strain',       'emoji' => '🌤️', 'color' => 'amber'];
    return                  ['key' => 'needs_support','label' => 'Needs support',     'emoji' => '🤝', 'color' => 'rose'];
}

function pi_area_label(string $key): string {
    return [
        'confidence' => 'Parenting confidence',
        'concern'    => 'Child concern',
        'couple'     => 'Couple alignment',
        'self'       => 'Your own wellbeing',
    ][$key] ?? $key;
}

function pi_area_emoji(string $key): string {
    return [
        'confidence' => '💪',
        'concern'    => '👶',
        'couple'     => '💑',
        'self'       => '🌿',
    ][$key] ?? '·';
}

// ── Handle submit ──────────────────────────────────────
$show_result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please refresh and try again.';
    } else {
        $answers = [];
        $missing = false;
        foreach ($QUESTIONS as $q) {
            $v = (int)($_POST[$q['id']] ?? 0);
            if ($v < 1 || $v > 5) { $missing = true; break; }
            $answers[$q['id']] = $v;
        }
        if ($missing) {
            $error = 'Please answer all 10 questions before submitting.';
        } else {
            // Score per area: sum-normalized to 0-25
            $area_sums = ['confidence' => 0, 'concern' => 0, 'couple' => 0, 'self' => 0];
            foreach ($QUESTIONS as $q) {
                $v = $answers[$q['id']];
                if (!empty($q['reverse'])) $v = 6 - $v;
                $area_sums[$q['area']] += $v;
            }
            // each area: sum is between N*1 and N*5; normalize to 0-25
            $area_scores = [];
            foreach ($area_sums as $a => $sum) {
                $n = $area_counts[$a];
                $min = $n * 1; $max = $n * 5;
                $area_scores[$a] = (int)round(($sum - $min) / ($max - $min) * 25);
            }
            $overall = (int)round(array_sum($area_scores) * (100 / 100)); // sum is already 0-100
            $band    = pi_score_to_band($overall);

            // Save
            db()->prepare("INSERT INTO parent_index_results
                (parent_id, score_confidence, score_concern, score_couple, score_self, score_overall, band, answers_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $parent_id,
                   $area_scores['confidence'],
                   $area_scores['concern'],
                   $area_scores['couple'],
                   $area_scores['self'],
                   $overall,
                   $band['key'],
                   json_encode($answers, JSON_UNESCAPED_UNICODE),
               ]);

            $rid = (int) db()->lastInsertId();
            header('Location: /parent-index.php?result=' . $rid);
            exit;
        }
    }
}

// ── Determine view: form, or result ────────────────────
$view = 'form';
$result_row = null;

if (!empty($_GET['result'])) {
    $rid = (int)$_GET['result'];
    $st = db()->prepare("SELECT * FROM parent_index_results WHERE id = ? AND parent_id = ?");
    $st->execute([$rid, $parent_id]);
    $result_row = $st->fetch();
    if ($result_row) $view = 'result';
}

// Past results (for "history" section)
$past = [];
if ($view === 'result') {
    $ps = db()->prepare("SELECT id, score_overall, band, created_at FROM parent_index_results
                          WHERE parent_id = ? AND id != ? ORDER BY id DESC LIMIT 5");
    $ps->execute([$parent_id, $result_row['id']]);
    $past = $ps->fetchAll();
}

$page_title = 'Free 2-min parenting check';
require __DIR__ . '/includes/header.php';
?>

<style>
  .pi-wrap { max-width: 760px; margin: 0 auto; }

  .pi-hero {
    background:
      radial-gradient(700px 320px at 0% 0%, rgba(255,212,150,0.5), transparent 60%),
      radial-gradient(600px 320px at 100% 100%, rgba(168,230,207,0.4), transparent 60%),
      linear-gradient(135deg, #FFF7E6 0%, #FFE9D6 60%, #E9F7EF 100%);
    border-radius: 24px;
    padding: 28px 22px;
    margin-bottom: 22px;
  }

  .pi-question {
    background: white;
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 14px;
    border: 1.5px solid #f1f5f9;
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .pi-question.answered { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.08); }
  .pi-q-text { font-size: 16px; font-weight: 600; color: #0f172a; line-height: 1.45; }
  .pi-q-area { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px; }

  .pi-scale-grid {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px;
    margin-top: 12px;
  }
  .pi-scale-btn {
    padding: 10px 4px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    background: white;
    font-weight: 700;
    font-size: 15px;
    color: #475569;
    cursor: pointer;
    transition: all 0.12s ease;
  }
  .pi-scale-btn:hover { border-color: #f59e0b; background: #fffbeb; }
  .pi-scale-btn.selected { border-color: #ea580c; background: #fb923c; color: white; transform: scale(1.04); }

  .pi-scale-labels { display: flex; justify-content: space-between; font-size: 11px; color: #94a3b8; margin-top: 6px; }

  .pi-submit-bar {
    position: sticky; bottom: 16px;
    background: white;
    border-radius: 18px;
    padding: 16px 20px;
    box-shadow: 0 -6px 30px rgba(15,23,42,0.08);
    border: 1px solid #e2e8f0;
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px;
    margin-top: 24px;
  }
  .pi-progress-text { font-size: 13px; font-weight: 600; color: #475569; }
  .pi-btn-submit {
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
    color: white; border: none;
    padding: 12px 24px;
    border-radius: 999px;
    font-weight: 700; font-size: 15px;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(234, 88, 12, 0.3);
    transition: transform 0.12s;
  }
  .pi-btn-submit:hover { transform: translateY(-1px); }
  .pi-btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

  /* Result view */
  .pi-overall-card {
    background: linear-gradient(135deg, #fef3c7 0%, #fed7aa 100%);
    border-radius: 22px; padding: 26px;
    text-align: center;
    margin-bottom: 18px;
    border: 1px solid #fcd34d;
  }
  .pi-overall-score { font-size: 64px; font-weight: 800; color: #92400e; line-height: 1; }
  .pi-overall-label { font-size: 13px; color: #78350f; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; margin-top: 4px; }
  .pi-overall-band { display: inline-block; padding: 6px 16px; border-radius: 999px; font-weight: 700; font-size: 14px; margin-top: 10px; }

  .pi-area-card {
    background: white;
    border-radius: 14px;
    padding: 16px 18px;
    border: 1px solid #e2e8f0;
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 10px;
  }
  .pi-area-emoji { font-size: 26px; }
  .pi-area-bar {
    flex: 1;
    height: 8px;
    background: #f1f5f9;
    border-radius: 999px;
    overflow: hidden;
    margin-top: 6px;
  }
  .pi-area-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #f97316, #f59e0b);
    border-radius: 999px;
  }
  .pi-area-score { font-size: 16px; font-weight: 700; color: #0f172a; min-width: 50px; text-align: right; }

  .pi-cta-paid {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: white;
    border-radius: 22px;
    padding: 26px 22px;
    text-align: center;
    margin-top: 22px;
  }
  .pi-cta-paid h3 { font-size: 20px; font-weight: 700; margin-bottom: 6px; }
  .pi-cta-paid p { font-size: 14px; opacity: 0.92; line-height: 1.5; margin-bottom: 16px; }
  .pi-cta-paid a {
    display: inline-block;
    background: white; color: #4f46e5;
    padding: 12px 24px;
    border-radius: 999px;
    font-weight: 700; font-size: 14px;
    text-decoration: none;
  }
</style>

<div class="pi-wrap">

<?php if ($view === 'form'): ?>

  <!-- HERO -->
  <div class="pi-hero">
    <div class="text-xs font-bold uppercase tracking-wider text-amber-700 mb-2">
      🎁 Your free parenting check
    </div>
    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 leading-tight mb-2">
      How are you doing as a parent — really?
    </h1>
    <p class="text-sm sm:text-base text-slate-700 leading-relaxed">
      10 honest questions. 2 minutes. A real report. <strong>No payment, no commitment.</strong>
      This is our gift to you — a snapshot of where you stand across 4 areas
      that matter most for Indian parents.
    </p>
  </div>

  <?php if ($error): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-lg p-3 mb-4"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" id="piForm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <?php foreach ($QUESTIONS as $i => $q):
      $qid = $q['id'];
      $area = $q['area'];
    ?>
      <div class="pi-question" data-qid="<?= e($qid) ?>">
        <div class="pi-q-area"><?= e(pi_area_emoji($area)) ?> <?= e(pi_area_label($area)) ?></div>
        <div class="pi-q-text"><?= ($i + 1) ?>. <?= e($q['text']) ?></div>
        <div class="pi-scale-grid">
          <?php for ($v = 1; $v <= 5; $v++): ?>
            <button type="button" class="pi-scale-btn" data-q="<?= e($qid) ?>" data-v="<?= $v ?>"><?= $v ?></button>
          <?php endfor; ?>
        </div>
        <div class="pi-scale-labels">
          <span>1 — <?= e($q['low']) ?></span>
          <span>5 — <?= e($q['high']) ?></span>
        </div>
        <input type="hidden" name="<?= e($qid) ?>" id="hidden_<?= e($qid) ?>" value="0">
      </div>
    <?php endforeach; ?>

    <div class="pi-submit-bar">
      <div class="pi-progress-text" id="progressText">0 / 10 answered</div>
      <button class="pi-btn-submit" id="submitBtn" type="submit" disabled>See my report →</button>
    </div>
  </form>

  <script>
  (function () {
    const TOTAL = 10;
    const answered = {};
    const btn = document.getElementById('submitBtn');
    const progress = document.getElementById('progressText');

    document.querySelectorAll('.pi-scale-btn').forEach(b => {
      b.addEventListener('click', () => {
        const q = b.dataset.q, v = parseInt(b.dataset.v, 10);
        answered[q] = v;
        document.getElementById('hidden_' + q).value = v;

        // Visual: highlight selected
        const container = b.closest('.pi-question');
        container.querySelectorAll('.pi-scale-btn').forEach(x => x.classList.remove('selected'));
        b.classList.add('selected');
        container.classList.add('answered');

        // Update progress
        const n = Object.keys(answered).length;
        progress.textContent = n + ' / ' + TOTAL + ' answered';
        if (n === TOTAL) {
          btn.disabled = false;
          progress.style.color = '#059669';
        }
      });
    });
  })();
  </script>

<?php else: /* result view */
  $band = pi_score_to_band((int)$result_row['score_overall']);
  $areas = [
    'confidence' => (int)$result_row['score_confidence'],
    'concern'    => (int)$result_row['score_concern'],
    'couple'     => (int)$result_row['score_couple'],
    'self'       => (int)$result_row['score_self'],
  ];
  // Find lowest area for "focus on this" suggestion
  asort($areas);
  $lowest_area = array_key_first($areas);
  $lowest_score = $areas[$lowest_area];
  arsort($areas);
  $highest_area = array_key_first($areas);
  // restore order for display
  $display_areas = [
    'confidence' => (int)$result_row['score_confidence'],
    'concern'    => (int)$result_row['score_concern'],
    'couple'     => (int)$result_row['score_couple'],
    'self'       => (int)$result_row['score_self'],
  ];

  $suggestions = [
    'confidence' => "Try this: each evening, write down ONE thing you handled well as a parent today. Tiny wins compound into confidence.",
    'concern'    => "Try this: this week, pick ONE specific worry and write it down with three observations. Patterns become much clearer than vague worry.",
    'couple'     => "Try this: 10 minutes a day, talk to your partner about something other than the kids — work, news, food. Couple-bond first, then parenting becomes easier.",
    'self'       => "Try this: protect 30 minutes for yourself this week — non-negotiable. A rested parent is a steadier parent.",
  ];
?>

  <!-- Overall score -->
  <div class="pi-overall-card">
    <div class="pi-overall-label">Your parenting check</div>
    <div class="pi-overall-score"><?= (int)$result_row['score_overall'] ?><span style="font-size: 28px; color: #b45309;">/100</span></div>
    <div class="pi-overall-band bg-white text-slate-800">
      <?= e($band['emoji']) ?> <?= e($band['label']) ?>
    </div>
    <p class="text-sm text-amber-900 mt-3 italic">
      Taken <?= date('M j, Y · g:i a', strtotime($result_row['created_at'])) ?>
    </p>
  </div>

  <!-- Area breakdown -->
  <h2 class="text-lg font-bold text-slate-900 mb-3">Across 4 areas</h2>
  <?php foreach ($display_areas as $key => $score): ?>
    <div class="pi-area-card">
      <div class="pi-area-emoji"><?= e(pi_area_emoji($key)) ?></div>
      <div class="flex-1">
        <div class="font-semibold text-slate-900 text-sm"><?= e(pi_area_label($key)) ?></div>
        <div class="pi-area-bar"><div class="pi-area-bar-fill" style="width: <?= ($score * 4) ?>%"></div></div>
      </div>
      <div class="pi-area-score"><?= $score ?>/25</div>
    </div>
  <?php endforeach; ?>

  <!-- What you're doing well -->
  <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-5 mt-4">
    <div class="text-xs font-bold uppercase tracking-wider text-emerald-700 mb-1">🌟 What's working</div>
    <div class="text-base font-semibold text-emerald-900">
      Your strongest area: <?= e(pi_area_label($highest_area)) ?>
    </div>
    <p class="text-sm text-emerald-800 mt-2">
      You're scoring <?= $display_areas[$highest_area] ?>/25 here. Whatever you're doing on this front — keep it.
    </p>
  </div>

  <!-- What to focus on -->
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mt-3">
    <div class="text-xs font-bold uppercase tracking-wider text-amber-700 mb-1">🎯 One thing this week</div>
    <div class="text-base font-semibold text-amber-900 mb-2">
      Your stretch area: <?= e(pi_area_label($lowest_area)) ?>
    </div>
    <p class="text-sm text-amber-900 leading-relaxed">
      <?= e($suggestions[$lowest_area]) ?>
    </p>
  </div>

  <!-- CTA toward paid eval -->
  <div class="pi-cta-paid">
    <h3>Want a much deeper look?</h3>
    <p>
      The full <strong>AI Parent Evaluation</strong> is a private 15-minute voice conversation that explores
      <strong>9 areas of your life</strong> — including finances, family stress, your hopes for your child, and your relationship.
      You get a detailed PDF report and a callback from our psychologist within 48 hours.
    </p>
    <a href="/parent-reflect.php">Take the full AI eval →</a>
  </div>

  <!-- Retake / history -->
  <div class="mt-6 text-center space-y-2">
    <a href="/parent-index.php" class="inline-block px-5 py-2 bg-white border border-slate-300 rounded-full text-sm font-semibold text-slate-700 hover:bg-slate-50">
      🔄 Retake this check
    </a>
    <div class="block">
      <a href="/dashboard.php" class="text-sm text-slate-500 hover:text-indigo-600 underline">← Back to dashboard</a>
    </div>
  </div>

  <?php if (!empty($past)): ?>
    <div class="mt-6 bg-white border border-slate-200 rounded-xl p-4">
      <h4 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-3">Your previous check-ins</h4>
      <div class="space-y-2">
        <?php foreach ($past as $p):
          $pb = pi_score_to_band((int)$p['score_overall']);
        ?>
          <a href="/parent-index.php?result=<?= (int)$p['id'] ?>" class="flex items-center justify-between p-2 rounded-lg hover:bg-slate-50">
            <span class="text-sm text-slate-700"><?= e(date('M j, Y', strtotime($p['created_at']))) ?></span>
            <span class="text-sm font-semibold text-slate-900"><?= e($pb['emoji']) ?> <?= (int)$p['score_overall'] ?>/100 · <?= e($pb['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
