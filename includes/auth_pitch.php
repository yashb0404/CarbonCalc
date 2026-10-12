<?php
// Left half of the login / register screens. Numbers come from the live database.
$facts = db()->query('SELECT (SELECT COUNT(*) FROM emission_factor WHERE is_active = 1) f,
                             (SELECT COUNT(*) FROM category) c,
                             (SELECT COUNT(*) FROM activity_log) s')->fetch();
// Illustrative month: shares of a 208 kg budget
$sample = [['Electricity', 74], ['Cooking Fuel', 33], ['Food', 46], ['Travel', 18], ['Waste', 9]];
$used = array_sum(array_column($sample, 1));
?>
<div class="auth-page">
  <section class="auth-pitch">
    <a class="brand" href="index.php">
      <svg class="mark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#fff"/><rect x="6" y="13" width="9" height="6" rx="1.5" fill="#1E4FD8"/><rect x="16" y="13" width="5" height="6" rx="1.5" fill="#E0A100"/><rect x="22" y="13" width="4" height="6" rx="1.5" fill="#2F9E6E"/></svg>
      <span><b>CarbonCalc</b><small>Personal CO₂ ledger</small></span>
    </a>
    <h1>Know what your <em>month</em> weighs.</h1>
    <p class="lede">Log a commute, an electricity bill or a week of groceries. CarbonCalc turns it into kilograms of CO₂ with published emission factors, and shows how much of a 1.5 °C-aligned budget you have left.</p>

    <div class="pitch-strip" aria-label="Example month: <?= $used ?> of 208 kg used">
      <div class="top"><span>Example month</span><span><b><?= $used ?> kg</b> of 208 kg budget</span></div>
      <div class="strip-track">
        <?php foreach ($sample as [$n, $v]): ?>
          <div class="strip-seg" style="width:<?= round($v / 208 * 100, 2) ?>%;<?= cat_meta($n)['style'] ?>"></div>
        <?php endforeach; ?>
      </div>
      <div class="legend">
        <?php foreach ($sample as [$n, $v]): ?><?= cat_chip($n) ?><?php endforeach; ?>
      </div>
    </div>

    <div class="auth-facts">
      <span><b><?= (int)$facts['f'] ?></b>emission factors</span>
      <span><b><?= (int)$facts['c'] ?></b>categories</span>
      <span><b><?= number_format($facts['s']) ?></b>entries logged</span>
    </div>
    <a href="methodology.php" style="color:#9DB7FF;font-weight:600;font-size:14px;margin-top:22px;text-decoration:none">See where every number comes from →</a>
  </section>
