<?php
include 'config/db.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = isset($_GET['registered']) ? 'Your account has been created. You can now sign in.' : '';
$fullname = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = is_string($_POST['fullname'] ?? null) ? trim($_POST['fullname']) : '';
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $rawPassword = $_POST['password'] ?? '';

    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (strlen($fullname) < 2 || strlen($fullname) > 100 || !preg_match("/^[\p{L}\p{M} .'-]+$/u", $fullname)) {
        $error = 'Enter a valid name using letters and standard punctuation.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = 'Enter a valid email address.';
    } elseif (!is_string($rawPassword) || strlen($rawPassword) < 8 || strlen($rawPassword) > 72) {
        $error = 'Use a password between 8 and 72 characters.';
    } else {
        $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            $error = 'We could not create your account right now. Please try again.';
        } else {
            $role = 'Client';
            try {
                $stmt = $conn->prepare("INSERT INTO users (fullname, email, passwordHash, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $fullname, $email, $passwordHash, $role);
                $stmt->execute();
                header('Location: register.php?registered=1');
                exit();
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $error = 'This email address is already registered.';
                } else {
                    error_log('KingQuiry account registration failed: ' . $exception->getMessage());
                    $error = 'We could not save your account. Confirm MySQL is running and the KingQuiry database is set up, then try again.';
                }
            }
        }
    }
}
include 'includes/header.php';
?>

