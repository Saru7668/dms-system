<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];
$role = $_SESSION['UserRole'] ?? 'user';

// ========================================
// ������ LOAD FEATURE FLAG (Booking Ref Requirement)
// ========================================
$require_ref_for_booking = 0;
$flag_res = mysqli_query($conn, "SELECT setting_value FROM app_settings WHERE setting_key = 'require_ref_for_booking' LIMIT 1");
if ($flag_res && mysqli_num_rows($flag_res) == 1) {
    $require_ref_for_booking = (int)mysqli_fetch_assoc($flag_res)['setting_value'];
}

// ========================================
// ������ MARK NOTIFICATIONS AS READ (Staff/Admin)
// ========================================
if (isset($_POST['mark_notif_read']) && in_array($role, ['staff', 'admin', 'superadmin'])) {

    // Approved request গুলো যেগুলো এখনো বুক হয়নি, সেগুলোকে read + timestamp দাও
    $sql_mark = "
        UPDATE notifications n
        JOIN visit_requests v ON v.id = n.request_id
        SET n.is_read = 1,
            n.last_shown_at = NOW()
        WHERE n.is_read = 0
          AND n.type = 'request_approved'
          AND v.status <> 'Booked'
    ";
    mysqli_query($conn, $sql_mark);

    header("Location: index.php");
    exit;
}

// Departments
$dept_list = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand", "Other"];

