<?php
// profile.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];   // এখানে username স্টোর করছ
$role = $_SESSION['UserRole'] ?? 'user';

$message = "";
if (isset($_SESSION['msg'])) {
    $message = $_SESSION['msg'];
    unset($_SESSION['msg']);   // একবার দেখানোর পর ক্লিয়ার
}

// departments list (guest_request-এর মতো)
$dept_list = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand", "Other"];

// 1) current data load
$sql = "SELECT full_name, email, username, title, department, phone, designation, address, emergency_contact, id_proof
        FROM users
        WHERE username = '".mysqli_real_escape_string($conn, $user)."'
        LIMIT 1";
$res = mysqli_query($conn, $sql);

$u = [
    'full_name'         => $user,
    'phone'             => '',
    'email'             => '',
    'designation'       => '',
    'address'           => '',
    'department'        => 'Other',
    'emergency_contact' => '',
    'id_proof'          => '',
    'title'             => 'Mr'
];

if ($res && mysqli_num_rows($res) > 0) {
    $row = mysqli_fetch_assoc($res);
    $u['full_name']         = $row['full_name']         ?? $user;
    $u['phone']             = $row['phone']             ?? '';
    $u['email']             = $row['email']             ?? '';
    $u['designation']       = $row['designation']       ?? '';
    $u['address']           = $row['address']           ?? '';
    $u['department']        = $row['department']        ?? 'Other';
    $u['emergency_contact'] = $row['emergency_contact'] ?? '';
    $u['id_proof']          = $row['id_proof']          ?? '';
    $u['title']             = $row['title']             ?? 'Mr';
}

// 2) save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {

    $full_name         = mysqli_real_escape_string($conn, $_POST['full_name']);
    $phone             = mysqli_real_escape_string($conn, $_POST['phone']);
    $email             = mysqli_real_escape_string($conn, $_POST['email']);
    $designation       = mysqli_real_escape_string($conn, $_POST['designation']);
    $address           = mysqli_real_escape_string($conn, $_POST['address']);
    $department        = mysqli_real_escape_string($conn, $_POST['department']);
    $emergency_contact = mysqli_real_escape_string($conn, $_POST['emergency_contact']);
    $id_proof          = mysqli_real_escape_string($conn, $_POST['id_proof']);
    $title             = mysqli_real_escape_string($conn, $_POST['title']);

    $upd = "
        UPDATE users SET
            full_name         = '$full_name',
            phone             = '$phone',
            email             = '$email',
            designation       = '$designation',
            address           = '$address',
            department        = '$department',
            emergency_contact = '$emergency_contact',
            id_proof          = '$id_proof',
            title             = '$title'
        WHERE username = '".mysqli_real_escape_string($conn, $user)."'
        LIMIT 1
    ";

    if (mysqli_query($conn, $upd)) {
        header("Location: profile.php?updated=1");
        exit;
    } else {
        $message = "<div class='alert alert-danger'>Error: ".mysqli_error($conn)."</div>";
    }
}

if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Profile updated successfully.</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile</title>
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
    

    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
        <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 position-relative text-white">
            <i class="fas fa-tasks me-2"></i>Manage All
        </a>
    <?php endif; ?>

    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="card shadow border-0">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>My Profile</h5>
                    </div>
                    <div class="card-body bg-white">
                        <?php echo $message; ?>

                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Title</label>
                                    <select name="title" class="form-select" required>
                                        <option value="">Title</option>
                                        <option value="Mr"  <?php if($u['title']=='Mr')  echo 'selected'; ?>>Mr</option>
                                        <option value="Mrs" <?php if($u['title']=='Mrs') echo 'selected'; ?>>Mrs</option>
                                        <option value="Ms"  <?php if($u['title']=='Ms')  echo 'selected'; ?>>Ms</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="form-control"
                                           value="<?php echo htmlspecialchars($u['full_name']); ?>" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone *</label>
                                    <input type="text" name="phone" class="form-control"
                                           value="<?php echo htmlspecialchars($u['phone']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?php echo htmlspecialchars($u['email']); ?>" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Designation</label>
                                    <input type="text" name="designation" class="form-control"
                                           value="<?php echo htmlspecialchars($u['designation']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Department</label>
                                    <select name="department" class="form-select">
                                        <?php foreach($dept_list as $d): ?>
                                            <option value="<?php echo $d; ?>" <?php if($u['department']==$d) echo 'selected'; ?>>
                                                <?php echo $d; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Emergency Contact</label>
                                    <input type="text" name="emergency_contact" class="form-control"
                                           value="<?php echo htmlspecialchars($u['emergency_contact']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">NID/Company ID</label>
                                    <input type="text" name="id_proof" class="form-control"
                                           value="<?php echo htmlspecialchars($u['id_proof']); ?>"required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <input type="text" name="address" class="form-control"
                                       value="<?php echo htmlspecialchars($u['address']); ?>">
                            </div>

                            <button type="submit" name="save_profile" class="btn btn-primary w-100">
                                <i class="fas fa-save me-2"></i>Save Profile
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
