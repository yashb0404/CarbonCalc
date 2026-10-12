<?php
require 'includes/auth.php';
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT user_id, name, password_hash, role FROM users WHERE email = ?');
    $st->execute([trim($_POST['email'] ?? '')]);
    $u = $st->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION += ['user_id' => $u['user_id'], 'name' => $u['name'], 'role' => $u['role']];
        mongo_log('login_success', ['email' => $_POST['email']]);
        header('Location: dashboard.php'); exit;
    }
    mongo_log('login_failed', ['email' => trim($_POST['email'] ?? '')]);
    $error = 'That email and password don’t match an account. Check both and try again.';
}
$title = 'Log in · CarbonCalc';
$mode = 'login';
include 'includes/header.php';
include 'includes/auth_pitch.php';
?>
  <section class="auth-form">
    <div class="auth-box">
      <h2>Log in</h2>
      <p>Pick up where you left off.</p>
      <?php if ($f): ?><div class="flash <?= e($f[1]) ?>"><?= icon($f[1] === 'err' ? 'alert' : 'check') ?><span><?= e($f[0]) ?></span></div><?php endif; ?>
      <?php if ($error): ?><div class="flash err"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
      <form method="post" id="login">
        <?= csrf_field() ?>
        <label>Email <input type="email" name="email" id="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required autofocus></label>
        <label>Password <input type="password" name="password" id="password" autocomplete="current-password" required></label>
        <button class="btn-block">Log in</button>
      </form>
      <div class="demo">
        <p>Reviewing the project? Use a demo account:</p>
        <div class="row2">
          <button type="button" class="btn-quiet" data-email="yash@demo.com" data-pass="demo123">Yash B<small>User · 6 months of data</small></button>
          <button type="button" class="btn-quiet" data-email="admin@demo.com" data-pass="admin123">Admin<small>Manages emission factors</small></button>
        </div>
      </div>
      <p class="switch-auth">New here? <a href="register.php">Create an account</a></p>
    </div>
  </section>
</div>
<script>
document.querySelectorAll('[data-email]').forEach(b => b.addEventListener('click', () => {
  document.getElementById('email').value = b.dataset.email;
  document.getElementById('password').value = b.dataset.pass;
  document.getElementById('login').requestSubmit();
}));
</script>
<?php include 'includes/footer.php'; ?>
