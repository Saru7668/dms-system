<?php
session_start();
require_once('db.php');
require_once('header.php');

// Already logged in?
if (isset($_SESSION['UserName']) && $_SESSION['UserName'] != "") {
    $role = $_SESSION['UserRole'] ?? 'user';

    if (!empty($_SESSION['force_profile_update'])) {
        // mandatory ????? ???? incomplete
        header("Location: profile.php");
        exit;
    } else {
        // profile complete ??? role ??????? redirect
        if ($role === 'admin') {
            header("Location: admin_dashboard.php");
        } else {
            header("Location: index.php");
        }
        exit;
    }
}


$error = "";
$returl = isset($_REQUEST['returl']) ? $_REQUEST['returl'] : "";
$secret_salt = "mrbs_secure_salt_2026";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_user = mysqli_real_escape_string($conn, trim($_POST['username']));
    $input_pass = $_POST['password'];
    $captcha_input = trim($_POST['captcha']);
    $captcha_hash_check = $_POST['captcha_hash'];

    $user_answer_hash = md5($captcha_input . $secret_salt);

    // CAPTCHA check
    if (empty($captcha_input) || $user_answer_hash !== $captcha_hash_check) {
        $error = "? Incorrect Security Answer! Please check your math.";
    }
    // Input validation
    elseif (empty($input_user) || empty($input_pass)) {
        $error = "?? Please enter username/email and password.";
    }
    else {
        // Username Check
        $user_exists_sql = "SELECT COUNT(*) as count FROM users WHERE username = ? OR email = ?";
        $user_exists_stmt = mysqli_prepare($conn, $user_exists_sql);
        mysqli_stmt_bind_param($user_exists_stmt, "ss", $input_user, $input_user);
        mysqli_stmt_execute($user_exists_stmt);
        $exists_result = mysqli_stmt_get_result($user_exists_stmt);
        $exists_row = mysqli_fetch_assoc($exists_result);
        
        if ($exists_row['count'] == 0) {
            $error = "? This Username/Email does not exist!";
        } else {
                  // Login Query
              $sql = "SELECT username, password, department, user_role, confirm_token,
                             full_name, phone, email, designation, id_proof
                      FROM users 
                      WHERE (username = ? OR email = ?) AND confirm_token IS NULL 
                      LIMIT 1";
              $stmt = mysqli_prepare($conn, $sql);
      
              if ($stmt) {
                  mysqli_stmt_bind_param($stmt, "ss", $input_user, $input_user);
                  mysqli_stmt_execute($stmt);
                  $result = mysqli_stmt_get_result($stmt);
      
                  if ($row = mysqli_fetch_assoc($result)) {
      
                      if (password_verify($input_pass, $row['password'])) {
      
                          // ---------- profile mandatory ????? ??? ??? ----------
                          $full_name   = trim((string)($row['full_name']   ?? ''));
                          $phone       = trim((string)($row['phone']       ?? ''));
                          $email_db    = trim((string)($row['email']       ?? ''));
                          $designation = trim((string)($row['designation'] ?? ''));
                          $user_dept   = trim((string)($row['department']  ?? ''));
                          // $id_proof    = trim((string)($row['id_proof']    ?? ''));
      
                          // ??? ????? ???? ????? profile_incomplete = true
                          $profile_incomplete =
                              ($full_name   === '') ||
                              ($phone       === '') ||
                              ($email_db    === '') ||
                              ($designation === '') ||
                              ($user_dept   === '');
                              // || ($id_proof === '');
      
                          // ---------- session ??? ??? ----------
                          $_SESSION['UserName']   = $row['username'];
                          $_SESSION['Department'] = $user_dept;
                          $_SESSION['UserRole']   = $row['user_role'];
                          $_SESSION['login_time'] = time();
                          $_SESSION['force_profile_update'] = $profile_incomplete;
      
                          session_regenerate_id(true);
      
                          // ---------- redirect ????????? ----------
                          if ($profile_incomplete) {
                              // ?????? ????? ??? session ? ??? ???
                              $_SESSION['msg'] = "
                                  <div class='alert alert-warning alert-dismissible fade show' role='alert'>
                                      <strong>Profile Incomplete!</strong> Please complete your profile before using the system.
                                      <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                                  </div>
                              ";
                          
                              header("Location: profile.php");
                              exit;
                          } else {
                              // profile complete, role ??????? redirect
                              if ($row['user_role'] == 'admin') {
                                  header("Location: admin_dashboard.php");
                              } else {
                                  header("Location: index.php");
                              }
                              exit;
                          }
      
                      } else {
                          $error = "? Invalid password.";
                      }
      
                  } else {
                      // username/email ???, ?????? confirm_token NULL ?? ??? ?????? ????
                      $error = "?? Account not activated or mismatch.";
                  }
      
                  mysqli_stmt_close($stmt);
      
              } else {
                  $error = "?? Database error.";
              }
        }
        mysqli_stmt_close($user_exists_stmt);
    }
}

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
    <title>SCL Dormitory System</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ?? KEY FIX FOR FOOTER: Use Flex Column Layout */
        html, body {
            height: 100%; margin: 0; padding: 0; width: 100%;
            font-family: 'Segoe UI', sans-serif;
            
            /* Background */
            background-image: url('background.jpg');
            background-size: cover; background-position: center;
            
            /* Layout */
            display: flex;
            flex-direction: column; /* Stack Items Vertically */
        }
        
        body::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: -1; }

        /* Centered Content Wrapper */
        .main-wrapper {
            flex: 1; /* Pushes Footer Down */
            display: flex;
            justify-content: center; /* Horizontal Center */
            align-items: center;     /* Vertical Center */
            width: 100%;
        }

        .login-container { 
            background: rgba(255, 255, 255, 0.95); 
            padding: 20px 25px; 
            border-radius: 10px; 
            box-shadow: 0 8px 20px rgba(0,0,0,0.3); 
            width: 100%; max-width: 400px; 
            text-align: center; 
        }

        .logo_login-img { width: 120px; height: 120px; object-fit: contain; margin-bottom: 20px; }
        .login-container h2 { color: #224895; margin-bottom: 18px; font-size: 20px; margin-top: 0; }
        .form-group { margin-bottom: 15px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 16px; }
        .toggle-password { position: absolute; right: 12px; top: 38px; cursor: pointer; color: #999; font-size: 16px; }
        .btn-login { width: 100%; padding: 12px; background: #2f6b96; color: white; border: none; border-radius: 5px; font-size: 18px; cursor: pointer; transition: all 0.3s; }
        .btn-login:hover { background: #1a4f75; }
        .btn-login:disabled { background-color: #cccccc !important; cursor: not-allowed; opacity: 0.6; }
        .error-msg { background: #ffdddd; color: darkred; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; border: 1px solid red; }

        /* ?? Footer Style Override for Login Page (Optional if not in footer.php) */
        .scl-footer {
            background: transparent !important;
            color: yellow   !important;
            font-weight: 600;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.9);
            text-align: center;
            padding: 15px 0;
            width: 100%;
        }
    </style>
</head>
<body>

    <!-- ?? Wrapper added to center Login Box -->
    <div class="main-wrapper">
        <div class="login-container">
            <img src="logo_login.png" alt="Logo" class="logo_login-img">
            <h2>SCL DORMITORY BOOKING SYSTEM</h2>
            <?php if ($error): ?> 
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div> 
            <?php endif; ?>

            <form method="post" action="login.php">
                <input type="hidden" name="returl" value="<?php echo htmlspecialchars($returl); ?>">
                
                <div class="form-group">
                    <label>Username / Email</label>
                    <input type="text" name="username" id="username" placeholder="Enter username or email" required autofocus>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" id="password" placeholder="Enter password" required>
                    <i class="fas fa-eye toggle-password" onclick="toggleVisibility('password', this)"></i>
                    <div style="text-align: right; margin-top: 5px;">
                        <a href="forgot_password.php" style="font-size: 13px; color: #2f6b96; text-decoration: none;">Forgot Password?</a>
                    </div>
                </div>

                <div class="form-group">
                    <label>Security Question: 
                        <span style="color:red; font-weight:bold;"><?php echo "$num1 + $num2"; ?> = ?</span>
                    </label>
                    <input type="number" name="captcha" id="captcha" placeholder="Enter result" required autocomplete="off">
                    <input type="hidden" id="realAnswer" value="<?php echo $sum; ?>">
                    <input type="hidden" name="captcha_hash" value="<?php echo $correct_hash; ?>">
                    <small style="font-size: 10px; color: #666;">Provide correct answer to enable Login button</small>
                </div>

                <button type="submit" class="btn-login" id="submitBtn" disabled>Login</button>
            </form>

            <div class="footer-links" style="margin-top: 20px;">
                <p>Don't have an account? 
                    <a href="register.php" style="color: #2f6b96; font-weight: bold; text-decoration: none;">Register Here</a>
                </p>
            </div>
        </div>
    </div>
    <!-- End Wrapper -->

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

        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const captchaInput = document.getElementById('captcha');
        const realAnswer = document.getElementById('realAnswer').value;
        const submitBtn = document.getElementById('submitBtn');

        function checkForm() {
            if (usernameInput.value.trim() !== "" &&
                passwordInput.value.trim() !== "" &&
                captchaInput.value.trim() === realAnswer) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = "1";
            } else {
                submitBtn.disabled = true;
                submitBtn.style.opacity = "0.6";
            }
        }

        [usernameInput, passwordInput, captchaInput].forEach(input => {
            input.addEventListener('input', checkForm);
        });
    </script>
    
    <!-- ? Footer correctly placed outside wrapper -->
    <?php require_once('footer.php'); ?>
</body>
</html>
