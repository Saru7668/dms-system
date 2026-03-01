<?php
// ‚úÖ ERROR REPORTING ON
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];
$role = $_SESSION['UserRole'] ?? 'user';

// ========================================
// Ì†ΩÌ¥ê DOUBLE SUBMISSION PREVENTION (CSRF TOKEN)
// ========================================
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

// ========================================
// Ì†ΩÌ±§ USER PROFILE DATA LOAD (Default values)
// ========================================
$user_data = [
    'full_name' => $user, 
    'phone' => '', 
    'email' => '', 
    'designation' => '', 
    'address' => '', 
    'department' => 'Other', 
    'id_proof' => ''
];

$profile_sql = "SELECT * FROM users WHERE username = '".mysqli_real_escape_string($conn, $user)."' LIMIT 1";
$profile_res = mysqli_query($conn, $profile_sql);

if ($profile_res && mysqli_num_rows($profile_res) > 0) {
    $fetched_data = mysqli_fetch_assoc($profile_res);
    $user_data = array_merge($user_data, array_map(function($val) { return $val ?? ''; }, $fetched_data));
}

// Department Logic
$dept_purposes = [
    "ICT" => ["Software Installation", "Hardware Maintenance", "Network Issue", "Other"],
    "HR & Admin" => ["Interview", "Policy Meeting", "Training", "Other"],
    "Default" => ["Official Visit", "Meeting", "Inspection", "Other"]
];
$current_dept = $user_data['department'] ?? 'Other';
$purpose_list = isset($dept_purposes[$current_dept]) ? $dept_purposes[$current_dept] : $dept_purposes['Default'];

// Variable to hold redirect instruction
$redirect_to = "";

