<?php
session_start();
require_once('db.php');
require_once('header.php');

ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = "";
$success = "";
$token_valid = false;

if (isset($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);

    $sql = "SELECT id FROM users WHERE confirm_token = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_fetch_assoc($result)) {
        $token_valid = true;
    } else {
        $error = "❌ Invalid or expired link!";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $token_valid) {
    $new_pass = $_POST['password'];
    $confirm_pass = $_POST['confirm_password'];
    $token = $_POST['token'];

    if (empty($new_pass) || strlen($new_pass) < 6) {
        $error = "❌ Password must be at least 6 characters.";
    } 
    elseif ($new_pass !== $confirm_pass) {
        $error = "❌ Passwords do not match!";
    } 
    elseif (!preg_match('/^(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\W_]).+$/', $new_pass)) {
        $error = "❌ Password needs: Letter + Number + Symbol";
    }
    else {
        $password_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        
        $update_sql = "UPDATE users SET password = ?, confirm_token = NULL WHERE confirm_token = ?";
        $up_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($up_stmt, "ss", $password_hash, $token);
        
        if (mysqli_stmt_execute($up_stmt)) {
            $success = "✅ Password updated successfully! You can now <a href='login.php'>login</a>.";
            $token_valid = false;
        } else {
            $error = "❌ Failed to update password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - SCL MRBS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; height: 100vh; background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('background.jpg'); background-size: cover; background-position: center; display: flex; justify-content: center; align-items: center; }
        .reset-container { background: rgba(255, 255, 255, 0.95); padding: 30px; border-radius: 10px; box-shadow: 0 8px 20px rgba(0,0,0,0.3); width: 100%; max-width: 400px; text-align: center; }
        .reset-container h2 { color: #224895; margin-bottom: 20px; font-size: 22px; }
        .form-group { margin-bottom: 15px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        .toggle-password { position: absolute; right: 12px; top: 38px; cursor: pointer; color: #999; }
        .btn-reset { width: 100%; padding: 12px; background: #2f6b96; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
        .message { padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; }
        .error { background: #ffdddd; color: darkred; border: 1px solid red; }
        .success { background: #ddffdd; color: darkgreen; border: 1px solid green; }
    </style>
</head>
<body>

    <div class="reset-container">
        <h2>Set New Password</h2>

        <?php if ($error): ?> <div class="message error"><?php echo $error; ?></div> <?php endif; ?>
        <?php if ($success): ?> <div class="message success"><?php echo $success; ?></div> <?php endif; ?>

        <?php if ($token_valid): ?>
        <form method="post">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token']); ?>">
            
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" id="password" placeholder="Enter new password" required>
                <i class="fas fa-eye toggle-password" onclick="toggleVisibility('password', this)"></i>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Repeat new password" required>
            </div>

            <button type="submit" class="btn-reset">Update Password</button>
        </form>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <a href="login.php" style="color: #2f6b96; text-decoration: none; font-size: 14px;">Back to Login</a>
        </div>
    </div>

    <script>
        function toggleVisibility(fieldId, iconElement) {
            const field = document.getElementById(fieldId);
            if (field.type === "password") {
                field.type = "text";
                iconElement.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                field.type = "password";
                iconElement.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
