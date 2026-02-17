<?php
session_start();
require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName']) || !in_array($_SESSION['UserRole'], ['admin', 'staff'])) {
    die("Access Denied!");
}

// ✅ DATE RANGE FILTER
$from_date = isset($_GET['from_date']) ? mysqli_real_escape_string($conn, $_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? mysqli_real_escape_string($conn, $_GET['to_date']) : '';

$where_clause = "";
if (!empty($from_date) && !empty($to_date)) {
    $where_clause = "WHERE DATE(cog.check_out_date) BETWEEN '$from_date' AND '$to_date'";
} elseif (!empty($from_date)) {
    $where_clause = "WHERE DATE(cog.check_out_date) >= '$from_date'";
} elseif (!empty($to_date)) {
    $where_clause = "WHERE DATE(cog.check_out_date) <= '$to_date'";
}

// ✅ Fetch All Data with Room Details
$sql = "SELECT cog.*, 
               b.guest_email, b.notes, b.purpose, b.arrival_time, b.secondary_guest_email,
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
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Dormitory History Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', sans-serif; margin: 15px; font-size: 9px; }
        
        /* ✅ Header Section with Logo */
        .header { 
            text-align: center; 
            background: linear-gradient(135deg, #224895 0%, #1a3666 100%);
            color: white; 
            padding: 20px 15px; 
            margin-bottom: 15px; 
            border-radius: 8px;
            position: relative;
        }
        
        /* ✅ Company Logo - Original Colors, Transparent Background */
        .company-logo {
            max-width: 120px;
            max-height: 80px;
            margin-bottom: 10px;
            background: transparetn; /* ✅ Transparent background */
            /* ❌ Removed: filter: brightness(0) invert(1); */
        }
        
        .header h1 { 
            margin: 0 0 5px 0; 
            font-size: 22px; 
            letter-spacing: 1px; 
            font-weight: bold;
        }
        .header h3 { 
            margin: 5px 0; 
            font-size: 13px; 
            font-weight: normal; 
            background: rgba(255,255,255,0.2);
            display: inline-block;
            padding: 5px 15px;
            border-radius: 5px;
        }
        .header p { 
            margin: 8px 0 0 0; 
            font-size: 11px; 
            opacity: 0.9;
        }
        
        /* Table Styles */
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        thead tr { 
            background: #224895; 
            color: white; 
        }
        
        th { 
            padding: 8px 4px; 
            text-align: left; 
            font-weight: bold; 
            font-size: 9px;
            border: 1px solid #1a3666;
            white-space: nowrap;
        }
        
        td { 
            padding: 6px 4px; 
            border: 1px solid #ddd; 
            font-size: 8px;
            vertical-align: top;
            background: white;
        }
        
        tbody tr:nth-child(even) td { 
            background-color: #f9f9f9; 
        }
        
        tbody tr:hover td { 
            background-color: #e8f4ff !important; 
        }
        
        /* Column Specific Styles */
        .col-sl { width: 30px; text-align: center; font-weight: bold; }
        .col-name { width: 100px; font-weight: bold; color: #224895; }
        .col-email { width: 120px; font-size: 7px; word-break: break-word; }
        .col-phone { width: 80px; }
        
        /* Room Number - Light Blue */
        .col-room { 
            width: 50px; 
            text-align: center; 
            font-weight: bold; 
            background: #d4edff !important;
            color: #224895;
        }
        
        .col-type { width: 60px; font-size: 8px; }
        .col-floor { width: 40px; text-align: center; }
        .col-dept { width: 80px; font-size: 8px; }
        .col-datetime { width: 95px; font-size: 7px; }
        
        /* ✅ Duration - White Background with Blue Text */
        .col-days { 
            width: 40px; 
            text-align: center; 
            font-weight: bold; 
            background: white !important; 
            color: #224895;
            font-size: 9px;
        }
        
        .col-time { width: 60px; text-align: center; font-size: 8px; }
        .col-purpose { width: 80px; font-size: 7px; }
        
        /* Notes - Better Contrast */
        .col-notes { 
            width: 80px; 
            font-size: 7px; 
            color: #333;
            font-style: italic;
        }
        
        .col-emergency { width: 80px; font-size: 7px; }
        .col-id { width: 70px; font-size: 7px; }
        
        /* Secondary Guest Columns - Light Green */
        .col-sec-name { 
            width: 90px; 
            font-size: 8px; 
            background: #e8f5e9 !important;
            color: #2e7d32;
            font-weight: 500;
        }
        .col-sec-phone { 
            width: 75px; 
            font-size: 7px; 
            background: #e8f5e9 !important;
            color: #2e7d32;
        }
        .col-sec-email { 
            width: 100px; 
            font-size: 7px; 
            word-break: break-word; 
            background: #e8f5e9 !important;
            color: #2e7d32;
        }
        
        .col-status { width: 70px; text-align: center; }
        
        .status-badge {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 7px;
            font-weight: bold;
            display: inline-block;
        }
        
        /* Empty Value Styling */
        .empty-value {
            color: #999;
            font-style: italic;
        }
        
        /* Print Styles */
        @media print {
            body { margin: 5px; }
            .no-print { display: none; }
            @page { 
                size: A3 landscape; 
                margin: 10mm;
            }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
            
            .col-room { background: #d4edff !important; }
            .col-days { background: white !important; }
            .col-sec-name, .col-sec-phone, .col-sec-email { 
                background: #e8f5e9 !important; 
            }
        }
        
        /* Print Button */
        .print-btn {
            padding: 12px 25px;
            background: #224895;
            color: white;
            border: none;
            cursor: pointer;
            margin-bottom: 15px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            transition: all 0.3s;
        }
        .print-btn:hover {
            background: #1a3666;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>

<div class="header">
    <!-- ✅ Company Logo with Original Colors -->
    <img src="assets/images/company-logo.png" alt="Company Logo" class="company-logo">
    
    <h1>DORMITORY HISTORY REPORT</h1>
    <h3>
        <?php 
        if (!empty($from_date) && !empty($to_date)) {
            echo "Period: " . date('d M Y', strtotime($from_date)) . " to " . date('d M Y', strtotime($to_date));
        } elseif (!empty($from_date)) {
            echo "From: " . date('d M Y', strtotime($from_date));
        } elseif (!empty($to_date)) {
            echo "Until: " . date('d M Y', strtotime($to_date));
        } else {
            echo "All Records (Generated on " . date('d M Y, h:i A') . ")";
        }
        ?>
    </h3>
    <p>Total Records: <strong><?php echo mysqli_num_rows($result); ?></strong> | Generated by: <?php echo $_SESSION['UserName']; ?></p>
</div>

<button onclick="window.print()" class="print-btn no-print">
     Print / Save as PDF
</button>

<table>
    <thead>
        <tr>
            <th class="col-sl">SL</th>
            <th class="col-name">Guest Name</th>
            <th class="col-email">Email</th>
            <th class="col-phone">Phone</th>
            <th class="col-room">Room No</th>
            <th class="col-type">Room Type</th>
            <th class="col-floor">Floor</th>
            <th class="col-dept">Department</th>
            <th class="col-datetime">Check-in Date</th>
            <th class="col-datetime">Check-out Date</th>
            <th class="col-days">Duration (Days)</th>
            <th class="col-time">Arrival Time</th>
            <th class="col-time">Departure Time</th>
            <th class="col-purpose">Purpose</th>
            <th class="col-notes">Notes</th>
            <th class="col-emergency">Emergency Contact</th>
            <th class="col-id">ID Proof</th>
            <th class="col-sec-name">Secondary Guest Name</th>
            <th class="col-sec-phone">Secondary Guest Phone</th>
            <th class="col-sec-email">Secondary Guest Email</th>
            <th class="col-status">Status</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $sl = 1;
        while ($row = mysqli_fetch_assoc($result)): 
            $checkin_ts = strtotime($row['check_in_date']);
            $checkout_ts = strtotime($row['check_out_date']);
            $duration_days = floor(($checkout_ts - $checkin_ts) / 86400);
            if ($duration_days <= 0) $duration_days = 1;
            
            $arrival_time_display = '';
            if (!empty($row['arrival_time'])) {
                $arrival_time_display = date('h:i A', strtotime($row['arrival_time']));
            } else {
                $arrival_time_display = date('h:i A', $checkin_ts);
            }
            
            $departure_time_display = date('h:i A', $checkout_ts);
        ?>
        <tr>
            <td class="col-sl"><?php echo $sl++; ?></td>
            <td class="col-name"><?php echo htmlspecialchars($row['guest_name']); ?></td>
            <td class="col-email"><?php echo !empty($row['guest_email']) ? htmlspecialchars($row['guest_email']) : '<span class="empty-value">N/A</span>'; ?></td>
            <td class="col-phone"><?php echo htmlspecialchars($row['phone']); ?></td>
            <td class="col-room"><?php echo htmlspecialchars($row['room_number']); ?></td>
            <td class="col-type"><?php echo !empty($row['room_type']) ? htmlspecialchars($row['room_type']) : '<span class="empty-value">N/A</span>'; ?></td>
            <td class="col-floor"><?php echo !empty($row['floor']) ? htmlspecialchars($row['floor']) : '<span class="empty-value">N/A</span>'; ?></td>
            <td class="col-dept"><?php echo htmlspecialchars($row['department']); ?></td>
            <td class="col-datetime"><?php echo date('d M Y, h:i A', $checkin_ts); ?></td>
            <td class="col-datetime"><?php echo date('d M Y, h:i A', $checkout_ts); ?></td>
            <td class="col-days"><?php echo $duration_days; ?></td>
            <td class="col-time"><?php echo $arrival_time_display; ?></td>
            <td class="col-time"><?php echo $departure_time_display; ?></td>
            <td class="col-purpose"><?php echo !empty($row['purpose']) ? htmlspecialchars($row['purpose']) : '<span class="empty-value">N/A</span>'; ?></td>
            <td class="col-notes"><?php echo !empty($row['notes']) ? htmlspecialchars($row['notes']) : '<span class="empty-value">N/A</span>'; ?></td>
            <td class="col-emergency"><?php echo !empty($row['emergency_contact']) ? htmlspecialchars($row['emergency_contact']) : '<span class="empty-value">N/A</span>'; ?></td>
            <td class="col-id"><?php echo !empty($row['id_proof']) ? htmlspecialchars($row['id_proof']) : '<span class="empty-value">N/A</span>'; ?></td>
            <td class="col-sec-name">
                <?php echo !empty($row['secondary_guest_name']) ? htmlspecialchars($row['secondary_guest_name']) : '<span class="empty-value">—</span>'; ?>
            </td>
            <td class="col-sec-phone">
                <?php echo !empty($row['secondary_guest_phone']) ? htmlspecialchars($row['secondary_guest_phone']) : '<span class="empty-value">—</span>'; ?>
            </td>
            <td class="col-sec-email">
                <?php echo !empty($row['secondary_guest_email']) ? htmlspecialchars($row['secondary_guest_email']) : '<span class="empty-value">—</span>'; ?>
            </td>
            <td class="col-status"><span class="status-badge">Checked-Out</span></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

</body>
</html>
