<?php
require 'includes/auth.php';
require_login();
$uid = $_SESSION['user_id'];
$db  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    csrf_check();
    $db->prepare('DELETE FROM activity_log WHERE log_id = ? AND user_id = ?')->execute([(int)$_POST['delete'], $uid]);
    mongo_log('activity_deleted', ['log_id' => (int)$_POST['delete']]);
    flash('Entry deleted. Your overview and history no longer count it.');
    $back = preg_replace('/[^\w=&%\-]/', '', $_POST['back'] ?? '');
    header('Location: history.php' . ($back ? "?$back" : '')); exit;
}

$isDate = fn($s) => is_string($s) && ($d = DateTime::createFromFormat('Y-m-d', $s)) && $d->format('Y-m-d') === $s;
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01', strtotime('-2 months'));
$to   = $isDate($_GET['to'] ?? null)   ? $_GET['to']   : date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
$cid  = (int)($_GET['category'] ?? 0);

$sql = 'SELECT a.log_id, a.log_date, c.category_name, f.factor_name, a.quantity, f.unit,
               a.factor_value_used, f.kg_co2_per_unit, a.co2_kg, f.source, a.note
        FROM activity_log a
        JOIN emission_factor f ON f.factor_id = a.factor_id
        JOIN category c        ON c.category_id = f.category_id
        WHERE a.user_id = ? AND a.log_date BETWEEN ? AND ?';
$args = [$uid, $from, $to];
if ($cid) { $sql .= ' AND c.category_id = ?'; $args[] = $cid; }
$sql .= ' ORDER BY a.log_date DESC, a.log_id DESC';
$st = $db->prepare($sql); $st->execute($args); $rows = $st->fetchAll();

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="carbon_history_' . $from . '_to_' . $to . '.csv"');
    mongo_log('csv_exported', ['from' => $from, 'to' => $to, 'rows' => count($rows)]);
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads CO₂ correctly
    fputcsv($out, ['Date','Category','Activity','Quantity','Unit','Factor used (kg/unit)','CO2 (kg)','Factor source','Note']);
    foreach ($rows as $r) fputcsv($out, [$r['log_date'],$r['category_name'],$r['factor_name'],$r['quantity'],$r['unit'],$r['factor_value_used'],$r['co2_kg'],$r['source'],$r['note']]);
    exit;
}

$cats = $db->query('SELECT category_id, category_name FROM category ORDER BY category_name')->fetchAll();
$sum = array_sum(array_column($rows, 'co2_kg'));
$byCat = [];
foreach ($rows as $r) $byCat[$r['category_name']] = ($byCat[$r['category_name']] ?? 0) + $r['co2_kg'];
arsort($byCat);

$ranges = [
    'This month'    => [date('Y-m-01'), date('Y-m-d')],
    'Last month'    => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'Last 3 months' => [date('Y-m-01', strtotime('-2 months')), date('Y-m-d')],
    'This year'     => [date('Y-01-01'), date('Y-m-d')],
];
$qs = fn(array $extra = []) => http_build_query(array_merge(['from' => $from, 'to' => $to, 'category' => $cid ?: null], $extra));
$fmtQty = 'trimnum';

$title = 'History · CarbonCalc';
include 'includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>History</h1>
    <p>Every entry you’ve logged. Each one keeps the emission factor it was calculated with, so old totals don’t change when a factor is updated. <a href="methodology.php#flow">How this works</a></p>
  </div>
  <a class="btn btn-quiet" href="history.php?<?= e($qs(['export' => 1])) ?>"><?= icon('download') ?>Export CSV</a>
</div>

