<?php
require 'includes/auth.php';
// Open to everyone, so it can be read before logging in.
$public = empty($_SESSION['user_id']);
$db = db();

/* ---------- 1. A worked example with real rows ---------- */
$ex = null;
if (!$public) {
    $st = $db->prepare('SELECT a.log_id, a.quantity, a.factor_value_used, a.co2_kg, a.log_date, f.factor_name, f.unit, f.source, c.category_name
                        FROM activity_log a JOIN emission_factor f USING (factor_id) JOIN category c USING (category_id)
                        WHERE a.user_id = ? ORDER BY a.log_id DESC LIMIT 1');
    $st->execute([$_SESSION['user_id']]);
    $ex = $st->fetch() ?: null;
}
if (!$ex) {   // fallback: a worked example built from the factor table
    $f = $db->query("SELECT f.factor_name, f.unit, f.kg_co2_per_unit, f.source, c.category_name FROM emission_factor f JOIN category c USING (category_id) WHERE f.factor_name = 'Metro / rail'")->fetch()
      ?: $db->query('SELECT f.factor_name, f.unit, f.kg_co2_per_unit, f.source, c.category_name FROM emission_factor f JOIN category c USING (category_id) LIMIT 1')->fetch();
    $ex = ['log_id' => null, 'quantity' => 50, 'factor_value_used' => $f['kg_co2_per_unit'], 'co2_kg' => round(50 * $f['kg_co2_per_unit'], 3),
           'log_date' => date('Y-m-d'), 'factor_name' => $f['factor_name'], 'unit' => $f['unit'], 'source' => $f['source'], 'category_name' => $f['category_name']];
}
$fmt = 'trimnum';

/* ---------- 2. Sources, grouped by publisher ---------- */
$publishers = [
    'DEFRA' => ['UK Government GHG Conversion Factors', 'Department for Energy Security and Net Zero (DESNZ) / DEFRA, UK', 'Updated every year',
                'The official table companies use to report their emissions. Gives kg CO₂e per passenger-km for each transport mode and per tonne for waste.',
                'https://www.gov.uk/government/collections/government-conversion-factors-for-company-reporting'],
    'CEA'   => ['CO₂ Baseline Database for the Indian Power Sector', 'Central Electricity Authority, Ministry of Power, Government of India', 'Updated every year',
                'Average CO₂ emitted per kWh generated on India’s grid, from plant-level data. The value here is about 0.716 kg/kWh, close to recent editions.',
                'https://cea.nic.in/cdm-co2-baseline-database/?lang=en'],
    'IPCC'  => ['2006 IPCC Guidelines for National Greenhouse Gas Inventories, Vol. 2 (Energy)', 'Intergovernmental Panel on Climate Change', '2006',
                'Default emission factors per unit of fuel energy. For LPG: 63.1 kg CO₂ per GJ × 47.3 MJ per kg = 2.985 kg CO₂ per kg burned.',
                'https://www.ipcc-nggip.iges.or.jp/public/2006gl/vol2.html'],
    'EWG'   => ['Meat Eater’s Guide to Climate Change + Health', 'Environmental Working Group, with CleanMetrics', '2011',
                'Full life-cycle emissions per kg of food, from farm to plate including processing, transport, cooking and waste.',
                'https://www.ewg.org/meateatersguide/'],
    'Poore' => ['Reducing food’s environmental impacts through producers and consumers', 'J. Poore & T. Nemecek, Science 360 (6392)', '2018',
                'Meta-analysis of about 38,700 farms in 119 countries. Gives kg CO₂e per kg of food product.',
                'https://doi.org/10.1126/science.aaq0216'],
];
$rows = $db->query('SELECT f.factor_name, f.unit, f.kg_co2_per_unit, f.source, f.is_active, c.category_name
                    FROM emission_factor f JOIN category c USING (category_id) ORDER BY c.category_id, f.kg_co2_per_unit DESC')->fetchAll();
$bySrc = [];
foreach ($rows as $r) {
    $key = 'Other';
    foreach (array_keys($publishers) as $k) if (stripos($r['source'], $k) === 0) { $key = $k; break; }
    $bySrc[$key][] = $r;
}

/* ---------- 3. The live database structure, read from information_schema ---------- */
$tables = $db->query("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_TYPE, FIELD(TABLE_NAME,'users','category','emission_factor','activity_log','report')")->fetchAll(PDO::FETCH_KEY_PAIR);
$cols = [];
foreach ($db->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $c) $cols[$c['TABLE_NAME']][] = $c;
$fks = [];
foreach ($db->query('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL') as $k) $fks[$k['TABLE_NAME']][$k['COLUMN_NAME']] = $k['REFERENCED_TABLE_NAME'] . '.' . $k['REFERENCED_COLUMN_NAME'];
$counts = [];
foreach ($tables as $t => $type) if ($type === 'BASE TABLE') $counts[$t] = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
$triggers = $db->query('SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll();
$routines = $db->query('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchAll();
$indexes = $db->query("SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME LIKE 'idx%'")->fetchAll();
$tableNote = [
    'users' => 'One row per person. Passwords are stored only as bcrypt hashes.',
    'category' => 'Travel, Electricity, Cooking Fuel, Food, Waste — admins can add more.',
    'emission_factor' => 'Every factor stored once, with its source. The single place a value lives.',
    'activity_log' => 'Every entry, never overwritten. Keeps a copy of the factor it was calculated with.',
    'report' => 'Saved reports created by the stored procedure.',
    'v_monthly_summary' => 'View: monthly totals per user and category. Feeds the overview charts.',
];

$title = 'How it works · CarbonCalc';
include 'includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="eyebrow">Method, sources and database</div>
    <h1>Where every number comes from</h1>
    <p>CarbonCalc doesn’t guess. Each kilogram is a quantity you entered, multiplied by a published emission factor. The calculation runs inside MySQL, and every factor names its source. This page shows each step with live data.</p>
  </div>
</div>

<nav class="toc noprint" aria-label="On this page">
  <a href="#flow">From entry to chart</a><a href="#sources">Sources</a><a href="#assumptions">Assumptions</a><a href="#database">Database</a><a href="#faq">Questions</a>
</nav>

<!-- 1. FLOW -->
<section class="card mt" id="flow">
  <div class="card-head"><div><h2>From entry to chart</h2>
    <p><?= $ex['log_id'] ? 'Your latest entry, #' . (int)$ex['log_id'] . ', followed through the system.' : 'A worked example: 50 km by metro.' ?></p></div></div>
  <ol class="flow">
    <li>
      <span class="flow-tag">PHP · add_activity.php</span>
      <h3>You submit the form</h3>
      <p>The server checks that the quantity is above 0 and the date isn’t in the future, then inserts the row with a prepared statement. PHP never calculates CO₂.</p>
      <code class="flow-data"><?= e($ex['factor_name']) ?>, <?= e($fmt($ex['quantity'])) ?> <?= e($ex['unit']) ?>, <?= e($ex['log_date']) ?></code>
    </li>
    <li>
      <span class="flow-tag">MySQL trigger · trg_activity_compute</span>
      <h3>The database does the maths</h3>
      <p>Before the row is written, the trigger looks up the factor and stores both the result and the factor it used.</p>
      <code class="flow-data"><?= e($fmt($ex['quantity'])) ?> × <?= e($fmt($ex['factor_value_used'])) ?> = <b><?= e($fmt($ex['co2_kg'])) ?> kg CO₂</b></code>
    </li>
    <li>
      <span class="flow-tag">Table · activity_log</span>
      <h3>The entry is saved for good</h3>
      <p>The saved factor (<code>factor_value_used</code>) means old entries keep their value even if an admin later updates the factor.</p>
      <code class="flow-data">factor_value_used = <?= e($fmt($ex['factor_value_used'])) ?></code>
    </li>
    <li>
      <span class="flow-tag">View · v_monthly_summary</span>
      <h3>Totals are grouped</h3>
      <p>A view adds entries up by user, month and category. The overview and history pages read from it rather than repeating the sums.</p>
      <code class="flow-data">GROUP BY user_id, month, category</code>
    </li>
    <li>
      <span class="flow-tag">Procedure · sp_generate_report</span>
      <h3>Reports are created on request</h3>
      <p>One call totals any date range and saves the result in the <code>report</code> table, so a report can be reopened later.</p>
      <code class="flow-data">CALL sp_generate_report(user, start, end)</code>
    </li>
  </ol>
  <p class="flow-side"><?= icon('db') ?><span>Alongside these steps, each action is also written as a document to <b>MongoDB</b> (<code>activity_events</code>). That is an audit trail only. The numbers above never depend on it.</span></p>
</section>

<!-- 2. SOURCES -->
<section class="mt" id="sources">
  <div class="section-head"><h2>Sources</h2><p><?= count($rows) ?> emission factors from <?= count($bySrc) ?> published sources. These lists are read live from the <code>emission_factor</code> table.</p></div>
  <div class="grid g2">
  <?php foreach ($bySrc as $key => $list): $p = $publishers[$key] ?? null; ?>
    <article class="card src-card">
      <div class="src-meta"><span><?= e($p[2] ?? '') ?></span></div>
      <h3><?= e($p[0] ?? $list[0]['source']) ?></h3>
      <p class="faint" style="font-size:13px;margin:2px 0 10px"><?= e($p[1] ?? '') ?></p>
      <?php if ($p): ?><p class="muted" style="font-size:14px"><?= e($p[3]) ?></p><?php endif; ?>
      <table class="src-table">
        <tbody>
        <?php foreach ($list as $r): ?>
          <tr<?= $r['is_active'] ? '' : ' class="off"' ?>><td><?= cat_chip($r['category_name']) ?></td><td><?= e($r['factor_name']) ?></td>
              <td class="r num"><b><?= e($fmt($r['kg_co2_per_unit'])) ?></b> <span class="faint">kg/<?= e($r['unit']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($p): ?><a class="src-link" href="<?= e($p[4]) ?>" target="_blank" rel="noopener"><?= icon('link') ?>Open the source</a><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
  <p class="note"><?= icon('info') ?><span>Factors are reference averages, not measurements of your own car or meter. Transport and food values are CO₂-equivalent (they include methane and nitrous oxide), and the app labels everything “CO₂” for readability. Admins can update any value under <i>Emission factors</i>, and past entries keep the value they were calculated with.</span></p>
</section>

<!-- 3. ASSUMPTIONS -->
<section class="mt" id="assumptions">
  <div class="section-head"><h2>Assumptions</h2><p>The other numbers on screen, and why they are what they are.</p></div>
  <div class="grid g2">
    <div class="card assume"><span class="ci" style="--cc:var(--ozone)"><?= icon('dashboard') ?></span><div>
      <h3>Monthly budget · 208 kg</h3>
      <p>To keep warming to 1.5 °C, average lifestyle emissions need to fall to about <b>2.5 t CO₂e per person per year by 2030</b>. 2,500 ÷ 12 ≈ 208 kg. Each user can change it, and it is stored in <code>users.monthly_budget_kg</code>.</p>
      <a class="src-link" href="https://hotorcool.org/1-5-degree-lifestyles-report/" target="_blank" rel="noopener"><?= icon('link') ?>Hot or Cool Institute, 1.5-Degree Lifestyles (2021)</a></div></div>
    <div class="card assume"><span class="ci" style="--cc:var(--c-food)"><?= icon('tree') ?></span><div>
      <h3>Tree-years · 22 kg</h3>
      <p>A mature tree absorbs roughly <b>22 kg of CO₂ a year</b>. That is a widely used average, and real trees vary a lot by species, age and climate. It is only used to make kilograms easier to picture.</p>
      <a class="src-link" href="https://www.eea.europa.eu/articles/forests-health-and-climate-change" target="_blank" rel="noopener"><?= icon('link') ?>European Environment Agency</a></div></div>
    <div class="card assume"><span class="ci" style="--cc:var(--c-electricity)"><?= icon('ac') ?></span><div>
      <h3>AC hours · 1.07 kg/h</h3>
      <p>A 1.5-ton split AC draws about <b>1.5 kW</b> while the compressor runs. 1.5 kWh × 0.716 kg/kWh (Indian grid factor above) ≈ 1.07 kg CO₂ per hour.</p></div></div>
    <div class="card assume"><span class="ci" style="--cc:var(--c-travel)"><?= icon('trend') ?></span><div>
      <h3>“On pace” projection</h3>
      <p>For the current month: total so far ÷ days elapsed × days in the month. The “fair share by today” marker is the budget × days elapsed ÷ days in the month.</p></div></div>
  </div>
</section>

<!-- 4. DATABASE -->
<section class="mt" id="database">
  <div class="section-head"><h2>The database, live</h2><p>Read from MySQL’s <code>information_schema</code> each time this page loads. What you see is exactly what’s running.</p></div>

  <div class="obj-row">
    <span class="obj"><b><?= count(array_filter($tables, fn($t) => $t === 'BASE TABLE')) ?></b>tables</span>
    <span class="obj"><b><?= count(array_filter($tables, fn($t) => $t === 'VIEW')) ?></b>view</span>
    <span class="obj"><b><?= count($triggers) ?></b>trigger</span>
    <span class="obj"><b><?= count($routines) ?></b>stored procedure</span>
    <span class="obj"><b><?= count($indexes) ?></b>extra indexes</span>
    <span class="obj"><b><?= array_sum(array_map('count', $fks)) ?></b>foreign keys</span>
  </div>

  <div class="schema">
  <?php foreach ($tables as $t => $type): ?>
    <article class="card tbl<?= $type === 'VIEW' ? ' is-view' : '' ?>">
      <header><h3 class="mono"><?= e($t) ?></h3><span class="faint num"><?= $type === 'VIEW' ? 'view' : number_format($counts[$t]) . ' rows' ?></span></header>
      <p><?= e($tableNote[$t] ?? '') ?></p>
      <ul>
      <?php foreach ($cols[$t] ?? [] as $c): $fk = $fks[$t][$c['COLUMN_NAME']] ?? null; ?>
        <li><span class="mono"><?= e($c['COLUMN_NAME']) ?></span>
          <span class="keys">
            <?php if ($c['COLUMN_KEY'] === 'PRI'): ?><i class="k pk">PK</i><?php endif; ?>
            <?php if ($fk): ?><i class="k fk" title="References <?= e($fk) ?>">FK → <?= e(explode('.', $fk)[0]) ?></i><?php endif; ?>
            <?php if ($c['COLUMN_KEY'] === 'UNI'): ?><i class="k uq">UNIQUE</i><?php endif; ?>
            <em><?= e(preg_replace('/\(.*/', '', $c['COLUMN_TYPE'])) ?></em></span></li>
      <?php endforeach; ?>
      </ul>
    </article>
  <?php endforeach; ?>
  </div>

  <div class="grid g3 mt">
    <?php foreach ($triggers as $t): ?>
    <div class="card"><div class="eyebrow">Trigger</div><h3 class="mono" style="font-size:14.5px"><?= e($t['TRIGGER_NAME']) ?></h3>
      <p class="muted" style="font-size:13.5px;margin-top:6px">Runs <?= e(strtolower($t['ACTION_TIMING'] . ' ' . $t['EVENT_MANIPULATION'])) ?> on <code><?= e($t['EVENT_OBJECT_TABLE']) ?></code>. Fills <code>co2_kg</code> and <code>factor_value_used</code>, so no application can store a wrong total.</p></div>
    <?php endforeach; ?>
    <?php foreach ($routines as $r): ?>
    <div class="card"><div class="eyebrow"><?= e(ucfirst(strtolower($r['ROUTINE_TYPE']))) ?></div><h3 class="mono" style="font-size:14.5px"><?= e($r['ROUTINE_NAME']) ?></h3>
      <p class="muted" style="font-size:13.5px;margin-top:6px">Takes a user and a date range, sums the entries, saves a <code>report</code> row and returns it. Used by the Reports page.</p></div>
    <?php endforeach; ?>
    <div class="card"><div class="eyebrow">Indexes</div><h3 class="mono" style="font-size:14.5px"><?= e(implode(', ', array_column($indexes, 'INDEX_NAME'))) ?></h3>
      <p class="muted" style="font-size:13.5px;margin-top:6px">On <code>activity_log</code> (user, date), so date-range filters and monthly totals stay fast as entries grow.</p></div>
  </div>
</section>

<!-- 5. FAQ -->
<section class="mt" id="faq">
  <div class="section-head"><h2>Questions people ask</h2><p>Short answers to what usually comes up in a review.</p></div>
  <div class="card faq">
    <?php
    $faq = [
      ['Where does the data come from?', 'Two places. Activity amounts are what the user enters: km travelled, kWh from the bill, kg of food. Emission factors come from published sources: UK Government (DEFRA/DESNZ) for transport and waste, India’s Central Electricity Authority for grid electricity, IPCC 2006 for LPG, and EWG and Poore &amp; Nemecek for food. Each factor stores its source in the database, and it’s shown next to every entry.'],
      ['How accurate are the results?', 'They are good estimates, not measurements. Factors are averages: a specific car, diet or month of electricity will differ. The point is to compare categories and see trends over time, which averages do well. Every number can be traced back to the quantity and the factor used.'],
      ['What happens to old entries if a factor is changed?', 'Nothing. When an entry is saved, the trigger copies the factor into <code>activity_log.factor_value_used</code>. Updating <code>emission_factor</code> only affects new entries, so past totals and saved reports don’t silently change. History marks entries whose factor has since been updated.'],
      ['Why calculate CO₂ in a trigger instead of PHP?', 'So the rule lives in one place and can’t be bypassed. Whether a row comes from the website, phpMyAdmin or a script, the database computes <code>co2_kg</code> the same way. It also shows a real use of triggers rather than a demo one.'],
      ['Is the database normalised?', 'Yes, to 3NF. Each factor is stored once in <code>emission_factor</code>, and categories live in their own table. <code>activity_log</code> refers to factors by key rather than copying names. The one deliberate copy, <code>factor_value_used</code>, is a historical snapshot (the value at that moment), not repeated data.'],
      ['Why use both MySQL and MongoDB?', 'Each does the job it is good at. MySQL holds the structured, related data that needs keys, constraints and joins. MongoDB holds the event log, where each event type has different fields (a login has an email, a report has a date range). That fits a schema-less document store. The app keeps working if MongoDB is offline.'],
      ['How is the data kept safe?', 'Passwords are hashed with bcrypt (<code>password_hash</code>). Every query uses prepared statements, so SQL injection is blocked. Forms carry CSRF tokens, the session ID is regenerated on login, and every query is scoped to the logged-in user, so one user can’t see or delete another’s entries. Admin pages check the role on the server.'],
      ['Where does the 208 kg budget come from?', 'The Hot or Cool Institute (2021) estimates lifestyle emissions must fall to about 2.5 t CO₂e per person per year by 2030 to stay on a 1.5 °C path. 2,500 ÷ 12 ≈ 208 kg a month. The app tracks the main household categories rather than every purchase, so the budget is a guide. Each user can set their own.'],
      ['Can it handle new kinds of activity?', 'Yes, without code changes. An admin adds a category or factor on the Emission factors page. It is a new row, and it shows up on the Log activity page immediately.'],
      ['What would you build next?', 'Reading electricity bills automatically, state-wise grid factors (the grid is cleaner in some states than others), household accounts that combine family members, and monthly goals with reminders.'],
    ];
    foreach ($faq as $i => [$q, $a]): ?>
      <details<?= $i === 0 ? ' open' : '' ?>><summary><?= $q ?></summary><p><?= $a ?></p></details>
    <?php endforeach; ?>
  </div>
</section>
<?php include 'includes/footer.php'; ?>
