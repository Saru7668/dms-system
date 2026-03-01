<?php
session_start();
require_once('db.php');

// ? 1. SET DATABASE CHARSET TO UTF-8
mysqli_set_charset($conn, "utf8mb4");

if (!isset($_SESSION['UserName']) || !in_array($_SESSION['UserRole'], ['admin', 'staff', 'superadmin'])) {
    die("Access Denied!");
}

// ? GET ALL FILTERS (MATCHING checkout_history.php)
$from_date = isset($_GET['from_date']) ? mysqli_real_escape_string($conn, trim($_GET['from_date'])) : '';
$to_date = isset($_GET['to_date']) ? mysqli_real_escape_string($conn, trim($_GET['to_date'])) : '';
$room_filter = isset($_GET['room']) ? mysqli_real_escape_string($conn, trim($_GET['room'])) : '';
$guest_filter = isset($_GET['guest_name']) ? mysqli_real_escape_string($conn, trim($_GET['guest_name'])) : '';
$ref_filter = isset($_GET['ref_no']) ? mysqli_real_escape_string($conn, trim($_GET['ref_no'])) : '';

// ? BUILD QUERY CONDITIONS
$where_conditions = [];

// Date Filter
if (!empty($from_date) && !empty($to_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) BETWEEN '$from_date' AND '$to_date'";
} elseif (!empty($from_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) >= '$from_date'";
} elseif (!empty($to_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) <= '$to_date'";
}

// Room Filter
if (!empty($room_filter)) {
    $where_conditions[] = "cog.room_number = '$room_filter'";
}

// Guest Filter
if (!empty($guest_filter)) {
    $where_conditions[] = "(cog.guest_name LIKE '%$guest_filter%' OR b.secondary_guest_name LIKE '%$guest_filter%')";
}

// Ref Filter
if (!empty($ref_filter)) {
    $where_conditions[] = "b.request_ref_id = '$ref_filter'";
}

// Combine Conditions
$where_clause = "";
if (count($where_conditions) > 0) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// ? FETCH DATA QUERY
$sql = "SELECT cog.*, 
               b.guest_email, b.notes, b.purpose, b.arrival_time, b.secondary_guest_email, b.request_ref_id,
               r.room_type, r.floor
        FROM checked_out_guests cog 
        LEFT JOIN bookings b ON cog.booking_id = b.id 
        LEFT JOIN rooms r ON cog.room_number = r.room_no
        $where_clause
        ORDER BY cog.check_out_date DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Query Error: " . mysqli_error($conn));
}

// ? 2. EXCEL HEADERS WITH UTF-8 BOM
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="Dormitory_History_Report_' . date('Y-m-d_His') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// ? ADD BOM (For correct bangla/special char display in Excel)
echo "\xEF\xBB\xBF"; 

// ? REPORT HEADING TABLE
echo "<table border='0' width='100%' cellpadding='10' cellspacing='0'>";

// Company Header
echo "<tr>";
echo "<td colspan='26' style='text-align:center; background-color:#224895; color:white; padding:20px;'>";
echo "<div style='font-size:18px; font-weight:bold; margin-bottom:5px;'>SHELTECH CERAMICS LIMITED</div>"; 
echo "</td>";
echo "</tr>";

// REPORT TITLE
echo "<tr>";
echo "<td colspan='26' style='text-align:center; font-size:20px; font-weight:bold; padding:15px; background-color:#1a3666; color:white;'>";
echo "DORMITORY HISTORY REPORT";
echo "</td>";
echo "</tr>";

// FILTER INFO DISPLAY
echo "<tr>";
echo "<td colspan='26' style='text-align:center; font-size:14px; font-weight:bold; padding:8px; background-color:#f0f0f0;'>";

$filter_msg = [];

if (!empty($from_date) && !empty($to_date)) {
    $filter_msg[] = "Period: " . date('d M Y', strtotime($from_date)) . " to " . date('d M Y', strtotime($to_date));
} elseif (!empty($from_date)) {
    $filter_msg[] = "From: " . date('d M Y', strtotime($from_date));
} elseif (!empty($to_date)) {
    $filter_msg[] = "Until: " . date('d M Y', strtotime($to_date));
}

if (!empty($room_filter)) {
    $filter_msg[] = "Room: " . htmlspecialchars($room_filter);
}
if (!empty($guest_filter)) {
    $filter_msg[] = "Guest: " . htmlspecialchars($guest_filter);
}
if (!empty($ref_filter)) {
    $filter_msg[] = "Ref No: " . htmlspecialchars($ref_filter);
}

if (empty($filter_msg)) {
    echo "All Records (Generated on " . date('d M Y, h:i A') . ")";
} else {
    echo implode(" | ", $filter_msg) . " (Generated on " . date('d M Y, h:i A') . ")";
}

echo "</td>";
echo "</tr>";
echo "</table>"; // End Header Table

// ? DATA TABLE
echo "<table border='1' cellpadding='5' cellspacing='0' width='100%'>";
echo "<thead>";
echo "<tr style='background-color: #224895; color: white; font-weight: bold;'>";
echo "<th>SL</th>";
echo "<th>Ref No</th>";
echo "<th>Guest Name</th>";
echo "<th>Designation</th>";
echo "<th>Address</th>";
echo "<th>Email</th>";
echo "<th>Phone</th>";
echo "<th>Room No</th>";
echo "<th>Room Type</th>";
echo "<th>Floor</th>";
echo "<th>Department</th>";
echo "<th>Check-in Date</th>";
echo "<th>Check-out Date</th>";
echo "<th>Duration (Days)</th>";
echo "<th>Arrival Time</th>";
echo "<th>Departure Time</th>";
echo "<th>Purpose</th>";
echo "<th>Notes</th>";
echo "<th>Emergency Contact</th>";
echo "<th>ID Proof</th>";
echo "<th>Secondary Guest Name</th>";
echo "<th>Sec. Guest Designation</th>";
echo "<th>Sec. Guest Address</th>";
echo "<th>Secondary Guest Phone</th>";
echo "<th>Secondary Guest Email</th>";
echo "<th>Status</th>";
echo "</tr>";
echo "</thead>";
echo "<tbody>";

