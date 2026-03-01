<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('db.php');
require_once('header.php');

// Only admin
if (!isset($_SESSION['UserName']) || $_SESSION['UserRole'] !== 'admin') {
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
$total_users      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE user_role != 'pending'"))['count'];
$checkout_history = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM checked_out_guests"))['count'];

// Fixed & VIP Room Stats
$vip_rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE room_type LIKE '%VIP%'"))['count'];

// Only NON-VIP Fixed Rooms 
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

// Who made how many requests (Group by User)
$requester_sql = "SELECT requested_by, 
                  COUNT(*) as total_req, 
                  SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending_req 
                  FROM visit_requests 
                  GROUP BY requested_by 
                  ORDER BY total_req DESC";
$requester_result = mysqli_query($conn, $requester_sql);

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
<meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Mobile viewport meta -->
<title>Super Admin Panel - SCL DMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }

/* Sidebar Styling */
.admin-sidebar { 
    background: linear-gradient(180deg, #1a2a3a 0%, #2f6b96 100%);
    color: white; 
    min-height: 100vh; 
    padding: 20px; 
    position: fixed; 
    width: 250px;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    z-index: 1000;
    transition: transform 0.3s ease;
}
.content { margin-left: 250px; padding: 30px; transition: margin-left 0.3s ease; }

/* Mobile Top Navbar */
.mobile-navbar { display: none; background: #1a2a3a; color: white; padding: 15px 20px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 999; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
.menu-toggle-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; }

/* Stats Cards */
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
.card-stat::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}
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
.quick-actions { background: white; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }

