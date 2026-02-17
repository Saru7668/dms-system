<?php
session_start();
require_once('db.php');
require_once('header.php');

// এরর রিপোর্টিং
ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = "";
$success = "";
$secret_salt = "mrbs_secure_salt_2026";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $captcha_input = trim($_POST['captcha']);
    $captcha_hash_check = $_POST['captcha_hash'];
    $user_answer_hash = md5($captcha_input . $secret_salt);

    // ১. ক্যাপচা চেক
    if (empty($captcha_input) || $user_answer_hash !== $captcha_hash_check) {
        $error = "Incorrect Security Answer!";
    } 
    elseif (empty($email)) {
        $error = "Please enter your email address.";
    } 
    else {
        // ২. ডাটাবেস-এ ইমেইল চেক করা
        $sql = "SELECT id, username FROM users WHERE email = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($result)) {
            $username = $row['username'];
            $token = bin2hex(random_bytes(32)); // নতুন রিসেট টোকেন
            
            // ৩. ডাটাবেস-এ টোকেন আপডেট করা (confirm_token কলামটি ব্যবহার করা হচ্ছে)
            $update_sql = "UPDATE users SET confirm_token = ? WHERE email = ?";
            $up_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($up_stmt, "ss", $token, $email);
            
            if (mysqli_stmt_execute($up_stmt)) {
                // ৪. ইমেইল পাঠানো
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
                
                $subject = "Password Reset - Sheltech Ceramics MRBS";
                $body = "Dear $username,\n\nYou requested a password reset. Please click the link below to set a new password:\n$reset_link\n\nIf you didn't request this, ignore this email.";
                $headers = "From: no-reply@sheltechceramics.com";
                
                if(@mail($email, $subject, $body, $headers)) {
                    $success = "A password reset link has been sent to your email ($email).";
                } else {
                    $error = "Email sending failed. Please contact ICT Admin.";
                }
            }
        } else {
            $error = "No account found with this email address.";
        }
    }
}

// ক্যাপচা জেনারেশন
$num1 = rand(1, 9);
$num2 = rand(1, 9);
$sum = $num1 + $num2;
$correct_hash = md5($sum . $secret_salt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - SCL MRBS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; height: 100vh; background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('background.jpg'); background-size: cover; background-position: center; display: flex; justify-content: center; align-items: center; }
        .forgot-container { background: rgba(255, 255, 255, 0.95); padding: 30px; border-radius: 10px; box-shadow: 0 8px 20px rgba(0,0,0,0.3); width: 100%; max-width: 400px; text-align: center; }
        .forgot-container h2 { color: #224895; margin-bottom: 20px; font-size: 22px; }
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        .btn-reset { width: 100%; padding: 12px; background: #2f6b96; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
        .btn-reset:disabled { background: #ccc; cursor: not-allowed; }
        .message { padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; }
        .error { background: #ffdddd; color: darkred; border: 1px solid red; }
        .success { background: #ddffdd; color: darkgreen; border: 1px solid green; }
    </style>
</head>
<body>

    <div class="forgot-container">
        <h2>Forgot Password?</h2>
        <p style="font-size: 14px; color: #666; margin-bottom: 20px;">Enter your email to receive a password reset link.</p>

        <?php if ($error): ?> <div class="message error"><?php echo $error; ?></div> <?php endif; ?>
        <?php if ($success): ?> <div class="message success"><?php echo $success; ?></div> <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your company email" required>
            </div>

            <div class="form-group">
                <label>Security: <span style="color:red;"><?php echo "$num1 + $num2"; ?> = ?</span></label>
                <input type="number" name="captcha" id="captcha" required autocomplete="off">
                <input type="hidden" id="realAnswer" value="<?php echo $sum; ?>">
                <input type="hidden" name="captcha_hash" value="<?php echo $correct_hash; ?>">
            </div>

            <button type="submit" class="btn-reset" id="submitBtn" disabled>Send Reset Link</button>
        </form>

        <div style="margin-top: 20px;">
            <a href="login.php" style="color: #2f6b96; text-decoration: none; font-size: 14px;">Back to Login</a>
        </div>
    </div>

    <script>
        const captchaInput = document.getElementById('captcha');
        const realAnswer = document.getElementById('realAnswer').value;
        const submitBtn = document.getElementById('submitBtn');

        captchaInput.addEventListener('input', function() {
            if (this.value === realAnswer) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        });
    </script>
</body>
</html>
