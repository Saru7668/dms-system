<?php
// ✅ ERROR REPORTING ON (To see real errors instead of 500)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once('db.php');
require_once('header.php');

// Login Check
if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];
$role = $_SESSION['UserRole'] ?? 'user';

// USER PROFILE DATA LOAD (auto-fill form)
$user_data = [
    'full_name' => $user,
    'phone' => '',
    'email' => '',
    'designation' => '',
    'address' => '',
    'department' => 'Other',
    'emergency_contact' => '',
    'id_proof' => ''
];

$profile_sql = "SELECT full_name, email, username, title, department, phone, designation, address, emergency_contact, id_proof
                FROM users
                WHERE username = '".mysqli_real_escape_string($conn, $user)."'
                LIMIT 1";
$profile_res = mysqli_query($conn, $profile_sql);

if ($profile_res && mysqli_num_rows($profile_res) > 0) {
    $row = mysqli_fetch_assoc($profile_res);

    $user_data['full_name']         = $row['full_name']         ?? $user;
    $user_data['phone']             = $row['phone']             ?? '';
    $user_data['email']             = $row['email']             ?? '';
    $user_data['designation']       = $row['designation']       ?? '';
    $user_data['address']           = $row['address']           ?? '';
    $user_data['department']        = $row['department']        ?? 'Other';
    $user_data['emergency_contact'] = $row['emergency_contact'] ?? '';
    $user_data['id_proof']          = $row['id_proof']          ?? '';
}

// ========================================
// DEPARTMENT WISE PURPOSE LIST
// ========================================
$dept_purposes = [
    "ICT"              => ["Software Installation", "Hardware Maintenance", "Network Issue", "Server Check", "Other"],
    "HR & Admin"       => ["Interview", "Policy Meeting", "Training", "Audit", "Other"],
    "Accounts & Finance"=>["Audit", "Payment Collection", "Budget Meeting", "Other"],
    "Sales & Marketing"=> ["Client Meeting", "Dealer Visit", "Strategy", "Other"],
    "Civil Engineering"=> ["Site Inspection", "Project Meeting", "Maintenance", "Other"],
    // বাকি গুলো চাইলে পরে বাড়াতে পারো
    "Default"          => ["Official Visit", "Meeting", "Inspection", "Maintenance", "Other"]
];

$current_dept  = $user_data['department'];
$purpose_list  = isset($dept_purposes[$current_dept]) ? $dept_purposes[$current_dept] : $dept_purposes['Default'];

// Handle Session Message (For displaying success after redirect)
$message = "";
if (isset($_SESSION['msg'])) {
    $message = $_SESSION['msg'];
    unset($_SESSION['msg']); // Show once then clear
}

// Departments List
$dept_list = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand", "Other"];

// Sidebar Badge Logic
$pending_count = 0;
if(in_array($role, ['staff', 'admin', 'superadmin'])){
    $pending_query = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM visit_requests WHERE status='Pending'");
    if($pending_query) {
        $pending_count = mysqli_fetch_assoc($pending_query)['cnt'];
    }
}