<style>
    body {
        background: #0f100d;
    }

    .navbar {
        background: rgba(10, 18, 25, 0.92) !important;
        border-bottom: 1px solid rgba(212, 175, 110, 0.25);
        box-shadow: none !important;
    }

    .navbar-brand {
        color: #f4efe8 !important;
        font-size: 2rem !important;
        letter-spacing: -.08em;
        text-transform: uppercase;
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        font-weight: 800;
    }

    .navbar-brand span {
        color: #d4a75f;
    }

    .navbar .nav-link {
        color: rgba(255,255,255,.8) !important;
        font-weight: 600;
        padding: .6rem 1rem !important;
        border-radius: .75rem;
    }

    .navbar .nav-link:hover,
    .navbar .nav-link:focus {
        background: rgba(255,255,255,.06);
        color: #fff !important;
    }

    .navbar .btn,
    .navbar .nav-item:last-child .nav-link {
        border-radius: .75rem;
        border: 1px solid rgba(212, 175, 110, 0.35);
        background: rgba(255,255,255,.04);
        color: #f9f3ea !important;
    }

    .navbar .nav-item:last-child .nav-link {
        padding-left: 1.1rem !important;
        padding-right: 1.1rem !important;
    }

    main.container {
        max-width: 1380px;
        padding-top: 1.5rem;
        padding-bottom: 1rem;
    }

    .kq-auth-page {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: calc(100vh - 150px);
        padding: 1.5rem 0;
    }

    .kq-auth-panel {
        width: min(100%, 460px);
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 2.1rem 1.8rem 1.5rem;
        border: 1px solid rgba(20, 34, 41, 0.08) !important;
        border-radius: 1.2rem !important;
        background: rgba(255, 255, 255, 0.97);
        box-shadow: 0 22px 50px rgba(0, 0, 0, 0.18);
    }

    .kq-brand {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.8rem;
        color: #132d42;
    }

    .kq-brand-logo {
        position: relative;
        display: flex;
        align-items: flex-end;
        justify-content: center;
        gap: 0.04em;
        min-height: 120px;
        margin: 0 auto .1rem;
        letter-spacing: -.08em;
        line-height: 1;
    }

    .kq-brand-crown {
        position: absolute;
        top: -22px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1;
        font-size: 2.3rem;
        color: #d4a75f;
        text-shadow: 0 4px 8px rgba(212, 167, 95, 0.12);
    }

    .kq-brand-number {
        position: relative;
        z-index: 2;
        font-size: clamp(4.9rem, 8vw, 7.3rem);
        font-weight: 900;
        color: #132d42;
        line-height: 0.8;
    }

    .kq-brand-word {
        position: relative;
        z-index: 2;
        font-size: clamp(2.1rem, 3.6vw, 3.3rem);
        font-weight: 900;
        color: #132d42;
        text-transform: uppercase;
        letter-spacing: -.06em;
    }

    .kq-brand-word .accent {
        color: #d4a75f;
    }

    .kq-brand-sub {
        margin-top: .05rem;
        font-size: .64rem;
        font-weight: 800;
        letter-spacing: .2em;
        text-transform: uppercase;
        color: rgba(19, 45, 66, .74);
    }

    .kq-auth-panel h2 {
        margin: .2rem 0 .25rem;
        font-size: clamp(2rem, 2vw, 2.6rem);
        line-height: 1.1;
        letter-spacing: -.05em;
        font-weight: 800;
        color: #122538;
        text-align: center;
    }

    .kq-auth-panel .subcopy {
        margin-bottom: 1.05rem;
        font-size: .95rem;
        color: #4b5d70;
        text-align: center;
    }

    .kq-auth-form {
        margin-top: 0.15rem;
    }

    .kq-form-group {
        margin-bottom: 1rem;
    }

    .kq-form-group label {
        display: block;
        margin-bottom: .45rem;
        font-size: .92rem;
        font-weight: 600;
        color: #3b4a5d;
    }

    .kq-form-group input {
        width: 100%;
        min-height: 50px;
        padding: .8rem 1rem;
        border: 1px solid #dfe6ec;
        border-radius: .8rem;
        background: #fff;
        color: #1a2c3d;
        font-size: 1rem;
        outline: none;
        transition: box-shadow .18s ease, border-color .18s ease;
    }

    .kq-form-group input:focus {
        border-color: #86a8c5;
        box-shadow: 0 0 0 .22rem rgba(23, 74, 112, .08);
    }

    .kq-password-wrap {
        position: relative;
    }

    .kq-password-wrap input {
        padding-right: 4.25rem;
    }

    .kq-toggle-password {
        position: absolute;
        top: 50%;
        right: .7rem;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #587089;
        font-size: .85rem;
        font-weight: 700;
        cursor: pointer;
        padding: .3rem .45rem;
    }

    .kq-auth-submit {
        display: block;
        width: 100%;
        min-height: 52px;
        margin-top: .3rem;
        border: 0;
        border-radius: .85rem;
        background: linear-gradient(180deg, #173d56, #102334);
        color: #fff;
        font-size: 1.08rem;
        font-weight: 800;
        letter-spacing: -.02em;
        cursor: pointer;
        box-shadow: 0 12px 24px rgba(18, 39, 58, 0.18);
    }

    .kq-auth-submit:hover {
        filter: brightness(1.03);
    }

    .kq-auth-register {
        margin-top: 1.15rem;
        text-align: center;
        font-size: 1rem;
        color: #2d4258;
    }

    .kq-auth-register a {
        color: #123b5a;
        font-weight: 700;
        text-decoration: none;
    }

    .kq-auth-register a:hover {
        text-decoration: underline;
    }

    .kq-page-note {
        margin-top: 1.1rem;
        text-align: center;
        color: rgba(255,255,255,0.75);
        font-size: .9rem;
    }

    .kq-page-note strong {
        color: #f0d7a4;
    }

    .alert {
        border-radius: .85rem;
        margin-bottom: 1rem;
    }

    @media (max-width: 767.98px) {
        .navbar {
            padding-top: .35rem;
            padding-bottom: .35rem;
        }

        .navbar-brand {
            font-size: 1.7rem !important;
        }

        .kq-auth-panel {
            padding-left: 1.1rem;
            padding-right: 1.1rem;
            border-radius: 1.2rem !important;
        }
    }
</style>

<div class="kq-auth-page">
    <aside class="kq-auth-panel card" aria-label="Registration panel">
        <div class="kq-brand" aria-label="KingQuiry logo">
            <div class="kq-brand-logo">
                <span class="kq-brand-crown">?</span>
                <span class="kq-brand-number">4</span>
                <span class="kq-brand-word"><span class="accent">K</span>INGS</span>
            </div>
            <div class="kq-brand-sub">Realty services</div>
        </div>

        <h2>Sign Up</h2>
        <p class="subcopy">Create your account to continue.</p>

        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <form method="POST" autocomplete="off" class="kq-auth-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="kq-form-group">
                <label for="register-fullname">Full name</label>
                <input type="text" id="register-fullname" name="fullname" required maxlength="100" value="<?= htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') ?>" placeholder="Your full name" autocomplete="name">
            </div>

            <div class="kq-form-group">
                <label for="register-email">Email address</label>
                <input type="email" id="register-email" name="email" required maxlength="100" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" autocomplete="email">
            </div>

            <div class="kq-form-group">
                <label for="register-password">Password</label>
                <div class="kq-password-wrap">
                    <input type="password" id="register-pass" name="password" required minlength="8" maxlength="72" placeholder="At least 8 characters" autocomplete="new-password">
                    <button type="button" class="kq-toggle-password" data-toggle-password="register-pass" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
                <div class="form-text mt-2">Use at least 8 characters.</div>
            </div>

            <button type="submit" class="kq-auth-submit">Create account</button>
        </form>

        <div class="kq-auth-register">
            Already have an account? <a href="client_login.php">Sign in here</a>
        </div>
    </aside>
</div>

<div class="kq-page-note">Find your place. <strong>Make your next move.</strong></div>

<script>
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });

</script>

<?php include 'includes/footer.php'; ?>