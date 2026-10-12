<?php
require 'includes/auth.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if ($name === '') $error = 'Enter your name.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter an email address like name@example.com.';
    elseif (strlen($pass) < 6) $error = 'Use a password with at least 6 characters.';
    else {
        try {
            db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            mongo_log('user_registered', ['email' => $email, 'name' => $name]);
            flash('Account created. Log in to start tracking.');
            header('Location: index.php'); exit;
        } catch (PDOException $ex) {
            $error = $ex->errorInfo[1] == 1062 ? 'An account with that email already exists. Log in instead.' : 'The account could not be created. Try again.';
        }
    }
}
$title = 'Create account · CarbonCalc';
include 'includes/header.php';
include 'includes/auth_pitch.php';
?>
  <section class="auth-form">
    <div class="auth-box">
      <h2>Create your account</h2>
      <p>Free, and your first entry takes under a minute.</p>
      <?php if ($error): ?><div class="flash err"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>Name <input name="name" autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>" required autofocus></label>
        <label>Email <input type="email" name="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required></label>
        <label>Password <span class="hint">· at least 6 characters</span>
          <input type="password" name="password" autocomplete="new-password" minlength="6" required></label>
        <button class="btn-block">Create account</button>
      </form>
      <p class="switch-auth">Already have an account? <a href="index.php">Log in</a></p>
    </div>
  </section>
</div>
<?php include 'includes/footer.php'; ?>
