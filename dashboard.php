<?php
require 'includes/auth.php';
require_login();
$uid = $_SESSION['user_id'];
$db  = db();

// Change monthly budget
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['budget'])) {
    csrf_check();
    $nb = (float)$_POST['budget'];
    if ($nb > 0 && $nb <= 100000) {
        $db->prepare('UPDATE users SET monthly_budget_kg = ? WHERE user_id = ?')->execute([$nb, $uid]);
        mongo_log('budget_changed', ['monthly_budget_kg' => $nb]);
        flash('Monthly budget set to ' . kg($nb) . '.');
    } else {
        flash('Enter a budget above 0 kg.', 'err');
    }
    header('Location: dashboard.php' . (isset($_GET['m']) ? '?m=' . urlencode($_GET['m']) : '')); exit;
}

// Which month? ?m=YYYY-MM, never in the future
$thisMonth = date('Y-m');
$m = (isset($_GET['m']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['m']) && $_GET['m'] <= $thisMonth) ? $_GET['m'] : $thisMonth;
$first = $db->prepare("SELECT DATE_FORMAT(MIN(log_date),'%Y-%m') FROM activity_log WHERE user_id = ?");
$first->execute([$uid]); $firstMonth = $first->fetchColumn() ?: $thisMonth;
$prevM = date('Y-m', strtotime("$m-01 -1 month"));
$nextM = date('Y-m', strtotime("$m-01 +1 month"));
$isCurrent = $m === $thisMonth;
$daysIn = (int)date('t', strtotime("$m-01"));
$day = $isCurrent ? (int)date('j') : $daysIn;

// Category totals for the month (from view v_monthly_summary)
$st = $db->prepare('SELECT category_name, total_co2_kg kg, entries FROM v_monthly_summary WHERE user_id = ? AND month = ? ORDER BY total_co2_kg DESC');
$st->execute([$uid, $m]); $cats = $st->fetchAll();
$total = array_sum(array_column($cats, 'kg'));
$entries = array_sum(array_column($cats, 'entries'));

