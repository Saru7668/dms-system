<?php
session_start();
require_once('db.php');
require_once('header.php');

ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = "";
$success = "";
$secret_salt = "mrbs_secure_salt_2026";
$department_list = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand"];

// AJAX availability check
if (isset($_POST['ajax_check_type'])) {
    $type = $_POST['ajax_check_type'];
    $value = trim($_POST['value']);

    if ($type == 'email') {
        $sql = "SELECT COUNT(*) as total FROM users WHERE email = ?";
    } else {
        $sql = "SELECT COUNT(*) as total FROM users WHERE username = ?";
    }
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $value);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    echo ($row['total'] > 0) ? "exists" : "available";
    exit;
}

// Main registration logic
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['name']); 
    $email = trim($_POST['email']);
    $department = $_POST['department'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $captcha_input = trim($_POST['captcha']);
    $captcha_hash_check = $_POST['captcha_hash'];

    $user_answer_hash = md5($captcha_input . $secret_salt);

    // Validation (same as yours)
    $userRegex = '/^(?=(?:.*[a-zA-Z]){2})(?=.*[0-9])[a-zA-Z0-9]+$/';

    if (empty($captcha_input) || $user_answer_hash !== $captcha_hash_check) {
        $error = "❌ Incorrect Security Answer!";
    }
    elseif (empty($username) || empty($email) || empty($password) || empty($department)) {
        $error = "⚠️ All fields are required.";
    } 
    elseif ($password != $confirm_password) {
        $error = "❌ Passwords do not match.";
    }
    elseif (!preg_match($userRegex, $username)) {
        $error = "❌ Invalid Username! Need 2 letters & 1 number.";
    }
    else {
        // Check duplicate
        $sql_check = "SELECT id FROM users WHERE username=? OR email=? LIMIT 1";
        $stmt_check = mysqli_prepare($conn, $sql_check);
        mysqli_stmt_bind_param($stmt_check, "ss", $username, $email);
        mysqli_stmt_execute($stmt_check);
        $res_check = mysqli_stmt_get_result($stmt_check);

        if (mysqli_num_rows($res_check) > 0) {
            $error = "❌ Username or Email already exists!";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32)); 
            $token_expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));
            
            $sql_insert = "INSERT INTO users (username, password, email, department, full_name, confirm_token, token_created_at, user_role, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'user', NOW())";
            $stmt_ins = mysqli_prepare($conn, $sql_insert);
            mysqli_stmt_bind_param($stmt_ins, "sssssss", $username, $password_hash, $email, $department, $username, $token, $token_expiry);
            
            if (mysqli_stmt_execute($stmt_ins)) {
                $confirm_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/confirm.php?token=" . $token;
                $subject = "✅ Confirm Account - SCL Dormitory Booking";
            
                $body = "Dear $username,\n\n"
                      . "Thank you for registering at SCL Dormitory Booking System.\n\n"
                      . "To activate your account, please click the link below (valid for **15 minutes**):\n"
                      . "$confirm_link\n\n"
                      . "If you do not confirm within 15 minutes, you will need to re-register.\n\n"
                      . "If you didn't register, you can safely ignore this email.\n\n"
                      . "Best regards,\n"
                      . "SCL Dormitory Team";
            
                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";
                $headers .= "Reply-To: admin@scl.com\r\n";
            
                if(@mail($email, $subject, $body, $headers)) {
                    $success = "✅ Registration Successful! Check your email to activate your account (valid for 15 minutes).";
                } else {
                    $success = "✅ Registered! Email delivery failed. Contact Admin or login directly.";
                }
            } else {
                $error = "❌ Registration failed: " . mysqli_error($conn);
            }
        }
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
    <title>Register - MRBS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    
    <style>
        /* [PERMA-FIX] Global Overflow Hide */
        html, body {
            margin: 0; padding: 0;
            font-family: 'Segoe UI', sans-serif;
            min-height: 100vh; width: 100%;
            overflow-x: hidden; 
            
            background-image: linear-gradient(rgba(255,255,255,0.3), rgba(255,255,255,0.3)), url('background.jpg');
            
            background-size: cover; background-position: center; background-repeat: no-repeat;
            display: flex; justify-content: center; align-items: center;
        }
        
        * { box-sizing: border-box; }

        body::before {
            content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.2); 
            z-index: -1;
        }

        .register-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px 30px; 
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
            width: 100%; max-width: 500px; 
            text-align: center;
            
            max-height: 90vh; 
            overflow-y: auto; 
            
            overflow-x: hidden; 
            
            margin: 10px;
            scrollbar-width: none; -ms-overflow-style: none;  
        }
        .register-container::-webkit-scrollbar { display: none; }
        
        .logo_login-img { width: 100px; height: 100px; object-fit: contain; margin-bottom: 10px; }
        
        .register-container h2 { 
            color: #224895; 
            margin-bottom: 5px; 
            font-size: 20px; 
            margin-top: 0; 
            white-space: nowrap; 
        }
        .register-container h3 { color: #224895; margin-bottom: 15px; font-size: 16px; margin-top: 0; }
        
        .form-group { margin-bottom: 12px; text-align: left; position: relative; width: 100%; }
        .form-group label { display: block; margin-bottom: 3px; font-weight: bold; color: #333; font-size: 14px; }
        
        .form-group input { 
            width: 100%; padding: 8px; padding-right: 35px; 
            border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 15px; 
            background: white;
        }

        .select2-container {
            width: 100% !important; 
            max-width: 100%;
        }
        .select2-container .select2-selection--single {
            height: 38px !important; 
            padding: 5px;
            border: 1px solid #ccc !important;
            border-radius: 5px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            top: 5px !important;
        }

        .toggle-password {
            position: absolute; right: 10px; top: 32px; cursor: pointer; color: #999; font-size: 15px; user-select: none; z-index: 2;
        }
        .toggle-password:hover { color: #2f6b96; }

        .success-icon {
            position: absolute; 
            right: 10px; 
            top: 32px; 
            color: #28a745; 
            font-size: 16px; 
            display: none; 
            z-index: 1;
        }
        
        .password-icon-adjust {
            right: 35px !important;
        }
        
        .select2-icon-adjust {
            top: 32px;
            right: 35px; 
            z-index: 99;
        }

        .btn-register {
            width: 100%; padding: 10px; background: #2f6b96; color: white;
            border: none; border-radius: 5px; font-size: 16px; cursor: pointer; margin-top: 5px; transition: all 0.3s;
        }
        .btn-register:hover { background: #1a4f75; }
        .btn-register:disabled { background-color: #cccccc !important; color: #666666; cursor: not-allowed; opacity: 0.6; }

        .message-box { padding: 8px; border-radius: 5px; margin-bottom: 10px; font-size: 13px; text-align: center; }
        .error { background: #ffdddd; color: darkred; border-left: 5px solid red; }
        .success { background: #ddffdd; color: darkgreen; border-left: 5px solid green; }
        
        .footer-links { margin-top: 10px; font-size: 13px; }
        .footer-links a { color: #2f6b96; text-decoration: none; font-weight: bold; }
        .footer-links a:hover { text-decoration: underline; }
        
        .hint { font-size: 10px; color: #666; display: block; margin-top: 2px; }
        .hint-error { color: red; display: none; font-size: 10px; margin-top: 1px; font-weight: bold; }
        
        .spinner { display: inline-block; width: 10px; height: 10px; border: 2px solid #ccc; border-top-color: #333; border-radius: 50%; animation: spin 1s linear infinite; margin-left: 5px; display: none; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="register-container">
        <img src="logo_login.png" alt="SCL DRBS Logo" class="logo_login-img">

        <h2>SCL DORMITORY BOOKING SYSTEM </h2>
        <h3>CREATE AN ACCOUNT</h3>

        <?php if ($error): ?>
            <div class="message-box error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="message-box success"><?php echo $success; ?></div>
        <?php else: ?>

        <form method="post" action="register.php">
            <div class="form-group">
                <label>Username <span class="spinner" id="userLoader"></span></label>
                <input type="text" name="name" id="name" placeholder="Choose a username" required>
                <i class="fas fa-check-circle success-icon" id="userTick"></i>
                
                <small class="hint">Min 2 letters & 1 number (No spaces/symbols)</small>
                <small id="userFormatError" class="hint-error">Invalid! Need 2+ letters, 1+ number, no symbols.</small>
                <small id="userExistError" class="hint-error">Username already exists!</small>
            </div>
            
            <div class="form-group">
                <label>Email Address <span class="spinner" id="emailLoader"></span></label>
                <input type="email" name="email" id="email" placeholder="Enter your email" required>
                <i class="fas fa-check-circle success-icon" id="emailTick"></i>

                <small class="hint">Only @sheltech... or @sheltechceramics... allowed</small>
                <small id="emailFormatError" class="hint-error">Invalid email! Must contain '@' and '.'</small>
                <small id="emailDomainError" class="hint-error">Domain must be sheltech/sheltechceramics</small>
                <small id="emailExistError" class="hint-error">Email already exists!</small>
            </div>

            <div class="form-group">
                <label>Department</label>
                <select name="department" id="department" style="width: 100%;" required>
                    <option value="" disabled selected>Select Department</option>
                    <?php foreach ($department_list as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="fas fa-check-circle success-icon select2-icon-adjust" id="deptTick"></i>
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" id="password" placeholder="Create password" required>
                <i class="fas fa-check-circle success-icon password-icon-adjust" id="passTick"></i>
                <i class="fas fa-eye toggle-password" onclick="toggleVisibility('password', this)"></i>                
                
                <small class="hint">Must contain: Letter, Number & Symbol (@, #, etc.)</small>
                <small id="passComplexError" class="hint-error">Must contain: Letter, Number & Symbol</small>
            </div>
            
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Repeat password" required>
                <i class="fas fa-check-circle success-icon password-icon-adjust" id="confirmPassTick"></i>
                <i class="fas fa-eye toggle-password" onclick="toggleVisibility('confirm_password', this)"></i>
                
                <small class="hint">Please enter the same password here</small>
                <small id="passError" class="hint-error">Passwords do not match!</small>
            </div>

            <div class="form-group">
                <label>Security Question: <span style="color:red; font-weight:bold;"><?php echo "$num1 + $num2"; ?> = ?</span></label>
                <input type="number" name="captcha" id="captcha" placeholder="Enter result" required autocomplete="off">
                <input type="hidden" id="realAnswer" value="<?php echo $sum; ?>">
                <input type="hidden" name="captcha_hash" value="<?php echo $correct_hash; ?>">
                <small class="hint">Please Provide correct answer to visible the Register Now button</small>
            </div>

            <button type="submit" class="btn-register" id="submitBtn" disabled>Register Now</button>
        </form>

        <div class="footer-links">
            <p>Already have an account? <a href="login.php">Login Here</a></p>
        </div>
        <?php endif; ?>
    </div>
    
    
        
      <?php require_once('footer.php'); ?>
     

    <script>
        $(document).ready(function() {
            $('#department').select2({
                placeholder: "Select or type department",
                allowClear: true,
                width: '100%' 
            });

            $('#department').on('change', function() {
                checkRegisterForm();
            });
        });

        function toggleVisibility(fieldId, iconElement) {
            const field = document.getElementById(fieldId);
            if (field.type === "password") {
                field.type = "text";
                iconElement.classList.remove('fa-eye');
                iconElement.classList.add('fa-eye-slash');
            } else {
                field.type = "password";
                iconElement.classList.remove('fa-eye-slash');
                iconElement.classList.add('fa-eye');
            }
        }

        const nameInput = document.getElementById('name');
        const emailInput = document.getElementById('email');
        const deptInput = document.getElementById('department');
        const passInput = document.getElementById('password');
        const confirmPassInput = document.getElementById('confirm_password');
        const captchaInput = document.getElementById('captcha');
        const realAnswer = document.getElementById('realAnswer').value;
        const submitBtn = document.getElementById('submitBtn');
        
        const passError = document.getElementById('passError');
        const userFormatError = document.getElementById('userFormatError');
        const userExistError = document.getElementById('userExistError');
        const passComplexError = document.getElementById('passComplexError');
        const emailFormatError = document.getElementById('emailFormatError');
        const emailDomainError = document.getElementById('emailDomainError');
        const emailExistError = document.getElementById('emailExistError');
        const userLoader = document.getElementById('userLoader');
        const emailLoader = document.getElementById('emailLoader');

        const userTick = document.getElementById('userTick');
        const emailTick = document.getElementById('emailTick');
        const deptTick = document.getElementById('deptTick');
        const passTick = document.getElementById('passTick');
        const confirmPassTick = document.getElementById('confirmPassTick');

        let isEmailUnique = false;
        let isUsernameUnique = false;
        let isDomainValid = false;

        function checkUsername() {
            const nameVal = nameInput.value.trim();
            const userRegex = /^(?=(?:.*[a-zA-Z]){2})(?=.*[0-9])[a-zA-Z0-9]+$/;

            if (nameVal === "") {
                userFormatError.style.display = "none";
                userExistError.style.display = "none";
                userTick.style.display = "none"; 
                isUsernameUnique = false;
                checkRegisterForm();
                return;
            }
            if (!userRegex.test(nameVal)) {
                userFormatError.style.display = "block";
                userExistError.style.display = "none";
                userTick.style.display = "none"; 
                isUsernameUnique = false;
                checkRegisterForm();
                return;
            } else {
                userFormatError.style.display = "none";
            }

            userLoader.style.display = "inline-block";
            const formData = new FormData();
            formData.append('ajax_check_type', 'username');
            formData.append('value', nameVal);

            fetch('register.php', { method: 'POST', body: formData })
            .then(response => response.text())
            .then(data => {
                userLoader.style.display = "none";
                if (data.trim() === 'exists') {
                    userExistError.style.display = "block";
                    userTick.style.display = "none"; 
                    isUsernameUnique = false;
                } else {
                    userExistError.style.display = "none";
                    userTick.style.display = "block"; 
                    isUsernameUnique = true;
                }
                checkRegisterForm();
            })
            .catch(error => { console.error(error); userLoader.style.display = "none"; });
        }

        function checkEmail() {
            const emailVal = emailInput.value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const domainRegex = /@(sheltech-bd|sheltechceramics)\./i;

            emailFormatError.style.display = "none";
            emailExistError.style.display = "none";
            emailDomainError.style.display = "none";

            if (emailVal === "") {
                isEmailUnique = false;
                isDomainValid = false;
                emailTick.style.display = "none"; 
                checkRegisterForm();
                return;
            }
            
            if (!emailRegex.test(emailVal)) {
                emailFormatError.style.display = "block";
                isEmailUnique = false;
                emailTick.style.display = "none"; 
                checkRegisterForm();
                return;
            }
            
            if (!domainRegex.test(emailVal)) {
                emailDomainError.style.display = "block";
                isDomainValid = false;
                emailTick.style.display = "none"; 
                checkRegisterForm();
                return;
            } else {
                isDomainValid = true;
            }

            emailLoader.style.display = "inline-block";
            const formData = new FormData();
            formData.append('ajax_check_type', 'email');
            formData.append('value', emailVal);

            fetch('register.php', { method: 'POST', body: formData })
            .then(response => response.text())
            .then(data => {
                emailLoader.style.display = "none";
                if (data.trim() === 'exists') {
                    emailExistError.style.display = "block";
                    emailTick.style.display = "none"; 
                    isEmailUnique = false;
                } else {
                    emailExistError.style.display = "none";
                    emailTick.style.display = "block"; 
                    isEmailUnique = true;
                }
                checkRegisterForm();
            })
            .catch(error => { console.error(error); emailLoader.style.display = "none"; });
        }

        function checkRegisterForm() {
            const passVal = passInput.value.trim();
            const confirmVal = confirmPassInput.value.trim();
            const captchaVal = captchaInput.value.trim();
            const deptVal = $('#department').val(); 

            const passComplexRegex = /^(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\W_]).+$/;
            const isPasswordComplex = passComplexRegex.test(passVal);
            const isCaptchaCorrect = (captchaVal === realAnswer);
            const isPasswordMatch = (passVal !== "" && passVal === confirmVal);

            if (passVal !== "" && isPasswordComplex) {
                passComplexError.style.display = "none";
                passTick.style.display = "block";
            } else {
                if(passVal !== "" && !isPasswordComplex) passComplexError.style.display = "block";
                passTick.style.display = "none";
            }

            if (isPasswordMatch) {
                passError.style.display = "none";
                confirmPassTick.style.display = "block";
            } else {
                if(confirmVal !== "") passError.style.display = "block";
                confirmPassTick.style.display = "none";
            }

            if (deptVal !== null && deptVal !== "") {
                deptTick.style.display = "block";
            } else {
                deptTick.style.display = "none";
            }

            if (isUsernameUnique && isEmailUnique && isDomainValid && isPasswordMatch && isCaptchaCorrect && isPasswordComplex && deptVal !== null && deptVal !== "") {
                submitBtn.disabled = false;
                submitBtn.style.opacity = "1";
            } else {
                submitBtn.disabled = true;
                submitBtn.style.opacity = "0.6";
            }
        }

        nameInput.addEventListener('blur', checkUsername);
        nameInput.addEventListener('input', () => { 
            userExistError.style.display = "none"; 
            userTick.style.display = "none"; 
            checkRegisterForm(); 
        });

        emailInput.addEventListener('blur', checkEmail); 
        emailInput.addEventListener('input', () => { 
            emailExistError.style.display = "none"; 
            emailTick.style.display = "none"; 
            checkRegisterForm(); 
        });
        
        [passInput, confirmPassInput, captchaInput].forEach(input => {
            input.addEventListener('input', checkRegisterForm);
        });
    </script>
</body>
</html>