// ========================================
// Ì†ΩÌ≥© HANDLE FORM SUBMISSION (Save Draft / Submit)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_draft']) || isset($_POST['submit_request']))) {

    // Ì†ΩÌªë VERIFY TOKEN TO PREVENT DOUBLE SUBMIT
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['form_token']) {
        $_SESSION['msg'] = "‚ùå Duplicate submission detected. Your request is already processing.";
        header("Location: my_requests.php");
        exit;
    }

    $action = isset($_POST['submit_request']) ? 'submit' : 'save';
    $new_status = ($action === 'submit') ? 'Pending' : 'Draft';
    
    $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

    // Array check
    if (!isset($_POST['guest_name']) || !is_array($_POST['guest_name'])) {
        $_SESSION['msg'] = "‚ùå Guest data missing.";
        header("Location: guest_request.php");
        exit;
    } else {
        $dept           = mysqli_real_escape_string($conn, $_POST['department']);
        $check_in_date  = mysqli_real_escape_string($conn, $_POST['check_in_date']);
        $check_in_time  = mysqli_real_escape_string($conn, $_POST['check_in_time']);
        $check_out_date = mysqli_real_escape_string($conn, $_POST['check_out_date']);
        $check_out_time = mysqli_real_escape_string($conn, $_POST['check_out_time']);

        // Date validation
        if ($check_out_date < $check_in_date || ($check_out_date === $check_in_date && $check_out_time <= $check_in_time)) {
            $_SESSION['msg'] = "‚ùå Check-out must be after Check-in.";
            header("Location: guest_request.php");
            exit;
        } else {
            $selected_purpose = mysqli_real_escape_string($conn, $_POST['purpose_select']);
            if ($selected_purpose === 'Other') {
                if (empty(trim($_POST['purpose_note']))) {
                    $_SESSION['msg'] = "‚ùå Please describe your purpose.";
                    header("Location: guest_request.php");
                    exit;
                } else {
                    $final_purpose = mysqli_real_escape_string($conn, trim($_POST['purpose_note']));
                }
            } else {
                $final_purpose = $selected_purpose;
            }

            // Title validation for all guests
            foreach ($_POST['guest_title'] as $t) {
                if (empty(trim($t))) {
                    $_SESSION['msg'] = "‚ùå Please select Title for all guests.";
                    header("Location: guest_request.php");
                    exit;
                }
            }

            $requested_by = $_SESSION['UserName'];

            $g_title_0 = mysqli_real_escape_string($conn, $_POST['guest_title'][0]);
            $g_name_0  = mysqli_real_escape_string($conn, $_POST['guest_name'][0]);
            $first_guest_full = $g_title_0 . " " . $g_name_0;
            
            $g_phone_0 = mysqli_real_escape_string($conn, $_POST['phone'][0] ?? '');
            $g_email_0 = mysqli_real_escape_string($conn, $_POST['email'][0] ?? '');
            $g_desig_0 = mysqli_real_escape_string($conn, $_POST['designation'][0] ?? '');
            $g_id_0    = mysqli_real_escape_string($conn, $_POST['id_proof'][0] ?? '');
            $g_addr_0  = mysqli_real_escape_string($conn, $_POST['address'][0] ?? ''); 

            mysqli_begin_transaction($conn);

            try {
                if ($request_id > 0) {
                    // Update Master Request
                    $sql_master = "UPDATE visit_requests SET 
                        guest_name='$first_guest_full', phone='$g_phone_0', email='$g_email_0', designation='$g_desig_0', id_proof='$g_id_0', address='$g_addr_0',
                        department='$dept', check_in_date='$check_in_date', check_in_time='$check_in_time', check_out_date='$check_out_date', check_out_time='$check_out_time', 
                        purpose='$final_purpose', status='$new_status' 
                        WHERE id='$request_id' AND requested_by='".mysqli_real_escape_string($conn, $requested_by)."'";
                        
                    if (!mysqli_query($conn, $sql_master)) throw new Exception("Master Update Error: " . mysqli_error($conn));
                    mysqli_query($conn, "DELETE FROM visit_guests WHERE request_id='$request_id'");
                    $req_id = $request_id;
                } else {
                    // Insert New Master Request
                    $sql_master = "INSERT INTO visit_requests 
                        (guest_name, phone, email, designation, id_proof, address, department, check_in_date, check_in_time, check_out_date, check_out_time, purpose, requested_by, status) 
                        VALUES 
                        ('$first_guest_full', '$g_phone_0', '$g_email_0', '$g_desig_0', '$g_id_0', '$g_addr_0', '$dept', '$check_in_date', '$check_in_time', '$check_out_date', '$check_out_time', '$final_purpose', '$requested_by', '$new_status')";
                        
                    if (!mysqli_query($conn, $sql_master)) throw new Exception("Master Insert Error: " . mysqli_error($conn));
                    $req_id = mysqli_insert_id($conn);
                }

                $all_guests_data = [];

                // Insert Multiple Guests
                foreach ($_POST['guest_name'] as $key => $val) {
                    $g_title = mysqli_real_escape_string($conn, $_POST['guest_title'][$key]);
                    $g_name  = mysqli_real_escape_string($conn, $_POST['guest_name'][$key]);
                    $g_phone = mysqli_real_escape_string($conn, $_POST['phone'][$key]);
                    $g_email = mysqli_real_escape_string($conn, $_POST['email'][$key]);
                    $g_desig = mysqli_real_escape_string($conn, $_POST['designation'][$key]);
                    $g_id_val= mysqli_real_escape_string($conn, $_POST['id_proof'][$key]);
                    $g_addr  = mysqli_real_escape_string($conn, $_POST['address'][$key] ?? '');

                    $sql_guest = "INSERT INTO visit_guests (request_id, guest_title, guest_name, phone, email, designation, id_proof, address) 
                                  VALUES ('$req_id', '$g_title', '$g_name', '$g_phone', '$g_email', '$g_desig', '$g_id_val', '$g_addr')";
                                  
                    if (!mysqli_query($conn, $sql_guest)) throw new Exception("Guest Insert Error: " . mysqli_error($conn));
                    
                    $all_guests_data[] = ['name' => "$g_title $g_name", 'email' => $g_email, 'phone' => $g_phone, 'designation' => $g_desig];
                }

                mysqli_commit($conn);
                
                // Ì†ΩÌ¥Ñ INVALIDATE TOKEN TO PREVENT RESUBMISSION
                unset($_SESSION['form_token']);

                // ========================================
                // Ì†ΩÌ≥ß SEND EMAIL LOGIC
                // ========================================
                if ($action === 'submit') {
                    $headers  = "MIME-Version: 1.0\\r\\nContent-type:text/html;charset=UTF-8\\r\\nFrom: SCL Dormitory <no-reply@scl-dormitory.com>\\r\\n";

                    // 1. MAIL TO GUEST
                    $guest_subject = "Dormitory Visit Request Pending - Ref: #$req_id";
                    
                    if (!empty($user_data['email'])) {
                        $all_guests_data[] = ['name' => $user_data['full_name'], 'email' => $user_data['email'], 'phone' => '', 'designation' => ''];
                    }

                    $unique_emails = [];
                    foreach ($all_guests_data as $g) {
                        if (!empty($g['email']) && !in_array($g['email'], $unique_emails)) {
                            $unique_emails[] = $g['email'];
                            $guest_mail_body = "
                            <html><body style='font-family:Segoe UI,sans-serif;color:#333;margin:0;padding:0;background-color:#f4f4f4;'>
                                <div style='max-width:600px;margin:20px auto;background-color:#ffffff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
                                    <div style='background:#1a2a3a;color:#fff;padding:20px;text-align:center;'>
                                        <h2 style='margin:0;'>Visit Request Received</h2>
                                        <p style='margin:5px 0 0;color:#d1d8e0;'>Reference ID: #$req_id</p>
                                    </div>
                                    <div style='padding:25px;'>
                                        <p style='margin-top:0;'>Dear <strong>{$g['name']}</strong>,</p>
                                        <p>A dormitory visit request has been submitted and is currently <strong>Pending Approval</strong>.</p>
                                        <div style='background:#f8f9fa;border-left:4px solid #1a2a3a;padding:15px;margin:20px 0;'>
                                            <p style='margin:0 0 8px 0;'><strong>Check-in:</strong> " . date('d M Y', strtotime($check_in_date)) . " at " . date('h:i A', strtotime($check_in_time)) . "</p>
                                            <p style='margin:0 0 8px 0;'><strong>Check-out:</strong> " . date('d M Y', strtotime($check_out_date)) . " at " . date('h:i A', strtotime($check_out_time)) . "</p>
                                            <p style='margin:0;'><strong>Purpose:</strong> $final_purpose</p>
                                        </div>
                                        <p style='margin-top:25px;'>The admin team will review and notify you once approved or rejected.</p>
                                        <br>
                                        <p style='margin:0;'>Best regards,<br>SCL Dormitory Management Team</p>
                                    </div>
                                    <div style='background:#f1f1f1;padding:15px;text-align:center;font-size:12px;color:#666;border-top:1px solid #eeeeee;'>
                                        <p style='margin:0;'>SCL Dormitory Management System</p>
                                    </div>
                                </div>
                            </body></html>";
                            @mail($g['email'], $guest_subject, $guest_mail_body, $headers);
                        }
                    }

                    // 2. MAIL TO ADMIN / AUTHORIZATION TEAM (USING BEAUTIFUL TEMPLATE)
                    $admin_subject = "New Request #$req_id Waiting for Approval";
                    $admin_emails = [];

                    $admin_sql = "SELECT email FROM users WHERE user_role IN ('admin', 'approver') AND email IS NOT NULL AND email != ''";
                    $admin_res = mysqli_query($conn, $admin_sql);
                    
                    if ($admin_res && mysqli_num_rows($admin_res) > 0) {
                        while ($row = mysqli_fetch_assoc($admin_res)) {
                            if (!empty($row['email'])) {
                                $admin_emails[] = $row['email'];
                            }
                        }
                    }

                    // Fallback email in case database doesn't return any admin email
                    if (empty($admin_emails)) {
                        $admin_emails[] = "it1@sheltechceramics.com"; 
                    }

                    $admin_check_in = date('d M Y h:i A', strtotime("$check_in_date $check_in_time"));
                    $admin_check_out = date('d M Y h:i A', strtotime("$check_out_date $check_out_time"));
                    
                    // NEW DYNAMIC HTML TEMPLATE FOR ADMIN
                    $admin_mail_body = "
                    <html><body style='font-family:Segoe UI,sans-serif;color:#333;margin:0;padding:0;background-color:#f4f4f4;'>
                        <div style='max-width:600px;margin:20px auto;background-color:#ffffff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
                            <div style='background-color:#0d6efd;color:#ffffff;padding:20px;text-align:center;'>
                                <h2 style='margin:0;font-size:22px;'>Action Required</h2>
                                <p style='margin:5px 0 0;font-size:14px;color:#e9ecef;'>New Visit Request Pending Approval</p>
                            </div>
                            <div style='padding:25px;'>
                                <p style='margin-top:0;'>Dear <strong>Authorization Team</strong>,</p>
                                <p>A new visit request has been submitted by <strong>$user</strong> and requires your review.</p>
                                
                                <div style='background-color:#f8f9fa;border-left:4px solid #0d6efd;padding:15px;margin:20px 0;'>
                                    <p style='margin:0 0 8px 0;'><strong>Reference ID:</strong> #$req_id</p>
                                    <p style='margin:0 0 8px 0;'><strong>Guest Name:</strong> $first_guest_full</p>
                                    <p style='margin:0 0 8px 0;'><strong>Requested By:</strong> $user</p>
                                    <p style='margin:0 0 8px 0;'><strong>Department:</strong> $dept</p>
                                    <p style='margin:0 0 8px 0;'><strong>Check-in:</strong> $admin_check_in</p>
                                    <p style='margin:0 0 8px 0;'><strong>Check-out:</strong> $admin_check_out</p>
                                    <p style='margin:0;'><strong>Purpose:</strong> $final_purpose</p>
                                </div>
                                
                                <p>Please log in to the system to review and approve or reject this request.</p>
                                
                                <div style='text-align:center;margin:30px 0 10px 0;'>
                                    <a href='http://192.168.6.113/dormitory/manage_requests.php' style='background-color:#0d6efd;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:5px;font-weight:bold;font-size:14px;display:inline-block;'>Review Request Now</a>
                                </div>
                            </div>
                            <div style='background-color:#f1f1f1;color:#777777;text-align:center;padding:15px;font-size:12px;border-top:1px solid #eeeeee;'>
                                SCL Dormitory Management System
                            </div>
                        </div>
                    </body></html>";

                    foreach (array_unique($admin_emails) as $admin_email) {
                        @mail($admin_email, $admin_subject, $admin_mail_body, $headers);
                    }

                    $_SESSION['msg'] = "‚úÖ Request Submitted! Ref ID: #$req_id. Email sent to guest and authorization team.";
                } else {
                    $_SESSION['msg'] = "‚úÖ Draft saved! Ref ID: #$req_id. You can edit and submit it later.";
                }

                // ‚úÖ INSTANT REDIRECT TO PREVENT RE-SUBMISSION (PRG PATTERN)
                header("Location: my_requests.php");
                exit;

            } catch (Exception $e) {
                mysqli_rollback($conn);
                $_SESSION['msg'] = "‚ùå Something went wrong: " . htmlspecialchars($e->getMessage());
                header("Location: guest_request.php");
                exit;
            }
        }
    }
}

