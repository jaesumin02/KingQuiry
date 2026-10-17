<?php
include 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

include 'includes/header.php';
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $rawPassword = $_POST['password'];
    $role = 'Client'; // Default registered user role

    $password = password_hash($rawPassword, PASSWORD_DEFAULT);

    $check = $conn->prepare("SELECT userID FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        $error = "This email address is already registered.";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, passwordHash, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $fullname, $email, $password, $role);
        
        if ($stmt->execute()) {
            $success = "Registration successful! You can now <a href='login.php'>login</a> using your credentials.";
        } else {
            $error = "Registration failed. Please try again.";
        }
    }
}
?>

<div class="row justify-content-center my-5">
    <div class="col-md-5">
        <div class="card shadow-sm border-0 p-4">
            <h3 class="fw-bold text-center mb-4">Create Account</h3>
            
            <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
            <?php if($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="fullname" class="form-control" required placeholder="Enter full name" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" required placeholder="example@email.com" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required placeholder="Create password" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-100">Register Account</button>
            </form>
            
            <p class="text-center mt-3 small">Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>