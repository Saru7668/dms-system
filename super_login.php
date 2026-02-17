<?php
session_start();

require_once('db.php');
require_once('config.php');
require_once('header.php');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $_POST['s_user'];
    $pass = $_POST['s_pass'];

    // হার্ডকোড করা ক্রেডেনশিয়াল চেক
    if ($user === SUPER_ADMIN_USER && $pass === SUPER_ADMIN_PASS) {
        $_SESSION['UserName'] = "administrator";
        $_SESSION['UserRole'] = "admin"; // আপনাকে অ্যাডমিন রোল দেওয়া হলো
        $_SESSION['Department'] = "ICT"; // আপনার ডিপার্টমেন্ট সেট করা হলো
        
        header("Location: index.php");
        exit;
    } else {
        $error = "Access Denied! Incorrect Super Credentials.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Super Admin Backend</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">
    <div class="container mt-5">
        <div class="card mx-auto p-4 bg-secondary" style="max-width: 400px;">
            <h2 class="text-center">Backend Access</h2>
            <form method="POST">
                <div class="mb-3">
                    <label>Super Username</label>
                    <input type="text" name="s_user" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label>Super Password</label>
                    <input type="password" name="s_pass" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-danger w-100">Get Master Access</button>
            </form>
        </div>
    </div>
</body>
</html>