$sl = 1;
if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        
        // Clean garbage characters & Safe Fetch
        $sec_name = !empty($row['secondary_guest_name']) ? htmlspecialchars($row['secondary_guest_name']) : '—';
        $sec_phone = !empty($row['secondary_guest_phone']) ? htmlspecialchars($row['secondary_guest_phone']) : '—';
        $sec_email = !empty($row['secondary_guest_email']) ? htmlspecialchars($row['secondary_guest_email']) : '—';
        
        $desig = !empty($row['designation']) ? htmlspecialchars($row['designation']) : '—';
        $addr = !empty($row['address']) ? htmlspecialchars($row['address']) : '—';
        $sec_desig = !empty($row['secondary_guest_designation']) ? htmlspecialchars($row['secondary_guest_designation']) : '—';
        $sec_addr = !empty($row['secondary_guest_address']) ? htmlspecialchars($row['secondary_guest_address']) : '—';
        
        // Remove known bad characters
        $bad_chars = ['ï¿½'];
        $sec_name = str_replace($bad_chars, '', $sec_name);
        $sec_phone = str_replace($bad_chars, '', $sec_phone);
        $sec_email = str_replace($bad_chars, '', $sec_email);

        $checkin_ts = strtotime($row['check_in_date']);
        $checkin_date_disp = ($checkin_ts > 0) ? date('d M Y', $checkin_ts) : '—';

        $checkout_ts = strtotime($row['check_out_date']);
        $checkout_date_disp = ($checkout_ts > 0) ? date('d M Y', $checkout_ts) : '—';
        
        // Duration Logic
        $duration_days = (int)$row['total_days'];
        if ($duration_days <= 0 && $checkin_ts > 0 && $checkout_ts > 0) {
            $duration_days = floor(($checkout_ts - $checkin_ts) / 86400);
            if ($duration_days <= 0) $duration_days = 1;
        }

        // Arrival Time Logic
        $arrival_time_display = '—'; 
        if (!empty($row['arrival_time']) && $row['arrival_time'] != '00:00:00') {
            $arrival_time_display = date('h:i A', strtotime($row['arrival_time']));
        } elseif ($checkin_ts > 0 && date('H:i:s', $checkin_ts) != '00:00:00') {
            $arrival_time_display = date('h:i A', $checkin_ts);
        }

        // Departure Time Logic
        $departure_time_display = '—';
        if ($checkout_ts > 0 && date('H:i:s', $checkout_ts) != '00:00:00') {
            $departure_time_display = date('h:i A', $checkout_ts);
        }
        
        echo "<tr>";
        echo "<td style='text-align:center;'>" . $sl++ . "</td>";
        echo "<td style='text-align:center;'>" . htmlspecialchars($row['request_ref_id'] ?? '—') . "</td>";
        echo "<td style='font-weight:bold;'>" . htmlspecialchars($row['guest_name']) . "</td>";
        echo "<td>" . $desig . "</td>";
        echo "<td>" . $addr . "</td>";
        echo "<td>" . htmlspecialchars($row['primary_email'] ?? ($row['guest_email'] ?? 'N/A')) . "</td>";
        echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
        echo "<td style='text-align:center; font-weight:bold; background-color:#d4edff;'>" . htmlspecialchars($row['room_number']) . "</td>";
        echo "<td>" . htmlspecialchars($row['room_type'] ?? 'N/A') . "</td>";
        echo "<td style='text-align:center;'>" . htmlspecialchars($row['floor'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['department']) . "</td>";
        echo "<td>" . $checkin_date_disp . "</td>"; 
        echo "<td>" . $checkout_date_disp . "</td>"; 
        echo "<td style='text-align:center; font-weight:bold;'>" . $duration_days . "</td>";
        
        echo "<td style='text-align:center;'>" . $arrival_time_display . "</td>";
        echo "<td style='text-align:center;'>" . $departure_time_display . "</td>";

        echo "<td>" . htmlspecialchars($row['purpose'] ?? 'N/A') . "</td>";
        echo "<td style='font-style:italic;'>" . htmlspecialchars($row['notes'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['emergency_contact'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['id_proof'] ?? 'N/A') . "</td>";
        echo "<td style='background-color:#e8f5e9; color:#2e7d32;'>" . $sec_name . "</td>";
        echo "<td style='background-color:#e8f5e9; color:#2e7d32;'>" . $sec_desig . "</td>";
        echo "<td style='background-color:#e8f5e9; color:#2e7d32;'>" . $sec_addr . "</td>";
        echo "<td style='background-color:#e8f5e9; color:#2e7d32;'>" . $sec_phone . "</td>";
        echo "<td style='background-color:#e8f5e9; color:#2e7d32;'>" . $sec_email . "</td>";
        echo "<td style='text-align:center; background-color:#28a745; color:white; font-weight:bold;'>Checked-Out</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='26' style='text-align:center; font-weight:bold; color:red; padding:20px;'>No Records Found for the selected criteria.</td></tr>";
}

echo "</tbody>";
echo "</table>";

mysqli_close($conn);
exit;
?>
