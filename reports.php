<?php
require 'includes/auth.php';
require_login();
$uid = $_SESSION['user_id'];
$db  = db();
$rep = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $s = $_POST['start'] ?? ''; $e = $_POST['end'] ?? '';
    if (!$s || !$e || $e < $s) {
        flash('The end date needs to be on or after the start date.', 'err');
        header('Location: reports.php'); exit;
    }
    $st = $db->prepare('CALL sp_generate_report(?, ?, ?)');   // stored procedure
    $st->execute([$uid, $s, $e]);
    $rep = $st->fetch();
    $st->closeCursor();
    mongo_log('report_generated', ['report_id' => (int)$rep['report_id'], 'from' => $s, 'to' => $e, 'total_co2_kg' => (float)$rep['total_co2_kg']]);
    header('Location: reports.php?id=' . (int)$rep['report_id']); exit;
}

if (isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM report WHERE report_id = ? AND user_id = ?');
    $st->execute([(int)$_GET['id'], $uid]);
    $rep = $st->fetch() ?: null;
}

$bd = $top = [];
if ($rep) {
    $q = $db->prepare('SELECT c.category_name, ROUND(SUM(a.co2_kg),2) kg, COUNT(*) n
                       FROM activity_log a JOIN emission_factor f USING (factor_id) JOIN category c USING (category_id)
                       WHERE a.user_id = ? AND a.log_date BETWEEN ? AND ? GROUP BY c.category_name ORDER BY kg DESC');
    $q->execute([$uid, $rep['period_start'], $rep['period_end']]);
    $bd = $q->fetchAll();
    $q = $db->prepare('SELECT f.factor_name, f.unit, c.category_name, SUM(a.quantity) qty, ROUND(SUM(a.co2_kg),2) kg
                       FROM activity_log a JOIN emission_factor f USING (factor_id) JOIN category c USING (category_id)
                       WHERE a.user_id = ? AND a.log_date BETWEEN ? AND ? GROUP BY f.factor_id ORDER BY kg DESC LIMIT 5');
    $q->execute([$uid, $rep['period_start'], $rep['period_end']]);
    $top = $q->fetchAll();
    $days = (int)((strtotime($rep['period_end']) - strtotime($rep['period_start'])) / 86400) + 1;
    $periodBudget = user_budget() * $days / 30.44;
    $total = (float)$rep['total_co2_kg'];
}

$past = $db->prepare('SELECT * FROM report WHERE user_id = ? ORDER BY generated_at DESC, report_id DESC LIMIT 20');
$past->execute([$uid]); $past = $past->fetchAll();

$presets = [
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'Last 3 months' => [date('Y-m-01', strtotime('-2 months')), date('Y-m-d')],
    'This year' => [date('Y-01-01'), date('Y-m-d')],
];
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 2, '.', ''), '0'), '.');
$title = ($rep ? 'Report #' . $rep['report_id'] : 'Reports') . ' · CarbonCalc';
include 'includes/header.php';
?>
<div class="page-head noprint">
  <div>
    <h1>Reports</h1>
    <p>Choose a period to get a summary you can print or save as PDF. Each report is saved, so you can open it again later.</p>
  </div>
</div>

<form class="card noprint" method="post">
  <?= csrf_field() ?>
  <div class="presets">
    <?php foreach ($presets as $label => [$a, $b2]): ?>
      <button type="button" class="btn-quiet" data-from="<?= $a ?>" data-to="<?= $b2 ?>"><?= $label ?></button>
    <?php endforeach; ?>
  </div>
  <div class="filters">
    <label>Start <input type="date" name="start" id="start" value="<?= e($rep['period_start'] ?? date('Y-m-01')) ?>" max="<?= date('Y-m-d') ?>" required></label>
    <label>End <input type="date" name="end" id="end" value="<?= e($rep['period_end'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required></label>
    <div class="actions"><button><?= icon('report') ?>Create report</button></div>
  </div>
</form>