// ========================================
// ‚úèÔ∏è LOAD DRAFT DATA
// ========================================
$edit_id = 0;
$draft_master = null;
$draft_guests = [];

if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $q_master = mysqli_query($conn, "SELECT * FROM visit_requests WHERE id = $edit_id AND requested_by = '$user' AND status = 'Draft'");
    if ($q_master && mysqli_num_rows($q_master) > 0) {
        $draft_master = mysqli_fetch_assoc($q_master);
        $q_guests = mysqli_query($conn, "SELECT * FROM visit_guests WHERE request_id = $edit_id ORDER BY id ASC");
        while ($g = mysqli_fetch_assoc($q_guests)) { $draft_guests[] = $g; }
    } else {
        $_SESSION['msg'] = "‚ö†Ô∏è Draft not found or you don't have permission.";
        header("Location: guest_request.php");
        exit;
    }
}

// Generate new token for the page load
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Mobile viewport meta -->
    <title><?php echo $edit_id > 0 ? "Edit Draft Request" : "Submit Visit Request"; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        
        /* Ì†ºÌºü Fade-in Animation */
        .page-fade-in { animation: fadeIn 0.6s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Ì†ºÌºü Floating Toast Notifications */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .custom-toast { min-width: 320px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); border: none; border-radius: 8px; overflow: hidden; }

        /* Sidebar Styling */
        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; z-index: 1000; transition: transform 0.3s ease; }
        .content { margin-left: 250px; padding: 30px; transition: margin-left 0.3s ease; }
        
        /* Mobile Top Navbar */
        .mobile-navbar { display: none; background: #1a2a3a; color: white; padding: 15px 20px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 999; }
        .menu-toggle-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; }

        .guest-card { background: #fff; border: 1px solid #dee2e6; padding: 20px; border-radius: 10px; margin-bottom: 15px; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .remove-guest { position: absolute; top: 15px; right: 15px; color: #dc3545; cursor: pointer; font-size: 1.2rem; transition: 0.3s; }
        .remove-guest:hover { color: #a71d2a; }
        .readonly-field { background-color:#e9ecef; cursor:not-allowed; }
        .btn-add { background-color: #28a745; color: white; border: none; }
        
        /* RESPONSIVE STYLES */
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); /* Hide sidebar by default on mobile */ }
            .sidebar.active { transform: translateX(0); /* Show sidebar when active */ }
            .content { margin-left: 0; padding: 15px; } 
            .mobile-navbar { display: flex; }
            .content { padding-top: 20px; }
            .card-body { padding: 15px; }
            .btn { font-size: 0.9rem; }
        }
        
        /* Overlay for mobile sidebar */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
        .sidebar-overlay.active { display: block; }
    </style>
</head>
<body class="page-fade-in">

<!-- Ì†ºÌºü Floating Toast Notifications -->
<div class="toast-container" id="toastBox">
    <?php if (isset($_SESSION['msg']) && !empty($_SESSION['msg'])): ?>
        <?php 
            $msg_text = strip_tags($_SESSION['msg']);
            $is_success = (strpos($msg_text, '‚úÖ') !== false);
            $is_warning = (strpos($msg_text, '‚ö†Ô∏è') !== false);
            
            if ($is_success) { $bg_class = 'bg-success'; $icon = 'fa-check-circle'; $title = 'Success'; }
            elseif ($is_warning) { $bg_class = 'bg-warning text-dark'; $icon = 'fa-exclamation-triangle'; $title = 'Warning'; }
            else { $bg_class = 'bg-danger'; $icon = 'fa-times-circle'; $title = 'Error'; }
        ?>
        <div class="toast custom-toast show" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
            <div class="toast-header <?php echo $bg_class; ?> text-white border-0">
                <i class="fas <?php echo $icon; ?> me-2"></i><strong class="me-auto"><?php echo $title; ?></strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body bg-white text-dark fw-semibold">
                <?php echo str_replace(['‚úÖ', '‚ùå', '‚ö†Ô∏è'], '', $msg_text); ?>
            </div>
        </div>
        <?php unset($_SESSION['msg']); ?>
    <?php endif; ?>
</div>

<!-- Mobile Navbar & Overlay -->
<div class="mobile-navbar shadow-sm">
    <h5 class="mb-0"><i class="fas fa-hotel me-2"></i>SCL DMS</h5>
    <button class="menu-toggle-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="d-flex justify-content-between align-items-center mb-4 d-md-none">
        <h4 class="mb-0"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
        <button class="btn btn-sm btn-outline-light" onclick="toggleSidebar()"><i class="fas fa-times"></i></button>
    </div>
    <h4 class="mb-4 text-center d-none d-md-block"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small>Logged in as</small><br><strong><?php echo htmlspecialchars($user); ?></strong>
    </div>
    <a href="index.php" class="btn btn-light w-100 mb-2 fw-bold"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="guest_request.php" class="btn btn-outline-info w-100 mb-2 text-white active"><i class="fas fa-paper-plane me-2"></i>Submit Request</a>
    <a href="my_requests.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-list-alt me-2"></i>My Sent Requests</a>
    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-md-12"> <!-- Adjusted column size for better mobile fit -->
                <div class="card shadow-sm border-0">
                    <div class="card-header <?php echo $edit_id > 0 ? 'bg-warning text-dark' : 'bg-dark text-white'; ?> d-flex justify-content-between align-items-center">
                        <h5 class="mb-0" style="font-size: 1.1rem;">
                            <?php if ($edit_id > 0): ?>
                                <i class="fas fa-pencil-alt me-2"></i>Edit Draft (Ref: #<?php echo $edit_id; ?>)
                            <?php else: ?>
                                <i class="fas fa-file-alt me-2"></i>New Visit Request
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        
                        <form method="POST" action="guest_request.php" id="requestForm">
                            <input type="hidden" name="request_id" value="<?php echo $edit_id; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['form_token']; ?>">

                            <h6 class="text-primary border-bottom pb-2 mb-3">Visit Schedule & Purpose</h6>
                            <div class="row">
                                <div class="col-sm-6 col-md-3 mb-3">
                                    <label class="form-label">Check-in Date *</label>
                                    <input type="date" name="check_in_date" class="form-control" value="<?php echo $draft_master ? $draft_master['check_in_date'] : ''; ?>" required>
                                </div>
                                <div class="col-sm-6 col-md-3 mb-3">
                                    <label class="form-label">Check-in Time *</label>
                                    <input type="time" name="check_in_time" class="form-control" value="<?php echo $draft_master ? $draft_master['check_in_time'] : ''; ?>" required>
                                </div>
                                <div class="col-sm-6 col-md-3 mb-3">
                                    <label class="form-label">Check-out Date *</label>
                                    <input type="date" name="check_out_date" class="form-control" value="<?php echo $draft_master ? $draft_master['check_out_date'] : ''; ?>" required>
                                </div>
                                <div class="col-sm-6 col-md-3 mb-3">
                                    <label class="form-label">Check-out Time *</label>
                                    <input type="time" name="check_out_time" class="form-control" value="<?php echo $draft_master ? $draft_master['check_out_time'] : ''; ?>" required>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Department</label>
                                    <input type="text" name="department" class="form-control readonly-field" value="<?php echo htmlspecialchars($draft_master['department'] ?? $user_data['department'] ?? 'Other'); ?>" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <?php 
                                        $d_purpose = $draft_master ? $draft_master['purpose'] : '';
                                        $is_other = ($d_purpose !== '' && !in_array($d_purpose, $purpose_list)); 
                                    ?>
                                    <label class="form-label">Purpose of Visit *</label>
                                    <select name="purpose_select" id="purposeSelect" class="form-select" onchange="toggleNote()" required>
                                        <option value="">-- Select Purpose --</option>
                                        <?php foreach($purpose_list as $p): ?>
                                          <option value="<?php echo htmlspecialchars($p); ?>" <?php if($d_purpose == $p) echo 'selected'; ?>><?php echo htmlspecialchars($p); ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other" <?php if($is_other) echo 'selected'; ?>>Other</option>
                                    </select>
                                    <textarea name="purpose_note" id="noteField" class="form-control mt-2" style="display:<?php echo $is_other ? 'block' : 'none'; ?>;" placeholder="Describe your purpose here..."><?php echo $is_other ? htmlspecialchars($d_purpose) : ''; ?></textarea>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                <h6 class="text-primary mb-0"><i class="fas fa-users me-2"></i>Guest Details</h6>
                                <button type="button" class="btn btn-sm btn-add" onclick="addGuest()"><i class="fas fa-user-plus me-1"></i> Add Guest</button>
                            </div>

                            <div id="guestContainer">
                                <?php if (!empty($draft_guests)): ?>
                                    <!-- Ì†ΩÌ¥µ EDIT MODE -->
                                    <?php foreach ($draft_guests as $index => $g): ?>
                                        <div class="guest-card" id="row_<?php echo $index; ?>">
                                            <?php if ($index > 0): ?>
                                                <span class="remove-guest" onclick="removeGuest('row_<?php echo $index; ?>')"><i class="fas fa-times-circle"></i></span>
                                            <?php endif; ?>
                                            <span class="badge <?php echo $index == 0 ? 'bg-secondary' : 'bg-info text-dark'; ?> mb-3 guest-badge">Guest #<?php echo $index + 1; ?> <?php echo $index == 0 ? '(Main)' : ''; ?></span>
                                            
                                            <div class="row">
                                                <div class="col-sm-4 col-md-2 mb-3">
                                                    <label class="form-label">Title *</label>
                                                    <select name="guest_title[]" class="form-select" required>
                                                        <option value="" disabled>-- Title --</option>
                                                        <option value="Mr." <?php if($g['guest_title']=='Mr.') echo 'selected'; ?>>Mr.</option>
                                                        <option value="Mrs." <?php if($g['guest_title']=='Mrs.') echo 'selected'; ?>>Mrs.</option>
                                                        <option value="Ms." <?php if($g['guest_title']=='Ms.') echo 'selected'; ?>>Ms.</option>
                                                    </select>
                                                </div>
                                                <div class="col-sm-8 col-md-5 mb-3"><label class="form-label">Name *</label><input type="text" name="guest_name[]" class="form-control <?php echo $index == 0 ? 'readonly-field' : ''; ?>" value="<?php echo htmlspecialchars($g['guest_name']); ?>" <?php echo $index == 0 ? 'readonly' : 'required'; ?>></div>
                                                <div class="col-sm-12 col-md-5 mb-3"><label class="form-label">Phone *</label><input type="text" name="phone[]" class="form-control <?php echo $index == 0 ? 'readonly-field' : ''; ?>" value="<?php echo htmlspecialchars($g['phone']); ?>" <?php echo $index == 0 ? 'readonly' : 'required'; ?>></div>
                                                <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Email</label><input type="email" name="email[]" class="form-control <?php echo $index == 0 ? 'readonly-field' : ''; ?>" value="<?php echo htmlspecialchars($g['email']); ?>" <?php echo $index == 0 ? 'readonly' : ''; ?>></div>
                                                <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Designation</label><input type="text" name="designation[]" class="form-control <?php echo $index == 0 ? 'readonly-field' : ''; ?>" value="<?php echo htmlspecialchars($g['designation']); ?>" <?php echo $index == 0 ? 'readonly' : ''; ?>></div>
                                                <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Address *</label><input type="text" name="address[]" class="form-control <?php echo $index == 0 ? 'readonly-field' : ''; ?>" value="<?php echo htmlspecialchars($g['address'] ?? ''); ?>" <?php echo $index == 0 ? 'readonly' : 'required'; ?>></div>
                                                <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">NID/Office ID</label><input type="text" name="id_proof[]" class="form-control <?php echo $index == 0 ? 'readonly-field' : ''; ?>" value="<?php echo htmlspecialchars($g['id_proof']); ?>" <?php echo $index == 0 ? 'readonly' : ''; ?>></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Ì†ΩÌø¢ NEW MODE -->
                                    <div class="guest-card" id="row_0">
                                        <span class="badge bg-secondary mb-3 guest-badge">Guest #1 (Main)</span>
                                        <div class="row">
                                            <div class="col-sm-4 col-md-2 mb-3">
                                                <label class="form-label">Title *</label>
                                                  <select name="guest_title[]" class="form-select" required>
                                                    <option value="" disabled selected>-- Title --</option>
                                                    <option value="Mr.">Mr.</option><option value="Mrs.">Mrs.</option><option value="Ms.">Ms.</option>
                                                </select>
                                            </div>
                                            <div class="col-sm-8 col-md-5 mb-3"><label class="form-label">Name *</label><input type="text" name="guest_name[]" class="form-control readonly-field" value="<?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?>" readonly></div>
                                            <div class="col-sm-12 col-md-5 mb-3"><label class="form-label">Phone *</label><input type="text" name="phone[]" class="form-control readonly-field" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" readonly></div>
                                            <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Email</label><input type="email" name="email[]" class="form-control readonly-field" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" readonly></div>
                                            <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Designation</label><input type="text" name="designation[]" class="form-control readonly-field" value="<?php echo htmlspecialchars($user_data['designation'] ?? ''); ?>" readonly></div>
                                            <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Address *</label><input type="text" name="address[]" class="form-control readonly-field" value="<?php echo htmlspecialchars($user_data['address'] ?? ''); ?>" readonly required></div>
                                            <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">NID/Office ID</label><input type="text" name="id_proof[]" class="form-control readonly-field" value="<?php echo htmlspecialchars($user_data['id_proof'] ?? ''); ?>" readonly></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12 col-md-6 mb-2">
                                  <button type="submit" name="save_draft" class="btn btn-secondary w-100 py-2 fw-bold">
                                    <i class="fas fa-save me-2"></i> <?php echo $edit_id > 0 ? "UPDATE DRAFT" : "SAVE DRAFT"; ?>
                                  </button>
                                </div>
                                <div class="col-12 col-md-6 mb-2">
                                  <button type="submit" id="submitBtn" name="submit_request" class="btn btn-primary w-100 py-2 fw-bold">
                                    <i class="fas fa-paper-plane me-2"></i>SUBMIT REQUEST
                                  </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar Toggle Logic for Mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

document.addEventListener('DOMContentLoaded', function () {
    // Initialize Toasts
    var toastElList = [].slice.call(document.querySelectorAll('.toast'));
    var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { autohide: false });
    });
    
    renumberGuests();

    // JS Prevent Double Submission
    const form = document.getElementById('requestForm');
    form.addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        if(btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
            btn.style.pointerEvents = 'none';
        }
    });
});

