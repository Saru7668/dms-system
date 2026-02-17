<?php
// ? ERROR REPORTING (Production ? ???? ??????)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Production ? 0 ?????
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

session_start();
require_once('db.php');
require_once('header.php');

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

// ? CSRF Token Generation (Session ? ?????)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Room filter from URL
$filter_room = isset($_GET['room']) ? trim($_GET['room']) : '';
if (!empty($filter_room) && !preg_match('/^[A-Z0-9-]+$/i', $filter_room)) {
    $filter_room = ''; // Invalid room format
}

// === Helper Function: Safe Email Sending ===
function sendSafeEmail($to, $subject, $body) {
    // Validate email
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    // Strip newlines from subject and email to prevent header injection
    $to = str_replace(["\r", "\n", "%0a", "%0d"], '', $to);
    $subject = str_replace(["\r", "\n", "%0a", "%0d"], '', $subject);
    
    // Safe headers
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    // Body ???? ???? dangerous characters ????
    $body = str_replace(["\r\n.\r\n"], ["\r\n. \r\n"], $body);
    
    return @mail($to, $subject, $body, $headers);
}

// --- CANCEL BOOKING LOGIC ---
if (isset($_POST['cancel_booking_submit'])) {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token. Please refresh and try again.");
    }
    
    $booking_id = (int)$_POST['booking_id'];
    $today = date('Y-m-d H:i:s');

    // Reason validation
    $cancel_reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : '';
    if (empty($cancel_reason) || strlen($cancel_reason) < 10) {
        header("Location: checkout_list.php?error=reason_required");
        exit;
    }
    if (strlen($cancel_reason) > 500) {
        $cancel_reason = substr($cancel_reason, 0, 500);
    }
    
    // Email injection ????????
    $cancel_reason = str_replace(["\r", "\n", "%0a", "%0d"], ' ', $cancel_reason);

    // === Start Transaction ===
    mysqli_begin_transaction($conn);
    
    try {
        // Prepared statement ????? booking fetch ????
        $stmt = mysqli_prepare($conn, "SELECT * FROM bookings WHERE id = ? AND status = 'Booked' LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $booking_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($res && mysqli_num_rows($res) == 1) {
            $bk = mysqli_fetch_assoc($res);

            // Prepare data for history insert (Prepared Statement)
            $stmt2 = mysqli_prepare($conn, "INSERT INTO cancelled_bookings (
                booking_id, guest_name, designation, address, room_number, check_in_date, cancel_date,
                department, phone, id_proof, cancel_reason, cancelled_by,
                secondary_guest_name, secondary_guest_designation, secondary_guest_address,
                secondary_guest_phone, secondary_guest_email, secondary_guest_id_proof
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            mysqli_stmt_bind_param($stmt2, "isssssssssssssssss",
                $booking_id,
                $bk['guest_name'],
                $bk['designation'],
                $bk['address'],
                $bk['room_no'],
                $bk['checked_in'],
                $today,
                $bk['department'],
                $bk['phone'],
                $bk['id_proof'],
                $cancel_reason,
                $userName,
                $bk['secondary_guest_name'],
                $bk['secondary_guest_designation'],
                $bk['secondary_guest_address'],
                $bk['secondary_guest_phone'],
                $bk['secondary_guest_email'],
                $bk['secondary_guest_id_proof']
            );
            
            if (!mysqli_stmt_execute($stmt2)) {
                throw new Exception("Failed to insert cancel history");
            }

            // Update room status
            $stmt3 = mysqli_prepare($conn, "UPDATE rooms SET status = 'Available' WHERE room_no = ?");
            mysqli_stmt_bind_param($stmt3, "s", $bk['room_no']);
            mysqli_stmt_execute($stmt3);

            // Update booking status
            $stmt4 = mysqli_prepare($conn, "UPDATE bookings SET status = 'Cancelled', cancel_reason = ?, cancel_date = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt4, "ssi", $cancel_reason, $today, $booking_id);
            mysqli_stmt_execute($stmt4);

            // Commit transaction
            mysqli_commit($conn);

            // === Send Cancellation Email ===
            $check_in_disp = date('d M Y, h:i A', strtotime($bk['checked_in']));
            $subject = "Dormitory booking cancelled - Room " . $bk['room_no'];
            
            $body  = "Dear " . $bk['guest_name'] . ",\r\n\r\n";
            $body .= "We regret to inform you that your dormitory booking has been cancelled.\r\n\r\n";
            $body .= "Booking Details:\r\n";
            $body .= "- Room: " . $bk['room_no'] . " (" . $bk['room_type'] . ")\r\n";
            $body .= "- Original check-in: " . $check_in_disp . "\r\n";
            $body .= "- Department: " . $bk['department'] . "\r\n\r\n";
            $body .= "Reason for cancellation:\r\n";
            $body .= $cancel_reason . "\r\n\r\n";
            $body .= "If you believe this was done in error, please contact the dormitory team.\r\n\r\n";
            $body .= "Warm regards,\r\n";
            $body .= "SCL Dormitory Management\r\n";

            if (!empty($bk['guest_email'])) {
                sendSafeEmail($bk['guest_email'], $subject, $body);
            }

            // Secondary guest email
            if (!empty($bk['secondary_guest_email'])) {
                $sec_body = "Dear " . $bk['secondary_guest_name'] . ",\r\n\r\n"
                          . "The dormitory booking for Room " . $bk['room_no'] . " has been cancelled.\r\n\r\n"
                          . "Reason: " . $cancel_reason . "\r\n\r\n"
                          . "SCL Dormitory Management\r\n";
                sendSafeEmail($bk['secondary_guest_email'], $subject, $sec_body);
            }

            header("Location: cancel_list.php?cancel_success=1");
            exit;
        } else {
            mysqli_rollback($conn);
            header("Location: checkout_list.php?error=not_found");
            exit;
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Cancel Booking Error: " . $e->getMessage());
        die("Database error. Please contact administrator.");
    }
}

// --- CHECKOUT LOGIC ---
if (isset($_GET['checkout_room'])) {
    // CSRF Protection (GET ? token ?????)
    if (!isset($_GET['csrf']) || $_GET['csrf'] !== $_SESSION['csrf_token']) {
        die("Invalid request. Please use the checkout button.");
    }
    
    $room_no = trim($_GET['checkout_room']);
    
    // Validate room number format
    if (!preg_match('/^[A-Z0-9-]+$/i', $room_no)) {
        header("Location: checkout_list.php?error=invalid_room");
        exit;
    }
    
    $today = date('Y-m-d H:i:s');
    $today_display = date('d M Y, h:i A');

    // === Start Transaction ===
    mysqli_begin_transaction($conn);
    
    try {
        // Prepared Statement
        $stmt = mysqli_prepare($conn, "SELECT * FROM bookings WHERE room_no = ? AND status = 'Booked' ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $room_no);
        mysqli_stmt_execute($stmt);
        $result_q = mysqli_stmt_get_result($stmt);
        
        if ($result_q && mysqli_num_rows($result_q) > 0) {
            $guest_data = mysqli_fetch_assoc($result_q);
            $booking_id = (int)$guest_data['id'];

            // Calculate Days
            $check_in_ts = strtotime($guest_data['checked_in']);
            $check_out_ts = strtotime($today);
            $total_days = (int)ceil(($check_out_ts - $check_in_ts) / 86400);
            if ($total_days < 1) $total_days = 1;

            // Insert into history
            $stmt2 = mysqli_prepare($conn, "INSERT INTO checked_out_guests 
                (booking_id, guest_name, designation, address, room_number, check_in_date, check_out_date, total_days, department, phone, id_proof,
                 secondary_guest_name, secondary_guest_designation, secondary_guest_address, secondary_guest_phone, secondary_guest_email, secondary_guest_id_proof)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            mysqli_stmt_bind_param($stmt2, "issssssisssssssss",
                $booking_id,
                $guest_data['guest_name'],
                $guest_data['designation'],
                $guest_data['address'],
                $room_no,
                $guest_data['checked_in'],
                $today,
                $total_days,
                $guest_data['department'],
                $guest_data['phone'],
                $guest_data['id_proof'],
                $guest_data['secondary_guest_name'],
                $guest_data['secondary_guest_designation'],
                $guest_data['secondary_guest_address'],
                $guest_data['secondary_guest_phone'],
                $guest_data['secondary_guest_email'],
                $guest_data['secondary_guest_id_proof']
            );
            
            if (!mysqli_stmt_execute($stmt2)) {
                throw new Exception("Failed to insert checkout history");
            }

            // Update room and booking
            $stmt3 = mysqli_prepare($conn, "UPDATE rooms SET status = 'Available' WHERE room_no = ?");
            mysqli_stmt_bind_param($stmt3, "s", $room_no);
            mysqli_stmt_execute($stmt3);

            $stmt4 = mysqli_prepare($conn, "UPDATE bookings SET status = 'Checked-Out', check_out_date = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt4, "si", $today, $booking_id);
            mysqli_stmt_execute($stmt4);

            mysqli_commit($conn);

            // === Send Checkout Email ===
            $check_in_display = date('d M Y, h:i A', strtotime($guest_data['checked_in']));
            $subject = "Checkout Confirmation - Room " . $room_no;
            
            $body  = "Dear " . $guest_data['guest_name'] . ",\r\n\r\n";
            $body .= "We hope you had a comfortable stay at SCL Dormitory.\r\n";
            $body .= "This email confirms that you have successfully checked out.\r\n\r\n";
            $body .= "STAY SUMMARY\r\n";
            $body .= "================================\r\n";
            $body .= "Room Number      : " . $room_no . "\r\n";
            $body .= "Check-in Date    : " . $check_in_display . "\r\n";
            $body .= "Check-out Date   : " . $today_display . "\r\n";
            $body .= "Total Duration   : " . $total_days . " Day(s)\r\n";
            $body .= "================================\r\n\r\n";
            $body .= "If you have left any personal belongings behind, please contact us immediately.\r\n\r\n";
            $body .= "We look forward to welcoming you again!\r\n\r\n";
            $body .= "Best regards,\r\n";
            $body .= "SCL Dormitory Management Team";

            if (!empty($guest_data['guest_email'])) {
                sendSafeEmail($guest_data['guest_email'], $subject, $body);
            }

            // Secondary guest email
            if (!empty($guest_data['secondary_guest_email'])) {
                $sec_name = !empty($guest_data['secondary_guest_name']) ? $guest_data['secondary_guest_name'] : 'Guest';
                $sec_body = str_replace($guest_data['guest_name'], $sec_name, $body);
                sendSafeEmail($guest_data['secondary_guest_email'], $subject, $sec_body);
            }

            header("Location: checkout_history.php?success=1");
            exit;
        } else {
            mysqli_rollback($conn);
            header("Location: checkout_list.php?error=not_found");
            exit;
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Checkout Error: " . $e->getMessage());
        die("Database error. Please contact administrator.");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Bookings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #1a2a3a; color: white; min-height: 100vh; padding: 20px; position: fixed; width: 250px; }
        .content { margin-left: 250px; padding: 30px; }
        .sec-badge { font-size: 0.75rem; }
        .email-text { font-size: 0.85rem; color: #666; word-break: break-all; }
        .details-text { font-size: 0.8rem; color: #555; display: block; line-height: 1.3; }
        .details-icon { font-size: 0.75rem; width: 15px; text-align: center; color: #888; margin-right: 3px; }
    </style>
</head>
<body>

<div class="sidebar">
    <h4 class="text-center mb-4">SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small class="text-light">Logged in as:</small><br>
        <strong class="text-white"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <span class="badge bg-warning text-dark mt-1 fs-6"><?php echo strtoupper(htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8')); ?></span>
    </div>
    <a href="index.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="checkout_list.php" class="btn btn-primary w-100 mb-2"><i class="fas fa-clipboard-check me-2"></i>Active Bookings</a>
    <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-history me-2"></i>History</a>
    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
<?php if(!empty($filter_room)): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span><i class="fas fa-filter me-2"></i>Showing only Room <strong><?php echo htmlspecialchars($filter_room, ENT_QUOTES, 'UTF-8'); ?></strong></span>
    <a href="checkout_list.php" class="btn btn-sm btn-outline-dark">Show All Rooms</a>
</div>
<?php endif; ?>

    <div class="card shadow border-0">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="fas fa-list me-2"></i>Active Guests List</h4>
            <span class="badge bg-light text-dark">Booked</span>
        </div>
        <div class="card-body">
            <?php if(isset($_GET['error'])): ?>
                <?php if($_GET['error'] === 'not_found'): ?>
                    <div class="alert alert-danger">Booking not found or already checked out.</div>
                <?php elseif($_GET['error'] === 'reason_required'): ?>
                    <div class="alert alert-danger">Cancel reason must be at least 10 characters.</div>
                <?php elseif($_GET['error'] === 'invalid_room'): ?>
                    <div class="alert alert-danger">Invalid room number format.</div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if(isset($_GET['cancel_success']) && $_GET['cancel_success'] == 1): ?>
                <div class="alert alert-warning">Booking cancelled and moved to Cancel List.</div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="25%">Guest(s) Details</th>
                            <th>Contact & Email</th>
                            <th>Room Info</th>
                            <th>Check-in / Check-out</th>
                            <th>Dept</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Prepared statement for listing
                        if (!empty($filter_room)) {
                            $stmt = mysqli_prepare($conn, "SELECT * FROM bookings WHERE status = 'Booked' AND room_no = ? ORDER BY id DESC");
                            mysqli_stmt_bind_param($stmt, "s", $filter_room);
                        } else {
                            $stmt = mysqli_prepare($conn, "SELECT * FROM bookings WHERE status = 'Booked' ORDER BY id DESC");
                        }
                        
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);

                        if ($result && mysqli_num_rows($result) > 0) {
                                while($row = mysqli_fetch_assoc($result)) {
                            
                                    // --------- Data prepare ----------
                                    $guestTitle = htmlspecialchars($row['guest_title'] ?? '', ENT_QUOTES, 'UTF-8');   // ????
                                    $guestName  = htmlspecialchars($row['guest_name'], ENT_QUOTES, 'UTF-8');
                                    $displayName = trim($guestTitle . ' ' . $guestName);
                                    $desig     = htmlspecialchars($row['designation'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $addr      = htmlspecialchars($row['address'] ?? '', ENT_QUOTES, 'UTF-8');
                            
                                    $secName  = htmlspecialchars($row['secondary_guest_name'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secPhone = htmlspecialchars($row['secondary_guest_phone'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secEmail = htmlspecialchars($row['secondary_guest_email'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secDesig = htmlspecialchars($row['secondary_guest_designation'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secAddr  = htmlspecialchars($row['secondary_guest_address'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $isDouble = (stripos($row['room_type'], 'double') !== false);
                            
                                    $room_no_safe   = htmlspecialchars($row['room_no'], ENT_QUOTES, 'UTF-8');
                                    $phone_safe     = htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8');
                                    $email_safe     = htmlspecialchars($row['guest_email'], ENT_QUOTES, 'UTF-8');
                                    $room_type_safe = htmlspecialchars($row['room_type'], ENT_QUOTES, 'UTF-8');
                                    $dept_safe      = htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8');
                                    $request_ref = !empty($row['request_ref_id']) ? (int)$row['request_ref_id'] : 0;
                            
                                    $dateDisplay = date('d M Y', strtotime($row['checked_in']));
                                    $timeDisplay = (!empty($row['arrival_time']) && $row['arrival_time'] != '00:00:00')
                                        ? date('h:i A', strtotime($row['arrival_time']))
                                        : date('h:i A', strtotime($row['checked_in']));
                            
                                    // Planned check-out (booking ?????)
                                    $plannedOutDate = !empty($row['check_out_date'])
                                        ? date('d M Y', strtotime($row['check_out_date']))
                                        : '';
                            
                                    $plannedOutTime = (!empty($row['departure_time']) && $row['departure_time'] != '00:00:00')
                                        ? date('h:i A', strtotime($row['departure_time']))
                                        : '';
                            
                                    // URLs & JS safe values
                                    $checkout_url = 'checkout_list.php?checkout_room=' . urlencode($row['room_no']) .
                                                    '&csrf=' . urlencode($_SESSION['csrf_token']);
                            
                                    $js_room  = $room_no_safe;
                                    $js_guest = $displayName;
                            
                                    // --------- HTML output ----------
                                    echo "<tr>
                                            <td>
                                                <div class='mb-1'>
                                                    <div class='fw-bold text-primary'>$displayName</div>";
                            
                                    if ($desig) echo "<span class='details-text'><i class='fas fa-briefcase details-icon'></i> $desig</span>";
                                    if ($addr)  echo "<span class='details-text'><i class='fas fa-map-marker-alt details-icon'></i> $addr</span>";
                                    if ($request_ref > 0) { echo "<span class='details-text'><i class='fas fa-tag details-icon'></i> Ref #$request_ref</span>";}
                                    echo    "</div>";
                            
                                    if ($isDouble && !empty($secName)) {
                                        echo "<div class='mt-3 pt-2 border-top'>
                                                <div class='d-flex align-items-center mb-1'>
                                                    <span class='badge bg-info text-dark sec-badge me-2'>2nd</span>
                                                    <span class='fw-semibold'>$secName</span>
                                                </div>";
                                        if ($secDesig) echo "<span class='details-text ms-1'><i class='fas fa-briefcase details-icon'></i> $secDesig</span>";
                                        if ($secAddr)  echo "<span class='details-text ms-1'><i class='fas fa-map-marker-alt details-icon'></i> $secAddr</span>";
                                        echo "</div>";
                                    }
                            
                                    echo    "</td>
                                             <td>
                                                <div>
                                                    <i class='fas fa-phone-alt text-success me-1'></i> $phone_safe<br>
                                                    <i class='fas fa-envelope text-primary me-1'></i> <span class='email-text'>$email_safe</span>
                                                </div>";
                            
                                    if ($isDouble && !empty($secName)) {
                                        echo "<div class='mt-3 pt-2 border-top'>";
                                        if (!empty($secPhone)) echo "<i class='fas fa-phone-alt text-success me-1'></i> $secPhone<br>";
                                        if (!empty($secEmail)) echo "<i class='fas fa-envelope text-primary me-1'></i> <span class='email-text'>$secEmail</span>";
                                        echo "</div>";
                                    }
                            
                                    echo   "</td>
                                            <td>
                                                <span class='badge bg-primary fs-6'>$room_no_safe</span><br>
                                                <small>$room_type_safe</small>
                                            </td>
                                            <td>
                                                <div class='fw-bold'>Check-In: $dateDisplay</div>
                                                <span class='badge bg-info text-dark'>$timeDisplay</span>";
                            
                                    if ($plannedOutDate !== '') {
                                        echo "<div class='fw-bold'>
                                                Checkout (planned):<br>
                                                $plannedOutDate";
                                        if ($plannedOutTime !== '') {
                                            echo " <span class='badge bg-secondary ms-1'>$plannedOutTime</span>";
                                        }
                                        echo "</div>";
                                    }
                            
                                    echo   "</td>
                                            <td><span class='badge bg-secondary'>$dept_safe</span></td>
                                            <td>
                                                <div class='btn-group' role='group'>
                                                    <a href='$checkout_url'
                                                       class='btn btn-warning btn-sm fw-bold'
                                                       onclick='return confirm(\"Check-out room $js_room? This will free up the room and send a confirmation email.\")'>
                                                        <i class='fas fa-sign-out-alt'></i>
                                                    </a>
                            
                                                    <button type='button'
                                                            class='btn btn-danger btn-sm fw-bold'
                                                            data-bs-toggle='modal'
                                                            data-bs-target='#cancelModal'
                                                            onclick='openCancelModal(".$row['id'].", \"$js_room\", \"$js_guest\")'>
                                                        <i class='fas fa-times-circle'></i>
                                                    </button>
                                                </div>
                                            </td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='text-center py-4 text-muted'><h5>No Active Bookings</h5></td></tr>";
                            }

                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Booking Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="cancelModalLabel">
          <i class="fas fa-ban me-2"></i>Cancel Booking
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="booking_id" id="cancelBookingId">
        <div class="modal-body">
          <p class="mb-2">You are about to cancel the booking for:</p>
          <h6 class="fw-bold" id="cancelGuestName"></h6>
          <p class="mb-3">
            <span class="badge bg-primary" id="cancelRoomBadge"></span>
          </p>

          <div class="mb-3">
            <label class="form-label fw-semibold">Please write the reason for cancellation (minimum 10 characters)</label>
            <textarea name="cancel_reason" id="cancelReason" class="form-control" rows="3" maxlength="500"
                      placeholder="Example: Guest schedule changed, room required for urgent VIP, etc."
                      required minlength="10"></textarea>
            <small class="text-muted">This reason will be shared with the guest in the cancellation email.</small>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="cancel_booking_submit" class="btn btn-danger">
            <i class="fas fa-paper-plane me-1"></i>Confirm Cancel
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openCancelModal(bookingId, roomNo, guestName) {
    document.getElementById('cancelBookingId').value = bookingId;
    document.getElementById('cancelRoomBadge').textContent = 'Room ' + roomNo;
    document.getElementById('cancelGuestName').textContent = guestName;
    document.getElementById('cancelReason').value = '';
}
</script>

</body>
</html>
