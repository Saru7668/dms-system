<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('db.php');
require_once('header.php');

// ✅ Allow staff, admin, and superadmin to view this page
if (!isset($_SESSION['UserName']) || !in_array($_SESSION['UserRole'], ['staff', 'admin', 'superadmin'])) {
    header("Location: index.php");
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole'];

// --- STATS QUERIES ---
$total_rooms      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms"))['count'];
$available_rooms  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE status='Available'"))['count'];
$booked_rooms     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE status='Booked'"))['count'];
$active_bookings  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM bookings WHERE status='Booked'"))['count'];
$checkout_history = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM checked_out_guests"))['count'];

// Fixed & VIP Room Stats
$vip_rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE room_type LIKE '%VIP%'"))['count'];

// Only NON-VIP Fixed Rooms (VIP বাদে অন্য ফিক্সড রুমগুলো)
$fixed_rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE is_fixed='Yes' AND room_type NOT LIKE '%VIP%'"))['count'];

// Partial Booking Stats (Double room with only 1 guest booked)
$partial_sql = "
    SELECT COUNT(*) as count FROM rooms r
    JOIN bookings b ON r.room_no = b.room_no AND b.status = 'Booked'
    WHERE r.room_type LIKE '%Double%' 
      AND (b.secondary_guest_name IS NULL OR b.secondary_guest_name = '')
";
$partial_rooms = mysqli_fetch_assoc(mysqli_query($conn, $partial_sql))['count'];

// Visit Request Stats
$pending_requests = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM visit_requests WHERE status='Pending'"))['count'];
$total_visit_reqs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM visit_requests"))['count'];

// Live Room Status Query
$rooms_sql = "SELECT r.*, b.guest_name, b.secondary_guest_name 
              FROM rooms r 
              LEFT JOIN bookings b 
                     ON r.room_no = b.room_no 
                    AND b.status = 'Booked'
              ORDER BY r.floor, r.room_no";
$rooms_result = mysqli_query($conn, $rooms_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Staff Dashboard - SCL DMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.admin-sidebar { 
    background: linear-gradient(180deg, #1a2a3a 0%, #2f6b96 100%);
    color: white; 
    min-height: 100vh; 
    padding: 20px; 
    position: fixed; 
    width: 250px;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    z-index: 100;
}
.content { margin-left: 260px; padding: 30px; }
.card-stat { 
    padding: 25px; 
    border-radius: 15px; 
    color: white; 
    text-align: center; 
    transition: transform 0.3s;
    position: relative;
    overflow: hidden;
    height: 100%;
}
.card-stat:hover { transform: translateY(-5px); }
.card-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; }
.bg-primary.card-stat::before { background: #0d6efd; }
.bg-success.card-stat::before { background: #198754; }
.bg-warning.card-stat::before { background: #ffc107; }
.bg-info.card-stat::before { background: #0dcaf0; }
.bg-danger.card-stat::before { background: #dc3545; }
.bg-purple.card-stat { background-color: #6f42c1; }
.bg-purple.card-stat::before { background: #4c2d87; }
.bg-teal.card-stat { background-color: #20c997; }
.bg-teal.card-stat::before { background: #198754; }
.bg-gold.card-stat { background: linear-gradient(135deg, #b8860b, #ffd700); color: #2c2100; }
.bg-gold.card-stat::before { background: #fff; }
.bg-indigo.card-stat { background-color: #6610f2; }
.bg-indigo.card-stat::before { background: #520dc2; }
.bg-orange.card-stat { background-color: #fd7e14; }
.bg-orange.card-stat::before { background: #d9660f; }

.badge-role { font-size: 0.75rem; padding: 4px 8px; border-radius: 10px; }
.stats-grid { gap: 20px; }
.quick-actions { background: white; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }

/* Live Room Status cards */
.room-card { 
    height: 220px; border-radius: 12px; color: white !important; text-align: center; padding: 15px; margin-bottom: 20px; 
    transition: transform 0.3s ease, box-shadow 0.3s ease; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    display: flex; flex-direction: column; justify-content: center; align-items: center;
}
.room-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); z-index: 10; }
.available-room { background: linear-gradient(135deg, #28a745, #20c997); }
.booked-room    { background: linear-gradient(135deg, #dc3545, #fd7e14); }
.room-card h4 { font-weight: 800; font-size: 1.4rem; text-shadow: 1px 1px 2px rgba(0,0,0,0.3); margin-bottom: 2px; }
.status-text { text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; margin-top: 5px; font-weight: bold; opacity: 0.9; }
.guest-name-badge {
    background: rgba(0,0,0,0.25); padding: 4px 10px; border-radius: 20px; font-size: 0.85rem; font-weight: 700;
    margin-top: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 95%; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.vip-room { background: linear-gradient(135deg, #b8860b, #ffd700, #fff9c4); box-shadow: 0 0 20px rgba(255, 215, 0, 0.8); border: 1px solid rgba(255, 255, 255, 0.7); color: #2c2100 !important; }
.bed-empty { opacity: 0.4; }

/* ✅ ফেড-ইন অ্যানিমেশন */
.fade-in {
    animation: fadeIn 0.6s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<!-- ✅ ফ্লোটিং টোস্ট অ্যালার্ট কন্টেইনার -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
    <div id="liveToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-bold" id="toastMessage"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" id="toastCloseBtn"></button>
        </div>
    </div>
</div>

<div class="admin-sidebar">
    <h4><i class="fas fa-hotel me-2"></i>SCL DMS Staff</h4>
    <hr>
    
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small class="text-light d-block">Dashboard Access</small>
        <strong><?php echo htmlspecialchars($userName); ?></strong><br>
        <span class="badge badge-role bg-info text-dark"><?php echo strtoupper($userRole); ?></span>
    </div>

    <a href="staff_dashboard.php" class="btn btn-primary w-100 mb-2 text-start active">
        <i class="fas fa-tachometer-alt me-2"></i> Staff Dashboard
    </a>
    
    <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 text-start text-white">
        <i class="fas fa-tasks me-2"></i> Manage Requests 
        <?php if($pending_requests > 0): ?>
            <span class="badge bg-danger ms-2"><?php echo $pending_requests; ?></span>
        <?php endif; ?>
    </a>

    <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-clipboard-list me-2"></i> Active Bookings
    </a>
    <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-history me-2"></i> Checkout History
    </a>
    
    <!-- If User is an Admin, they can easily switch back to Admin Panel -->
    <?php if(in_array($userRole, ['admin', 'superadmin'])): ?>
        <hr class="my-3 border-light">
        <a href="admin_dashboard.php" class="btn btn-outline-warning w-100 mb-2 text-start">
            <i class="fas fa-crown me-2"></i> Admin Panel
        </a>
    <?php endif; ?>

    <hr class="my-3">
    <a href="index.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-home me-2"></i> Main Booking Page
    </a>
    <a href="logout.php" class="btn btn-danger w-100 mt-3">
        <i class="fas fa-sign-out-alt me-2"></i> Logout
    </a>
</div>

<div class="content fade-in">
    
    <?php if(isset($_SESSION['msg'])): ?>
        <?php 
            $toast_msg = strip_tags($_SESSION['msg']);
            $toast_type = (strpos(strtolower($toast_msg), 'error') !== false || strpos(strtolower($toast_msg), 'fail') !== false || strpos(strtolower($toast_msg), 'required') !== false) ? 'danger' : 'success';
            unset($_SESSION['msg']);
        ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var toastEl = document.getElementById('liveToast');
                var toastBody = document.getElementById('toastMessage');
                var closeBtn = document.getElementById('toastCloseBtn');
                
                toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'text-white', 'text-dark');
                closeBtn.classList.remove('btn-close-white');
                
                if ('<?php echo $toast_type; ?>' === 'success') {
                    toastEl.classList.add('bg-success', 'text-white');
                    closeBtn.classList.add('btn-close-white');
                } else {
                    toastEl.classList.add('bg-danger', 'text-white');
                    closeBtn.classList.add('btn-close-white');
                }
                
                toastBody.innerHTML = "<?php echo addslashes($toast_msg); ?>";
                var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
                toast.show();
            });
        </script>
    <?php endif; ?>

    <!-- STATS ROW 1 -->
    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-lg-4 col-md-6">
            <div class="card-stat bg-purple">
                <i class="fas fa-envelope-open-text fa-2x mb-2"></i>
                <h5>Pending Requests</h5>
                <h2><?php echo $pending_requests; ?></h2>
                <small>Total: <?php echo $total_visit_reqs; ?></small>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6">
            <div class="card-stat bg-primary">
                <i class="fas fa-door-open fa-2x mb-2"></i>
                <h5>Total Rooms</h5>
                <h2><?php echo $total_rooms; ?></h2>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6">
            <div class="card-stat bg-success">
                <i class="fas fa-check-circle fa-2x mb-2"></i>
                <h5>Available</h5>
                <h2><?php echo $available_rooms; ?></h2>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6">
            <div class="card-stat bg-indigo">
                <i class="fas fa-user-check fa-2x mb-2"></i>
                <h5>Active Bookings</h5>
                <h2><?php echo $active_bookings; ?></h2>
            </div>
        </div>
    </div>

    <!-- STATS ROW 2 -->
    <div class="row g-3 mb-4">
        
        <!-- VIP Rooms Card (Golden with Crown) -->
        <div class="col-xl-4 col-lg-4 col-md-6">
            <div class="card-stat bg-gold">
                <i class="fas fa-crown fa-2x mb-2"></i>
                <h5>VIP Rooms</h5>
                <h2><?php echo $vip_rooms; ?></h2>
            </div>
        </div>

        <!-- Fixed Rooms Card (Only Normal Fixed Rooms, No Extra Text) -->
        <div class="col-xl-4 col-lg-4 col-md-6">
            <div class="card-stat bg-teal">
                <i class="fas fa-thumbtack fa-2x mb-2"></i>
                <h5>Fixed Rooms</h5>
                <h2><?php echo $fixed_rooms; ?></h2>
            </div>
        </div>

        <!-- Partial Booking Card -->
        <div class="col-xl-4 col-lg-4 col-md-6">
            <div class="card-stat bg-orange">
                <i class="fas fa-user-clock fa-2x mb-2"></i>
                <h5>Partial Booking</h5>
                <h2><?php echo $partial_rooms; ?></h2>
                <?php if($partial_rooms > 0): ?>
                    <small>1 Seat Available in Double Room</small>
                <?php else: ?>
                    <small>&nbsp;</small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Staff Actions -->
    <div class="row mt-4">
        <div class="col-lg-6">
            <div class="card quick-actions p-4">
                <h4 class="mb-3">Quick Staff Actions</h4>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <a href="checkout_list.php" class="btn btn-outline-warning w-100 h-100 p-3">
                            <i class="fas fa-clipboard-list fa-2x d-block mb-2"></i>Checkouts
                        </a>
                    </div>
                    <div class="col-md-6 mb-2">
                        <a href="checkout_history.php" class="btn btn-outline-info w-100 h-100 p-3">
                            <i class="fas fa-history fa-2x d-block mb-2"></i>History
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card quick-actions p-4">
                <h5><i class="fas fa-history text-info me-2"></i>Checkout History Summary</h5>
                <h3 class="text-info mt-2"><?php echo $checkout_history; ?> Total</h3>
                <a href="checkout_history.php" class="btn btn-outline-info mt-3 w-100">View History Details</a>
            </div>
        </div>
    </div>

    <!-- ✅ LIVE ROOM STATUS (Dynamic) -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card quick-actions p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0"><i class="fas fa-th text-info me-2"></i>Live Room Status</h4>
                </div>
                <div class="row g-3">
                    <?php if ($rooms_result && mysqli_num_rows($rooms_result) > 0): ?>
                        <?php while($room = mysqli_fetch_assoc($rooms_result)): ?>
                            <?php
                                $is_booked = ($room['status'] == 'Booked' || $room['is_fixed'] == 'Yes');
                                $is_vip = (stripos($room['room_type'], 'VIP') !== false);
                                $is_double = (stripos($room['room_type'], 'Double') !== false);
                                
                                $primary_guest = !empty($room['guest_name']) ? $room['guest_name'] : '';
                                $secondary_guest = !empty($room['secondary_guest_name']) ? $room['secondary_guest_name'] : '';
                                
                                if ($room['is_fixed'] == 'Yes') {
                                    $primary_guest = !empty($room['current_guest']) ? $room['current_guest'] : "Fixed Guest";
                                }

                                // Dynamic Icons & Status Logic
                                if ($is_vip) {
                                    $card_class  = 'vip-room';
                                    $status_text = ($room['is_fixed'] == 'Yes') ? 'VIP FIXED' : 'VIP';
                                    $center_icon ='<i class="fas fa-crown"></i>';
                                } else {
                                    if ($is_double) {
                                        if ($is_booked) {
                                            if (!empty($primary_guest) && !empty($secondary_guest)) {
                                                $card_class = 'booked-room';
                                                $status_text = 'FULL BOOKED';
                                                $center_icon = '<i class="fas fa-bed"></i><i class="fas fa-bed ms-1"></i>';
                                            } else {
                                                $card_class = 'booked-room';
                                                $status_text = '1/2 BOOKED';
                                                $center_icon = '<i class="fas fa-bed"></i><i class="fas fa-bed bed-empty ms-1"></i>';
                                            }
                                        } else {
                                            $card_class = 'available-room';
                                            $status_text = 'AVAILABLE';
                                            $center_icon = '<i class="fas fa-door-open"></i><small>(2)</small>';
                                        }
                                    } else {
                                        // Single room
                                        $card_class  = $is_booked ? 'booked-room' : 'available-room';
                                        $status_text = $is_booked ? ($room['is_fixed'] == 'Yes' ? 'FIXED' : 'BOOKED') : 'AVAILABLE';
                                        $center_icon = $is_booked ? '<i class="fas fa-bed"></i>' : '<i class="fas fa-door-open"></i>';
                                    }
                                }
                            ?>
                            <div class="col-xl-3 col-lg-4 col-md-4 col-sm-6 col-6">
                                <div class="room-card <?php echo $card_class; ?>">
                                    <h4><?php echo $room['room_no']; ?></h4>
                                    <small><?php echo htmlspecialchars($room['room_type']); ?></small>
                                    
                                    <div class="room-icons fa-2x my-2">
                                        <?php echo $center_icon; ?>
                                    </div>
                                    
                                    <div class="status-text"><?php echo $status_text; ?></div>

                                    <?php if(!empty($primary_guest)): ?>
                                        <div class="guest-name-badge">
                                            <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($primary_guest); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($secondary_guest)): ?>
                                        <div class="guest-name-badge">
                                            <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($secondary_guest); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12 text-center text-muted">
                            No room data found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