function toggleNote() {
    var sel = document.getElementById('purposeSelect');
    var note = document.getElementById('noteField');
    note.style.display = (sel.value === 'Other') ? 'block' : 'none';
    note.required = (sel.value === 'Other');
}

function addGuest() {
    let container = document.getElementById('guestContainer');
    let id = "row_" + Date.now();

    let html = `
      <div class="guest-card" id="${id}">
        <span class="remove-guest" onclick="removeGuest('${id}')"><i class="fas fa-times-circle"></i></span>
        <span class="badge bg-info text-dark mb-3 guest-badge">Guest</span>
        <div class="row">
          <div class="col-sm-4 col-md-2 mb-3"><label class="form-label">Title *</label><select name="guest_title[]" class="form-select" required><option value="" disabled selected>-- Title --</option><option value="Mr.">Mr.</option><option value="Mrs.">Mrs.</option><option value="Ms.">Ms.</option></select></div>
          <div class="col-sm-8 col-md-5 mb-3"><label class="form-label">Name *</label><input type="text" name="guest_name[]" class="form-control" required></div>
          <div class="col-sm-12 col-md-5 mb-3"><label class="form-label">Phone *</label><input type="text" name="phone[]" class="form-control" required></div>
          <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Email</label><input type="email" name="email[]" class="form-control" required></div>
          <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Designation</label><input type="text" name="designation[]" class="form-control"></div>
          <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">Address *</label><input type="text" name="address[]" class="form-control" required></div>
          <div class="col-sm-12 col-md-4 mb-3"><label class="form-label">NID/Office ID</label><input type="text" name="id_proof[]" class="form-control" required></div>
        </div>
      </div>`;
    container.insertAdjacentHTML('beforeend', html);
    renumberGuests();
}

function renumberGuests() {
    const cards = document.querySelectorAll('#guestContainer .guest-card');
    let num = 1;
    cards.forEach((card, idx) => {
        const badge = card.querySelector('.guest-badge');
        const removeBtn = card.querySelector('.remove-guest');
        if (idx === 0) {
            if (badge) badge.textContent = 'Guest #1 (Main)';
            if (removeBtn) removeBtn.style.display = 'none';
        } else {
            num = idx + 1;
            if (badge) badge.textContent = `Guest #${num}`;
            if (removeBtn) removeBtn.style.display = 'block';
        }
    });
}

function removeGuest(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
    renumberGuests();
}
</script>
</body>
</html>
