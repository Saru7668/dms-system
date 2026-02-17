<?php
session_start();
require_once('db.php');
require_once('header.php');

$message = "";
$type = "";

if (isset($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    $sql = "SELECT id, username, token_created_at FROM users WHERE confirm_token=? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $token_time = strtotime($row['token_created_at']);
        $now = time();

        if (($now - $token_time) <= 15 * 60) {
            $user_id = $row['id'];
            $update_sql = "UPDATE users SET confirm_token=NULL, token_created_at=NULL WHERE id=?";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $user_id);
            if (mysqli_stmt_execute($update_stmt)) {
                $message = "Your account has been activated successfully! You can now log in.";
                $type = "success";
            } else {
                $message = "Error activating your account.";
                $type = "danger";
            }
        } else {
            $message = "This link has expired (valid for 15 minutes only). Please register again.";
            $type = "warning";
        }
    } else {
        $message = "Invalid token!";
        $type = "danger";
    }
} else {
    $message = "No token provided!";
    $type = "warning";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Account Confirmation</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
html,body{margin:0;padding:0;font-family:'Segoe UI',sans-serif;height:100vh;width:100%;overflow:hidden;background-image:linear-gradient(rgba(255,255,255,0.3),rgba(255,255,255,0.3)),url('background.jpg');background-size:cover;background-position:center;background-repeat:no-repeat;display:flex;justify-content:center;align-items:center;}
body::before{content:"";position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.2);z-index:-1;}
.confirm-container{background:rgba(255,255,255,0.95);border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,0.3);width:100%;max-width:420px;text-align:center;padding:25px 30px;}
.logo_login-img{width:100px;height:100px;object-fit:contain;margin-bottom:10px;}
h2{color:#224895;margin-bottom:5px;font-size:20px;}
h3{color:#224895;margin-bottom:20px;font-size:16px;}
.btn-login{margin-top:10px;background-color:#2f6b96;border:none;color:white;font-weight:600;border-radius:5px;padding:10px 20px;transition:0.3s;}
.btn-login:hover{background-color:#1a4f75;}
</style>
</head>
<body>
<div class="confirm-container">
<img src="logo_login.png" alt="SCL DRBS Logo" class="logo_login-img">
<h2>SCL DORMITORY BOOKING SYSTEM</h2>
<h3>ACCOUNT CONFIRMATION</h3>
<div class="alert alert-<?php echo $type; ?>"><?php echo $message; ?></div>
<a href="login.php" class="btn btn-login">Go to Login</a>
</div>
</body>
</html>