<?php if ($rep): ?>
<article class="card sheet mt">
  <header class="sheet-head">
    <div>
      <div class="eyebrow">CarbonCalc emission report</div>
      <h2 style="font-size:28px"><?= e($_SESSION['name']) ?></h2>
      <p class="muted" style="margin:4px 0 0"><?= e(date('j M Y', strtotime($rep['period_start']))) ?> – <?= e(date('j M Y', strtotime($rep['period_end']))) ?> · <?= $days ?> days</p>
    </div>
    <div class="meta">Report #<?= (int)$rep['report_id'] ?><br>Created <?= e(date('j M Y, H:i', strtotime($rep['generated_at']))) ?><br>
      <button class="btn-quiet btn-sm noprint" onclick="window.print()" style="margin-top:8px"><?= icon('print') ?>Print / save PDF</button></div>
  </header>

  <div class="sheet-total">
    <div><div class="k">Total emissions</div><div class="v num"><?= kg($total, false) ?> <small><?= $total >= 1000 ? 't' : 'kg' ?> CO₂</small></div></div>
    <div><div class="k">Budget for this period</div><div class="v num"><?= kg($periodBudget, false) ?> <small><?= $periodBudget >= 1000 ? 't' : 'kg' ?></small></div>
      <?php if ($rep['entry_count'] > 0): ?><span class="verdict <?= $total > $periodBudget ? 'over' : '' ?>" style="margin-top:6px"><i></i><?= $total > $periodBudget ? kg($total - $periodBudget) . ' over' : kg($periodBudget - $total) . ' under' ?></span><?php endif; ?></div>
    <div><div class="k">Daily average</div><div class="v num"><?= kg($total / max(1, $days), false) ?> <small>kg/day</small></div><span class="faint" style="font-size:13px"><?= (int)$rep['entry_count'] ?> entries</span></div>
  </div>

  <?php if ($bd): ?>
  <h3 style="margin-bottom:8px">By category</h3>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Category</th><th class="r">Entries</th><th class="r">CO₂ (kg)</th><th style="width:40%">Share</th></tr></thead>
    <tbody>
    <?php foreach ($bd as $r): $pct = $total > 0 ? $r['kg'] / $total * 100 : 0; ?>
      <tr><td><?= cat_chip($r['category_name']) ?></td><td class="r num"><?= (int)$r['n'] ?></td><td class="kg-cell"><?= kg((float)$r['kg'], false) ?></td>
          <td style="<?= cat_meta($r['category_name'])['style'] ?>"><span class="share-bar" style="width:<?= max(2, round($pct * .8, 1)) ?>%"></span><span class="num faint" style="font-size:13px"><?= number_format($pct, 1) ?>%</span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <h3 style="margin:28px 0 8px">Largest sources</h3>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Activity</th><th class="r">Amount</th><th class="r">CO₂ (kg)</th></tr></thead>
    <tbody>
    <?php foreach ($top as $r): ?>
      <tr><td><div style="display:flex;gap:10px;align-items:center"><?= cat_icon($r['category_name']) ?><b style="font-weight:600"><?= e($r['factor_name']) ?></b></div></td>
          <td class="r num"><?= e($fmtQty($r['qty'])) ?> <span class="faint"><?= e($r['unit']) ?></span></td><td class="kg-cell"><?= kg((float)$r['kg'], false) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="grid g3 mt">
    <div class="eq"><span class="ci" style="--cc:var(--c-food)"><?= icon('tree') ?></span><div><div class="v num"><?= number_format($total / KG_PER_TREE_YEAR, 1) ?></div><p>tree-years to absorb</p></div></div>
    <div class="eq"><span class="ci" style="--cc:var(--c-travel)"><?= icon('travel') ?></span><div><div class="v num"><?= number_format($total / KG_PER_CAR_KM) ?> km</div><p>in a petrol car, equivalent</p></div></div>
    <div class="eq"><span class="ci" style="--cc:var(--c-electricity)"><?= icon('ac') ?></span><div><div class="v num"><?= number_format($total / KG_PER_AC_HOUR) ?> h</div><p>of a 1.5-ton AC, equivalent</p></div></div>
  </div>
  <?php else: ?>
    <div class="empty"><span class="ci"><?= icon('report') ?></span><h3>No entries in this period</h3><p>The report was saved with a total of 0 kg. Pick dates that include logged activities.</p></div>
  <?php endif; ?>

  <footer class="sheet-foot">Totals are created by the stored procedure <code>sp_generate_report</code> and saved in the <code>report</code> table. Each entry is calculated with the emission factor that applied when it was logged (sources: DEFRA, CEA, IPCC 2006, Poore &amp; Nemecek 2018). Budget: <?= kg(user_budget()) ?> a month, scaled to <?= $days ?> days. <span class="noprint">· <a href="methodology.php">Method and sources</a></span></footer>
</article>
<?php endif; ?>

<section class="card mt noprint">
  <div class="card-head"><h3>Saved reports</h3></div>
  <?php if ($past): ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Period</th><th class="r">Entries</th><th class="r">CO₂ (kg)</th><th class="hide-sm">Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($past as $p): ?>
      <tr<?= $rep && $rep['report_id'] == $p['report_id'] ? ' style="background:var(--ozone-soft)"' : '' ?>>
        <td class="num faint"><?= (int)$p['report_id'] ?></td>
        <td class="date-cell"><?= e(date('j M Y', strtotime($p['period_start'])) . ' – ' . date('j M Y', strtotime($p['period_end']))) ?></td>
        <td class="r num"><?= (int)$p['entry_count'] ?></td>
        <td class="kg-cell"><?= kg((float)$p['total_co2_kg'], false) ?></td>
        <td class="date-cell hide-sm"><?= e(date('j M, H:i', strtotime($p['generated_at']))) ?></td>
        <td class="r"><a class="btn btn-text btn-sm" href="?id=<?= (int)$p['report_id'] ?>">Open</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
    <p class="muted" style="margin:0">No saved reports yet. Pick a period above and create your first one.</p>
  <?php endif; ?>
</section>

<script>
document.querySelectorAll('[data-from]').forEach(b => b.addEventListener('click', () => {
  document.getElementById('start').value = b.dataset.from;
  document.getElementById('end').value = b.dataset.to;
  b.closest('form').requestSubmit();
}));
</script>
<?php include 'includes/footer.php'; ?>