/* Live Room Status cards */
.room-card { 
    height: 220px; 
    border-radius: 12px; 
    color: white !important; 
    text-align: center; 
    padding: 15px; 
    margin-bottom: 20px; 
    transition: transform 0.3s ease, box-shadow 0.3s ease; 
    position: relative; 
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    display: flex; 
    flex-direction: column; 
    justify-content: center; 
    align-items: center;
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

/* ?? RESPONSIVE STYLES */
@media (max-width: 768px) {
    .admin-sidebar { transform: translateX(-100%); }
    .admin-sidebar.active { transform: translateX(0); }
    .content { margin-left: 0; padding: 15px; padding-top: 20px; }
    .mobile-navbar { display: flex; }
    .card-stat { padding: 15px; } /* Smaller padding for mobile cards */
    .card-stat h5 { font-size: 1rem; }
    .card-stat h2 { font-size: 1.5rem; margin-bottom: 0; }
    .room-card { height: 180px; padding: 10px; } /* Smaller room cards */
    .room-card h4 { font-size: 1.25rem; }
    .guest-name-badge { font-size: 0.75rem; padding: 3px 8px; }
    .quick-actions.p-4 { padding: 15px !important; } /* Reduce padding on quick actions container */
}

/* Overlay for mobile sidebar */
.sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
.sidebar-overlay.active { display: block; }

/* ?? Fade-in Animation */
.fade-in { animation: fadeIn 0.6s ease-in-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>

<!-- ?? Floating Toast Notifications -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
    <div id="liveToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-bold" id="toastMessage"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" id="toastCloseBtn"></button>
        </div>
    </div>
</div>

<!-- Mobile Navbar & Overlay -->
<div class="mobile-navbar">
    <h5 class="mb-0"><i class="fas fa-hotel me-2"></i>SCL DMS Admin</h5>
    <button class="menu-toggle-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<div class="admin-sidebar" id="sidebar">
    <div class="d-flex justify-content-between align-items-center mb-4 d-md-none">
        <h4 class="mb-0"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
        <button class="btn btn-sm btn-outline-light border-0" onclick="toggleSidebar()"><i class="fas fa-times fa-lg"></i></button>
    </div>
    <h4 class="d-none d-md-block"><i class="fas fa-hotel me-2"></i>SCL DMS Admin</h4>
    <hr class="d-none d-md-block">
    
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small class="text-light d-block">Super Admin</small>
        <strong><?php echo htmlspecialchars($userName); ?></strong><br>
        <span class="badge badge-role bg-warning text-dark mt-1"><?php echo strtoupper($userRole); ?></span>
    </div>

    <a href="admin_dashboard.php" class="btn btn-primary w-100 mb-2 text-start active">
        <i class="fas fa-tachometer-alt me-2"></i> Dashboard
    </a>
    
    <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 text-start text-white position-relative">
        <i class="fas fa-tasks me-2"></i> Manage Requests 
        <?php if($pending_requests > 0): ?>
            <span class="position-absolute top-50 end-0 translate-middle-y badge bg-danger me-2"><?php echo $pending_requests; ?></span>
        <?php endif; ?>
    </a>

    <a href="admin_rooms.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-door-open me-2"></i> Manage Rooms
    </a>
    <a href="admin_users.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-users me-2"></i> Manage Users
    </a>
    <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-clipboard-list me-2"></i> Active Bookings
    </a>
    <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-history me-2"></i> Checkout History
    </a>
    <hr class="my-3 border-light opacity-25">
    <a href="index.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-user-circle me-2"></i> User Dashboard
    </a>
    <a href="logout.php" class="btn btn-danger w-100 mt-3 text-start">
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
                    toastBody.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + "<?php echo addslashes($toast_msg); ?>";
                } else {
                    toastEl.classList.add('bg-danger', 'text-white');
                    closeBtn.classList.add('btn-close-white');
                    toastBody.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + "<?php echo addslashes($toast_msg); ?>";
                }
                
                var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
                toast.show();
            });
        </script>
    <?php endif; ?>

    <!-- STATS ROW 1 -->
    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-lg-4 col-md-6 col-6">
            <div class="card-stat bg-purple">
                <i class="fas fa-envelope-open-text fa-2x mb-2 d-none d-md-block"></i>
                <i class="fas fa-envelope-open-text mb-1 d-md-none fs-4"></i>
                <h5>Pending Requests</h5>
                <h2><?php echo $pending_requests; ?></h2>
                <small class="d-none d-md-inline">Total: <?php echo $total_visit_reqs; ?></small>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 col-6">
            <div class="card-stat bg-primary">
                <i class="fas fa-door-open fa-2x mb-2 d-none d-md-block"></i>
                <i class="fas fa-door-open mb-1 d-md-none fs-4"></i>
                <h5>Total Rooms</h5>
                <h2><?php echo $total_rooms; ?></h2>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 col-6">
            <div class="card-stat bg-success">
                <i class="fas fa-check-circle fa-2x mb-2 d-none d-md-block"></i>
                <i class="fas fa-check-circle mb-1 d-md-none fs-4"></i>
                <h5>Available</h5>
                <h2><?php echo $available_rooms; ?></h2>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 col-6">
            <div class="card-stat bg-indigo">
                <i class="fas fa-user-check fa-2x mb-2 d-none d-md-block"></i>
                <i class="fas fa-user-check mb-1 d-md-none fs-4"></i>
                <h5>Active Bookings</h5>
                <h2><?php echo $active_bookings; ?></h2>
            </div>
        </div>
    </div>

    <!-- STATS ROW 2 -->
    <div class="row g-3 mb-4">
        
        <!-- VIP Rooms Card (Golden with Crown) -->
        <div class="col-xl-4 col-lg-4 col-md-6 col-4">
            <div class="card-stat bg-gold">
                <i class="fas fa-crown fa-2x mb-2 d-none d-md-block"></i>
                <i class="fas fa-crown mb-1 d-md-none fs-4"></i>
                <h5>VIP Rooms</h5>
                <h2><?php echo $vip_rooms; ?></h2>
            </div>
        </div>

        <!-- Fixed Rooms Card -->
        <div class="col-xl-4 col-lg-4 col-md-6 col-4">
            <div class="card-stat bg-teal">
                <i class="fas fa-thumbtack fa-2x mb-2 d-none d-md-block"></i>
                <i class="fas fa-thumbtack mb-1 d-md-none fs-4"></i>
                <h5>Fixed Rooms</h5>
                <h2><?php echo $fixed_rooms; ?></h2>
            </div>
        </div>

        <!-- Partial Booking Card -->
        <div class="col-xl-4 col-lg-4 col-md-6 col-4">
            <div class="card-stat bg-orange">
                <i class="fas fa-user-clock fa-2x mb-2 d-none d-md-block"></i>
                <i class="fas fa-user-clock mb-1 d-md-none fs-4"></i>
                <h5 class="d-none d-md-block">Partial Booking</h5>
                <h5 class="d-md-none" style="font-size: 0.85rem;">Partial</h5> <!-- Shorter text for mobile -->
                <h2><?php echo $partial_rooms; ?></h2>
                <?php if($partial_rooms > 0): ?>
                    <small class="d-none d-md-inline">1 Seat Available in Double Room</small>
                <?php else: ?>
                    <small class="d-none d-md-inline">&nbsp;</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    
    <!-- User Request Statistics + Users/History -->
    <div class="row mt-4">
        <div class="col-lg-7 mb-4 mb-lg-0">
            <div class="card quick-actions p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0 fs-6 fs-md-5"><i class="fas fa-chart-bar text-primary me-2"></i>Request Stats</h5>
                    <a href="manage_requests.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>User Name</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Pending</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if(mysqli_num_rows($requester_result) > 0) {
                                while($row = mysqli_fetch_assoc($requester_result)): 
                            ?>
                            <tr>
                                <td class="fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['requested_by']); ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?php echo $row['total_req']; ?></span></td>
                                <td class="text-center">
                                    <?php if($row['pending_req'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $row['pending_req']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="manage_requests.php" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            } else {
                                echo "<tr><td colspan='4' class='text-center text-muted'>No requests found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="row g-3">
                <div class="col-sm-6 col-lg-12">
                    <div class="card quick-actions p-4 text-center text-sm-start d-sm-flex flex-sm-row align-items-sm-center justify-content-sm-between">
                        <div>
                            <h6 class="text-muted mb-1"><i class="fas fa-users text-primary me-2"></i>Total Users</h6>
                            <h3 class="text-success mb-2 mb-sm-0"><?php echo $total_users; ?></h3>
                        </div>
                        <a href="admin_users.php" class="btn btn-outline-primary btn-sm">Manage</a>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-12">
                    <div class="card quick-actions p-4 text-center text-sm-start d-sm-flex flex-sm-row align-items-sm-center justify-content-sm-between">
                        <div>
                            <h6 class="text-muted mb-1"><i class="fas fa-history text-info me-2"></i>Checkouts</h6>
                            <h3 class="text-info mb-2 mb-sm-0"><?php echo $checkout_history; ?></h3>
                        </div>
                        <a href="checkout_history.php" class="btn btn-outline-info btn-sm">History</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Admin Actions -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card quick-actions p-4">
                <h5 class="mb-3">Quick Actions</h5>
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <a href="admin_rooms.php" class="btn btn-outline-primary w-100 h-100 p-2 p-md-3">
                            <i class="fas fa-door-open fs-3 fs-md-2 d-block mb-1 mb-md-2"></i>Rooms
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="admin_users.php" class="btn btn-outline-secondary w-100 h-100 p-2 p-md-3">
                            <i class="fas fa-users fs-3 fs-md-2 d-block mb-1 mb-md-2"></i>Users
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="checkout_list.php" class="btn btn-outline-warning w-100 h-100 p-2 p-md-3 text-dark">
                            <i class="fas fa-clipboard-list fs-3 fs-md-2 d-block mb-1 mb-md-2"></i>Active
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="checkout_history.php" class="btn btn-outline-info w-100 h-100 p-2 p-md-3">
                            <i class="fas fa-history fs-3 fs-md-2 d-block mb-1 mb-md-2"></i>History
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ?? LIVE ROOM STATUS -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card quick-actions p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="fas fa-th text-info me-2"></i>Live Room Status</h5>
                </div>
                <div class="row g-2 g-md-3">
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
                            <div class="col-6 col-sm-4 col-md-4 col-lg-3 col-xl-3">
                                <div class="room-card <?php echo $card_class; ?>">
                                    <h4><?php echo $room['room_no']; ?></h4>
                                    <small style="font-size: 0.75rem;"><?php echo htmlspecialchars($room['room_type']); ?></small>
                                    
                                    <div class="room-icons fa-2x my-1 my-md-2">
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
<script>
// Sidebar Toggle Logic for Mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
</script>
</body>
</html>
