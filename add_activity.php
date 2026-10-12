<?php
require 'includes/auth.php';
require_login();
$db  = db();
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $fid  = (int)($_POST['factor_id'] ?? 0);
    $qty  = (float)($_POST['quantity'] ?? 0);
    $date = $_POST['log_date'] ?? '';
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if ($fid <= 0) {
        flash('Choose an activity before saving.', 'err');
    } elseif ($qty <= 0) {
        flash('Enter an amount above 0.', 'err');
    } elseif (!$d || $date > date('Y-m-d')) {
        mongo_log('validation_failed', ['form' => 'add_activity', 'quantity' => $qty, 'date' => $date]);
        flash('Pick a date that is today or earlier.', 'err');
    } else {
        // co2_kg and factor_value_used are filled by trigger trg_activity_compute
        $db->prepare('INSERT INTO activity_log (user_id, factor_id, quantity, log_date, note) VALUES (?, ?, ?, ?, ?)')
           ->execute([$uid, $fid, $qty, $date, trim($_POST['note'] ?? '') ?: null]);
        $newId = (int)$db->lastInsertId();
        $row = $db->query('SELECT a.co2_kg, a.factor_value_used, f.factor_name, f.unit FROM activity_log a JOIN emission_factor f USING (factor_id) WHERE a.log_id = ' . $newId)->fetch();
        mongo_log('activity_added', ['log_id' => $newId, 'activity' => $row['factor_name'], 'quantity' => $qty, 'unit' => $row['unit'],
                                     'factor_used' => (float)$row['factor_value_used'], 'co2_kg' => (float)$row['co2_kg'], 'log_date' => $date]);
        flash('Saved ' . $row['factor_name'] . ' — ' . kg((float)$row['co2_kg']) . ' CO₂ added to ' . date('F', strtotime($date)) . '.', 'ok',
              ['See it on the overview', 'dashboard.php?m=' . substr($date, 0, 7)]);
    }
    header('Location: add_activity.php'); exit;
}