<form class="card" method="get">
  <div class="presets">
    <?php foreach ($ranges as $label => [$a, $b2]): $on = $from === $a && $to === $b2; ?>
      <a class="btn btn-sm <?= $on ? 'btn-primary' : 'btn-quiet' ?>" href="?<?= e(http_build_query(['from' => $a, 'to' => $b2, 'category' => $cid ?: null])) ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filters">
    <label>From <input type="date" name="from" value="<?= e($from) ?>" max="<?= date('Y-m-d') ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>"></label>
    <label>Category
      <select name="category"><option value="0">All categories</option>
      <?php foreach ($cats as $c): ?><option value="<?= $c['category_id'] ?>" <?= $cid == $c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option><?php endforeach; ?>
      </select></label>
    <div class="actions"><button>Apply</button></div>
  </div>
</form>

<section class="card">
  <?php if ($rows): ?>
  <div class="summary-line">
    <span class="big num"><?= kg($sum) ?></span>
    <span class="muted"><?= count($rows) ?> <?= count($rows) === 1 ? 'entry' : 'entries' ?> · <?= e(date('j M Y', strtotime($from))) ?> – <?= e(date('j M Y', strtotime($to))) ?></span>
  </div>
  <div class="strip-track" style="height:10px;margin:14px 0 4px;border-radius:5px">
    <?php foreach ($byCat as $n => $v): ?><div class="strip-seg" title="<?= e($n . ': ' . kg($v)) ?>" style="width:<?= round($v / max($sum, .001) * 100, 3) ?>%;<?= cat_meta($n)['style'] ?>;border-right-width:1px"></div><?php endforeach; ?>
  </div>
  <div class="legend" style="border:0;margin-top:6px;padding-top:6px">
    <?php foreach ($byCat as $n => $v): ?><span class="cat" style="<?= cat_meta($n)['style'] ?>"><i class="dot"></i><?= e($n) ?> <b><?= kg($v) ?></b></span><?php endforeach; ?>
  </div>

  <div class="table-wrap mt">
  <table>
    <thead><tr><th>Date</th><th>Activity</th><th class="r">Amount</th><th class="r hide-sm">Factor used</th><th class="r">CO₂</th><th><span class="sr">Delete</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $changed = (float)$r['factor_value_used'] !== (float)$r['kg_co2_per_unit']; ?>
    <tr>
      <td class="date-cell"><?= e(date('j M Y', strtotime($r['log_date']))) ?><span class="sub"><?= e(date('l', strtotime($r['log_date']))) ?></span></td>
      <td style="min-width:200px"><div style="display:flex;gap:10px;align-items:center"><?= cat_icon($r['category_name']) ?>
        <div><b style="font-weight:600"><?= e($r['factor_name']) ?></b><span class="sub"><?= $r['note'] ? e($r['note']) : e($r['category_name']) ?></span></div></div></td>
      <td class="r num" style="white-space:nowrap"><?= e($fmtQty($r['quantity'])) ?> <span class="faint"><?= e($r['unit']) ?></span></td>
      <td class="r num hide-sm" title="Source: <?= e($r['source']) ?>"><?= e($fmtQty($r['factor_value_used'])) ?>
        <?php if ($changed): ?><span class="sub" style="color:var(--c-electricity)">now <?= e($fmtQty($r['kg_co2_per_unit'])) ?></span><?php endif; ?></td>
      <td class="kg-cell"><?= kg((float)$r['co2_kg']) ?></td>
      <td class="r"><form method="post" onsubmit="return confirm('Delete this entry? This can’t be undone.')"><?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
          <button class="icon-del" name="delete" value="<?= $r['log_id'] ?>" aria-label="Delete <?= e($r['factor_name'] . ' on ' . $r['log_date']) ?>" title="Delete entry"><?= icon('x') ?></button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="empty">
    <span class="ci"><?= icon('history') ?></span>
    <h3>No entries in this range</h3>
    <p>Try a wider date range or another category, or log something for these dates.</p>
    <a class="btn btn-primary" href="add_activity.php">Log activity</a>
  </div>
  <?php endif; ?>
</section>
<?php include 'includes/footer.php'; ?>
