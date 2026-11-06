<?php
include 'config/db.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();

if (isset($_SESSION['user'])) {
    $role = normalized_user_role($_SESSION['user']);
    $destination = $role === 'Admin' ? 'admin_dashboard.php' : ($role === 'Sales Agent' ? 'agent_dashboard.php' : 'client_dashboard.php');
    header('Location: ' . $destination);
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    }
    $postedIdentifier = $_POST['identifier'] ?? $_POST['email'] ?? '';
    $identifier = is_string($postedIdentifier) ? trim(substr($postedIdentifier, 0, 100)) : '';
    $password = $_POST['password'] ?? '';

    if ($error === '' && $identifier !== '' && is_string($password) && $password !== '') {
        $stmt = $conn->prepare("SELECT userID, fullname, email, passwordHash, role FROM users WHERE email = ? OR SUBSTRING_INDEX(email, '@', 1) = ? LIMIT 2");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $normalizedRole = normalized_user_role($user);
            if (password_verify($password, $user['passwordHash']) && $normalizedRole === 'Sales Agent') {
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                header('Location: agent_dashboard.php');
                exit();
            }
        }
    }

    if ($error === '') {
        $error = 'Invalid email or password for the agent portal.';
    }
}
include 'includes/header.php';
?>

<style>
    body { background: #0f100d; }
    .navbar { background: rgba(10, 18, 25, 0.92) !important; border-bottom: 1px solid rgba(212, 175, 110, 0.25); box-shadow: none !important; }
    main.container { max-width: 1380px; padding-top: 1.5rem; padding-bottom: 1rem; }
    .kq-auth-page { display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 150px); padding: 1.5rem 0; }
    .kq-auth-panel { width: min(100%, 460px); display: flex; flex-direction: column; justify-content: center; padding: 2.1rem 1.8rem 1.5rem; border: 1px solid rgba(20, 34, 41, 0.08) !important; border-radius: 1.2rem !important; background: rgba(255, 255, 255, 0.97); box-shadow: 0 22px 50px rgba(0, 0, 0, 0.18); }
    .kq-brand { display: flex; flex-direction: column; align-items: center; justify-content: center; margin-bottom: 0.8rem; color: #132d42; }
    .kq-brand-logo { position: relative; display: flex; align-items: flex-end; justify-content: center; gap: 0.04em; min-height: 120px; margin: 0 auto .1rem; letter-spacing: -.08em; line-height: 1; }
    .kq-brand-crown { position: absolute; top: -22px; left: 50%; transform: translateX(-50%); z-index: 1; font-size: 2.3rem; color: #d4a75f; }
    .kq-brand-number { position: relative; z-index: 2; font-size: clamp(4.9rem, 8vw, 7.3rem); font-weight: 900; color: #132d42; line-height: 0.8; }
    .kq-brand-word { position: relative; z-index: 2; font-size: clamp(2.1rem, 3.6vw, 3.3rem); font-weight: 900; color: #132d42; text-transform: uppercase; letter-spacing: -.06em; }
    .kq-brand-word .accent { color: #d4a75f; }
    .kq-brand-sub { margin-top: .05rem; font-size: .64rem; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; color: rgba(19, 45, 66, .74); }
    .kq-auth-panel h2 { margin: .2rem 0 .25rem; font-size: clamp(2rem, 2vw, 2.6rem); line-height: 1.1; letter-spacing: -.05em; font-weight: 800; color: #122538; text-align: center; }
    .kq-auth-panel .subcopy { margin-bottom: 1.05rem; font-size: .95rem; color: #4b5d70; text-align: center; }
    .kq-form-group { margin-bottom: 1rem; }
    .kq-form-group label { display: block; margin-bottom: .45rem; font-size: .92rem; font-weight: 600; color: #3b4a5d; }
    .kq-form-group input { width: 100%; min-height: 50px; padding: .8rem 1rem; border: 1px solid #dfe6ec; border-radius: .8rem; background: #fff; color: #1a2c3d; font-size: 1rem; outline: none; }
    .kq-password-wrap { position: relative; }
    .kq-password-wrap input { padding-right: 4.25rem; }
    .kq-toggle-password { position: absolute; top: 50%; right: .7rem; transform: translateY(-50%); border: 0; background: transparent; color: #587089; font-size: .85rem; font-weight: 700; cursor: pointer; padding: .3rem .45rem; }
    .kq-form-options { display: flex; justify-content: space-between; align-items: center; gap: .75rem; margin: .1rem 0 1rem; }
    .kq-check { display: inline-flex; align-items: center; gap: .55rem; color: #465a6f; font-size: .95rem; }
    .kq-check input { width: 1rem; height: 1rem; margin: 0; accent-color: #133d58; }
    .kq-link { color: #3a536f; font-size: .95rem; text-decoration: none; }
    .kq-auth-submit { display: block; width: 100%; min-height: 52px; margin-top: .3rem; border: 0; border-radius: .85rem; background: linear-gradient(180deg, #173d56, #102334); color: #fff; font-size: 1.08rem; font-weight: 800; letter-spacing: -.02em; cursor: pointer; box-shadow: 0 12px 24px rgba(18, 39, 58, 0.18); }
    .alert { border-radius: .85rem; margin-bottom: 1rem; }
</style>

<div class="kq-auth-page">
    <aside class="kq-auth-panel card" aria-label="Agent login panel">
        <div class="kq-brand" aria-label="KingQuiry logo">
            <div class="kq-brand-logo">
                <span class="kq-brand-crown">♛</span>
                <span class="kq-brand-number">4</span>
                <span class="kq-brand-word"><span class="accent">K</span>INGS</span>
            </div>
            <div class="kq-brand-sub">Agent portal</div>
        </div>

        <h2>Agent Sign In</h2>
        <p class="subcopy">Private access for assigned sales agents.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" class="kq-auth-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="kq-form-group">
                <label for="login-identifier">Email address or username</label>
                <input type="text" id="login-identifier" name="identifier" required maxlength="100" placeholder="Enter your authorized email" autocomplete="off">
            </div>

            <div class="kq-form-group">
                <label for="login-password">Password</label>
                <div class="kq-password-wrap">
                    <input type="password" id="login-password" name="password" required maxlength="255" placeholder="Enter your password" autocomplete="off">
                    <button type="button" class="kq-toggle-password" id="toggle-password" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>

            <div class="kq-form-options">
                <label class="kq-check" for="remember-me">
                    <input type="checkbox" id="remember-me" name="remember_me">
                    <span>Remember me</span>
                </label>
                <a class="kq-link" href="update_pass.php">Forgot password?</a>
            </div>

            <button type="submit" class="kq-auth-submit">Access agent portal</button>
        </form>
    </aside>
</div>

<script>
    const passwordInput = document.getElementById('login-password');
    const passwordToggle = document.getElementById('toggle-password');
    if (passwordInput && passwordToggle) {
        passwordToggle.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            passwordToggle.textContent = isHidden ? 'Hide' : 'Show';
            passwordToggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            passwordToggle.setAttribute('aria-pressed', String(isHidden));
        });
    }
</script>

<?php include 'includes/footer.php'; ?>