// ========================================
// ������ HANDLE FORM SUBMISSION
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    
    // Check for duplicates (Simple Spam Protection)
    $chk_spam = mysqli_query(
        $conn,
        "SELECT id FROM visit_requests 
         WHERE requested_by = '$user' 
           AND guest_name = '".mysqli_real_escape_string($conn, $_POST['guest_name'])."' 
           AND created_at > NOW() - INTERVAL 1 MINUTE"
    );
    
    if($chk_spam && mysqli_num_rows($chk_spam) > 0) {
        $_SESSION['msg'] = "<div class='alert alert-warning'>⚠️ Please wait! You just submitted a request for this guest.</div>";
        header("Location: guest_request.php");
        exit;
    }

    $guest_name   = mysqli_real_escape_string($conn, $_POST['guest_name']);
    $guest_title  = isset($_POST['guest_title']) ? mysqli_real_escape_string($conn, $_POST['guest_title']) : '';
    $phone        = mysqli_real_escape_string($conn, $_POST['phone']);
    $email        = mysqli_real_escape_string($conn, $_POST['email']);
    $designation  = mysqli_real_escape_string($conn, $_POST['designation']);
    $address      = mysqli_real_escape_string($conn, $_POST['address']);
    $id_proof     = mysqli_real_escape_string($conn, $_POST['id_proof']);
    $emergency    = mysqli_real_escape_string($conn, $_POST['emergency_contact']);
    $dept         = mysqli_real_escape_string($conn, $_POST['department']);
    // নতুন ফিল্ডগুলো
    $check_in_date  = mysqli_real_escape_string($conn, $_POST['check_in_date']);
    $check_in_time  = mysqli_real_escape_string($conn, $_POST['check_in_time']);
    $check_out_date = mysqli_real_escape_string($conn, $_POST['check_out_date']);
    $check_out_time = mysqli_real_escape_string($conn, $_POST['check_out_time']);
    
    // Purpose dropdown + other note
    $selected_purpose = mysqli_real_escape_string($conn, $_POST['purpose_select']);
    if ($selected_purpose === 'Other') {
        $final_purpose = mysqli_real_escape_string($conn, $_POST['purpose_note']);
    } else {
        $final_purpose = $selected_purpose;
    }
    
    $requested_by = $_SESSION['UserName'];

    $sql = "INSERT INTO visit_requests 
          (guest_name, guest_title, phone, email, designation, address, id_proof, department, emergency_contact,
           check_in_date, check_in_time, check_out_date, check_out_time, purpose, requested_by) 
          VALUES 
          ('$guest_name', '$guest_title', '$phone', '$email', '$designation', '$address', '$id_proof', '$dept', '$emergency',
           '$check_in_date', '$check_in_time', '$check_out_date', '$check_out_time', '$final_purpose', '$requested_by')";

    if (mysqli_query($conn, $sql)) {
        $req_id = mysqli_insert_id($conn);

        // ✅ requester (login user) এর ইমেইল বের করি – যেন guest-এর মত একই মেইল পায়
        $requester_email = '';
        $req_user_sql = "
            SELECT email 
            FROM users 
            WHERE UserName = '".mysqli_real_escape_string($conn, $requested_by)."' 
            LIMIT 1
        ";
        $req_user_res = mysqli_query($conn, $req_user_sql);
        if ($req_user_res && mysqli_num_rows($req_user_res) > 0) {
            $requester_email = mysqli_fetch_assoc($req_user_res)['email'];
        }
        
        // ========================================
        // ������ PROFESSIONAL MAIL LOGIC
        // ========================================
        // IMPORTANT: এখানে আসল CRLF, তাই \r\n (double slash না)
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";
        
        
        // Guest name with title (Mr/Mrs/Ms)
        $guest_display_name = trim($guest_title) !== '' 
            ? $guest_title . ' ' . $guest_name 
            : $guest_name;
        
                
        // 1. Mail to Guest
        if (!empty($email)) {
        
            // ----- guest er mail -----
            $subj_guest = "Request Received: Visit Ref #$req_id";
        
            $msg_guest  = "Dear $guest_display_name,\r\n\r\n";
            $msg_guest .= "Thank you for choosing SCL Dormitory. We have received your visit request.\r\n\r\n";
            $msg_guest .= "REQUEST SUMMARY\r\n";
            $msg_guest .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\r\n";
            $msg_guest .= "Reference ID     : #$req_id\r\n";
            $msg_guest .= "Guest Name       : $guest_name\r\n";
            $msg_guest .= "Check-in         : $checkin_disp\r\n";

            if ($planned_checkout !== '') {
            $msg_guest .= "Planned Check-out: $planned_checkout\r\n";
            }
            $msg_guest .= "Current Status   : PENDING APPROVAL\r\n";
            $msg_guest .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\r\n\r\n";
            $msg_guest .= "You will receive a confirmation email once your visit is approved.\r\n\r\n";
            $msg_guest .= "Warm regards,\r\n";
            $msg_guest .= "SCL Dormitory Management Team";
        
            @mail($email, $subj_guest, $msg_guest, $headers);
        
            // ----- requester er mail (same summary, kintu greeting requester er naam) -----
            if (!empty($requester_email) && $requester_email !== $email) {
        
                // এখানে তুমি চাইলে আলাদা subject রাখতে পারো
                $subj_req = "Copy: Visit request for $guest_name (Ref #$req_id)";
        
                // requester নাম (session er UserName dhore নিচ্ছি)
                $requester_name = $requested_by;  // অথবা $user
                
                // ✅ একই person হলে (same email বা same নাম) আর আলাদা মেইল পাঠাবো না
                $same_person =
                    (strcasecmp(trim($requester_email), trim($email)) === 0) ||
                    (strcasecmp(trim($requester_name), trim($guest_name)) === 0);
        
                if (!$same_person) {
        
                    $subj_req = "Copy: Visit request for $guest_name (Ref #$req_id)";
          
                  $msg_req  = "Dear $requester_name,\r\n\r\n";
                  $msg_req .= "This is a copy of the guest visit request you submitted for $guest_name.\r\n\r\n";
                  $msg_req .= "REQUEST SUMMARY\r\n";
                  $msg_req .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\r\n";
                  $msg_req .= "Reference ID     : #$req_id\r\n";
                  $msg_req .= "Guest Name       : $guest_name\r\n";
                  $msg_req .= "Check-in : " . date('d M Y', strtotime($check_in_date)) . " $check_in_time\\r\\n";
                  $msg_req .= "Current Status   : PENDING APPROVAL\r\n";
                  $msg_req .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\r\n\r\n";
                  $msg_req .= "You will also receive the approval / rejection notification for this guest.\r\n\r\n";
                  $msg_req .= "Best regards,\r\n";
                  $msg_req .= "SCL Dormitory Management Team";
          
                  @mail($requester_email, $subj_req, $msg_req, $headers);
              }
            }  
          }

        // 2. Mail to Admins (আগের layout, শুধু \r\n সঠিক করা হয়েছে, emoji বাদ)
        $admin_sql = "SELECT email FROM users WHERE user_role IN ('admin', 'superadmin', 'approver') AND email IS NOT NULL AND email != ''";
        $admin_res = mysqli_query($conn, $admin_sql);
        
        if ($admin_res) {
            $subj_admin = "New Request #$req_id Waiting for Approval";

            $msg_admin  = "SCL DORMITORY SYSTEM - NOTIFICATION\r\n";
            $msg_admin .= "==================================================\r\n\r\n";
            $msg_admin .= "Dear Authorization Team,\r\n\r\n";
            $msg_admin .= "A new visit request has been submitted and requires your attention.\r\n\r\n";
            $msg_admin .= "REQUEST DETAILS\r\n";
            $msg_admin .= "Guest Name       : $guest_name\r\n";
            $msg_admin .= "Requested By     : $requested_by\r\n";
            $msg_admin .= "Check-in         : $checkin_disp\r\n";
            if ($planned_checkout !== '') {
                $msg_admin .= "Planned Check-out: $planned_checkout\r\n";
            }
            $msg_admin .= "--------------------------------------------------\r\n";
            $msg_admin .= "Please log in to approve: http://" . $_SERVER['HTTP_HOST'] . "/dormitory/manage_requests.php\r\n\r\n";
            $msg_admin .= "SCL Dormitory Management System";

            while ($admin_row = mysqli_fetch_assoc($admin_res)) {
                if (!empty($admin_row['email'])) {
                    @mail($admin_row['email'], $subj_admin, $msg_admin, $headers);
                }
            }
        }

        // ✅ REDIRECT TO PREVENT RESUBMISSION
        $_SESSION['msg'] = "<div class='alert alert-success'>✅ Request Submitted Successfully! Reference ID: #$req_id</div>";
        header("Location: guest_request.php");
        exit;

    } else {
        $_SESSION['msg'] = "<div class='alert alert-danger'>❌ Error: " . mysqli_error($conn) . "</div>";
        header("Location: guest_request.php");
        exit;
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Submit Visit Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; z-index: 1000; }
        .content { margin-left: 250px; padding: 30px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; }
        }
        .readonly-field { background-color:#e9ecef; cursor:not-allowed; }

    </style>
</head>
<body>

<div class="sidebar">
    <h4 class="mb-4 text-center"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small>Logged in as</small><br><strong><?php echo htmlspecialchars($user); ?></strong><br>
        <span class="badge bg-warning text-dark mt-1"><?php echo strtoupper($role); ?></span>
    </div>
    
    <a href="index.php" class="btn btn-light w-100 mb-2 fw-bold"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="guest_request.php" class="btn btn-outline-info w-100 mb-2 text-white"><i class="fas fa-paper-plane me-2"></i>Submit Request</a>
    <a href="my_requests.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-list-alt me-2"></i>My Sent Requests</a>
    <a href="profile.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-user me-2"></i>My Profile</a>

    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
        <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 position-relative text-white">
            <i class="fas fa-tasks me-2"></i>Manage All 
            <?php if(isset($pending_count) && $pending_count > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    <?php echo $pending_count; ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endif; ?>

    <?php if(in_array($role, ['staff', 'admin', 'superadmin'])): ?>
        <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-clipboard-check me-2"></i>Check-out</a>
        <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-history me-2"></i>History</a>
    <?php endif; ?>
    
    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
        <hr class="border-light">
        <a href="admin_dashboard.php" class="btn btn-warning w-100 mb-2"><i class="fas fa-crown me-2"></i>Admin Panel</a>
    <?php endif; ?>
    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="card shadow border-0">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-pen-alt me-2"></i>Guest Visit Request Form</h5>
                    </div>
                    <div class="card-body bg-white">
                        <!-- Message Display Here -->
                        <?php echo $message; ?>
                        
                        <form method="POST">
                            <h6 class="text-primary border-bottom pb-2 mb-3">Guest Information (Auto from Profile)</h6>
                          <div class="row">
                              <div class="col-md-6 mb-3">
                                  <label class="form-label">Guest Name *</label>
                                  <div class="input-group">
                                      <select name="guest_title" class="form-select" style="max-width: 90px;"  required>
                                          <option value="">Title</option>
                                          <option value="Mr">Mr</option>
                                          <option value="Mrs">Mrs</option>
                                          <option value="Ms">Ms</option>
                                      </select>
                                      <input type="text" name="guest_name" class="form-control readonly-field"
                                          value="<?php echo htmlspecialchars($user_data['full_name']); ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Phone *</label>
                                            <input type="text" name="phone" class="form-control readonly-field"
                                                   value="<?php echo htmlspecialchars($user_data['phone']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Designation *</label>
                                            <input type="text" name="designation" class="form-control readonly-field"
                                                   value="<?php echo htmlspecialchars($user_data['designation']); ?>" readonly>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Address</label>
                                            <input type="text" name="address" class="form-control readonly-field"
                                                   value="<?php echo htmlspecialchars($user_data['address']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email *</label>
                                            <input type="email" name="email" class="form-control readonly-field"
                                                   value="<?php echo htmlspecialchars($user_data['email']); ?>" readonly>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">NID/Office ID *</label>
                                            <input type="text" name="id_proof" class="form-control readonly-field"
                                                   value="<?php echo htmlspecialchars($user_data['id_proof']); ?>" readonly>
                                        </div>
                                    </div>
                                    
                                    <!-- Hidden fields for emergency & department if চাইলে আলাদা করে edit করাবি না -->
                                    <input type="hidden" name="emergency_contact" value="<?php echo htmlspecialchars($user_data['emergency_contact']); ?>">
                                    <input type="hidden" name="department" value="<?php echo htmlspecialchars($user_data['department']); ?>">

                            <!--<h6 class="text-primary border-bottom pb-2 mb-3 mt-2">Visit Details</h6>
//                            <div class="row">
//                                <div class="col-md-6 mb-3">
//                                    <label class="form-label">Department</label>
//                                    <select name="department" class="form-select">
//                                        <?php foreach($dept_list as $d) echo "<option value='$d'>$d</option>"; ?>
//                                    </select>
//                                </div>
//                                <div class="col-md-6 mb-3">
//                                    <label class="form-label">Emergency Contact</label>
//                                    <input type="text" name="emergency_contact" class="form-control">
//                                </div>
                            </div> -->
                            <div class="row">
                                      <div class="col-md-6 mb-3">
                                          <label class="form-label">Tentative Check-in Date *</label>
                                          <input type="date" name="check_in_date" class="form-control" required>
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label class="form-label">Check-in Time *</label>
                                          <input type="time" name="check_in_time" class="form-control" required>
                                      </div>
                                  </div>
                                  <div class="row">
                                      <div class="col-md-6 mb-3">
                                          <label class="form-label">Tentative Check-out Date *</label>
                                          <input type="date" name="check_out_date" class="form-control" required>
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label class="form-label">Check-out Time *</label>
                                          <input type="time" name="check_out_time" class="form-control" required>
                                      </div>
                                  </div>
                                  <div class="mb-3">
                                      <label class="form-label">Purpose of Visit *</label>
                                      <select name="purpose_select" id="purposeSelect" class="form-select" onchange="toggleNote()" required>
                                          <option value="">-- Select Purpose --</option>
                                          <?php foreach($purpose_list as $p){ echo "<option value='$p'>$p</option>"; } ?>
                                      </select>
                                  
                                      <div id="noteField" class="mt-2" style="display:none;">
                                          <label class="form-label text-muted small">If Other, please specify:</label>
                                          <textarea name="purpose_note" id="purposeNote" class="form-control" rows="2"></textarea>
                                      </div>
                                  </div>
                            <button type="submit" name="submit_request" class="btn btn-primary w-100 py-2 fw-bold">
                                <i class="fas fa-paper-plane me-2"></i>Submit Request
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleNote() {
    var sel  = document.getElementById('purposeSelect');
    var div  = document.getElementById('noteField');
    var note = document.getElementById('purposeNote');

    if (sel.value === 'Other') {
        div.style.display = 'block';
        note.required = true;
    } else {
        div.style.display = 'none';
        note.required = false;
        note.value = '';
    }
}
</script>

</body>
</html>