$factors = $db->query('SELECT f.factor_id, f.factor_name, f.unit, f.kg_co2_per_unit, f.source, c.category_name
                       FROM emission_factor f JOIN category c USING (category_id)
                       WHERE f.is_active = 1 ORDER BY c.category_id, f.kg_co2_per_unit DESC')->fetchAll();
$groups = [];
foreach ($factors as $f) $groups[$f['category_name']][] = $f;

// Month so far, so the result can show its share of the budget
$st = $db->prepare("SELECT COALESCE(SUM(co2_kg),0) FROM activity_log WHERE user_id = ? AND DATE_FORMAT(log_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')");
$st->execute([$uid]); $monthSoFar = (float)$st->fetchColumn();
$budget = user_budget();

// The user's most recent distinct activities, for one-tap repeats
$st = $db->prepare('SELECT a.factor_id, a.quantity, f.factor_name, f.unit, c.category_name
                    FROM activity_log a JOIN emission_factor f USING (factor_id) JOIN category c USING (category_id)
                    WHERE a.user_id = ? AND f.is_active = 1
                      AND a.log_id IN (SELECT MAX(log_id) FROM activity_log WHERE user_id = ? GROUP BY factor_id)
                    ORDER BY a.log_id DESC LIMIT 4');
$st->execute([$uid, $uid]); $repeat = $st->fetchAll();

$title = 'Log activity · CarbonCalc';
include 'includes/header.php';
$fmtQty = 'trimnum';
?>
<div class="page-head">
  <div>
    <h1>Log an activity</h1>
    <p>Choose what you did and how much. The database works out the CO₂ using the factor shown, and keeps that factor with the entry.</p>
  </div>
</div>

<div class="add-layout">
<form method="post" class="card" id="add" novalidate>
  <?= csrf_field() ?>

  <?php if ($repeat): ?>
  <p class="step">Log again</p>
  <div class="presets" style="margin-bottom:24px">
    <?php foreach ($repeat as $r): ?>
      <button type="button" class="btn-quiet" data-repeat="<?= $r['factor_id'] ?>" data-qty="<?= e($fmtQty($r['quantity'])) ?>" style="<?= cat_meta($r['category_name'])['style'] ?>">
        <i class="dot"></i><?= e($r['factor_name']) ?> · <span class="num"><?= e($fmtQty($r['quantity']) . ' ' . $r['unit']) ?></span></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="step"><b>1</b> Category</p>
  <div class="cat-picker" role="radiogroup" aria-label="Category">
    <?php foreach ($groups as $cat => $list): $m = cat_meta($cat); ?>
      <label style="<?= $m['style'] ?>"><input type="radio" name="cat" value="<?= e($cat) ?>">
        <span class="tile"><?= cat_icon($cat) ?><span><?= e($cat) ?></span></span></label>
    <?php endforeach; ?>
  </div>

  <p class="step"><b>2</b> Activity</p>
  <div class="opt-hint" id="optHint">Choose a category to see its activities.</div>
  <div class="opt-list" id="opts">
    <?php foreach ($groups as $cat => $list): foreach ($list as $f): ?>
      <label data-cat="<?= e($cat) ?>" style="<?= cat_meta($cat)['style'] ?>">
        <input type="radio" name="factor_id" value="<?= $f['factor_id'] ?>" required
               data-name="<?= e($f['factor_name']) ?>" data-cat="<?= e($cat) ?>" data-unit="<?= e($f['unit']) ?>"
               data-f="<?= $f['kg_co2_per_unit'] ?>" data-src="<?= e($f['source']) ?>">
        <span class="opt"><b><?= e($f['factor_name']) ?></b><small><?= trimnum($f['kg_co2_per_unit']) ?> kg / <?= e($f['unit']) ?></small></span>
      </label>
    <?php endforeach; endforeach; ?>
  </div>

  <p class="step"><b>3</b> Details</p>
  <label for="qty">How much? <span class="hint" id="qtyHint">· choose an activity first</span></label>
  <div class="field-unit" style="margin:-8px 0 14px">
    <input type="number" name="quantity" id="qty" step="0.01" min="0.01" inputmode="decimal" placeholder="0" required>
    <span class="u" id="unit">—</span>
  </div>
  <div class="row2">
    <label>Date
      <input type="date" name="log_date" id="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
      <span class="quick-dates">
        <button type="button" class="btn-quiet" data-date="<?= date('Y-m-d') ?>">Today</button>
        <button type="button" class="btn-quiet" data-date="<?= date('Y-m-d', strtotime('-1 day')) ?>">Yesterday</button>
      </span>
    </label>
    <label>Note <span class="hint">· optional</span>
      <input name="note" maxlength="255" placeholder="e.g. Office commute"></label>
  </div>
  <button class="btn-block" id="save" style="margin-top:6px">Save activity</button>
</form>

<aside class="result idle" id="result" aria-live="polite">
  <div class="card">
    <div class="eyebrow">This entry</div>
    <div class="result-kg num" id="rkg">0.0<small>kg CO₂</small></div>
    <div class="formula" id="formula">quantity × emission factor</div>
    <div class="src" id="src">Pick an activity to see where its factor comes from.</div>
    <a class="src-inline" href="methodology.php#sources" style="display:inline-block;margin:-8px 0 16px">All sources and how they’re used</a>
    <div class="eyebrow" style="margin-top:4px">Your <?= date('F') ?> budget</div>
    <div class="minibar"><i style="width:<?= min(100, round($monthSoFar / $budget * 100, 2)) ?>%"></i><i id="addBar" style="width:0"></i></div>
    <div class="faint" style="font-size:13px" id="budgetLine"><?= kg($monthSoFar) ?> used of <?= kg($budget) ?></div>
    <div class="warn-big" id="warn"></div>
    <div class="eq" style="margin-top:14px"><span class="ci" style="--cc:var(--c-food)"><?= icon('tree') ?></span>
      <div><div class="v num" id="trees">0</div><p>tree-years to absorb it</p></div></div>
    <div class="eq"><span class="ci" style="--cc:var(--c-electricity)"><?= icon('ac') ?></span>
      <div><div class="v num" id="ac">0 h</div><p>of a 1.5-ton AC, for comparison</p></div></div>
  </div>
</aside>
</div>

<script>
(() => {
  const form = document.getElementById('add'), qty = document.getElementById('qty');
  const opts = [...document.querySelectorAll('#opts label')], hint = document.getElementById('optHint');
  const $ = id => document.getElementById(id);
  const soFar = <?= json_encode($monthSoFar) ?>, budget = <?= json_encode($budget) ?>;
  const fmt = (n, d = 1) => n.toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d });

  function showCat(cat) {
    opts.forEach(l => l.hidden = l.dataset.cat !== cat);
    hint.hidden = !!cat;
    const cur = form.querySelector('input[name=factor_id]:checked');
    if (cur && cur.dataset.cat !== cat) cur.checked = false;
    const visible = opts.filter(l => !l.hidden);
    if (visible.length === 1) visible[0].querySelector('input').checked = true;
    update();
  }

  function update() {
    const o = form.querySelector('input[name=factor_id]:checked');
    const box = $('result');
    if (!o) {
      box.classList.add('idle'); $('unit').textContent = '—'; $('qtyHint').textContent = '· choose an activity first';
      $('rkg').innerHTML = '0.0<small>kg CO₂</small>'; $('formula').textContent = 'quantity × emission factor';
      $('src').textContent = 'Pick an activity to see where its factor comes from.'; $('addBar').style.width = 0; $('warn').classList.remove('show');
      $('trees').textContent = '0'; $('ac').textContent = '0 h'; $('save').textContent = 'Save activity';
      return;
    }
    box.style.setProperty('--cc', getComputedStyle(o.closest('label')).getPropertyValue('--cc'));
    const q = parseFloat(qty.value) || 0, f = parseFloat(o.dataset.f), kg = q * f, u = o.dataset.unit;
    $('unit').textContent = u;
    $('qtyHint').textContent = '· in ' + u;
    box.classList.toggle('idle', q <= 0);
    $('rkg').innerHTML = (kg >= 1000 ? fmt(kg / 1000, 2) + '<small>t CO₂</small>' : fmt(kg, kg < 100 ? 2 : 0) + '<small>kg CO₂</small>');
    $('formula').innerHTML = `<b>${q || 0} ${u}</b> × <b>${f}</b> kg CO₂/${u} = <b>${fmt(kg, 2)} kg</b>`;
    $('src').textContent = 'Factor for ' + o.dataset.name + ': ' + o.dataset.src;
    const room = Math.max(budget, soFar + kg);
    document.querySelector('.minibar i').style.width = (soFar / room * 100) + '%';
    $('addBar').style.width = (kg / room * 100) + '%';
    const after = soFar + kg;
    $('budgetLine').textContent = `${fmt(soFar)} kg used + ${fmt(kg)} kg = ${fmt(after)} kg of ${fmt(budget, 0)} kg` + (after > budget ? ' (over budget)' : '');
    $('trees').textContent = fmt(kg / <?= KG_PER_TREE_YEAR ?>, kg / <?= KG_PER_TREE_YEAR ?> < 10 ? 2 : 0);
    $('ac').textContent = fmt(kg / <?= KG_PER_AC_HOUR ?>, 0) + ' h';
    $('save').textContent = q > 0 ? `Save activity · ${fmt(kg, kg < 100 ? 2 : 0)} kg CO₂` : 'Save activity';
    const w = $('warn');
    w.classList.toggle('show', kg > budget);
    w.textContent = kg > budget ? 'This one entry is more than your whole monthly budget. Check the amount and unit before saving.' : '';
  }

  form.querySelectorAll('input[name=cat]').forEach(r => r.addEventListener('change', () => showCat(r.value)));
  form.querySelectorAll('input[name=factor_id]').forEach(r => r.addEventListener('change', () => { update(); qty.focus(); }));
  qty.addEventListener('input', update);
  document.querySelectorAll('[data-date]').forEach(b => b.addEventListener('click', () => { $('date').value = b.dataset.date; }));
  document.querySelectorAll('[data-repeat]').forEach(b => b.addEventListener('click', () => {
    const r = form.querySelector(`input[name=factor_id][value="${b.dataset.repeat}"]`);
    form.querySelector(`input[name=cat][value="${CSS.escape(r.dataset.cat)}"]`).checked = true;
    showCat(r.dataset.cat); r.checked = true; qty.value = b.dataset.qty; update(); qty.focus(); qty.select();
  }));

  form.addEventListener('submit', ev => {
    const o = form.querySelector('input[name=factor_id]:checked'), q = parseFloat(qty.value) || 0;
    let msg = '';
    if (!o) msg = 'Choose an activity first.';
    else if (q <= 0) { msg = 'Enter an amount above 0.'; qty.focus(); }
    if (msg) { ev.preventDefault(); const w = $('warn'); w.textContent = msg; w.classList.add('show'); return; }
    const kg = q * parseFloat(o.dataset.f);
    if (kg > budget && !confirm(`This entry is ${fmt(kg)} kg CO₂ — more than your whole monthly budget. Save it anyway?`)) ev.preventDefault();
  });

  showCat(null);
})();
</script>
<?php include 'includes/footer.php'; ?>
