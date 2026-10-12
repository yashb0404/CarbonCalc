<?php
require '../includes/auth.php';
require_admin();
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        switch ($_POST['action'] ?? '') {
            case 'add_category':
                $db->prepare('INSERT INTO category (category_name, unit) VALUES (?, ?)')
                   ->execute([trim($_POST['category_name']), trim($_POST['unit'])]);
                flash('Category “' . trim($_POST['category_name']) . '” added. Add a factor to it so people can log it.'); break;
            case 'add':
                $db->prepare('INSERT INTO emission_factor (category_id, factor_name, unit, kg_co2_per_unit, source) VALUES (?, ?, ?, ?, ?)')
                   ->execute([(int)$_POST['category_id'], trim($_POST['factor_name']), trim($_POST['unit']), (float)$_POST['value'], trim($_POST['source'])]);
                flash('Factor “' . trim($_POST['factor_name']) . '” added. It now appears on the Log activity page.'); break;
            case 'update':
                // Past entries keep factor_value_used, so history is not rewritten.
                $db->prepare('UPDATE emission_factor SET kg_co2_per_unit = ?, source = ? WHERE factor_id = ?')
                   ->execute([(float)$_POST['value'], trim($_POST['source']), (int)$_POST['factor_id']]);
                flash('Factor saved. New entries use the new value; existing entries keep the value they were calculated with.'); break;
            case 'toggle':
                $db->prepare('UPDATE emission_factor SET is_active = 1 - is_active WHERE factor_id = ?')
                   ->execute([(int)$_POST['factor_id']]);
                flash('Factor status changed.'); break;
        }
    } catch (PDOException $ex) {
        $msg = $ex->errorInfo[1] == 1062 ? 'That name already exists in this category.' : 'The database rejected this change: ' . $ex->errorInfo[2];
        flash($msg, 'err');
    }
    mongo_log('admin_' . ($_POST['action'] ?? 'unknown'), array_diff_key($_POST, ['csrf' => 1]));
    header('Location: factors.php'); exit;
}

$cats = $db->query('SELECT * FROM category ORDER BY category_name')->fetchAll();
$rows = $db->query('SELECT f.*, c.category_name,
                      (SELECT COUNT(*) FROM activity_log a WHERE a.factor_id = f.factor_id) AS uses
                    FROM emission_factor f JOIN category c USING (category_id)
                    ORDER BY c.category_name, f.kg_co2_per_unit DESC')->fetchAll();
$stats = $db->query('SELECT (SELECT COUNT(*) FROM users) u, (SELECT COUNT(*) FROM activity_log) a,
                            (SELECT COALESCE(SUM(co2_kg),0) FROM activity_log) kg,
                            (SELECT COUNT(*) FROM emission_factor WHERE is_active = 1) f')->fetch();
$fmt = 'trimnum';
$title = 'Emission factors · CarbonCalc';
include '../includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="eyebrow">Admin</div>
    <h1>Emission factors</h1>
    <p>The values every calculation uses. Changing one affects new entries only — each past entry stores the factor it was calculated with.</p>
  </div>
</div>

<div class="grid g3" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
  <div class="card eq"><span class="ci" style="--cc:var(--ozone)"><?= icon('dashboard') ?></span><div><div class="v num"><?= (int)$stats['u'] ?></div><p>users</p></div></div>
  <div class="card eq"><span class="ci" style="--cc:var(--c-food)"><?= icon('history') ?></span><div><div class="v num"><?= number_format($stats['a']) ?></div><p>entries logged</p></div></div>
  <div class="card eq"><span class="ci" style="--cc:var(--c-fuel)"><?= icon('trend') ?></span><div><div class="v num"><?= kg((float)$stats['kg']) ?></div><p>CO₂ tracked in total</p></div></div>
  <div class="card eq"><span class="ci" style="--cc:var(--c-electricity)"><?= icon('sliders') ?></span><div><div class="v num"><?= (int)$stats['f'] ?></div><p>active factors</p></div></div>
</div>

<section class="card mt">
  <div class="card-head"><div><h3>All factors</h3><p>Edit a value or source and press Save. Turn a factor off to hide it from the Log activity page without losing history.</p></div></div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Activity</th><th>kg CO₂ per unit</th><th>Source</th><th class="r">Used</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'f' . $r['factor_id']; ?>
    <tr class="admin-row <?= $r['is_active'] ? '' : 'off' ?>">
      <td style="min-width:190px"><div style="display:flex;gap:10px;align-items:center"><?= cat_icon($r['category_name']) ?>
        <div><b style="font-weight:600"><?= e($r['factor_name']) ?></b><span class="sub"><?= e($r['category_name']) ?> · per <?= e($r['unit']) ?></span></div></div></td>
      <td><form method="post" class="inline" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="update">
          <input type="hidden" name="factor_id" value="<?= $r['factor_id'] ?>">
          <input type="number" name="value" step="0.0001" min="0" value="<?= e($fmt($r['kg_co2_per_unit'])) ?>" class="sm" aria-label="kg CO₂ per <?= e($r['unit']) ?> for <?= e($r['factor_name']) ?>"></form></td>
      <td><div class="admin-row"><div class="inline" style="display:flex;gap:6px;align-items:center">
          <input name="source" form="<?= $fid ?>" value="<?= e($r['source']) ?>" style="margin:0;padding:7px 9px;font-size:13px;min-width:220px" aria-label="Source for <?= e($r['factor_name']) ?>">
          <button class="btn-quiet btn-sm" form="<?= $fid ?>">Save</button></div></div></td>
      <td class="r num"><?= (int)$r['uses'] ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle">
          <button class="status <?= $r['is_active'] ? 'on' : 'off' ?>" name="factor_id" value="<?= $r['factor_id'] ?>" title="<?= $r['is_active'] ? 'Turn off' : 'Turn on' ?>"><i></i><?= $r['is_active'] ? 'Active' : 'Off' ?></button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<div class="grid g2 mt">
  <form class="card" method="post">
    <div class="card-head"><div><h3>Add a factor</h3><p>Shows up on the Log activity page straight away.</p></div></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Category <select name="category_id"><?php foreach ($cats as $c): ?><option value="<?= $c['category_id'] ?>"><?= e($c['category_name']) ?></option><?php endforeach; ?></select></label>
    <label>Activity name <input name="factor_name" required placeholder="e.g. Electric car"></label>
    <div class="row2">
      <label>Unit <input name="unit" required placeholder="km, kWh, kg"></label>
      <label>kg CO₂ per unit <input type="number" name="value" step="0.0001" min="0" required class="num"></label>
    </div>
    <label>Source <input name="source" required placeholder="Publication and year"></label>
    <button>Add factor</button>
  </form>
  <form class="card" method="post">
    <div class="card-head"><div><h3>Add a category</h3><p>A new category is a new row in the <code>category</code> table — no code changes needed.</p></div></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="add_category">
    <label>Category name <input name="category_name" required placeholder="e.g. Water"></label>
    <label>Default unit <input name="unit" required placeholder="e.g. litre"></label>
    <button>Add category</button>
  </form>
</div>
<?php include '../includes/footer.php'; ?>
