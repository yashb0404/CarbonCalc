<?php
require 'includes/auth.php';
require_login();
$isAdmin = $_SESSION['role'] === 'admin';
$online  = mongo_online();
$filter  = $isAdmin ? [] : ['user_id' => (int)$_SESSION['user_id']];
$type    = $_GET['type'] ?? '';
if ($type !== '') $filter['type'] = $type;

$events = $online ? mongo_find($filter, 100) : [];
$match  = $isAdmin ? [] : ['user_id' => (int)$_SESSION['user_id']];
$counts = $online ? mongo_aggregate([
    ['$match' => $match],
    ['$group' => ['_id' => '$type', 'count' => ['$sum' => 1]]],
    ['$sort'  => ['count' => -1]],
]) : [];

// Plain-language names for event types
$labels = [
    'login_success' => 'Logged in', 'login_failed' => 'Failed login', 'logout' => 'Logged out',
    'activity_added' => 'Activity added', 'activity_deleted' => 'Activity deleted', 'csv_exported' => 'CSV exported',
    'report_generated' => 'Report created', 'user_registered' => 'Account created', 'validation_failed' => 'Rejected entry',
    'budget_changed' => 'Budget changed', 'admin_update' => 'Factor edited', 'admin_add' => 'Factor added',
    'admin_toggle' => 'Factor turned on/off', 'admin_add_category' => 'Category added',
];
$label = fn($t) => $labels[$t] ?? ucfirst(str_replace('_', ' ', $t));

$title = 'Event log · CarbonCalc';
include 'includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="eyebrow">MongoDB · carbon_calc.activity_events</div>
    <h1>Event log</h1>
    <p>A record of what happened in the app: logins, entries, deletions, exports and admin changes<?= $isAdmin ? ', for all users' : ' on your account' ?>.</p>
  </div>
  <span class="verdict <?= $online ? '' : 'over' ?>"><i></i><?= $online ? 'MongoDB connected' : 'MongoDB offline' ?></span>
</div>

<section class="card">
  <div class="grid g2" style="align-items:start">
    <div>
      <h3 style="margin-bottom:8px">Why two databases?</h3>
      <p class="muted" style="font-size:14px"><b>MySQL</b> holds the structured records — users, factors, entries and reports — with keys, constraints, a trigger, a view and a stored procedure.
        <b>MongoDB</b> holds this event log. Each event type carries different details, which suits a schema-less document store.</p>
      <?php if (!$online): ?><p class="muted" style="font-size:14px">Start the MongoDB service to see events here. The rest of the app keeps working on MySQL alone.</p><?php endif; ?>
    </div>
    <pre class="shell-block">use carbon_calc
db.activity_events.find().sort({ at: -1 }).limit(5)
db.activity_events.aggregate([
  { $group: { _id: "$type", count: { $sum: 1 } } },
  { $sort: { count: -1 } }
])</pre>
  </div>
</section>

<?php if ($online): ?>
<?php if ($counts): ?>
<div class="presets mt" style="margin-bottom:0">
  <a class="btn btn-sm <?= $type === '' ? 'btn-primary' : 'btn-quiet' ?>" href="mongo_log.php">All events</a>
  <?php foreach ($counts as $c): ?>
    <a class="btn btn-sm <?= $type === $c['_id'] ? 'btn-primary' : 'btn-quiet' ?>" href="?type=<?= urlencode($c['_id']) ?>"><?= e($label($c['_id'])) ?> <span class="num" style="opacity:.7"><?= (int)$c['count'] ?></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card mt">
  <?php if ($events): ?>
  <div class="card-head"><h3><?= count($events) ?> most recent<?= $type ? ' · ' . e($label($type)) : '' ?></h3></div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>When</th><th>Event</th><?php if ($isAdmin): ?><th>User</th><?php endif; ?><th>Document</th></tr></thead>
    <tbody>
    <?php foreach ($events as $ev): ?>
    <tr>
      <td class="date-cell"><?= e(date('j M, H:i:s', intdiv($ev['at']->ms, 1000))) ?></td>
      <td style="white-space:nowrap"><b style="font-weight:600"><?= e($label($ev['type'])) ?></b><span class="sub mono"><?= e($ev['type']) ?></span></td>
      <?php if ($isAdmin): ?><td style="white-space:nowrap"><?= e($ev['user_name']) ?></td><?php endif; ?>
      <td class="json"><?= e(json_encode($ev['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="empty"><span class="ci"><?= icon('db') ?></span><h3>No events yet</h3><p>Log an activity or create a report, then come back. Each action adds a document here.</p>
    <a class="btn btn-primary" href="add_activity.php">Log activity</a></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
