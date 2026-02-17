<?php
session_start();
require_once('db.php');
require_once('header.php');

// Security Check (Admin & Staff Access)
if (!isset($_SESSION['UserName']) || !in_array($_SESSION['UserRole'], ['admin', 'staff', 'superadmin'])) {
    header("Location: index.php");
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole']; 

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- DELETE LOGIC (Only Admin) ---
if (isset($_GET['delete']) && $_GET['delete'] == '1' && isset($_GET['id']) && isset($_GET['csrf'])) {
    
    // CSRF Protection
    if ($_GET['csrf'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }
    
    if (!in_array($userRole, ['admin', 'superadmin'])) {
        header("Location: checkout_history.php?error=access_denied");
        exit;
    }

    $record_id = (int)$_GET['id'];
    
    // Prepared statement for security
    $stmt = mysqli_prepare($conn, "SELECT booking_id FROM checked_out_guests WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $record_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $booking_check = mysqli_fetch_assoc($result);
    
    // Delete from history
    $stmt2 = mysqli_prepare($conn, "DELETE FROM checked_out_guests WHERE id = ?");
    mysqli_stmt_bind_param($stmt2, "i", $record_id);
    
    if (mysqli_stmt_execute($stmt2)) {
        // Delete from bookings table too
        if ($booking_check && $booking_check['booking_id']) {
            $stmt3 = mysqli_prepare($conn, "DELETE FROM bookings WHERE id = ?");
            mysqli_stmt_bind_param($stmt3, "i", $booking_check['booking_id']);
            mysqli_stmt_execute($stmt3);
        }
        header("Location: checkout_history.php?deleted=1");
    } else {
        header("Location: checkout_history.php?error=delete_failed");
    }
    exit;
}

// ✅ FILTERS: DATE RANGE + ROOM NUMBER
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$room_filter = isset($_GET['room']) ? trim($_GET['room']) : '';

// Validate room format
if (!empty($room_filter) && !preg_match('/^[A-Z0-9-]+$/i', $room_filter)) {
    $room_filter = '';
}

// Build WHERE clause with prepared statement parameters
$where_conditions = [];
$params = [];
$types = "";

if (!empty($from_date) && !empty($to_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= "ss";
} elseif (!empty($from_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) >= ?";
    $params[] = $from_date;
    $types .= "s";
} elseif (!empty($to_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) <= ?";
    $params[] = $to_date;
    $types .= "s";
}

// Room filter
if (!empty($room_filter)) {
    $where_conditions[] = "cog.room_number = ?";
    $params[] = $room_filter;
    $types .= "s";
}

$where_clause = "";
if (count($where_conditions) > 0) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// ✅ QUERY with prepared statement
$sql = "SELECT cog.*, 
               b.arrival_time, 
               b.guest_email as primary_email, 
               b.secondary_guest_email as sec_email_booking,
               b.secondary_guest_phone as sec_phone_booking
        FROM checked_out_guests cog 
        LEFT JOIN bookings b ON cog.booking_id = b.id 
        $where_clause
        ORDER BY cog.check_out_date DESC";

$stmt = mysqli_prepare($conn, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout History - SCL DMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .header { text-align: center; background: linear-gradient(135deg, #224895, #2f6b96); color: white; padding: 30px; }
        .table-container { max-height: 70vh; overflow: auto; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .delete-btn { color: #dc3545; font-size: 1.2rem; transition: all 0.3s; cursor: pointer; }
        .delete-btn:hover { color: #b02a37; transform: scale(1.2); }
        .time-badge { font-weight: 500; font-size: 0.85em; }
        .phone-btn { font-size: 1rem; }
        .sec-tag { font-size: 0.72rem; }
        .email-text { font-size: 0.8rem; color: #555; display: block; margin-top: 2px;}
        .details-text { font-size: 0.8rem; color: #555; display: block; line-height: 1.3; }
        .details-icon { font-size: 0.75rem; width: 15px; text-align: center; color: #888; margin-right: 3px; }
        .call-link { text-decoration: none; color: inherit; transition: color 0.2s; }
        .call-link:hover { color: #198754; text-decoration: underline; }
    </style>
</head>
<body>

    <div class="header shadow">
        <div class="container">
            <h2><i class="fas fa-history me-3"></i>Checkout History</h2>
            <p class="lead mb-0">Complete guest checkout records</p>
            
            <!-- DATE RANGE + ROOM FILTER FORM -->
            <div class="card bg-white text-dark mt-4 shadow">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label fw-bold"><i class="fas fa-door-open me-2"></i>Room No</label>
                            <input type="text" name="room" class="form-control" placeholder="e.g. 2A" value="<?php echo htmlspecialchars($room_filter, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-2"></i>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-2"></i>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="checkout_history.php" class="btn btn-secondary w-100">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                    </form>

                    <!-- EXPORT BUTTONS ROW -->
                    <div class="row mt-3 g-2">
                        <?php 
                        $export_params = http_build_query([
                            'from_date' => $from_date, 
                            'to_date' => $to_date,
                            'room' => $room_filter
                        ]);
                        ?>
                        <div class="col-md-6">
                            <a href="export_history_pdf.php?<?php echo $export_params; ?>" class="btn btn-danger w-100 btn-lg shadow" target="_blank">
                                <i class="fas fa-file-pdf me-2"></i>Export to PDF
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="export_history_excel.php?<?php echo $export_params; ?>" class="btn btn-success w-100 btn-lg shadow">
                                <i class="fas fa-file-excel me-2"></i>Export to Excel
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if($result && mysqli_num_rows($result) > 0): ?>
                <div class="mt-3">
                    <span class="badge bg-light text-dark fs-6 px-3 py-2">
                        <i class="fas fa-list me-2"></i><?php echo mysqli_num_rows($result); ?> Records
                    </span>
                    <?php if(!empty($room_filter)): ?>
                        <span class="badge bg-info text-dark fs-6 px-3 py-2 ms-2">
                            <i class="fas fa-door-open me-2"></i>Room: <?php echo htmlspecialchars($room_filter, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                    <?php if(!empty($from_date) || !empty($to_date)): ?>
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2 ms-2">
                            <i class="fas fa-calendar-check me-2"></i>
                            <?php 
                            if(!empty($from_date) && !empty($to_date)) {
                                echo date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date));
                            } elseif(!empty($from_date)) {
                                echo 'From: ' . date('d M Y', strtotime($from_date));
                            } else {
                                echo 'Until: ' . date('d M Y', strtotime($to_date));
                            }
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="container mt-5">
        <?php if(isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-trash-alt me-2"></i>Record deleted successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['error']) && $_GET['error'] == 'access_denied'): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-ban me-2"></i>Access Denied! Only Admin can delete records.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['error']) && $_GET['error'] == 'delete_failed'): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>Failed to delete record.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if(!$result || mysqli_num_rows($result) == 0): ?>
            <div class="row justify-content-center">
                <div class="col-md-8 text-center py-5">
                    <i class="fas fa-inbox fa-5x text-muted mb-4"></i>
                    <h4 class="text-muted">No Checkout Records</h4>
                    <p class="text-muted mb-4">
                        <?php 
                        if (!empty($room_filter)) {
                            echo 'No records found for Room ' . htmlspecialchars($room_filter, ENT_QUOTES, 'UTF-8') . '.';
                        } elseif (!empty($from_date) || !empty($to_date)) {
                            echo 'No records found for selected date range.';
                        } else {
                            echo 'Complete checkouts from Active Bookings to see history.';
                        }
                        ?>
                    </p>
                    <a href="checkout_list.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-clipboard-list me-2"></i>Active Bookings
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow border-0">
                <div class="card-header bg-white py-3 border-0">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-0 text-primary fw-bold">
                                <i class="fas fa-table me-2"></i>Records List
                            </h5>
                        </div>
                        <div class="col-md-4 text-end">
                            <a href="checkout_list.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-plus me-1"></i>New Checkout
                            </a>
                        </div>
                    </div>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th width="40">#</th>
                                <th>Guest(s)</th>
                                <th>Contact Info</th>
                                <th>Room</th>
                                <th>Check-in</th>
                                <th>Check-out</th>
                                <th>Duration</th>
                                <th>Dept</th>
                                <?php if(in_array($userRole, ['admin', 'superadmin'])): ?>
                                    <th width="60" class="text-center">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = mysqli_fetch_assoc($result)): 
                                // Safe Email Retrieval
                                $pEmail = !empty($row['primary_email']) ? $row['primary_email'] : 'N/A';
                                $sEmail = !empty($row['secondary_guest_email']) ? $row['secondary_guest_email'] : (!empty($row['sec_email_booking']) ? $row['sec_email_booking'] : '');

                                // Safe Phone Retrieval
                                $secPhone = !empty($row['secondary_guest_phone']) ? $row['secondary_guest_phone'] : (!empty($row['sec_phone_booking']) ? $row['sec_phone_booking'] : '');
                                
                                // Safe Fields
                                $desig    = isset($row['designation']) ? $row['designation'] : '';
                                $addr     = isset($row['address']) ? $row['address'] : '';
                                $secDesig = isset($row['secondary_guest_designation']) ? $row['secondary_guest_designation'] : '';
                                $secAddr  = isset($row['secondary_guest_address']) ? $row['secondary_guest_address'] : '';
                            ?>
                            <tr>
                                <td class="fw-bold"><?php echo $sl++; ?></td>
                                
                                <td>
                                    <!-- Primary Guest Name -->
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($row['guest_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    
                                    <!-- Primary Designation & Address -->
                                    <?php if($desig): ?>
                                        <span class="details-text"><i class="fas fa-briefcase details-icon"></i> <?php echo htmlspecialchars($desig, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <?php if($addr): ?>
                                        <span class="details-text"><i class="fas fa-map-marker-alt details-icon"></i> <?php echo htmlspecialchars($addr, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($row['id_proof'])): ?>
                                        <small class="text-muted d-block mt-1">ID: <?php echo htmlspecialchars($row['id_proof'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>

                                    <!-- Secondary Guest -->
                                    <?php if(!empty($row['secondary_guest_name'])): ?>
                                        <div class="mt-2 pt-2 border-top">
                                            <span class="badge bg-info text-dark sec-tag">Secondary</span>
                                            <span class="ms-1 fw-semibold"><?php echo htmlspecialchars($row['secondary_guest_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            
                                            <?php if($secDesig): ?>
                                                <span class="details-text mt-1 ms-1"><i class="fas fa-briefcase details-icon"></i> <?php echo htmlspecialchars($secDesig, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                            <?php if($secAddr): ?>
                                                <span class="details-text ms-1"><i class="fas fa-map-marker-alt details-icon"></i> <?php echo htmlspecialchars($secAddr, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div>
                                        <!-- Clickable Phone Link (Primary) -->
                                        <a href="tel:<?php echo htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8'); ?>" class="call-link" title="Call">
                                            <i class="fas fa-phone text-success me-1"></i> <?php echo htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>

                                        <?php if($pEmail != 'N/A'): ?>
                                            <br><i class="fas fa-envelope text-primary me-1"></i> <span class="email-text d-inline"><?php echo htmlspecialchars($pEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if(!empty($row['secondary_guest_name'])): ?>
                                        <div class="mt-2 pt-2 border-top">
                                            <?php if(!empty($secPhone)): ?>
                                                <a href="tel:<?php echo htmlspecialchars($secPhone, ENT_QUOTES, 'UTF-8'); ?>" class="call-link" title="Call">
                                                    <i class="fas fa-phone text-success me-1"></i> <?php echo htmlspecialchars($secPhone, ENT_QUOTES, 'UTF-8'); ?> (Sec)
                                                </a>
                                            <?php else: ?>
                                                <small class="text-muted">No Phone</small>
                                            <?php endif; ?>

                                            <?php if(!empty($sEmail)): ?>
                                                <br><i class="fas fa-envelope text-primary me-1"></i> <span class="email-text d-inline"><?php echo htmlspecialchars($sEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <td><span class="badge bg-warning text-dark fs-6"><?php echo htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                
                                <td>
                                    <div class="fw-medium"><?php echo date('M d, Y', strtotime($row['check_in_date'])); ?></div>
                                    <span class="badge bg-info time-badge text-dark">
                                        <?php 
                                        $in_time = date('h:i A', strtotime($row['check_in_date']));
                                        if($in_time == '12:00 AM' && !empty($row['arrival_time'])) {
                                            echo date('h:i A', strtotime($row['arrival_time']));
                                        } else {
                                            echo $in_time;
                                        }
                                        ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <div class="fw-medium"><?php echo date('M d, Y', strtotime($row['check_out_date'])); ?></div>
                                    <span class="badge bg-success time-badge">
                                        <?php echo date('h:i A', strtotime($row['check_out_date'])); ?>
                                    </span>
                                </td>
                                
                                <td><span class="badge bg-primary fs-6"><?php echo (int)$row['total_days']; ?> day(s)</span></td>
                                
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                
                                <?php if(in_array($userRole, ['admin', 'superadmin'])): ?>
                                    <td class="text-center">
                                        <a href="?delete=1&id=<?php echo $row['id']; ?>&csrf=<?php echo urlencode($_SESSION['csrf_token']); ?>" 
                                           class="delete-btn btn btn-sm p-1 rounded-circle"
                                           onclick="return confirm('⚠️ Are you sure? This will delete the record permanently.')"
                                           title="Delete Record">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="text-center mt-5 mb-5">
            <div class="row justify-content-center g-3">
                <div class="col-md-3">
                    <a href="checkout_list.php" class="btn btn-primary btn-lg w-100 shadow-sm">
                        <i class="fas fa-clipboard-list me-2"></i>Active Bookings
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="index.php" class="btn btn-outline-secondary btn-lg w-100 shadow-sm">
                        <i class="fas fa-home me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