// Previous month, cut at the same day of the month so a half-finished month is compared fairly
$st = $db->prepare("SELECT COALESCE(SUM(co2_kg),0) FROM activity_log
                    WHERE user_id = ? AND DATE_FORMAT(log_date,'%Y-%m') = ? AND DAY(log_date) <= ?");
$st->execute([$uid, $prevM, $isCurrent ? (int)date('j') : 31]); $prevTotal = (float)$st->fetchColumn();

$budget = user_budget();
$projected = $isCurrent && $day > 0 ? $total / $day * $daysIn : $total;
$scale = max($budget, $total) * 1.0;

// Trend: six months ending at the selected one, stacked by category
$from6 = date('Y-m', strtotime("$m-01 -5 months"));
$st = $db->prepare('SELECT month, category_name, total_co2_kg kg FROM v_monthly_summary WHERE user_id = ? AND month BETWEEN ? AND ? ORDER BY month');
$st->execute([$uid, $from6, $m]);
$months = []; for ($i = 5; $i >= 0; $i--) $months[] = date('Y-m', strtotime("$m-01 -$i months"));
$series = [];
foreach ($st->fetchAll() as $r) $series[$r['category_name']][$r['month']] = (float)$r['kg'];
$datasets = [];
foreach ($series as $name => $byMonth) {
    $datasets[] = ['label' => $name, 'var' => cat_meta($name)['var'], 'data' => array_map(fn($mm) => round($byMonth[$mm] ?? 0, 1), $months)];
}

// Per-activity totals this month, used for the suggestion
$st = $db->prepare('SELECT f.factor_name, c.category_name, SUM(a.quantity) qty, SUM(a.co2_kg) kg
                    FROM activity_log a JOIN emission_factor f USING (factor_id) JOIN category c USING (category_id)
                    WHERE a.user_id = ? AND DATE_FORMAT(a.log_date, \'%Y-%m\') = ? GROUP BY f.factor_id');
$st->execute([$uid, $m]);
$acts = []; foreach ($st->fetchAll() as $r) $acts[$r['factor_name']] = $r;
$fac = $db->query('SELECT factor_name, kg_co2_per_unit FROM emission_factor')->fetchAll(PDO::FETCH_KEY_PAIR);
$fv = fn($n, $d) => (float)($fac[$n] ?? $d);

// Every rule that applies becomes a candidate; the one with the largest saving is shown
$cands = [];
foreach ($cats as $c) {
    $n = $c['category_name']; $k = (float)$c['kg'];
    if ($n === 'Travel') {
        $carKm = ($acts['Petrol car']['qty'] ?? 0) + ($acts['Diesel car']['qty'] ?? 0);
        if ($carKm > 0) { $cands[] = [$n, 'Take the metro for your car trips', 'You drove ' . number_format($carKm) . ' km this month. The same distance by metro emits about a fifth as much.', $carKm * ($fv('Petrol car', .17) - $fv('Metro / rail', .035))]; }
        if (!empty($acts['Domestic flight'])) { $cands[] = [$n, 'Go by train for the next short trip', 'Flights are the most carbon-heavy way to cover distance. Rail emits about 7× less per km.', $acts['Domestic flight']['qty'] * ($fv('Domestic flight', .25) - $fv('Metro / rail', .035))]; }
    }
    if ($n === 'Electricity' && $k > 0) { $cands[] = [$n, 'Trim electricity by 10%', 'Running the AC at 24 °C instead of 20 °C, and switching off standby loads, is usually enough.', $k * .10]; }
    if ($n === 'Food') {
        if (!empty($acts['Beef'])) { $cands[] = [$n, 'Swap beef for chicken', 'Beef has the highest footprint of any common food — about 4× chicken per kg.', $acts['Beef']['qty'] * ($fv('Beef', 27) - $fv('Chicken', 6.9))]; }
        if (!empty($acts['Chicken'])) { $cands[] = [$n, 'Swap half your chicken for dal and vegetables', 'Per kg, vegetables and pulses have a fraction of the footprint of chicken.', $acts['Chicken']['qty'] * .5 * ($fv('Chicken', 6.9) - $fv('Vegetables', .5))]; }
        if ($k > 0 && empty($acts['Chicken']) && empty($acts['Beef'])) { $cands[] = [$n, 'Cut food waste', 'Buying a little less and finishing leftovers trims emissions with no change in diet.', $k * .10]; }
    }
    if ($n === 'Cooking Fuel' && $k > 0) { $cands[] = [$n, 'Cook with lids and a pressure cooker', 'Covered pots and pressure cooking can cut LPG use by around a fifth.', $k * .20]; }
    if ($n === 'Waste' && $k > 0) { $cands[] = [$n, 'Compost wet waste', 'Kitchen scraps are about half of household waste. Composting keeps them out of landfill.', $k * .50]; }
}
usort($cands, fn($x, $y) => $y[3] <=> $x[3]);
$nudge = $cands[0] ?? null;

// Latest entries in the month
$st = $db->prepare('SELECT a.log_date, a.quantity, a.co2_kg, a.note, f.factor_name, f.unit, c.category_name
                    FROM activity_log a JOIN emission_factor f USING (factor_id) JOIN category c USING (category_id)
                    WHERE a.user_id = ? AND DATE_FORMAT(a.log_date, \'%Y-%m\') = ? ORDER BY a.log_date DESC, a.log_id DESC LIMIT 6');
$st->execute([$uid, $m]); $recent = $st->fetchAll();

$monthLabel = date('F Y', strtotime("$m-01"));
$firstName = explode(' ', trim($_SESSION['name']))[0];
$title = 'Overview · CarbonCalc';
include 'includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="eyebrow"><?= $isCurrent ? 'Day ' . $day . ' of ' . $daysIn : 'Closed month' ?></div>
    <h1><?= $isCurrent ? 'Hi ' . e($firstName) . ', here’s ' . date('F') . ' so far' : e($monthLabel) ?></h1>
  </div>
  <nav class="month-nav" aria-label="Choose month">
    <a href="?m=<?= $prevM ?>" class="<?= $m <= $firstMonth ? 'off' : '' ?>" aria-label="Previous month">←</a>
    <span><?= e($monthLabel) ?></span>
    <a href="?m=<?= $nextM ?>" class="<?= $isCurrent ? 'off' : '' ?>" aria-label="Next month">→</a>
  </nav>
</div>

<section class="card budget" aria-label="Monthly carbon budget">
  <div class="budget-top">
    <div>
      <div class="budget-fig num"><?= kg($total, false) ?><small><?= $total >= 1000 ? 't' : 'kg' ?> CO₂</small></div>
      <p class="budget-sub">
        <?php if (!$entries): ?>
          Nothing logged for <?= e($monthLabel) ?> yet.
        <?php elseif ($total <= $budget): ?>
          <b><?= kg($budget - $total) ?></b> left of your <?= kg($budget) ?> monthly budget · <?= $entries ?> entries
        <?php else: ?>
          <b><?= kg($total - $budget) ?> over</b> your <?= kg($budget) ?> monthly budget · <?= $entries ?> entries
        <?php endif; ?>
      </p>
    </div>
    <?php if (!$entries): ?>
      <span class="verdict none"><i></i>No data</span>
    <?php elseif ($isCurrent): ?>
      <span class="verdict <?= $projected > $budget ? 'over' : '' ?>"><i></i><?= $projected > $budget ? 'On pace to finish at ' . kg($projected) : 'On track · heading for ' . kg($projected) ?></span>
    <?php else: ?>
      <span class="verdict <?= $total > $budget ? 'over' : '' ?>"><i></i><?= $total > $budget ? 'Over budget' : 'Within budget' ?></span>
    <?php endif; ?>
  </div>

  <div class="strip">
    <div class="strip-track" role="img" aria-label="<?= kg($total) ?> used of <?= kg($budget) ?> budget">
      <?php foreach ($cats as $c): ?>
        <div class="strip-seg" title="<?= e($c['category_name'] . ': ' . kg((float)$c['kg'])) ?>" style="width:<?= round($c['kg'] / $scale * 100, 3) ?>%;<?= cat_meta($c['category_name'])['style'] ?>"></div>
      <?php endforeach; ?>
      <?php if ($total > $budget): ?><div class="strip-over" style="width:<?= round(($total - $budget) / $scale * 100, 3) ?>%"></div><?php endif; ?>
    </div>
    <?php $bp = round($budget / $scale * 100, 3); ?>
    <div class="strip-line<?= $bp > 92 ? ' edge' : '' ?>" style="left:<?= $bp ?>%"><span>Budget <?= kg($budget) ?></span></div>
    <?php if ($isCurrent && $day < $daysIn): ?>
      <div class="strip-today" style="left:<?= round($budget * $day / $daysIn / $scale * 100, 3) ?>%">fair share by today</div>
    <?php endif; ?>
  </div>
  <?php if ($cats): ?>
  <div class="legend">
    <?php foreach ($cats as $c): ?>
      <span class="cat" style="<?= cat_meta($c['category_name'])['style'] ?>"><i class="dot"></i><?= e($c['category_name']) ?> <b><?= kg((float)$c['kg']) ?></b></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <details class="noprint" style="margin-top:14px">
    <summary class="faint" style="cursor:pointer;font-size:13px">Where does <?= kg($budget) ?> come from? Change it</summary>
    <p class="muted" style="font-size:13.5px;margin-top:10px;max-width:70ch">To stay in line with the 1.5 °C goal, lifestyle emissions need to fall to about 2.5 t CO₂ per person per year by 2030 (Hot or Cool Institute, 2021). That is roughly 208 kg a month. Set your own target below. <a href="methodology.php#assumptions">More on this</a></p>
    <form method="post" class="filters" style="max-width:360px">
      <?= csrf_field() ?>
      <label>Monthly budget (kg CO₂) <input type="number" name="budget" min="1" step="1" value="<?= (int)$budget ?>" class="num"></label>
      <button class="btn-quiet">Save budget</button>
    </form>
  </details>
</section>

<?php if ($entries): ?>
<div class="grid g3 mt">
  <div class="card eq"><span class="ci" style="--cc:var(--c-food)"><?= icon('tree') ?></span>
    <div><div class="v num"><?= number_format($total / KG_PER_TREE_YEAR, 1) ?></div><p>trees would need a full year to absorb this</p></div></div>
  <div class="card eq"><span class="ci" style="--cc:var(--c-electricity)"><?= icon('ac') ?></span>
    <div><div class="v num"><?= number_format($total / KG_PER_AC_HOUR) ?> h</div><p>of running a 1.5-ton AC on the Indian grid</p></div></div>
  <?php if ($prevTotal > 0): $d = ($total - $prevTotal) / $prevTotal * 100; ?>
  <div class="card eq"><span class="ci" style="--cc:var(<?= $d > 0 ? '--over' : '--under' ?>)"><?= icon('trend') ?></span>
    <div><div class="v num <?= $d > 0 ? 'delta-up' : 'delta-down' ?>"><?= ($d > 0 ? '+' : '−') . number_format(abs($d)) ?>%</div>
    <p><?= $isCurrent ? 'vs the same point in ' . date('F', strtotime("$prevM-01")) : 'vs ' . date('F', strtotime("$prevM-01")) ?> (<?= kg($prevTotal) ?>)</p></div></div>
  <?php else: ?>
  <div class="card eq"><span class="ci" style="--cc:var(--c-travel)"><?= icon('travel') ?></span>
    <div><div class="v num"><?= number_format($total / KG_PER_CAR_KM) ?> km</div><p>in an average petrol car would emit the same</p></div></div>
  <?php endif; ?>
</div>
<p class="faint" style="font-size:12.5px;margin:8px 2px 0">Comparisons use averages: 22 kg CO₂ per tree per year, 1.07 kg per AC hour, 0.17 kg per car-km. <a class="src-inline" href="methodology.php#assumptions">How these are worked out</a></p>
<?php endif; ?>
<?php if ($nudge): [$nc, $nt, $nd, $ns] = $nudge; ?>
<section class="card nudge mt" style="<?= cat_meta($nc)['style'] ?>">
  <?= cat_icon($nc) ?>
  <div><div class="eyebrow" style="margin:0 0 2px">Biggest lever this month · <?= e($nc) ?></div><h3><?= e($nt) ?></h3><p><?= e($nd) ?></p></div>
  <div class="save"><b class="num">−<?= kg($ns) ?></b><small>possible saving on <?= e(date('F', strtotime("$m-01"))) ?>’s total</small></div>
</section>
<?php endif; ?>

<?php if (!$entries && !$recent): ?>
<section class="card empty mt">
  <span class="ci"><?= icon('plus') ?></span>
  <h3>Log your first activity for <?= e(date('F', strtotime("$m-01"))) ?></h3>
  <p>An electricity bill or the week’s commute is a good start. You’ll see the result in kilograms straight away.</p>
  <a class="btn btn-primary" href="add_activity.php">Log activity</a>
</section>
<?php endif; ?>

<div class="grid g-wide mt">
  <section class="card">
    <div class="card-head"><div><h3>Last six months</h3><p>Monthly total by category. The dashed line is your budget.</p></div></div>
    <div style="position:relative;height:290px"><canvas id="trend" aria-label="Bar chart of monthly emissions"></canvas></div>
  </section>
  <section class="card">
    <div class="card-head"><div><h3>Where it comes from</h3><p><?= e($monthLabel) ?></p></div></div>
    <?php if ($cats): ?>
    <ul class="bars">
      <?php foreach ($cats as $c): $pct = $total > 0 ? $c['kg'] / $total * 100 : 0; ?>
      <li style="<?= cat_meta($c['category_name'])['style'] ?>">
        <?= cat_chip($c['category_name']) ?>
        <span class="amt"><?= kg((float)$c['kg']) ?><small><?= number_format($pct) ?>%</small></span>
        <span class="track"><span class="fill" style="display:block;width:<?= round($pct, 2) ?>%"></span></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="muted">No entries this month.</p><?php endif; ?>
  </section>
</div>

<?php if ($recent): ?>
<section class="card mt">
  <div class="card-head"><h3>Latest entries</h3><a class="btn-text btn" href="history.php?from=<?= $m ?>-01&to=<?= $m ?>-<?= $daysIn ?>">See all for <?= e(date('F', strtotime("$m-01"))) ?> →</a></div>
  <ul class="feed">
    <?php foreach ($recent as $r): ?>
    <li><?= cat_icon($r['category_name']) ?>
      <span class="what"><b><?= e($r['factor_name']) ?> · <span class="num"><?= e(trimnum($r['quantity']) . ' ' . $r['unit']) ?></span></b>
        <small><?= e(date('D j M', strtotime($r['log_date']))) ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?></small></span>
      <span class="kg"><?= kg((float)$r['co2_kg']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<script src="assets/js/chart.umd.min.js"></script>
<script>
(() => {
  const css = getComputedStyle(document.documentElement);
  const v = n => css.getPropertyValue(n).trim();
  const labels = <?= json_encode(array_map(fn($mm) => date('M', strtotime("$mm-01")), $months)) ?>;
  const sets = <?= json_encode($datasets) ?>;
  const budget = <?= json_encode($budget) ?>;
  Chart.defaults.font.family = v('--body');
  Chart.defaults.color = v('--ink-3');
  new Chart(document.getElementById('trend'), {
    data: {
      labels,
      datasets: [
        { type: 'line', label: 'Budget', data: labels.map(() => budget), borderColor: v('--ink'), borderDash: [5, 4], borderWidth: 1.5, pointRadius: 0, order: 0 },
        ...sets.map(s => ({ type: 'bar', label: s.label, data: s.data, backgroundColor: v(s.var), borderRadius: 3, borderSkipped: false, maxBarThickness: 46, order: 1 }))
      ]
    },
    options: {
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: { stacked: true, grid: { display: false }, border: { display: false } },
        y: { stacked: true, beginAtZero: true, grid: { color: v('--line-2') }, border: { display: false }, ticks: { callback: x => x + ' kg' } }
      },
      plugins: {
        legend: { display: false },
        tooltip: { backgroundColor: v('--ink'), padding: 10, cornerRadius: 8, boxPadding: 4,
          callbacks: { label: c => ` ${c.dataset.label}: ${Number(c.raw).toFixed(1)} kg`,
                       footer: items => 'Total: ' + items.filter(i => i.dataset.type === 'bar').reduce((a, i) => a + Number(i.raw), 0).toFixed(1) + ' kg' } }
      }
    }
  });
})();
</script>
<?php include 'includes/footer.php'; ?>
