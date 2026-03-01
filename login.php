<?php
session_start();
require_once('db.php');
require_once('header.php');

// Already logged in?
if (isset($_SESSION['UserName']) && $_SESSION['UserName'] != "") {
    $role = $_SESSION['UserRole'] ?? 'user';

    if (!empty($_SESSION['force_profile_update'])) {
        // mandatory profile update incomplete
        header("Location: profile.php");
        exit;
    } else {
        // profile complete, redirect based on role
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

// ?? Login Success Flag
$login_success = false;
$redirect_url = "";

// ========================================
// ?? HELPER FUNCTION: GET REAL IP ADDRESS
// ========================================
function getUserIP() {
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]; // Get the first IP in list
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return trim($ip);
}

// ========================================
// ??? HELPER FUNCTION: GET OS, BROWSER & DEVICE TYPE
// ========================================
function getDeviceInfo() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $os_platform  = "Unknown OS";
    $browser_name = "Unknown Browser";
    $device_type  = "Desktop / Laptop"; // Default fallback

    // --- Detect Device Type ---
    $tablet_browser = 0;
    $mobile_browser = 0;

    if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', strtolower($user_agent))) {
        $tablet_browser++;
    }
    if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', strtolower($user_agent))) {
        $mobile_browser++;
    }

    if ($tablet_browser > 0) {
        $device_type = 'Tablet';
    } else if ($mobile_browser > 0) {
        $device_type = 'Mobile Device';
    }

    // --- Detect OS ---
    $os_array = array(
        '/windows nt 11/i'      =>  'Windows 11',
        '/windows nt 10/i'      =>  'Windows 10',
        '/windows nt 6.3/i'     =>  'Windows 8.1',
        '/windows nt 6.2/i'     =>  'Windows 8',
        '/windows nt 6.1/i'     =>  'Windows 7',
        '/macintosh|mac os x/i' =>  'Mac OS X',
        '/mac_powerpc/i'        =>  'Mac OS 9',
        '/linux/i'              =>  'Linux',
        '/ubuntu/i'             =>  'Ubuntu',
        '/iphone/i'             =>  'iPhone',
        '/ipod/i'               =>  'iPod',
        '/ipad/i'               =>  'iPad',
        '/android/i'            =>  'Android',
        '/blackberry/i'         =>  'BlackBerry',
        '/webos/i'              =>  'Mobile OS'
    );

    foreach ($os_array as $regex => $value) {
        if (preg_match($regex, $user_agent)) {
            $os_platform = $value;
            break;
        }
    }

    // --- Detect Browser ---
    $browser_array = array(
        '/edge/i'       => 'Edge',
        '/edg/i'        => 'Edge',
        '/chrome/i'     => 'Chrome',
        '/safari/i'     => 'Safari',
        '/firefox/i'    => 'Firefox',
        '/opera/i'      => 'Opera',
        '/netscape/i'   => 'Netscape',
        '/maxthon/i'    => 'Maxthon',
        '/konqueror/i'  => 'Konqueror',
        '/mobile/i'     => 'Mobile Browser'
    );

    foreach ($browser_array as $regex => $value) {
        if (preg_match($regex, $user_agent)) {
            $browser_name = $value;
            break;
        }
    }

    return array(
        'os' => $os_platform, 
        'browser' => $browser_name,
        'device' => $device_type
    );
}

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
              // ?? UPDATE: Added 'last_login_ip' in the SELECT query
              $sql = "SELECT username, password, department, user_role, confirm_token,
                             title, full_name, phone, email, designation, id_proof, last_login_ip
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
      
                          // ---------- profile mandatory check ----------
                          $user_title  = trim((string)($row['title']       ?? '')); 
                          $full_name   = trim((string)($row['full_name']   ?? ''));
                          $phone       = trim((string)($row['phone']       ?? ''));
                          $email_db    = trim((string)($row['email']       ?? ''));
                          $designation = trim((string)($row['designation'] ?? ''));
                          $user_dept   = trim((string)($row['department']  ?? ''));
                          $last_ip     = trim((string)($row['last_login_ip'] ?? '')); // Fetch previous IP
      
                          // profile_incomplete = true check
                          $profile_incomplete =
                              ($full_name   === '') ||
                              ($phone       === '') ||
                              ($email_db    === '') ||
                              ($designation === '') ||
                              ($user_dept   === '');
      
                          // ---------- set session ----------
                          $_SESSION['UserName']   = $row['username'];
                          $_SESSION['Department'] = $user_dept;
                          $_SESSION['UserRole']   = $row['user_role'];
                          $_SESSION['login_time'] = time();
                          $_SESSION['force_profile_update'] = $profile_incomplete;
      
                          session_regenerate_id(true);
      
                          // ?? Delaying redirect to show animation/toast
                          $login_success = true;
                          
                          if ($profile_incomplete) {
                              $_SESSION['msg'] = "
                                  <div class='alert alert-warning alert-dismissible fade show' role='alert'>
                                      <strong>Profile Incomplete!</strong> Please complete your profile before using the system.
                                      <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                                  </div>
                              ";
                              $redirect_url = "profile.php";
                          } else {
                              if ($row['user_role'] == 'admin') {
                                  $redirect_url = "admin_dashboard.php";
                              } else {
                                  $redirect_url = "index.php";
                              }
                          }

                          // ========================================
                          // ?? SEND LOGIN SECURITY ALERT EMAIL (ONLY IF IP IS NEW)
                          // ========================================
                          
                          date_default_timezone_set('Asia/Dhaka');
                          $current_user_ip = getUserIP();
                          
                          if (!empty($email_db) && $current_user_ip !== $last_ip) {
                              
                              $device_data = getDeviceInfo();
                              $os_name = $device_data['os'];
                              $browser_name = $device_data['browser'];
                              $device_type = $device_data['device'];
                              $login_time = date('d M Y, h:i A');
                              
                              $display_name = !empty($full_name) ? $full_name : $row['username'];
                              if (!empty($user_title)) {
                                  $display_name = $user_title . " " . $display_name;
                              }

                              $subject = "Security Alert: New Device/Location Login to Your SCL Dormitory Account";
                              
                              $headers  = "MIME-Version: 1.0\r\n";
                              $headers .= "Content-type:text/html;charset=UTF-8\r\n";
                              $headers .= "From: SCL Dormitory Security <no-reply@scl-dormitory.com>\r\n";

                              $icon_time = "https://img.icons8.com/color/48/000000/clock--v1.png";
                              $icon_ip = "https://img.icons8.com/color/48/000000/domain--v1.png";
                              $icon_device = "https://img.icons8.com/color/48/000000/mac-client.png";
                              $icon_os = "https://img.icons8.com/color/48/000000/windows-10.png";
                              $icon_browser = "https://img.icons8.com/color/48/000000/chrome--v1.png";

                              $mail_body = "
                              <!DOCTYPE html>
                              <html>
                              <head>
                                  <meta charset='utf-8'>
                                  <style>
                                      body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                                      .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); overflow: hidden; }
                                      .email-header { background-color: #2f6b96; color: #ffffff; padding: 20px; text-align: center; }
                                      .email-header h2 { margin: 0; font-size: 22px; }
                                      .email-body { padding: 30px; color: #333333; line-height: 1.6; }
                                      .info-box { background-color: #f8f9fa; border-left: 4px solid #f0ad4e; padding: 20px; margin: 25px 0; border-radius: 4px; }
                                      .info-table { border-collapse: collapse; width: 100%; font-size: 14px; }
                                      .info-table td { padding: 8px 0; vertical-align: middle; }
                                      .icon-td { width: 30px; text-align: center; }
                                      .icon-td img { width: 20px; height: 20px; display: block; }
                                      .label-td { font-weight: bold; width: 140px; color: #333; }
                                      .value-td { color: #555; }
                                      .footer { border-top: 1px solid #eeeeee; margin-top: 30px; padding-top: 20px; font-size: 12px; color: #999999; text-align: center; }
                                  </style>
                              </head>
                              <body>
                                  <div class='email-container'>
                                      <div class='email-header'>
                                          <h2>New Login Location Detected</h2>
                                      </div>
                                      <div class='email-body'>
                                          <p style='font-size: 16px; margin-top: 0;'>Dear <strong>{$display_name}</strong>,</p>
                                          <p>We noticed a successful login to your SCL Dormitory Management System account from a new IP Address. If this was you, no further action is required.</p>
                                          
                                          <div class='info-box'>
                                              <table class='info-table'>
                                                  <tr>
                                                      <td class='icon-td'><img src='{$icon_time}' alt='Time'></td>
                                                      <td class='label-td'>Time:</td>
                                                      <td class='value-td'>{$login_time}</td>
                                                  </tr>
                                                  <tr>
                                                      <td class='icon-td'><img src='{$icon_ip}' alt='IP'></td>
                                                      <td class='label-td'>IP Address:</td>
                                                      <td class='value-td'>{$current_user_ip}</td>
                                                  </tr>
                                                  <tr>
                                                      <td class='icon-td'><img src='{$icon_device}' alt='Device'></td>
                                                      <td class='label-td'>Device Type:</td>
                                                      <td class='value-td'>{$device_type}</td>
                                                  </tr>
                                                  <tr>
                                                      <td class='icon-td'><img src='{$icon_os}' alt='OS'></td>
                                                      <td class='label-td'>Operating System:</td>
                                                      <td class='value-td'>{$os_name}</td>
                                                  </tr>
                                                  <tr>
                                                      <td class='icon-td'><img src='{$icon_browser}' alt='Browser'></td>
                                                      <td class='label-td'>Browser:</td>
                                                      <td class='value-td'>{$browser_name}</td>
                                                  </tr>
                                              </table>
                                          </div>
                                          
                                          <p style='font-size: 14px; color: #555;'>If you did not authorize this login, please change your password immediately or contact the IT Department.</p>
                                          
                                          <div class='footer'>
                                              This is an automated security email from the SCL Dormitory Management System.<br>Please do not reply to this email.
                                          </div>
                                      </div>
                                  </div>
                              </body>
                              </html>";

                              @mail($email_db, $subject, $mail_body, $headers);
                              
                              // Update Database with the new IP Address
                              $update_ip_sql = "UPDATE users SET last_login_ip = ? WHERE username = ?";
                              $ip_stmt = mysqli_prepare($conn, $update_ip_sql);
                              if($ip_stmt){
                                  mysqli_stmt_bind_param($ip_stmt, "ss", $current_user_ip, $row['username']);
                                  mysqli_stmt_execute($ip_stmt);
                                  mysqli_stmt_close($ip_stmt);
                              }
                          }
                          // ========================================
      
                      } else {
                          $error = "? Invalid password.";
                      }
      
                  } else {
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
    <title>SCL Dormitory System - Login</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- ?? Bootstrap CSS for Toast -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
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

        /* ?? ???-?? ?????????? */
        .fade-in {
            animation: fadeIn 0.8s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo_login-img { width: 120px; height: 120px; object-fit: contain; margin-bottom: 20px; }
        .login-container h2 { color: #2f6b96; margin-bottom: 18px; font-size: 20px; margin-top: 0; font-weight: bold; }
        .form-group { margin-bottom: 15px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 16px; }
        .toggle-password { position: absolute; right: 12px; top: 38px; cursor: pointer; color: #999; font-size: 16px; }
        
        .btn-login { width: 100%; padding: 12px; background: #2f6b96; color: white; border: none; border-radius: 5px; font-size: 18px; font-weight: bold; cursor: pointer; transition: all 0.3s; }
        .btn-login:hover { background: #1a4f75; transform: scale(1.02); }
        .btn-login:disabled { background-color: #cccccc !important; cursor: not-allowed; opacity: 0.6; transform: none; }
        
        .error-msg { background: #ffdddd; color: darkred; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; border: 1px solid red; font-weight: 500; }

        /* ?? Footer Style Override */
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

    <!-- ?? ?????? ????? ????????? (Login Success) -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
        <div id="liveToast" class="toast align-items-center text-white bg-success border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body fw-bold fs-6">
                    <i class="fas fa-check-circle me-2"></i> Login Successful! Redirecting...
                </div>
            </div>
        </div>
    </div>

    <!-- ?? Wrapper added to center Login Box -->
    <div class="main-wrapper">
        <div class="login-container fade-in">
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
                        <a href="forgot_password.php" style="font-size: 13px; color: #2f6b96; text-decoration: none; font-weight: bold;">Forgot Password?</a>
                    </div>
                </div>

                <div class="form-group">
                    <label>Security Question: 
                        <span style="color:red; font-weight:bold;"><?php echo "$num1 + $num2"; ?> = ?</span>
                    </label>
                    <input type="number" name="captcha" id="captcha" placeholder="Enter result" required autocomplete="off">
                    <input type="hidden" id="realAnswer" value="<?php echo $sum; ?>">
                    <input type="hidden" name="captcha_hash" value="<?php echo $correct_hash; ?>">
                    <small style="font-size: 10.5px; color: #555;">Provide correct answer to enable Login button</small>
                </div>

                <button type="submit" class="btn-login" id="submitBtn" disabled>
                    <i class="fas fa-sign-in-alt me-2"></i> Login
                </button>
            </form>

            <div class="footer-links" style="margin-top: 20px;">
                <p>Don't have an account? 
                    <a href="register.php" style="color: #2f6b96; font-weight: bold; text-decoration: none;">Register Here</a>
                </p>
            </div>
        </div>
    </div>
    <!-- End Wrapper -->

    <!-- ?? Bootstrap JS for Toast -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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
    
    <!-- ?? Login Success Logic & Redirect Delay -->
    <?php if ($login_success): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Disable button and change text
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Please wait...';
            btn.disabled = true;

            // Show Toast
            var toastEl = document.getElementById('liveToast');
            var toast = new bootstrap.Toast(toastEl, { delay: 1500 });
            toast.show();

            // Redirect after 1.5 Seconds
            setTimeout(function() {
                window.location.href = "<?php echo $redirect_url; ?>";
            }, 1500);
        });
    </script>
    <?php endif; ?>

    <!-- ?? Footer correctly placed outside wrapper -->
    <?php require_once('footer.php'); ?>
</body>
</html>
