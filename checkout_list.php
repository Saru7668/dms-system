<?php
session_start();
require_once('db.php');
require_once('header.php');

// ? ERROR REPORTING (Production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// ? Access Control
$role = isset($_SESSION['UserRole']) ? $_SESSION['UserRole'] : '';

if (!isset($_SESSION['UserName']) || !in_array($role, ['staff', 'admin', 'superadmin'])) {
    if ($role === 'approver') {
        header("Location: manage_requests.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole'];

date_default_timezone_set('Asia/Dhaka');

// ? CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Room filter from URL
$filter_room = isset($_GET['room']) ? trim($_GET['room']) : '';
if (!empty($filter_room) && !preg_match('/^[A-Z0-9-]+$/i', $filter_room)) {
    $filter_room = ''; // Invalid room format
}

// === Helper Function: Beautiful HTML Email ===
function sendBeautifulEmail($to, $subject, $title, $ref_id, $message_body) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";

    $ref_text = ($ref_id > 0) ? "Reference ID: #$ref_id" : "SCL Dormitory Notification";

    $html = "
    <html><body style='font-family:Segoe UI, sans-serif; background-color:#f4f7f6; padding:20px; color:#333;'>
        <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 4px 10px rgba(0,0,0,0.1); border:1px solid #e0e0e0;'>
            <div style='background-color:#1a2a3a; color:#ffffff; padding:20px; text-align:center;'>
                <h2 style='margin:0; font-size:22px; font-weight:600;'>$title</h2>
                <p style='margin:5px 0 0; font-size:14px; color:#d1d8e0;'>$ref_text</p>
            </div>
            <div style='padding:30px; font-size:15px; line-height:1.6;'>
                $message_body
                <br><br>
                <p style='margin:0;'>Best regards,<br><strong>SCL Dormitory Management Team</strong></p>
            </div>
            <div style='background-color:#f1f1f1; color:#777; text-align:center; padding:15px; font-size:12px; border-top:1px solid #e9ecef;'>
                SCL Dormitory Management System
            </div>
        </div>
    </body></html>";

    return @mail($to, $subject, $html, $headers);
}

// ========================================
// --- CANCEL BOOKING LOGIC ---
// ========================================
if (isset($_POST['cancel_booking_submit'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token. Please refresh and try again.");
    }
    
    $booking_id = (int)$_POST['booking_id'];
    $today = date('Y-m-d H:i:s');
    
    // ???? ??? ????? ???, ??? ?????? ???? ????? ????
    $cancel_reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : '';
    if (empty($cancel_reason)) {
        $cancel_reason = 'Cancelled by Admin/Staff';
    }
    
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT * FROM bookings WHERE id = ? AND status = 'Booked' LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $booking_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($res && mysqli_num_rows($res) == 1) {
            $bk = mysqli_fetch_assoc($res);
            $req_ref_id = !empty($bk['request_ref_id']) ? $bk['request_ref_id'] : 0;

            // ?. Cancelled Bookings ?????? ???? ??????? ???
            $stmt2 = mysqli_prepare($conn, "INSERT INTO cancelled_bookings (booking_id, request_ref_id, guest_name, designation, address, room_number, check_in_date, cancel_date, department, phone, id_proof, cancel_reason, cancelled_by, secondary_guest_name, secondary_guest_title, secondary_guest_designation, secondary_guest_address, secondary_guest_phone, secondary_guest_email, secondary_guest_id_proof) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt2, "iissssssssssssssssss", $booking_id, $req_ref_id, $bk['guest_name'], $bk['designation'], $bk['address'], $bk['room_no'], $bk['checked_in'], $today, $bk['department'], $bk['phone'], $bk['id_proof'], $cancel_reason, $userName, $bk['secondary_guest_name'], $bk['secondary_guest_title'], $bk['secondary_guest_designation'], $bk['secondary_guest_address'], $bk['secondary_guest_phone'], $bk['secondary_guest_email'], $bk['secondary_guest_id_proof']);
            mysqli_stmt_execute($stmt2);

            // ?. Room ????????? Available ???
            $stmt3 = mysqli_prepare($conn, "UPDATE rooms SET status = 'Available' WHERE room_no = ?");
            mysqli_stmt_bind_param($stmt3, "s", $bk['room_no']);
            mysqli_stmt_execute($stmt3);

            // ?. Bookings ????? ???? ????? ???
            $stmt4 = mysqli_prepare($conn, "DELETE FROM bookings WHERE id = ?");
            mysqli_stmt_bind_param($stmt4, "i", $booking_id);
            mysqli_stmt_execute($stmt4);

            // ?. Notification ??? Request Status ????? ??? 
            if ($req_ref_id > 0) {
                // ?????????? ????? ???? ???? ??????? ?????
                mysqli_query($conn, "DELETE FROM notifications WHERE request_id = $req_ref_id AND type IN ('cancellation_request', 'cancellationrequest')");
                mysqli_query($conn, "UPDATE visit_requests SET status = 'Cancelled' WHERE id = $req_ref_id");
            }

            mysqli_commit($conn);

            // ========================================
            // ?? Send Beautiful Cancellation Emails
            // ========================================
            $guest_name_full = trim(($bk['guest_title'] ?? '') . ' ' . $bk['guest_name']);
            $check_in_disp = date('d M Y h:i A', strtotime($bk['checked_in']));

            $email_template = "
                <p><strong>Dear {GUEST_NAME},</strong></p>
                <p>We regret to inform you that your dormitory booking has been <strong style='color:#dc3545;'>CANCELLED</strong>.</p>
                <div style='background-color:#f8f9fa; border-left:4px solid #dc3545; padding:15px; margin:20px 0;'>
                    <ul style='list-style:none; padding:0; margin:0;'>
                        <li style='margin-bottom:8px;'><strong>Room:</strong> {$bk['room_no']}</li>
                        <li style='margin-bottom:8px;'><strong>Original Check-in:</strong> $check_in_disp</li>
                        <li style='margin-bottom:8px;'><strong>Department:</strong> {$bk['department']}</li>
                        <li><strong>Cancellation Reason:</strong> $cancel_reason</li>
                    </ul>
                </div>
                <p>If you believe this was done in error, please contact the dormitory team immediately.</p>";

            if (!empty($bk['guest_email'])) {
                $primary_body = str_replace('{GUEST_NAME}', $guest_name_full, $email_template);
                sendBeautifulEmail($bk['guest_email'], "Booking Cancelled - Room {$bk['room_no']}", "Booking Cancelled", $req_ref_id, $primary_body);
            }

            if (!empty($bk['secondary_guest_email'])) {
                $sec_name = trim(($bk['secondary_guest_title'] ?? '') . ' ' . $bk['secondary_guest_name']);
                if(!empty($sec_name)) {
                    $sec_body = str_replace('{GUEST_NAME}', $sec_name, $email_template);
                    sendBeautifulEmail($bk['secondary_guest_email'], "Booking Cancelled - Room {$bk['room_no']}", "Booking Cancelled", $req_ref_id, $sec_body);
                }
            }

            header("Location: cancel_list.php?cancel_success=1"); 
            exit;
        } else {
            header("Location: checkout_list.php?error=not_found"); 
            exit;
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        // ???????? ??? ??????? ??? ????
        die("Database Error: " . $e->getMessage()); 
        // header("Location: checkout_list.php?error=db_error"); 
        // exit;
    }
}

// ========================================
// --- CHECKOUT LOGIC ---
// ========================================
if (isset($_GET['checkout_room'])) {
    if (!isset($_GET['csrf']) || $_GET['csrf'] !== $_SESSION['csrf_token']) die("Invalid request.");
    
    $room_no = trim($_GET['checkout_room']);
    $today = date('Y-m-d H:i:s');
    $today_display = date('d M Y h:i A');

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT * FROM bookings WHERE room_no = ? AND status = 'Booked' ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $room_no);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        
        if ($res && mysqli_num_rows($res) > 0) {
            $bk = mysqli_fetch_assoc($res);
            $booking_id = (int)$bk['id'];
            $req_ref_id = !empty($bk['request_ref_id']) ? $bk['request_ref_id'] : 0;
            $total_days = max(1, (int)ceil((strtotime($today) - strtotime($bk['checked_in'])) / 86400));

            $stmt2 = mysqli_prepare($conn, "INSERT INTO checked_out_guests (booking_id, request_ref_id, guest_name, guest_title, designation, address, room_number, check_in_date, check_out_date, total_days, department, phone, id_proof, secondary_guest_name, secondary_guest_title, secondary_guest_designation, secondary_guest_address, secondary_guest_phone, secondary_guest_email, secondary_guest_id_proof) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt2, "iisssssssissssssssss", $booking_id, $req_ref_id, $bk['guest_name'], $bk['guest_title'], $bk['designation'], $bk['address'], $room_no, $bk['checked_in'], $today, $total_days, $bk['department'], $bk['phone'], $bk['id_proof'], $bk['secondary_guest_name'], $bk['secondary_guest_title'], $bk['secondary_guest_designation'], $bk['secondary_guest_address'], $bk['secondary_guest_phone'], $bk['secondary_guest_email'], $bk['secondary_guest_id_proof']);
            mysqli_stmt_execute($stmt2);

            mysqli_query($conn, "UPDATE rooms SET status = 'Available' WHERE room_no = '$room_no'");
            mysqli_query($conn, "UPDATE bookings SET status = 'Checked-Out', check_out_date = '$today' WHERE id = $booking_id");

            mysqli_commit($conn);

            // Send Beautiful Checkout Emails
            $guest_name_full = trim(($bk['guest_title'] ?? '') . ' ' . $bk['guest_name']);
            $check_in_disp = date('d M Y h:i A', strtotime($bk['checked_in']));

            $body = "
                <p><strong>Dear $guest_name_full,</strong></p>
                <p>We hope you had a comfortable stay at the SCL Dormitory.</p>
                <p>This email confirms that you have successfully <strong style='color:#28a745;'>CHECKED OUT</strong>.</p>
                <div style='background-color:#f8f9fa; border-left:4px solid #1a2a3a; padding:15px; margin:20px 0;'>
                    <h4 style='margin-top:0; color:#1a2a3a;'>Stay Summary</h4>
                    <ul style='list-style:none; padding:0; margin:0;'>
                        <li style='margin-bottom:8px;'><strong>Room Number:</strong> $room_no</li>
                        <li style='margin-bottom:8px;'><strong>Check-in Date:</strong> $check_in_disp</li>
                        <li style='margin-bottom:8px;'><strong>Check-out Date:</strong> $today_display</li>
                        <li><strong>Total Duration:</strong> $total_days Day(s)</li>
                    </ul>
                </div>
                <p>If you have left any personal belongings behind, please contact us immediately.</p>
                <p>We look forward to welcoming you again!</p>";

            if (!empty($bk['guest_email'])) {
                sendBeautifulEmail($bk['guest_email'], "Checkout Confirmation - Room $room_no", "Checkout Successful", $req_ref_id, $body);
            }

            if (!empty($bk['secondary_guest_email'])) {
                $sec_name = trim(($bk['secondary_guest_title'] ?? '') . ' ' . $bk['secondary_guest_name']);
                $sec_body = str_replace($guest_name_full, $sec_name, $body);
                sendBeautifulEmail($bk['secondary_guest_email'], "Checkout Confirmation - Room $room_no", "Checkout Successful", $req_ref_id, $sec_body);
            }

            header("Location: checkout_history.php?checkout_success=1"); exit;
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Mobile viewport meta -->
    <title>Active Bookings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .page-fade-in { animation: fadeIn 0.6s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .custom-toast { min-width: 300px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: none; border-radius: 8px; overflow: hidden; }
        
        /* Sidebar Styling */
        .sidebar { background: #1a2a3a; color: white; min-height: 100vh; padding: 20px; position: fixed; width: 250px; z-index: 1000; transition: transform 0.3s ease; }
        .content { margin-left: 250px; padding: 30px; transition: margin-left 0.3s ease; }
        
        /* Mobile Top Navbar */
        .mobile-navbar { display: none; background: #1a2a3a; color: white; padding: 15px 20px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 999; }
        .menu-toggle-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; }

        .sec-badge { font-size: 0.75rem; }
        .email-text { font-size: 0.85rem; color: #666; word-break: break-all; }
        .details-text { font-size: 0.8rem; color: #555; display: block; line-height: 1.3; }
        .details-icon { font-size: 0.75rem; width: 15px; text-align: center; color: #888; margin-right: 3px; }
        tbody tr { transition: background-color 0.2s ease; }
        tbody tr:hover { background-color: #f1f8ff !important; }

        /* RESPONSIVE STYLES */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .content { margin-left: 0; padding: 15px; padding-top: 20px; }
            .mobile-navbar { display: flex; }
            .card-body { padding: 10px; }
            .table-responsive { border: 0; }
        }
        
        /* Overlay for mobile sidebar */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
        .sidebar-overlay.active { display: block; }
    </style>
</head>
<body class="page-fade-in">

<div class="toast-container">
    <?php if(isset($_GET['cancel_success'])): ?>
    <div class="toast custom-toast show" role="alert" data-bs-delay="4000">
        <div class="toast-header bg-danger text-white border-0">
            <i class="fas fa-check-circle me-2"></i><strong class="me-auto">Success</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white text-dark fw-semibold">Booking has been cancelled and email sent.</div>
    </div>
    <?php endif; ?>
    <?php if(isset($_GET['checkout_success'])): ?>
    <div class="toast custom-toast show" role="alert" data-bs-delay="4000">
        <div class="toast-header bg-success text-white border-0">
            <i class="fas fa-check-circle me-2"></i><strong class="me-auto">Checked Out</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white text-dark fw-semibold">Guest checked out successfully and email sent.</div>
    </div>
    <?php endif; ?>
    <?php if(isset($_GET['error'])): ?>
    <div class="toast custom-toast show" role="alert" data-bs-delay="5000">
        <div class="toast-header bg-warning text-dark border-0">
            <i class="fas fa-exclamation-triangle me-2"></i><strong class="me-auto">Error</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white text-dark">
            <?php 
                if($_GET['error'] == 'not_found') echo "Booking not found or already checked out.";
                elseif($_GET['error'] == 'reason_required') echo "Valid cancellation reason is required.";
                else echo "Something went wrong.";
            ?>
        </div>
    </div>
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
    <h4 class="text-center mb-4 d-none d-md-block">SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small class="text-light">Logged in as:</small><br>
        <strong class="text-white"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <span class="badge bg-warning text-dark mt-1 fs-6"><?php echo strtoupper(htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8')); ?></span>
    </div>
    <a href="index.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="checkout_list.php" class="btn btn-primary w-100 mb-2"><i class="fas fa-clipboard-check me-2"></i>Active Bookings</a>
    <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-history me-2"></i>History</a>
    <a href="cancel_list.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-ban me-2"></i>Cancelled List</a>
    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
<?php if(!empty($filter_room)): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center shadow-sm">
    <span><i class="fas fa-filter me-2"></i>Showing only Room <strong><?php echo htmlspecialchars($filter_room, ENT_QUOTES, 'UTF-8'); ?></strong></span>
    <a href="checkout_list.php" class="btn btn-sm btn-outline-dark">Show All Rooms</a>
</div>
<?php endif; ?>

    <div class="card shadow border-0">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0" style="font-size: 1.1rem;"><i class="fas fa-list me-2"></i>Active Guests List</h5>
            <span class="badge bg-light text-dark">Booked</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0" style="min-width: 900px;"> <!-- Minimum width to avoid squishing on mobile -->
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" width="25%">Guest(s) Details</th>
                            <th>Contact & Email</th>
                            <th>Room Info</th>
                            <th>Check-in / Check-out</th>
                            <th>Dept</th>
                            <th class="pe-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (!empty($filter_room)) {
                            $stmt = mysqli_prepare($conn, "SELECT b.*, v.cancel_reason AS v_cancel_reason FROM bookings b LEFT JOIN visit_requests v ON b.request_ref_id = v.id WHERE b.status = 'Booked' AND b.room_no = ? ORDER BY b.id DESC");
                            mysqli_stmt_bind_param($stmt, "s", $filter_room);
                        } else {
                            $stmt = mysqli_prepare($conn, "SELECT b.*, v.cancel_reason AS v_cancel_reason FROM bookings b LEFT JOIN visit_requests v ON b.request_ref_id = v.id WHERE b.status = 'Booked' ORDER BY b.id DESC");
                        }
                        
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);

                        if ($result && mysqli_num_rows($result) > 0) {
                                while($row = mysqli_fetch_assoc($result)) {
                                    $guestTitle = htmlspecialchars($row['guest_title'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $guestName  = htmlspecialchars($row['guest_name'], ENT_QUOTES, 'UTF-8');
                                    $displayName = trim($guestTitle . ' ' . $guestName);
                                    $desig     = htmlspecialchars($row['designation'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $addr      = htmlspecialchars($row['address'] ?? '', ENT_QUOTES, 'UTF-8');
                            
                                    $secName  = htmlspecialchars($row['secondary_guest_name'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secPhone = htmlspecialchars($row['secondary_guest_phone'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secEmail = htmlspecialchars($row['secondary_guest_email'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secDesig = htmlspecialchars($row['secondary_guest_designation'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $isDouble = (stripos($row['room_type'], 'double') !== false);
                            
                                    $room_no_safe   = htmlspecialchars($row['room_no'], ENT_QUOTES, 'UTF-8');
                                    $phone_safe     = htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8');
                                    $email_safe     = htmlspecialchars($row['guest_email'], ENT_QUOTES, 'UTF-8');
                                    $room_type_safe = htmlspecialchars($row['room_type'], ENT_QUOTES, 'UTF-8');
                                    $dept_safe      = htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8');
                                    $request_ref = !empty($row['request_ref_id']) ? (int)$row['request_ref_id'] : 0;
                            
                                    $dateDisplay = date('d M Y', strtotime($row['checked_in']));
                                    $timeDisplay = (!empty($row['arrival_time']) && $row['arrival_time'] != '00:00:00')
                                        ? date('h:i A', strtotime($row['arrival_time'])) : date('h:i A', strtotime($row['checked_in']));
                            
                                    // Planned Check-out logic
                                    $plannedOutDate = !empty($row['check_out_date']) ? date('d M Y', strtotime($row['check_out_date'])) : '';
                                    $plannedOutTime = (!empty($row['departure_time']) && $row['departure_time'] != '00:00:00') 
                                        ? date('h:i A', strtotime($row['departure_time'])) : '';

                                    $checkout_url = 'checkout_list.php?checkout_room=' . urlencode($row['room_no']) . '&csrf=' . urlencode($_SESSION['csrf_token']);
                            
                                    echo "<tr>
                                            <td class='ps-3'>
                                                <div class='mb-1'>
                                                    <div class='fw-bold text-primary'>$displayName</div>";
                                    if ($desig) echo "<span class='details-text'><i class='fas fa-briefcase details-icon'></i> $desig</span>";
                                    if ($addr)  echo "<span class='details-text'><i class='fas fa-map-marker-alt details-icon'></i> $addr</span>";
                                    echo    "</div>";
                            
                                    if ($isDouble && !empty($secName)) {
                                        echo "<div class='mt-2 pt-2 border-top'>
                                                <span class='badge bg-info text-dark sec-badge me-1'>2nd</span><span class='fw-semibold'>$secName</span>";
                                        if ($secDesig) echo "<span class='details-text ms-1'><i class='fas fa-briefcase details-icon'></i> $secDesig</span>";
                                        echo "</div>";
                                    }
                                    echo    "</td>
                                             <td style='font-size: 0.9rem;'>
                                                <div><i class='fas fa-phone-alt text-success me-1'></i> $phone_safe<br><i class='fas fa-envelope text-primary me-1'></i> <span class='email-text'>$email_safe</span></div>";
                                    if ($isDouble && !empty($secName)) {
                                        echo "<div class='mt-2 pt-2 border-top'>";
                                        if (!empty($secPhone)) echo "<i class='fas fa-phone-alt text-success me-1'></i> $secPhone<br>";
                                        if (!empty($secEmail)) echo "<i class='fas fa-envelope text-primary me-1'></i> <span class='email-text'>$secEmail</span>";
                                        echo "</div>";
                                    }
                                    echo   "</td>
                                            <td>
                                                <span class='badge bg-primary fs-6 mb-1'>$room_no_safe</span><br><small style='font-size: 0.8rem;'>$room_type_safe</small>";
                                    if($request_ref > 0) echo "<br><span class='badge bg-warning text-dark mt-1'><i class='fas fa-tag me-1'></i>Ref #$request_ref</span>";
                                    echo   "</td>
                                            <td style='font-size: 0.9rem;'>
                                                <div class='mb-2'>
                                                    <small class='text-muted fw-bold'>Check-In</small><br>
                                                    <span class='fw-bold text-success'>$dateDisplay</span>
                                                    <span class='badge bg-success bg-opacity-25 text-dark ms-1'>$timeDisplay</span>
                                                </div>";
                                    if ($plannedOutDate !== '') {
                                        echo "<div>
                                                <small class='text-muted fw-bold'>Checkout (Planned)</small><br>
                                                <span class='fw-bold text-danger'>$plannedOutDate</span>";
                                        if($plannedOutTime !== '') echo "<span class='badge bg-danger bg-opacity-25 text-dark ms-1'>$plannedOutTime</span>";
                                        echo "</div>";
                                    }
                                    // ? ??? ??? ????? ???????? ????????? ??? ?? ??
                                    $isCancelRequested = !empty($row['v_cancel_reason']);

                                    echo   "</td>
                                            <td><span class='badge bg-secondary'>$dept_safe</span></td>
                                            <td class='pe-3 text-center'>
                                                <div class='d-flex flex-column gap-2'>";
                                    
                                    if ($isCancelRequested) {
                                        // ?? Cancel Request ????? Checkout ???? ????, ???? Cancel ???? ??????
                                        $cancelReasonSafe = isset($row['v_cancel_reason']) ? htmlspecialchars($row['v_cancel_reason'], ENT_QUOTES, 'UTF-8') : '';
                                        echo "<span class='badge bg-danger mb-1' style='font-size: 0.75rem;'><i class='fas fa-exclamation-circle me-1'></i>Cancel Requested</span>
                                              <button type='button' class='btn btn-danger btn-sm fw-bold shadow-sm' data-bs-toggle='modal' data-bs-target='#cancelModal' onclick='openCancelModal({$row['id']}, \"$room_no_safe\", \"".addslashes($displayName)."\", \"".addslashes($cancelReasonSafe)."\")'>
                                                  <i class='fas fa-times-circle me-1'></i>Approve Cancel
                                              </button>";
                                    } else {
                                        // ?? ?????? ??????? Checkout ??? Cancel ??? ????? ??????
                                        echo "<a href='$checkout_url' class='btn btn-warning btn-sm fw-bold shadow-sm' onclick='return confirm(\"Check-out room $room_no_safe? This will free up the room and send a confirmation email.\")'>
                                                  <i class='fas fa-sign-out-alt me-1'></i>Check-out
                                              </a>
                                              <button type='button' class='btn btn-danger btn-sm fw-bold shadow-sm' data-bs-toggle='modal' data-bs-target='#cancelModal' onclick='openCancelModal({$row['id']}, \"$room_no_safe\", \"".addslashes($displayName)."\")'>
                                                  <i class='fas fa-times-circle me-1'></i>Cancel
                                              </button>";
                                    }

                                    echo "      </div>
                                            </td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='text-center py-5 text-muted'><i class='fas fa-bed fa-3x mb-3 opacity-25'></i><br><h5>No Active Bookings</h5></td></tr>";
                            }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Booking Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-ban me-2"></i>Cancel Booking</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="booking_id" id="cancelBookingId">
        <div class="modal-body bg-light">
          <p class="mb-2 text-dark">You are about to cancel the booking for:</p>
          <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded border mb-3">
              <h6 class="fw-bold mb-0 text-primary" id="cancelGuestName"></h6>
              <span class="badge bg-danger fs-6" id="cancelRoomBadge"></span>
          </div>
          <div class="mb-2">
            <label class="form-label fw-bold text-dark">Cancellation Reason <span class="text-danger">*</span></label>
            <textarea name="cancel_reason" id="cancelReason" class="form-control border-danger" rows="3" placeholder="Write cancellation reason..."></textarea>
            <small class="text-muted d-block mt-1"><i class="fas fa-info-circle me-1"></i>This reason will be included in the email sent to the guest.</small>
          </div>
        </div>
        <div class="modal-footer bg-light border-0">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" name="cancel_booking_submit" class="btn btn-danger"><i class="fas fa-paper-plane me-1"></i>Confirm Cancel</button>
        </div>
      </form>
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
    var toastElList = [].slice.call(document.querySelectorAll('.toast'));
    var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { autohide: true });
    });
});

function openCancelModal(bookingId, roomNo, guestName, prefilledReason = '') {
    document.getElementById('cancelBookingId').value = bookingId;
    document.getElementById('cancelRoomBadge').textContent = 'Room ' + roomNo;
    document.getElementById('cancelGuestName').textContent = guestName;
    
    // ??? ??? ????? ???????? ???? ????, ???? ????? ??? ????? ????
    if(prefilledReason && prefilledReason.trim() !== '') {
        document.getElementById('cancelReason').value = prefilledReason;
    } else {
        document.getElementById('cancelReason').value = '';
    }
}
</script>

</body>
</html>
