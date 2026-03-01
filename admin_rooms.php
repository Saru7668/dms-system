<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName']) || $_SESSION['UserRole'] != 'admin') {
    header("Location: index.php");
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole'];

// URL diye asha success message
if (isset($_GET['msg']) && $_GET['msg'] === 'added') {
    $_SESSION['msg'] = "Room Added Successfully!";
    $_SESSION['msg_type'] = "success";
    header("Location: admin_rooms.php");
    exit;
}

// Add room
if (isset($_POST['add_room'])) {
    $room_no = mysqli_real_escape_string($conn, $_POST['room_no']);
    $floor   = mysqli_real_escape_string($conn, $_POST['floor']);
    $type    = mysqli_real_escape_string($conn, $_POST['type']);
    $is_fixed = mysqli_real_escape_string($conn, $_POST['is_fixed']);

    // VIP hole force fixed
    if ($type === 'VIP') {
        $is_fixed = 'Yes';
    }

    $status  = ($is_fixed == 'Yes') ? 'Booked' : 'Available';

    $check = mysqli_query($conn, "SELECT id FROM rooms WHERE room_no = '$room_no'");
    if (mysqli_num_rows($check) > 0) {
        $_SESSION['msg'] = "Room $room_no already exists!";
        $_SESSION['msg_type'] = "danger";
    } else {
        $sql = "INSERT INTO rooms (room_no, floor, room_type, status, is_fixed, current_guest)
                VALUES ('$room_no', '$floor', '$type', '$status', '$is_fixed', NULL)";

        if (mysqli_query($conn, $sql)) {
            // success -> redirect, jate refresh dile abar insert na hoy
            header("Location: admin_rooms.php?msg=added");
            exit;
        } else {
            $_SESSION['msg'] = "DB Error: " . mysqli_error($conn);
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: admin_rooms.php");
    exit;
}

// Delete room
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    if(mysqli_query($conn, "DELETE FROM rooms WHERE id=$id")){
        $_SESSION['msg'] = "Room Deleted Successfully!";
        $_SESSION['msg_type'] = "warning";
    } else {
        $_SESSION['msg'] = "Delete failed: " . mysqli_error($conn);
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_rooms.php");
    exit;
}

// Update current guest for fixed room
if (isset($_POST['update_guest'])) {
    $room_id = (int)$_POST['room_id'];
    $guest_name = mysqli_real_escape_string($conn, $_POST['current_guest']);
    if(mysqli_query($conn, "UPDATE rooms SET current_guest = '$guest_name' WHERE id = $room_id")){
        $_SESSION['msg'] = "Guest name updated!";
        $_SESSION['msg_type'] = "info";
    } else {
        $_SESSION['msg'] = "Failed to update guest!";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_rooms.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Rooms - SCL DMS</title>
<!-- Essential Meta Tag for Mobile Responsiveness -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; margin: 0; padding: 0; }
    
    /* ?? Sidebar Styling for Desktop */
    .sidebar { 
        background-color: #1a2332; 
        color: white; 
        min-height: 100vh; 
        padding: 20px; 
        position: fixed; 
        top: 0;
        left: 0;
        width: 280px; 
        z-index: 1000; 
        transition: transform 0.3s ease-in-out;
        box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        overflow-y: auto;
    }
    
    .sidebar-brand { font-size: 1.5rem; font-weight: 500; letter-spacing: 1px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 15px; }
    
    /* Logged in info box */
    .user-info-box { background-color: #2a3441; border-radius: 8px; padding: 15px; text-align: center; margin-bottom: 25px; }
    .user-info-box small { color: #a0aec0; }
    .user-info-box strong { display: block; font-size: 1.1rem; margin: 5px 0; }
    .role-badge { background-color: #ffc107; color: #000; font-weight: 700; border-radius: 12px; padding: 3px 10px; font-size: 0.75rem; letter-spacing: 0.5px; }
    
    /* Sidebar Buttons */
    .sidebar .btn { 
        text-align: center; 
        border-radius: 6px; 
        margin-bottom: 10px; 
        padding: 10px; 
        font-weight: 500;
        border: 1px solid rgba(255,255,255,0.2);
        display: block;
        width: 100%;
    }
    .sidebar .btn-outline-light { background: transparent; color: white; text-decoration: none; }
    .sidebar .btn-outline-light:hover { background: rgba(255,255,255,0.1); }
    .sidebar .btn-primary { background-color: #0d6efd; border-color: #0d6efd; color: white; text-decoration: none; }
    .sidebar .btn-danger { background-color: #dc3545; border-color: #dc3545; margin-top: 15px; text-decoration: none; }

    /* ?? Mobile Navbar (Hidden on Desktop) */
    .mobile-navbar { 
        display: none; 
        background-color: #1a2332; 
        color: white; 
        padding: 15px 20px; 
        align-items: center; 
        justify-content: space-between; 
        position: sticky; 
        top: 0; 
        z-index: 999; 
        box-shadow: 0 2px 5px rgba(0,0,0,0.2); 
        width: 100%;
    }
    .mobile-navbar h5 { margin: 0; font-size: 1.3rem; letter-spacing: 0.5px; }
    .menu-toggle-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; padding: 0; }

    /* Content Area */
    .main-content { margin-left: 280px; padding: 30px; transition: margin-left 0.3s ease-in-out; }
    
    /* Sidebar Overlay for Mobile */
    .sidebar-overlay { 
        display: none; 
        position: fixed; 
        top: 0; 
        left: 0; 
        width: 100vw; 
        height: 100vh; 
        background: rgba(0,0,0,0.6); 
        z-index: 998; 
        opacity: 0;
        transition: opacity 0.3s ease-in-out;
    }

    /* Fade-in Animation */
    .fade-in { animation: fadeIn 0.6s ease-in-out; }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ?? STRICT RESPONSIVE STYLES FOR MOBILE ?? */
    @media screen and (max-width: 768px) {
        .mobile-navbar { display: flex !important; }
        
        /* Hide Sidebar completely off-screen initially */
        .sidebar { 
            transform: translateX(-100%); 
            width: 260px;
        }
        
        /* When active class is added, slide sidebar in */
        .sidebar.active { 
            transform: translateX(0); 
        }
        
        /* Expand main content to full width */
        .main-content { 
            margin-left: 0 !important; 
            padding: 15px !important; 
            width: 100%;
        }
        
        /* Show overlay when active */
        .sidebar-overlay.active { 
            display: block; 
            opacity: 1;
        }

        /* Adjust Table & Card margins for mobile */
        .card { padding: 15px !important; }
        .table-responsive { overflow-x: auto; }
        .btn-group { flex-wrap: wrap; justify-content: center; }
        .btn-group .btn { margin: 2px; border-radius: 4px !important; }
    }
</style>
</head>
<body>

<!-- Toast Notification Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
    <div id="liveToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-bold" id="toastMessage"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" id="toastCloseBtn"></button>
        </div>
    </div>
</div>

<!-- ?? Mobile Navbar -->
<div class="mobile-navbar">
    <h5>SCL DMS</h5>
    <button class="menu-toggle-btn" id="menuToggleBtn"><i class="fas fa-bars"></i></button>
</div>

<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ?? Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="d-flex justify-content-between align-items-center d-md-none mb-3">
        <div class="sidebar-brand mb-0 border-0 pb-0">SCL DMS</div>
        <button class="btn btn-sm text-light border-0 p-0 m-0" id="closeSidebarBtn" style="width:auto; border:none !important;"><i class="fas fa-times fa-lg"></i></button>
    </div>
    
    <div class="sidebar-brand d-none d-md-block">SCL DMS</div>
    
    <div class="user-info-box">
        <small>Logged in as:</small>
        <strong><?php echo htmlspecialchars($userName); ?></strong>
        <span class="badge role-badge">ADMIN</span>
    </div>
    
    <a href="index.php" class="btn btn-outline-light w-100">Dashboard</a>
    <a href="admin_rooms.php" class="btn btn-primary w-100">Manage Rooms</a>
    <a href="admin_users.php" class="btn btn-outline-light w-100">Manage Users</a>
    <a href="checkout_list.php" class="btn btn-outline-light w-100">Active Checkouts</a>
    
    <a href="logout.php" class="btn btn-danger w-100">Logout</a>
</div>

<!-- Main Content Area -->
<div class="main-content fade-in">
        
    <?php if(isset($_SESSION['msg'])): ?>
        <?php 
            $toast_msg = $_SESSION['msg'];
            $toast_type = isset($_SESSION['msg_type']) ? $_SESSION['msg_type'] : 'info';
            
            unset($_SESSION['msg']);
            unset($_SESSION['msg_type']);
        ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var toastEl = document.getElementById('liveToast');
                var toastBody = document.getElementById('toastMessage');
                var closeBtn = document.getElementById('toastCloseBtn');
                
                toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-white', 'text-dark');
                closeBtn.classList.remove('btn-close-white');
                
                if ('<?php echo $toast_type; ?>' === 'warning' || '<?php echo $toast_type; ?>' === 'info') {
                    toastEl.classList.add('bg-<?php echo $toast_type; ?>', 'text-dark');
                } else {
                    toastEl.classList.add('bg-<?php echo $toast_type; ?>', 'text-white');
                    closeBtn.classList.add('btn-close-white');
                }
                
                toastBody.innerHTML = "<?php echo addslashes($toast_msg); ?>";
                var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
                toast.show();
            });
        </script>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Add Room Card -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4">
                    <h5 class="text-primary mb-4"><i class="fas fa-plus-circle me-2"></i>Add New Room</h5>
                    
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Room Number</label>
                            <input type="text" name="room_no" class="form-control" placeholder="e.g. 101" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Floor</label>
                            <input type="text" name="floor" class="form-control" placeholder="e.g. 2nd Floor" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Type</label>
                            <select name="type" class="form-select">
                                <option value="Single">Single</option>
                                <option value="Double">Double</option>
                                <option value="VIP">VIP</option>
                                <option value="Dormitory">Dormitory</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Fixed Guest?</label>
                            <select name="is_fixed" class="form-select">
                                <option value="No">No (Available for booking)</option>
                                <option value="Yes">Yes (Fixed - Manual guest)</option>
                            </select>
                        </div>
                        <button type="submit" name="add_room" class="btn btn-primary w-100 mb-2 py-2">
                            <i class="fas fa-plus me-2"></i>Add Room
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Room List Card -->
        <div class="col-md-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4">
                    <h5 class="mb-4"><i class="fas fa-list me-2"></i>Room List <span class="badge bg-secondary ms-2"><?php echo mysqli_num_rows(mysqli_query($conn, "SELECT id FROM rooms")); ?> Total</span></h5>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Room No</th>
                                    <th>Floor</th>
                                    <th>Type</th>
                                    <th>Current Guest</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $res = mysqli_query($conn, "SELECT * FROM rooms ORDER BY floor ASC, room_no ASC");
                                while ($row = mysqli_fetch_assoc($res)) {
                                    $is_fixed = ($row['is_fixed'] == 'Yes');
                                    
                                    // Status Badge Logic
                                    if ($is_fixed) {
                                        $status_badge = '<span class="badge bg-danger"><i class="fas fa-lock me-1"></i>Fixed</span>';
                                    } elseif ($row['status'] == 'Available') {
                                        $status_badge = '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Available</span>';
                                    } else {
                                        $status_badge = '<span class="badge bg-warning text-dark"><i class="fas fa-bed me-1"></i>Booked</span>';
                                    }

                                    // Guest Display Logic
                                    $guest_display = '<span class="text-muted small fst-italic">None</span>';
                                    if (!empty($row['current_guest'])) {
                                        $guest_display = '<span class="fw-semibold text-primary"><i class="fas fa-user me-1"></i>' . htmlspecialchars($row['current_guest']) . '</span>';
                                    }
                                    
                                    // Room Type Badge
                                    $type_badge_class = 'bg-secondary';
                                    if ($row['room_type'] === 'VIP') $type_badge_class = 'bg-warning text-dark';
                                    elseif ($row['room_type'] === 'Double') $type_badge_class = 'bg-info text-dark';

                                    echo "<tr>
                                            <td><strong class='text-dark'>{$row['room_no']}</strong></td>
                                            <td><small class='text-muted'>{$row['floor']}</small></td>
                                            <td><span class='badge {$type_badge_class}'>" . htmlspecialchars($row['room_type']) . "</span></td>
                                            <td>$guest_display</td>
                                            <td>$status_badge</td>
                                            <td class='text-end'>
                                                <div class='btn-group' role='group'>
                                                    <a href='admin_edit_room.php?id={$row['id']}' class='btn btn-outline-primary btn-sm' title='Edit Room'>
                                                        <i class='fas fa-edit'></i>
                                                    </a>
                                                    <a href='admin_rooms.php?del={$row['id']}' class='btn btn-outline-danger btn-sm' onclick='return confirm(\"Are you sure you want to delete room {$row['room_no']}?\")' title='Delete Room'>
                                                        <i class='fas fa-trash-alt'></i>
                                                    </a>
                                                </div>
                                            </td>
                                          </tr>";

                                    // Inline Edit for Fixed Rooms
                                    if ($is_fixed) {
                                        echo "<tr class='table-light border-bottom'>
                                                <td colspan='6' class='p-3'>
                                                    <form method='POST' class='d-flex flex-column flex-md-row gap-2 align-items-md-center'>
                                                        <input type='hidden' name='room_id' value='{$row['id']}'>
                                                        <div class='input-group input-group-sm w-100'>
                                                            <span class='input-group-text bg-white'><i class='fas fa-user-edit text-muted'></i></span>
                                                            <input type='text' name='current_guest' class='form-control' placeholder='Update permanent guest name' value='".htmlspecialchars($row['current_guest'])."'>
                                                            <button type='submit' name='update_guest' class='btn btn-success px-3'>Save</button>
                                                        </div>
                                                    </form>
                                                </td>
                                              </tr>";
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle sidebar logic using plain JavaScript
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const menuBtn = document.getElementById('menuToggleBtn');
        const closeBtn = document.getElementById('closeSidebarBtn');

        function toggleMenu() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            // Prevent scrolling on body when menu is open
            if(sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = 'auto';
            }
        }

        // Event Listeners
        if(menuBtn) menuBtn.addEventListener('click', toggleMenu);
        if(closeBtn) closeBtn.addEventListener('click', toggleMenu);
        if(overlay) overlay.addEventListener('click', toggleMenu);
    });
</script>
</body>
</html>