// ✅ BOOKING LOGIC: Only Staff & Admin (Approver Removed)
if (isset($_POST['confirm_booking']) && in_array($role, ['staff', 'admin', 'superadmin'])) {
    
    $tagged_request_id = isset($_POST['tagged_request_id']) ? (int)$_POST['tagged_request_id'] : 0;
    
    
    // ========================================
    // ������ CHECK FEATURE FLAG: Ref Required for Booking?
    // ========================================
    if ($require_ref_for_booking == 1 && $tagged_request_id <= 0) {
        $message = "<div class='alert alert-danger'>❌ Booking Blocked: You must select an Approved Request Ref to proceed with booking!</div>";
    } else {
        // ... (All Input Fields Fetching) ...
        $guest_name = mysqli_real_escape_string($conn, $_POST['guest_name']);
        $guest_title = isset($_POST['guest_title']) ? mysqli_real_escape_string($conn, $_POST['guest_title']) : '';
        $designation = mysqli_real_escape_string($conn, $_POST['designation']);
        $address = mysqli_real_escape_string($conn, $_POST['address']);
        $guest_email = mysqli_real_escape_string($conn, $_POST['guest_email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $id_proof = mysqli_real_escape_string($conn, $_POST['id_proof']);
        $emergency_contact = mysqli_real_escape_string($conn, $_POST['emergency_contact']);
        
        $dept_select = mysqli_real_escape_string($conn, $_POST['department']);
        $dept_other = mysqli_real_escape_string($conn, $_POST['dept_other']);
        $dept = ($dept_select === 'Other') ? $dept_other : $dept_select;

        $room_no = mysqli_real_escape_string($conn, $_POST['room_no']);
        $room_type = mysqli_real_escape_string($conn, $_POST['room_type']);
        $floor_level = mysqli_real_escape_string($conn, $_POST['floor_level']);
        $check_in = mysqli_real_escape_string($conn, $_POST['check_in']);
        $arrival_time = mysqli_real_escape_string($conn, $_POST['arrival_time']); 
        $check_out_date = mysqli_real_escape_string($conn, $_POST['check_out_date']);
        $departure_time = mysqli_real_escape_string($conn, $_POST['departure_time']);
        $purpose = mysqli_real_escape_string($conn, $_POST['purpose']);
        $notes = mysqli_real_escape_string($conn, $_POST['notes']);

        // Secondary Guest
        $sec_guest_name = isset($_POST['sec_guest_name']) ? mysqli_real_escape_string($conn, $_POST['sec_guest_name']) : NULL;
        $sec_guest_title = isset($_POST['sec_guest_title']) ? mysqli_real_escape_string($conn, $_POST['sec_guest_title']) : '';
        $sec_designation = isset($_POST['sec_designation']) ? mysqli_real_escape_string($conn, $_POST['sec_designation']) : NULL;
        $sec_address = isset($_POST['sec_address']) ? mysqli_real_escape_string($conn, $_POST['sec_address']) : NULL;
        $sec_guest_phone = isset($_POST['sec_guest_phone']) ? mysqli_real_escape_string($conn, $_POST['sec_guest_phone']) : NULL;
        $sec_guest_email = isset($_POST['sec_guest_email']) ? mysqli_real_escape_string($conn, $_POST['sec_guest_email']) : NULL;
        $sec_id_proof = isset($_POST['sec_id_proof']) ? mysqli_real_escape_string($conn, $_POST['sec_id_proof']) : NULL;

        $user_id_val = isset($_SESSION['UserID']) && !empty($_SESSION['UserID']) ? $_SESSION['UserID'] : 'NULL';

        if (empty($guest_email) || empty($phone) || empty($room_no) || empty($arrival_time)) {
            $message = "<div class='alert alert-danger'>❌ Error: Email, Phone, Room, and Arrival Time are required!</div>";
        } else {
            $check_sql = "SELECT status FROM rooms WHERE room_no='$room_no'";
            $check_result = mysqli_query($conn, $check_sql);
            $room_check = mysqli_fetch_assoc($check_result);

            if ($room_check && $room_check['status'] == 'Available') {
                $full_check_in_datetime = $check_in . ' ' . $arrival_time;
            
                // Ref ID value (0 হলে NULL সেভ করব)
                            $request_ref_val = ($tagged_request_id > 0) ? $tagged_request_id : 'NULL';
                            
                            $sql = "INSERT INTO bookings (
                                        guest_name, designation, address, guest_email, phone, id_proof, emergency_contact, 
                                        department, room_no, level, room_type, checked_in, check_out_date,
                                        arrival_time, departure_time, purpose, notes, status, user_id, request_ref_id,
                                        secondary_guest_name, secondary_guest_designation, secondary_guest_address, secondary_guest_phone, secondary_guest_email, secondary_guest_id_proof
                                    ) VALUES (
                                        '$guest_name', '$designation', '$address', '$guest_email', '$phone', '$id_proof', '$emergency_contact', 
                                        '$dept', '$room_no', '$floor_level', '$room_type', '$full_check_in_datetime', '$check_out_date',
                                        '$arrival_time', '$departure_time', '$purpose', '$notes', 'Booked', $user_id_val, $request_ref_val,
                                        '$sec_guest_name', '$sec_designation', '$sec_address', '$sec_guest_phone', '$sec_guest_email', '$sec_id_proof'
                                    )";

                
                if (mysqli_query($conn, $sql)) {
                    mysqli_query($conn, "UPDATE rooms SET status = 'Booked' WHERE room_no = '$room_no'");
                    
                    if ($tagged_request_id > 0) {
                            mysqli_query($conn, "
                                UPDATE visit_requests
                                SET status = 'Booked'
                                WHERE id = $tagged_request_id
                            ");
                        
                            // এই request-এর notification আর দেখানোর দরকার নাই
                            mysqli_query($conn, "
                                UPDATE notifications
                                SET is_read = 1,
                                    last_shown_at = NOW()
                                WHERE request_id = $tagged_request_id
                                  AND type = 'request_approved'
                            ");
                        }

                    // MAIL LOGIC
                      $headers  = "MIME-Version: 1.0\r\n";
                      $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                      $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";
                      
                       // ---- Guest display name with title ----
                      $guest_display_name = trim($guest_title) !== '' 
                          ? $guest_title . ' ' . $guest_name 
                          : $guest_name;
                          
                        // ---- Secondary guest display name with title ----
                      $sec_guest_display_name = (!empty($sec_guest_name) && trim($sec_guest_title) !== '') 
                          ? $sec_guest_title . ' ' . $sec_guest_name 
                          : $sec_guest_name;  
                      
                      if (!empty($guest_email)) {
                          $subject = "Your dormitory booking is confirmed - Room $room_no";
                      
                          // Check-in nicely formatted (optional improvement)
                          $checkin_disp = date('d M Y, h:i A', strtotime($full_check_in_datetime));
                      
                          // Planned check-out (date + time)
                          $planned_checkout = '';
                      
                          if (!empty($check_out_date)) {
                              $planned_checkout = date('d M Y', strtotime($check_out_date));
                          }
                      
                          if (!empty($departure_time) && $departure_time != '00:00:00') {
                              $planned_checkout .= ($planned_checkout ? ' ' : '') .
                                                   date('h:i A', strtotime($departure_time));
                          }
                      
                          $msg_body  = "Dear $guest_display_name,\r\n\r\n";
                          $msg_body .= "We are pleased to confirm your booking at the SCL Dormitory.\r\n\r\n";
                          $msg_body .= "Booking Details:\r\n";
                          $msg_body .= "- Room: $room_no ($room_type)\r\n";
                          $msg_body .= "- Check-in: $checkin_disp\r\n";
                      
                          // নতুন লাইন: Planned check-out
                          if ($planned_checkout !== '') {
                              $msg_body .= "- Planned check-out: $planned_checkout\r\n";
                          }
                      
                          $msg_body .= "- Department: $dept\r\n";
                          if (!empty($purpose)) {
                              $msg_body .= "- Purpose of visit: $purpose\r\n";
                          }
                          $msg_body .= "\r\n";
                          $msg_body .= "On arrival, please contact the dormitory reception for room key collection and any further assistance you may need.\r\n";
                          $msg_body .= "If your schedule changes or you are unable to come on time, kindly inform the dormitory team in advance.\r\n\r\n";
                          $msg_body .= "We look forward to welcoming you and hope you have a comfortable stay with us.\r\n\r\n";
                          $msg_body .= "Warm regards,\r\n";
                          $msg_body .= "SCL Dormitory Management\r\n";
                      
                          @mail($guest_email, $subject, $msg_body, $headers);
                      }
                      
                      if (!empty($sec_guest_email)) {
                          $subject = "Your dormitory booking is confirmed - Room $room_no";
                      
                          $msg_body  = "Dear $sec_guest_display_name,\r\n\r\n";
                          $msg_body .= "We are pleased to inform you that a dormitory booking has been confirmed for you at SCL Dormitory.\r\n\r\n";
                          $msg_body .= "Booking Details:\r\n";
                          $msg_body .= "- Room: $room_no ($room_type)\r\n";
                          $msg_body .= "- Check-in: $full_check_in_datetime\r\n";
                          if (!empty($departure_time)) {
                              $msg_body .= "- Planned departure time: $departure_time\r\n";
                          }
                          $msg_body .= "- Department: $dept\r\n";
                          if (!empty($purpose)) {
                              $msg_body .= "- Purpose of visit: $purpose\r\n";
                          }
                          $msg_body .= "\r\n";
                          $msg_body .= "On arrival, please contact the dormitory reception for room key collection and any further assistance you may need.\r\n";
                          $msg_body .= "If your schedule changes or you are unable to come on time, kindly inform the dormitory team in advance.\r\n\r\n";
                          $msg_body .= "We look forward to welcoming you and hope you have a comfortable stay with us.\r\n\r\n";
                          $msg_body .= "Warm regards,\r\n";
                          $msg_body .= "SCL Dormitory Management\r\n";
                      
                          @mail($sec_guest_email, $subject, $msg_body, $headers);
                      }

                    $message = "<div class='alert alert-success'>✅ Booking Successful & Mail Sent!</div>";
                } else {
                    $message = "<div class='alert alert-danger'>❌ Database Error: " . mysqli_error($conn) . "</div>";
                }
            } else {
                $message = "<div class='alert alert-warning'>⚠️ Room $room_no is already booked!</div>";
            }
        }
    }
}

// Stats for sidebar badges
$pending_count = 0;
if(in_array($role, ['admin', 'superadmin', 'approver'])){
    $pending_query = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM visit_requests WHERE status='Pending'");
    $pending_count = mysqli_fetch_assoc($pending_query)['cnt'];
}

// ========================================
// ������ NOTIFICATION COUNT & LIST (Staff/Admin)
// ========================================
$unread_notif_count = 0;
$notifications = [];
// ������ 1 ঘন্টা পর আবার reminder অন করা
// শর্ত: type = request_approved, is_read = 1, এখনও Booking হয়নি (status != 'Booked')
$reactivate_sql = "
    UPDATE notifications n
    JOIN visit_requests v ON v.id = n.request_id
    SET n.is_read = 0
    WHERE n.type = 'request_approved'
      AND n.is_read = 1
      AND v.status <> 'Booked'
      AND n.last_shown_at IS NOT NULL
      AND n.last_shown_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
";
mysqli_query($conn, $reactivate_sql);

if (in_array($role, ['staff', 'admin', 'superadmin'])) {
    // Unread count
    // Unread count (only Approved + not yet Booked)
$q1 = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM notifications n
    JOIN visit_requests v ON v.id = n.request_id
    WHERE n.type = 'request_approved'
      AND n.is_read = 0
      AND v.status <> 'Booked'
");
    if ($q1) {
        $unread_notif_count = (int)mysqli_fetch_assoc($q1)['cnt'];
    }

    // List (only those not booked yet)
$q2 = mysqli_query($conn, "
    SELECT n.id, n.request_id, n.created_at, n.is_read,
           v.guest_name, v.phone
    FROM notifications n
    LEFT JOIN visit_requests v ON v.id = n.request_id
    WHERE n.type = 'request_approved'
      AND n.is_read = 0
      AND v.status <> 'Booked'
    ORDER BY n.id DESC
    LIMIT 20
");
    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            $notifications[] = $row;
        }
    }
}

//Approved request load করার query:
$approved_reqs = mysqli_query($conn, "SELECT * FROM visit_requests WHERE status = 'Approved'");
$available_rooms = mysqli_query($conn, "SELECT * FROM rooms WHERE status = 'Available' AND is_fixed = 'No' ORDER BY floor, room_no");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SCL Dormitory Dashboard</title>
    f
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; z-index: 1000; }
        .content { margin-left: 250px; padding: 30px; }
        .room-card { 
            height: 220px; 
            border-radius: 12px; color: white !important; text-align: center; padding: 15px; margin-bottom: 20px; 
            transition: transform 0.3s ease, box-shadow 0.3s ease; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex; flex-direction: column; justify-content: center; align-items: center;
        }
        .room-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); z-index: 10; }
        .available-room { background: linear-gradient(135deg, #28a745, #20c997); }
        .booked-room { background: linear-gradient(135deg, #dc3545, #fd7e14); }
        .room-card h4 { font-weight: 800; font-size: 1.5rem; text-shadow: 1px 1px 2px rgba(0,0,0,0.3); margin-bottom: 2px; }
        .status-text { text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; margin-top: 5px; font-weight: bold; opacity: 0.9; }
        .guest-name-badge {
            background: rgba(0,0,0,0.25); padding: 5px 12px; border-radius: 20px; font-size: 0.9rem; font-weight: 700;
            margin-top: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 95%; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .hidden { display: none; }
        #notifDropdown { 
            font-size: 0.85rem; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        #notifDropdown ul li {
            padding: 8px 0;
        }
        
                /* VIP room card – golden, চকচকে look */
        .vip-room {
            background: linear-gradient(135deg, #b8860b, #ffd700, #fff9c4);
            /* dark goldenrod → pure gold → light gold */
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.7);
            color: #2c2100 !important; /* টেক্সট একটু গাঢ় */
        }

        
    </style>
</head>
<body>

<div class="sidebar">
    <h4 class="mb-4 text-center"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small>Welcome,</small><br><strong><?php echo htmlspecialchars($user); ?></strong><br>
        <span class="badge bg-warning text-dark mt-1"><?php echo strtoupper($role); ?></span>
    </div>
    
    <a href="index.php" class="btn btn-light w-100 mb-2 fw-bold"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="guest_request.php" class="btn btn-outline-info w-100 mb-2 text-white"><i class="fas fa-paper-plane me-2"></i>Submit Request</a>
    <a href="my_requests.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-list-alt me-2"></i>My Sent Requests</a>
    <a href="profile.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-user me-2"></i>My Profile</a>

    <!-- ✅ Manage Requests: Only Admin & Approver -->
    <?php if(in_array($role, ['admin', 'superadmin', 'approver'])): ?>
        <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 position-relative text-white">
            <i class="fas fa-tasks me-2"></i>Manage All 
            <?php if($pending_count > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    <?php echo $pending_count; ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endif; ?>

        <!-- ������ NOTIFICATION BUTTON (Staff/Admin Only) -->
    <?php if(in_array($role, ['staff','admin','superadmin'])): ?>
        <button type="button" class="btn btn-outline-light w-100 mb-2 position-relative"
                onclick="document.getElementById('notifDropdown').classList.toggle('hidden');">
            <i class="fas fa-bell me-2"></i>Notifications
            <?php if($unread_notif_count > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    <?php echo $unread_notif_count; ?>
                </span>
            <?php endif; ?>
        </button>

        <!-- ������ NOTIFICATION DROPDOWN -->
        <div id="notifDropdown" class="bg-white text-dark rounded p-2 mb-3 hidden" style="max-height:300px; overflow-y:auto;">
            <?php if(count($notifications) == 0): ?>
                <small class="text-muted">No notifications yet.</small>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach($notifications as $n): ?>
                        <li class="border-bottom py-2 <?php echo ($n['is_read'] == 1) ? 'opacity-50' : ''; ?>">
                            <small>
                                <?php if($n['is_read'] == 0): ?>
                                    <span class="badge bg-success" style="font-size:0.65rem;">NEW</span>
                                <?php endif; ?>
                                <strong>Request #<?php echo $n['request_id']; ?></strong><br>
                                <?php echo htmlspecialchars($n['guest_name'] ?? 'Guest'); ?>
                                (<?php echo htmlspecialchars($n['phone'] ?? ''); ?>) - <span class="text-success">Approved</span>
                            </small><br>
                            <small class="text-muted">
                                <i class="far fa-clock me-1"></i>
                                <?php echo date('d M Y, h:i A', strtotime($n['created_at'])); ?>
                            </small>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <?php if($unread_notif_count > 0): ?>
                    <form method="post" class="mt-2">
                        <button type="submit" name="mark_notif_read" class="btn btn-sm btn-outline-secondary w-100">
                            <i class="fas fa-check-double me-1"></i>Mark all as read
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-center mt-2">
                        <small class="text-muted">All caught up! ✓</small>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>


    <!-- ✅ Booking/Checkout: Only Admin & Staff -->
    <?php if(in_array($role, ['staff', 'admin'])): ?>
    <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2">
        <i class="fas fa-clipboard-check me-2"></i>Check-out
    </a>
    <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2">
        <i class="fas fa-history me-2"></i>History</a>
    </a>
    <a href="cancel_list.php" class="btn btn-outline-light w-100 mb-2">
        <i class="fas fa-ban me-2"></i>Cancelled List
    </a>
    <?php endif; ?>
    
    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
        <hr class="border-light">
        <a href="admin_dashboard.php" class="btn btn-warning w-100 mb-2"><i class="fas fa-crown me-2"></i>Admin Panel</a>
    <?php endif; ?>
    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
    <?php if(!empty($message)) echo $message; ?>

    <div class="row">
        <!-- ✅ BOOKING FORM: Hidden for Approver, Visible to Staff & Admin -->
        <?php if(in_array($role, ['staff', 'admin', 'superadmin'])): ?>
        <div class="col-lg-6">
            <div class="card shadow border-0 mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>New Booking</h5>
                    <?php if($require_ref_for_booking == 1): ?>
                        <small class="text-warning fw-bold"><i class="fas fa-exclamation-triangle me-1"></i>Ref Required</small>
                    <?php else: ?>
                        <small class="text-light"><i class="fas fa-check-circle me-1"></i>Manual booking allowed</small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    
                    <div class="mb-4 p-3 bg-info bg-opacity-10 border border-info rounded">
                        <label class="form-label fw-bold text-dark"><i class="fas fa-tag me-2"></i>Tag Approved Request (Auto-fill)</label>
                        <select id="requestTag" class="form-select" onchange="fillRequestData()">
                            <option value="">-- Select an Approved Request --</option>
                            <?php 
                            if(mysqli_num_rows($approved_reqs) > 0) {
                                while($req = mysqli_fetch_assoc($approved_reqs)) {
                                    $req_json = htmlspecialchars(json_encode($req), ENT_QUOTES, 'UTF-8');
                                    echo "<option value='$req_json'>Ref #{$req['id']} - {$req['guest_name']} ({$req['phone']})</option>";
                                }
                            } else {
                                echo "<option value='' disabled>No Approved Requests</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="tagged_request_id" id="taggedRequestId">

                        <div class="mb-3 p-3 bg-light rounded border">
                            <label class="form-label text-primary fw-bold">Select Room *</label>
                            <select name="room_no" id="roomSelect" class="form-select" required>
                            
                                <option value="">-- Choose Available Room --</option>
                                <?php 
                                if($available_rooms && mysqli_num_rows($available_rooms) > 0):
                                    mysqli_data_seek($available_rooms, 0);
                                    while($room = mysqli_fetch_assoc($available_rooms)):
                                ?>
                                    <option value="<?php echo $room['room_no']; ?>" 
                                            data-type="<?php echo $room['room_type']; ?>" 
                                            data-floor="<?php echo $room['floor']; ?>">
                                        <?php echo $room['room_no']; ?> (Level <?php echo $room['floor']; ?>) - <?php echo $room['room_type']; ?>
                                    </option>
                                <?php endwhile; else: ?>
                                    <option value="">No Rooms Available</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- ... (Guest Info Fields - Keeping original structure) ... -->
                        <h6 class="text-secondary border-bottom pb-2 mb-3">Primary Guest Info</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Guest Name *</label>
                                <div class="input-group">
                                    <select name="guest_title" class="form-select" style="max-width: 90px;">
                                        <option value="">Title</option>
                                        <option value="Mr">Mr</option>
                                        <option value="Mrs">Mrs</option>
                                        <option value="Ms">Ms</option>
                                    </select>
                                    <input type="text" name="guest_name" id="g_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number *</label>
                                <input type="text" name="phone" id="g_phone" class="form-control" placeholder="017..." required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Designation</label>
                                <input type="text" name="designation" id="g_desig" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Address</label>
                                <input type="text" name="address" id="g_addr" class="form-control">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" name="guest_email" id="g_email" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">ID Proof</label>
                                <input type="text" name="id_proof" id="g_id" class="form-control">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <select name="department" id="deptSelect" class="form-select" required>
                                    <option value="">Select Dept</option>
                                    <?php foreach($dept_list as $d) echo "<option value='$d'>$d</option>"; ?>
                                </select>
                                <input type="text" name="dept_other" id="deptOther" class="form-control mt-2 hidden" placeholder="Enter Department Name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Emergency Contact</label>
                                <input type="text" name="emergency_contact" id="g_emg" class="form-control">
                            </div>
                        </div>

                        <div id="secondaryGuestSection" class="hidden p-3 mb-3 bg-warning bg-opacity-10 border border-warning rounded">
                            <h6 class="text-dark border-bottom border-warning pb-2 mb-3"><i class="fas fa-user-friends me-2"></i>Secondary Guest (Double Room)</h6>
                            <div class="row">
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Secondary Guest Name</label>
                                <div class="input-group">
                                    <select name="sec_guest_title" class="form-select" style="max-width: 90px;">
                                        <option value="">Title</option>
                                        <option value="Mr">Mr</option>
                                        <option value="Mrs">Mrs</option>
                                        <option value="Ms">Ms</option>
                                    </select>
                                    <input type="text" name="sec_guest_name" class="form-control" placeholder="Secondary guest name">
                                </div>
                            </div>
                            
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="sec_guest_phone" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Designation</label>
                                    <input type="text" name="sec_designation" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="sec_address" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="sec_guest_email" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">ID Proof</label>
                                    <input type="text" name="sec_id_proof" class="form-control">
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Check-in Date *</label>
                                <input type="date" name="check_in" id="g_date" class="form-control"
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label text-primary fw-bold">Check-in Time *</label>
                                <input type="time" name="arrival_time" id="g_checkin_time" class="form-control" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Check-out Date *</label>
                                <input type="date" name="check_out_date" id="g_checkout_date" class="form-control" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Check-out Time *</label>
                                <input type="time" name="departure_time" id="g_checkout_time" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Purpose</label>
                            <input type="text" name="purpose" id="g_purp" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="1"></textarea>
                        </div>

                        <input type="hidden" name="room_type" id="roomTypeHidden">
                        <input type="hidden" name="floor_level" id="floorLevelHidden">

                        <button type="submit" name="confirm_booking" class="btn btn-success w-100 fw-bold py-2">
                            <i class="fas fa-check-circle me-2"></i>CONFIRM BOOKING
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Right Side: Live Room Status (Visible to Everyone) -->
        <div class="<?php echo in_array($role, ['staff','admin','superadmin']) ? 'col-lg-6' : 'col-12'; ?>">
            <div class="card shadow border-0">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-th me-2"></i>Live Room Status</h5>
                </div>
                <div class="card-body bg-light">
                    <div class="row g-3">
                        <?php
                        $sql_all = "SELECT r.*, b.guest_name 
                                    FROM rooms r 
                                    LEFT JOIN bookings b ON r.room_no = b.room_no AND b.status = 'Booked'
                                    ORDER BY r.floor, r.room_no";
                        
                        $all_rooms = mysqli_query($conn, $sql_all);
                        
                        while($room = mysqli_fetch_assoc($all_rooms)):
                            $is_booked = ($room['status'] == 'Booked' || $room['is_fixed'] == 'Yes');
                        
                            // VIP কিনা চেক
                            $is_vip = (stripos($room['room_type'], 'VIP') !== false);
                        
                            if ($is_vip) {
                                // VIP রুমের জন্য আলাদা golden card
                                $card_class  = 'vip-room';
                                
                                // Status text-ও আলাদা দেখাই
                                $status_text = ($room['is_fixed'] == 'Yes') ? 'VIP FIXED' : 'VIP';
                                
                                // ������ এই লাইনটি নতুন: VIP এর জন্য মুকুট আইকন
                                $center_icon ='<i class="fas fa-crown"></i>';
                            } else {
                                // Normal logic (available/booked)
                                $card_class  = $is_booked ? 'booked-room' : 'available-room';
                                $status_text = $is_booked
                                    ? ($room['is_fixed'] == 'Yes' ? 'FIXED' : 'BOOKED')
                                    : 'AVAILABLE';
                            }
                        
                            $is_double = (stripos($room['room_type'], 'Double') !== false);
                            $bed_icon = $is_double ? '<i class="fas fa-bed"></i><i class="fas fa-bed"></i>' : '<i class="fas fa-bed"></i>';
                            if(!$is_booked) $bed_icon = $is_double ? '<i class="fas fa-door-open"></i><small>(2)</small>' : '<i class="fas fa-door-open"></i>';
                            
                            $guest_display = "";
                            if (!empty($room['guest_name'])) {
                                $guest_display = $room['guest_name']; 
                            } elseif ($room['is_fixed'] == 'Yes') {
                                $guest_display = !empty($room['current_guest']) ? $room['current_guest'] : "Fixed Guest";
                            }
                        ?>

                        <div class="col-xl-4 col-md-6 col-6">
                            <div class="room-card <?php echo $card_class; ?>">
                                <h4><?php echo $room['room_no']; ?></h4>
                                <small><?php echo $room['room_type']; ?></small>
                                <div class="room-icons fa-2x my-2"><?php echo $is_vip ? $center_icon : $bed_icon; ?></div>
                                <div class="status-text"><?php echo $status_text; ?></div>
                                
                                <?php if($is_booked): ?>
                                    <div class="guest-name-badge">
                                        <i class="fas fa-user-circle me-1"></i> 
                                        <?php echo htmlspecialchars($guest_display); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto Fill Logic
function fillRequestData() {
    var select = document.getElementById('requestTag');
    var val = select.value;
    if (!val) return;

    var data = JSON.parse(val);

    console.log('REQ DATA =', data); // একবার দেখে নিও console এ কী আসছে

    // ----- Basic guest fields -----
    document.getElementById('g_name').value  = data.guest_name || '';

    var titleSelect = document.querySelector('select[name="guest_title"]');
    if (titleSelect && data.guest_title) {
        titleSelect.value = data.guest_title;
    }

    document.getElementById('g_phone').value = data.phone || '';
    document.getElementById('g_email').value = data.email || '';
    document.getElementById('g_desig').value = data.designation || '';
    document.getElementById('g_addr').value  = data.address || '';
    document.getElementById('g_id').value    = data.id_proof || '';
    document.getElementById('g_emg').value   = data.emergency_contact || '';
    document.getElementById('g_purp').value  = data.purpose || '';

    // ====== DATE গুলো ======
    // যদি date DATETIME হয় (2026-02-18 00:00:00), তাহলে শুধু প্রথম 10 অক্ষর নিবো
    function toDateValue(d) {
        if (!d) return '';
        d = String(d).trim();
        if (d.length >= 10) return d.substring(0, 10);
        return d;
    }

    document.getElementById('g_date').value = toDateValue(data.check_in_date);
    var outDateInput = document.getElementById('g_checkout_date');
    if (outDateInput) outDateInput.value = toDateValue(data.check_out_date);

    // ====== TIME গুলো ======
    // TIME যদি HH:MM:SS হয় বা DATETIME এর ভিতরে থাকে, দুই কেসই handle করি
    function toTimeValue(t) {
        if (!t) return '';
        t = String(t).trim();

        // যদি space থাকে (যেমন 2026-02-18 09:30:00), তাহলে শেষ অংশটা নেই
        if (t.indexOf(' ') !== -1) {
            var parts = t.split(' ');
            t = parts[parts.length - 1];
        }
        // এখন t হয়তো HH:MM:SS. input[type=time] এ HH:MM দিলে যথেষ্ট।[web:66][web:68]
        if (t.length >= 5) {
            return t.substring(0, 5);
        }
        return t;
    }

    var inTimeInput  = document.getElementById('g_checkin_time');
    var outTimeInput = document.getElementById('g_checkout_time');

    if (inTimeInput)  inTimeInput.value  = toTimeValue(data.check_in_time);
    if (outTimeInput) outTimeInput.value = toTimeValue(data.check_out_time);

    // ----- tagged request id -----
    document.getElementById('taggedRequestId').value = data.id || 0;

    // ----- Department -----
    var deptSelect = document.getElementById('deptSelect');
    if (deptSelect) {
        deptSelect.value = data.department || '';
        if (deptSelect.value === "") {
            deptSelect.value = "Other";
            var otherInput = document.getElementById('deptOther');
            if (otherInput) {
                otherInput.classList.remove('hidden');
                otherInput.value = data.department || '';
            }
        }
    }
}

// ������ Feature Flag: Ref Required Validation (Optional Client-side)
document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e){
    var requireRef = <?php echo (int)$require_ref_for_booking; ?>;
    if (requireRef === 1) {
        var tagged = document.getElementById('taggedRequestId').value;
        if (!tagged || parseInt(tagged) <= 0) {
            e.preventDefault();
            alert('⚠️ Approved Request Ref is required for booking. Please select a request from the dropdown.');
        }
    }
});

// Room & Dept Scripts (Same as before)
document.getElementById('roomSelect')?.addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var type = selected.getAttribute('data-type') || '';
    var floor = selected.getAttribute('data-floor') || '';
    document.getElementById('roomTypeHidden').value = type;
    document.getElementById('floorLevelHidden').value = floor;
    var secSection = document.getElementById('secondaryGuestSection');
    if (type.toLowerCase().includes('double')) {
        secSection.classList.remove('hidden');
    } else {
        secSection.classList.add('hidden');
        secSection.querySelectorAll('input').forEach(input => input.value = '');
    }
});
document.getElementById('deptSelect')?.addEventListener('change', function() {
    var otherInput = document.getElementById('deptOther');
    if (this.value === 'Other') {
        otherInput.classList.remove('hidden');
        otherInput.required = true;
    } else {
        otherInput.classList.add('hidden');
        otherInput.required = false;
        otherInput.value = '';
    }
});
</script>

</body>
</html>
