<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

$host = 'localhost';
$db   = 'ubistreet';
$user = 'root';
$pass = '';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// ====================== CHECK AND CREATE TABLES IF NOT EXISTS ======================
// Check if disabled_dates table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'disabled_dates'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE disabled_dates (
        disable_date DATE NOT NULL,
        field_type VARCHAR(10) NOT NULL,
        reason VARCHAR(255),
        PRIMARY KEY (disable_date, field_type)
    )");
}

// Check if disabled_slots table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'disabled_slots'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE disabled_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        disable_date DATE NOT NULL,
        field_type VARCHAR(10) NOT NULL,
        reason TEXT,
        slot_time VARCHAR(20) NOT NULL,
        INDEX idx_date (disable_date),
        INDEX idx_field (field_type),
        UNIQUE KEY unique_disabled_slot (disable_date, slot_time, field_type)
    )");
}

// Check if slot_prices table exists
$table_check = $conn->query("SHOW TABLES LIKE 'slot_prices'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE slot_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slot_time VARCHAR(20) NOT NULL UNIQUE,
        price INT NOT NULL DEFAULT 0
    )");
    
    // Insert default slots
    $default_slots = [
        '4:00 PM - 6:00 PM' => 180,
        '6:00 PM - 8:00 PM' => 200,
        '8:00 PM - 10:00 PM' => 220,
        '10:00 PM - 12:00 AM' => 250
    ];
    foreach ($default_slots as $slot => $price) {
        $stmt = $conn->prepare("INSERT INTO slot_prices (slot_time, price) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("si", $slot, $price);
            $stmt->execute();
        }
    }
}

// Check if bookings table exists
$table_check = $conn->query("SHOW TABLES LIKE 'bookings'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(100) NOT NULL,
        customer_phone VARCHAR(20) NOT NULL,
        customer_email VARCHAR(100),
        booking_date DATE NOT NULL,
        slot_time VARCHAR(20) NOT NULL,
        field_type VARCHAR(10) NOT NULL,
        price INT NOT NULL,
        notification TINYINT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_booking_date (booking_date),
        INDEX idx_notification (notification)
    )");
}

$message = '';
$active_tab = $_GET['tab'] ?? 'bookings';

// Get auto-refresh interval from GET or default to 30 seconds
$refresh_interval = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 30;

// ====================== HANDLE NEW BOOKING NOTIFICATIONS ======================
// Get unread bookings with details
$unread_bookings = $conn->query("SELECT id, field_type, booking_date, slot_time, customer_name, created_at FROM bookings WHERE notification = 1 ORDER BY created_at DESC");
$unread_count = $unread_bookings ? $unread_bookings->num_rows : 0;

// Build detailed notification message (only if there are unread bookings)
if ($unread_count > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $message = '<div class="space-y-2">';
    $message .= '<div class="font-bold text-green-400 mb-2"><i class="fas fa-bell mr-2"></i>📬 New Booking' . ($unread_count > 1 ? 's' : '') . ' Awaiting Acknowledgment:</div>';
    
    $current_month = date('Y-m');
    $next_month = date('Y-m', strtotime('+1 month'));
    
    while ($booking = $unread_bookings->fetch_assoc()) {
        $booking_month = date('Y-m', strtotime($booking['booking_date']));
        $field_display = $booking['field_type'] === '7side' ? '7-a-side' : '9-a-side';
        $date_display = date('d M Y', strtotime($booking['booking_date']));
        
        // Set color based on month
        if ($booking_month == $current_month) {
            $badge_color = 'bg-blue-500/20 text-blue-400 border-blue-500/30';
            $month_badge = '<span class="text-xs bg-blue-500/20 text-blue-400 px-2 py-0.5 rounded-full ml-2">Current Month</span>';
        } elseif ($booking_month == $next_month) {
            $badge_color = 'bg-purple-500/20 text-purple-400 border-purple-500/30';
            $month_badge = '<span class="text-xs bg-purple-500/20 text-purple-400 px-2 py-0.5 rounded-full ml-2">Next Month</span>';
        } else {
            $badge_color = 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30';
            $month_badge = '<span class="text-xs bg-yellow-500/20 text-yellow-400 px-2 py-0.5 rounded-full ml-2">' . date('M Y', strtotime($booking['booking_date'])) . '</span>';
        }
        
        $message .= '<div class="bg-zinc-800/50 border-l-4 border-yellow-500 p-3 rounded-lg ' . $badge_color . '">';
        $message .= '<div class="flex items-center justify-between flex-wrap gap-2">';
        $message .= '<div class="flex items-center gap-2 flex-wrap">';
        $message .= '<span class="font-semibold text-white">' . htmlspecialchars($field_display) . '</span>';
        $message .= '<span class="text-zinc-400">•</span>';
        $message .= '<span><i class="fas fa-calendar-alt mr-1 text-green-400"></i>' . $date_display . '</span>';
        $message .= '<span class="text-zinc-400">•</span>';
        $message .= '<span><i class="fas fa-clock mr-1 text-green-400"></i>' . htmlspecialchars($booking['slot_time']) . '</span>';
        $message .= $month_badge;
        $message .= '</div>';
        $message .= '<div class="text-xs text-zinc-500">';
        $message .= '<i class="fas fa-user mr-1"></i>' . htmlspecialchars($booking['customer_name']);
        $message .= '</div>';
        $message .= '</div>';
        $message .= '</div>';
    }
    
    $message .= '</div>';
}

// ====================== HANDLE SETTINGS ACTIONS ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Change Slot Price
    if (isset($_POST['change_price'])) {
        $slot_time = $_POST['slot_time'];
        $new_price = (int)$_POST['new_price'];
        $stmt = $conn->prepare("UPDATE slot_prices SET price = ? WHERE slot_time = ?");
        if ($stmt) {
            $stmt->bind_param("is", $new_price, $slot_time);
            $stmt->execute();
            $message = "✅ Slot price updated successfully!";
        } else {
            $message = "❌ Failed to update slot price.";
        }
        $active_tab = 'settings';
    }

    // Disable Full Date
    if (isset($_POST['btn_disable_date'])) {
        $date = $_POST['disable_date_value'];
        $field_type = $_POST['field_type'];
        $reason = trim($_POST['reason_date']);
        
        if (empty($reason)) {
            $reason = "Admin disabled";
        }
        
        $stmt = $conn->prepare("INSERT INTO disabled_dates (disable_date, field_type, reason) VALUES (?, ?, ?) 
                                ON DUPLICATE KEY UPDATE reason = VALUES(reason)");
        if ($stmt) {
            $stmt->bind_param("sss", $date, $field_type, $reason);
            if ($stmt->execute()) {
                $field_name = $field_type === '7side' ? '7-a-side' : '9-a-side';
                $message = "✅ $field_name field disabled for " . date('d F Y', strtotime($date));
                if (!empty($reason)) {
                    $message .= " (Reason: " . htmlspecialchars($reason) . ")";
                }
            } else {
                $message = "❌ Failed to disable date.";
            }
        } else {
            $message = "❌ Database error: Could not prepare statement.";
        }
        $active_tab = 'settings';
    }

    // Disable Specific Slot
    if (isset($_POST['btn_disable_slot'])) {
        $date = $_POST['disable_date_slot_val'];
        $slot = $_POST['slot_time_val'];
        $field_type = $_POST['field_type_slot_val'];
        $reason = trim($_POST['reason_slot']);
        
        if (empty($reason)) {
            $reason = "Admin disabled";
        }
        
        $stmt = $conn->prepare("INSERT INTO disabled_slots (disable_date, slot_time, field_type, reason) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssss", $date, $slot, $field_type, $reason);
            if ($stmt->execute()) {
                $message = "✅ Slot disabled successfully!";
                if (!empty($reason)) {
                    $message .= " (Reason: " . htmlspecialchars($reason) . ")";
                }
            } else {
                $message = "❌ Failed to disable slot.";
            }
        } else {
            $message = "❌ Database error: Could not prepare statement.";
        }
        $active_tab = 'settings';
    }

    // Enable (Remove) Disabled Date
    if (isset($_POST['btn_enable_date_process'])) {
        $date = $_POST['enable_date_id'];
        $field_type = $_POST['enable_field_type_id'];
        $stmt = $conn->prepare("DELETE FROM disabled_dates WHERE disable_date = ? AND field_type = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $date, $field_type);
            if ($stmt->execute()) {
                $message = "✅ Field re-enabled successfully!";
            } else {
                $message = "❌ Failed to re-enable field.";
            }
        } else {
            $message = "❌ Database error: Could not prepare statement.";
        }
        $active_tab = 'settings';
    }

    // Enable (Remove) Disabled Slot
    if (isset($_POST['btn_enable_slot_process'])) {
        $id = (int)$_POST['enable_slot_db_id'];
        $stmt = $conn->prepare("DELETE FROM disabled_slots WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $message = "✅ Slot has been re-enabled!";
            } else {
                $message = "❌ Failed to re-enable slot.";
            }
        } else {
            $message = "❌ Database error: Could not prepare statement.";
        }
        $active_tab = 'settings';
    }
    
    // Acknowledge a specific booking
    if (isset($_POST['acknowledge_booking'])) {
        $booking_id = (int)$_POST['booking_id'];
        $stmt = $conn->prepare("UPDATE bookings SET notification = 0 WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $booking_id);
            $stmt->execute();
        }
        header("Location: dashboard.php?tab=bookings&month=" . urlencode($_GET['month'] ?? date('Y-m')) . "&refresh=" . $refresh_interval);
        exit;
    }
    
    // Acknowledge all bookings
    if (isset($_POST['acknowledge_all'])) {
        $conn->query("UPDATE bookings SET notification = 0 WHERE notification = 1");
        header("Location: dashboard.php?tab=bookings&month=" . urlencode($_GET['month'] ?? date('Y-m')) . "&refresh=" . $refresh_interval);
        exit;
    }
    
    // Set refresh interval
    if (isset($_POST['set_refresh_interval'])) {
        $new_interval = (int)$_POST['refresh_interval'];
        header("Location: dashboard.php?tab=" . $active_tab . "&month=" . urlencode($_GET['month'] ?? date('Y-m')) . "&refresh=" . $new_interval);
        exit;
    }
    
    // ====================== BOOKING MANAGEMENT ACTIONS ======================
    
    // Cancel Booking
    if (isset($_POST['cancel_booking'])) {
        $booking_id = (int)$_POST['booking_id'];
        $reason = trim($_POST['cancel_reason']);
        
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $booking_id);
            if ($stmt->execute()) {
                $message = "✅ Booking #$booking_id has been cancelled!";
                if (!empty($reason)) {
                    $message .= " Reason: " . htmlspecialchars($reason);
                }
            } else {
                $message = "❌ Failed to cancel booking.";
            }
        } else {
            $message = "❌ Database error: Could not prepare statement.";
        }
        $active_tab = 'manage';
    }
    
    // Amend Booking Date
    if (isset($_POST['amend_booking'])) {
        $booking_id = (int)$_POST['booking_id'];
        $new_date = $_POST['new_booking_date'];
        $new_slot = $_POST['new_slot_time'];
        $field_type = $_POST['field_type'];
        
        // Check if the new slot is available
        $check_stmt = $conn->prepare("SELECT id FROM bookings WHERE booking_date = ? AND slot_time = ? AND field_type = ? AND id != ?");
        if ($check_stmt) {
            $check_stmt->bind_param("sssi", $new_date, $new_slot, $field_type, $booking_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = "❌ The selected slot is already booked! Please choose another time.";
            } else {
                // Get the price for the slot
                $price_stmt = $conn->prepare("SELECT price FROM slot_prices WHERE slot_time = ?");
                if ($price_stmt) {
                    $price_stmt->bind_param("s", $new_slot);
                    $price_stmt->execute();
                    $price_result = $price_stmt->get_result();
                    $price = $price_result->fetch_assoc();
                    $new_price = $price ? $price['price'] : 0;
                    
                    // Update the booking
                    $update_stmt = $conn->prepare("UPDATE bookings SET booking_date = ?, slot_time = ?, price = ? WHERE id = ?");
                    if ($update_stmt) {
                        $update_stmt->bind_param("ssii", $new_date, $new_slot, $new_price, $booking_id);
                        if ($update_stmt->execute()) {
                            $message = "✅ Booking #$booking_id has been amended to " . date('d M Y', strtotime($new_date)) . " at $new_slot!";
                        } else {
                            $message = "❌ Failed to amend booking.";
                        }
                    } else {
                        $message = "❌ Database error: Could not prepare update statement.";
                    }
                } else {
                    $message = "❌ Database error: Could not get price information.";
                }
            }
        } else {
            $message = "❌ Database error: Could not check availability.";
        }
        $active_tab = 'manage';
    }
    
    // Create Advance Booking
    if (isset($_POST['create_booking'])) {
        $customer_name = trim($_POST['customer_name']);
        $customer_phone = trim($_POST['customer_phone']);
        $customer_email = trim($_POST['customer_email']);
        $booking_date = $_POST['booking_date'];
        $slot_time = $_POST['slot_time'];
        $field_type = $_POST['field_type'];
        
        // Validate inputs
        if (empty($customer_name) || empty($customer_phone) || empty($booking_date) || empty($slot_time)) {
            $message = "❌ Please fill in all required fields (Name, Phone, Date, Slot)";
        } else {
            // Check if the slot is available
            $check_stmt = $conn->prepare("SELECT id FROM bookings WHERE booking_date = ? AND slot_time = ? AND field_type = ?");
            if ($check_stmt) {
                $check_stmt->bind_param("sss", $booking_date, $slot_time, $field_type);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "❌ This slot is already booked! Please choose another time.";
                } else {
                    // Check if date is disabled
                    $disabled_date_check = $conn->prepare("SELECT disable_date FROM disabled_dates WHERE disable_date = ? AND field_type = ?");
                    $date_disabled = false;
                    if ($disabled_date_check) {
                        $disabled_date_check->bind_param("ss", $booking_date, $field_type);
                        $disabled_date_check->execute();
                        $date_disabled = $disabled_date_check->get_result()->num_rows > 0;
                    }
                    
                    // Check if slot is disabled
                    $disabled_slot_check = $conn->prepare("SELECT id FROM disabled_slots WHERE disable_date = ? AND slot_time = ? AND field_type = ?");
                    $slot_disabled = false;
                    if ($disabled_slot_check) {
                        $disabled_slot_check->bind_param("sss", $booking_date, $slot_time, $field_type);
                        $disabled_slot_check->execute();
                        $slot_disabled = $disabled_slot_check->get_result()->num_rows > 0;
                    }
                    
                    if ($date_disabled || $slot_disabled) {
                        $message = "❌ This date or slot is currently disabled!";
                    } else {
                        // Get the price for the slot
                        $price_stmt = $conn->prepare("SELECT price FROM slot_prices WHERE slot_time = ?");
                        if ($price_stmt) {
                            $price_stmt->bind_param("s", $slot_time);
                            $price_stmt->execute();
                            $price_result = $price_stmt->get_result();
                            $price = $price_result->fetch_assoc();
                            $amount = $price ? $price['price'] : 0;
                            
                            // Insert the booking
                            $insert_stmt = $conn->prepare("INSERT INTO bookings (customer_name, customer_phone, customer_email, booking_date, slot_time, field_type, price, notification, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                            if ($insert_stmt) {
                                $insert_stmt->bind_param("ssssssi", $customer_name, $customer_phone, $customer_email, $booking_date, $slot_time, $field_type, $amount);
                                
                                if ($insert_stmt->execute()) {
                                    $booking_id = $conn->insert_id;
                                    $message = "✅ Advance booking created successfully! Booking #$booking_id for " . htmlspecialchars($customer_name) . " on " . date('d M Y', strtotime($booking_date)) . " at $slot_time";
                                } else {
                                    $message = "❌ Failed to create booking. Please try again.";
                                }
                            } else {
                                $message = "❌ Database error: Could not prepare insert statement.";
                            }
                        } else {
                            $message = "❌ Database error: Could not get price information.";
                        }
                    }
                }
            } else {
                $message = "❌ Database error: Could not check availability.";
            }
        }
        $active_tab = 'manage';
    }
}

// ====================== LOAD DATA FOR BOOKINGS ======================
$selected_month = $_GET['month'] ?? date('Y-m');

$stmt = $conn->prepare("SELECT * FROM bookings WHERE DATE_FORMAT(booking_date, '%Y-%m') = ? ORDER BY booking_date DESC, slot_time");
if ($stmt) {
    $stmt->bind_param("s", $selected_month);
    $stmt->execute();
    $bookings = $stmt->get_result();
} else {
    $bookings = false;
}

$disabled_dates = $conn->query("SELECT * FROM disabled_dates ORDER BY disable_date DESC");
$disabled_slots = $conn->query("SELECT ds.*, DATE_FORMAT(ds.disable_date, '%d %b %Y') as formatted_date FROM disabled_slots ds ORDER BY ds.disable_date DESC");

// ====================== ANALYTICS DATA ======================
$monthly_stats = $conn->query("
    SELECT 
        DATE_FORMAT(booking_date, '%Y-%m') as month,
        DATE_FORMAT(booking_date, '%M %Y') as month_name,
        COUNT(*) as total_bookings,
        SUM(price) as total_income,
        SUM(CASE WHEN field_type = '7side' THEN 1 ELSE 0 END) as bookings_7side,
        SUM(CASE WHEN field_type = '9side' THEN 1 ELSE 0 END) as bookings_9side
    FROM bookings 
    GROUP BY DATE_FORMAT(booking_date, '%Y-%m')
    ORDER BY month DESC
");

$overall_result = $conn->query("
    SELECT 
        COUNT(*) as total_all_bookings,
        SUM(price) as total_all_income,
        SUM(CASE WHEN field_type = '7side' THEN 1 ELSE 0 END) as total_7side,
        SUM(CASE WHEN field_type = '9side' THEN 1 ELSE 0 END) as total_9side
    FROM bookings
");
$overall_stats = $overall_result ? $overall_result->fetch_assoc() : ['total_all_bookings' => 0, 'total_all_income' => 0, 'total_7side' => 0, 'total_9side' => 0];

$current_result = $conn->query("
    SELECT 
        COUNT(*) as current_bookings,
        SUM(price) as current_income
    FROM bookings 
    WHERE DATE_FORMAT(booking_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
");
$current_month_stats = $current_result ? $current_result->fetch_assoc() : ['current_bookings' => 0, 'current_income' => 0];

$next_result = $conn->query("
    SELECT 
        COUNT(*) as next_bookings,
        SUM(price) as next_income
    FROM bookings 
    WHERE DATE_FORMAT(booking_date, '%Y-%m') = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 MONTH), '%Y-%m')
");
$next_month_stats = $next_result ? $next_result->fetch_assoc() : ['next_bookings' => 0, 'next_income' => 0];

$all_bookings = $conn->query("SELECT * FROM bookings ORDER BY booking_date DESC, slot_time DESC LIMIT 100");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard • Astromomo Football FIeldStreet 7side</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap');
    .tail-container { font-family: 'Inter', sans-serif; }
    .display-font { font-family: 'Space Grotesk', sans-serif; }
    .tab-active { border-bottom: 2px solid #4ade80; color: #4ade80; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    @keyframes glow {
        0%, 100% { box-shadow: 0 0 5px rgba(74, 222, 128, 0.3); }
        50% { box-shadow: 0 0 15px rgba(74, 222, 128, 0.6); }
    }
    .notification-badge { animation: pulse 2s infinite; }
    .notification-message { animation: slideIn 0.3s ease-out; }
    
    .date-input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    .date-input-wrapper input[type="date"] {
        width: 100%;
        padding-left: 2.5rem;
        cursor: pointer;
    }
    .date-input-wrapper .calendar-icon {
        position: absolute;
        left: 0.75rem;
        color: #6b7280;
        font-size: 1rem;
        z-index: 2;
        cursor: pointer;
        transition: color 0.2s ease;
        pointer-events: auto;
    }
    .date-input-wrapper .calendar-icon:hover { color: #4ade80; }
    .date-input-wrapper .calendar-icon:active { transform: scale(0.95); }
    .date-input-wrapper input[type="date"]:hover { border-color: #4ade80; }
    input[type="date"]::-webkit-calendar-picker-indicator {
        opacity: 0;
        position: absolute;
        right: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
    }
    .reason-text {
        font-size: 0.7rem;
        color: #9ca3af;
        margin-top: 0.25rem;
        font-style: italic;
    }
    .new-booking-row {
        background-color: rgba(74, 222, 128, 0.1);
        border-left: 3px solid #f59e0b;
        animation: glow 2s infinite;
    }
    .new-booking-row:hover { background-color: rgba(74, 222, 128, 0.15); }
    .acknowledge-btn { transition: all 0.2s ease; }
    .acknowledge-btn:hover { transform: scale(1.05); }
    .refresh-indicator {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        padding: 8px 16px;
        border-radius: 50px;
        font-size: 12px;
        z-index: 1000;
        border: 1px solid rgba(74, 222, 128, 0.3);
    }
    .countdown { font-family: monospace; font-weight: bold; color: #4ade80; }
    .stat-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
    .modal { transition: opacity 0.3s ease; }
    .modal-content { animation: slideIn 0.3s ease-out; }
  </style>
</head>
<body class="tail-container bg-zinc-950 text-white">

<nav class="bg-black py-4 border-b border-green-500/20 sticky top-0 z-50">
  <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
    <div class="flex items-center gap-4">
      <div class="display-font text-2xl font-semibold">Admin Panel</div>
    </div>
    <div class="flex items-center gap-4">
      <?php 
      $unread_count_badge = $conn->query("SELECT COUNT(*) FROM bookings WHERE notification = 1");
      $unread_count_badge_val = ($unread_count_badge && $unread_count_badge->num_rows > 0) ? $unread_count_badge->fetch_row()[0] : 0;
      if ($unread_count_badge_val > 0): 
      ?>
        <div class="bg-red-500 text-white px-3 py-1.5 rounded-full text-xs font-bold notification-badge">
          <i class="fas fa-bell"></i> <?= $unread_count_badge_val ?>
        </div>
      <?php endif; ?>
      <a href="logout.php" class="text-red-400 hover:text-red-300 text-sm"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>
</nav>

<div class="max-w-7xl mx-auto px-6 py-8">

  <div class="flex gap-8 border-b border-zinc-800 mb-8 overflow-x-auto">
    <a href="?tab=bookings&month=<?= urlencode($selected_month) ?>&refresh=<?= $refresh_interval ?>" 
       class="pb-3 px-2 text-lg font-medium transition-all whitespace-nowrap <?= $active_tab === 'bookings' ? 'tab-active text-green-400' : 'text-zinc-400' ?>">
      <i class="fas fa-calendar-alt mr-2"></i> Bookings
    </a>
    <a href="?tab=manage&refresh=<?= $refresh_interval ?>" 
       class="pb-3 px-2 text-lg font-medium transition-all whitespace-nowrap <?= $active_tab === 'manage' ? 'tab-active text-green-400' : 'text-zinc-400' ?>">
      <i class="fas fa-edit mr-2"></i> Manage Bookings
    </a>
    <a href="?tab=analytics&refresh=<?= $refresh_interval ?>" 
       class="pb-3 px-2 text-lg font-medium transition-all whitespace-nowrap <?= $active_tab === 'analytics' ? 'tab-active text-green-400' : 'text-zinc-400' ?>">
      <i class="fas fa-chart-line mr-2"></i> Analytics
    </a>
    <a href="?tab=settings&refresh=<?= $refresh_interval ?>" 
       class="pb-3 px-2 text-lg font-medium transition-all whitespace-nowrap <?= $active_tab === 'settings' ? 'tab-active text-green-400' : 'text-zinc-400' ?>">
      <i class="fas fa-sliders-h mr-2"></i> Settings
    </a>
  </div>

  <?php if ($message): ?>
    <div class="bg-yellow-600/20 border-l-4 border-yellow-500 text-yellow-400 p-4 rounded-xl mb-6 text-sm notification-message">
      <?= $message ?>
    </div>
  <?php endif; ?>

  <?php if ($active_tab === 'bookings'): ?>
    <!-- Bookings Tab Content -->
    <div class="flex justify-between items-center mb-6 flex-wrap gap-4">
      <h1 class="display-font text-3xl font-semibold">Booking Records</h1>
      <div class="flex items-center gap-3 flex-wrap">
        <div class="relative">
          <form method="POST" class="inline-block">
            <select name="refresh_interval" onchange="this.form.submit()" class="bg-zinc-900 border border-zinc-700 rounded-lg px-3 py-2 text-sm focus:border-green-500">
              <option value="10" <?= $refresh_interval == 10 ? 'selected' : '' ?>>🔄 10 seconds</option>
              <option value="30" <?= $refresh_interval == 30 ? 'selected' : '' ?>>🔄 30 seconds</option>
              <option value="60" <?= $refresh_interval == 60 ? 'selected' : '' ?>>🔄 1 minute</option>
              <option value="300" <?= $refresh_interval == 300 ? 'selected' : '' ?>>🔄 5 minutes</option>
            </select>
            <input type="hidden" name="set_refresh_interval" value="1">
          </form>
        </div>
        
        <?php 
        $unread_ack = $conn->query("SELECT COUNT(*) FROM bookings WHERE notification = 1");
        $unread_count_ack = ($unread_ack && $unread_ack->num_rows > 0) ? $unread_ack->fetch_row()[0] : 0;
        if ($unread_count_ack > 0): 
        ?>
        <form method="POST" onsubmit="return confirm('Acknowledge all <?= $unread_count_ack ?> new booking(s)?');">
          <button type="submit" name="acknowledge_all" 
                  class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
            <i class="fas fa-check-double mr-2"></i> Acknowledge All (<?= $unread_count_ack ?>)
          </button>
        </form>
        <?php endif; ?>
        
        <form method="GET" class="flex items-center gap-3">
          <input type="hidden" name="tab" value="bookings">
          <input type="hidden" name="refresh" value="<?= $refresh_interval ?>">
          <select name="month" onchange="this.form.submit()" class="bg-zinc-900 border border-zinc-700 rounded-xl px-4 py-2 text-sm focus:border-green-500">
            <?php 
            for ($m=1; $m<=12; $m++) {
              $month_val = date('Y-m', strtotime("2026-$m-01"));
              $month_name = date("F Y", strtotime($month_val));
              $is_current = ($month_val == date('Y-m'));
              $is_next = ($month_val == date('Y-m', strtotime('+1 month')));
              $style = '';
              if ($is_current) $style = 'style="background-color: #1e3a8a;"';
              if ($is_next) $style = 'style="background-color: #5b21b6;"';
              echo "<option value='$month_val' $style " . ($selected_month == $month_val ? 'selected' : '') . ">$month_name</option>";
            }
            ?>
          </select>
        </form>
      </div>
    </div>

    <div class="bg-zinc-900 rounded-2xl overflow-hidden">
      <table class="w-full text-sm text-left">
        <thead class="bg-zinc-800 text-zinc-400">
          <tr>
            <th class="px-5 py-4">Status</th>
            <th class="px-5 py-4">Date</th>
            <th class="px-5 py-4">Field</th>
            <th class="px-5 py-4">Slot</th>
            <th class="px-5 py-4">Customer</th>
            <th class="px-5 py-4 text-right">Amount</th>
            <th class="px-5 py-4 text-center">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-800">
          <?php if ($bookings && $bookings->num_rows > 0): ?>
            <?php while ($row = $bookings->fetch_assoc()): 
              $booking_month = date('Y-m', strtotime($row['booking_date']));
              $row_class = ($row['notification'] == 1) ? 'new-booking-row' : '';
            ?>
              <tr class="hover:bg-zinc-800/50 <?= $row_class ?>">
                <td class="px-5 py-4">
                  <?php if ($row['notification'] == 1): ?>
                    <span class="bg-yellow-500/20 text-yellow-400 text-xs px-2 py-1 rounded-full animate-pulse font-semibold">
                      <i class="fas fa-bell mr-1"></i> NEW
                    </span>
                  <?php else: ?>
                    <span class="text-zinc-600"><i class="fas fa-check-circle mr-1"></i> Acknowledged</span>
                  <?php endif; ?>
                 </td>
                <td class="px-5 py-4">
                  <?= date('d M Y', strtotime($row['booking_date'])) ?>
                  <?php if ($booking_month == date('Y-m')): ?>
                    <span class="ml-2 text-xs bg-blue-500/20 text-blue-400 px-2 py-0.5 rounded-full">Current</span>
                  <?php elseif ($booking_month == date('Y-m', strtotime('+1 month'))): ?>
                    <span class="ml-2 text-xs bg-purple-500/20 text-purple-400 px-2 py-0.5 rounded-full">Next</span>
                  <?php endif; ?>
                 </td>
                <td class="px-5 py-4"><?= ucfirst($row['field_type']) ?>-side</td>
                <td class="px-5 py-4 font-mono"><?= htmlspecialchars($row['slot_time']) ?></td>
                <td class="px-5 py-4"><?= htmlspecialchars($row['customer_name']) ?></td>
                <td class="px-5 py-4 text-right font-bold text-green-400">RM<?= $row['price'] ?></td>
                <td class="px-5 py-4 text-center">
                  <?php if ($row['notification'] == 1): ?>
                    <form method="POST" style="display: inline;">
                      <input type="hidden" name="booking_id" value="<?= $row['id'] ?>">
                      <input type="hidden" name="month" value="<?= $selected_month ?>">
                      <button type="submit" name="acknowledge_booking" 
                              class="acknowledge-btn bg-green-600 hover:bg-green-700 text-white text-xs px-3 py-1.5 rounded-lg transition-all font-semibold">
                        <i class="fas fa-check-circle mr-1"></i> Acknowledge
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-xs text-zinc-600">✓ Done</span>
                  <?php endif; ?>
                 </td>
               </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="7" class="px-5 py-12 text-center text-zinc-500">No bookings found for this month.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
  <?php endif; ?>

  <?php if ($active_tab === 'manage'): ?>
    <!-- Manage Bookings Tab Content -->
    <div class="space-y-8">
      <div class="bg-zinc-900 rounded-2xl p-6 border border-green-500/30">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-2xl font-bold text-green-400">
            <i class="fas fa-plus-circle mr-2"></i> Create Advance Booking
          </h2>
          <span class="text-xs text-zinc-500">All fields with * are required</span>
        </div>
        
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-zinc-400 mb-2">Customer Name *</label>
            <input type="text" name="customer_name" required 
                   class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 focus:border-green-500 focus:outline-none transition-colors">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-zinc-400 mb-2">Phone Number *</label>
            <input type="tel" name="customer_phone" required 
                   class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 focus:border-green-500 focus:outline-none transition-colors">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-zinc-400 mb-2">Email Address</label>
            <input type="email" name="customer_email" 
                   class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 focus:border-green-500 focus:outline-none transition-colors">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-zinc-400 mb-2">Booking Date *</label>
            <div class="date-input-wrapper">
              <i class="fas fa-calendar-alt calendar-icon" onclick="this.nextElementSibling.showPicker();"></i>
              <input type="date" name="booking_date" required 
                     class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 pl-10 focus:border-green-500 focus:outline-none transition-colors">
            </div>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-zinc-400 mb-2">Slot Time *</label>
            <select name="slot_time" required class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 focus:border-green-500 focus:outline-none">
              <?php $slots = $conn->query("SELECT * FROM slot_prices ORDER BY slot_time"); while($s = $slots->fetch_assoc()): ?>
                <option value="<?= $s['slot_time'] ?>"><?= $s['slot_time'] ?> (RM<?= $s['price'] ?>)</option>
              <?php endwhile; ?>
            </select>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-zinc-400 mb-2">Field Type *</label>
            <select name="field_type" required class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 focus:border-green-500 focus:outline-none">
              <option value="7side">7-a-side</option>
              <option value="9side">9-a-side</option>
            </select>
          </div>
          
          <div class="md:col-span-2">
            <button type="submit" name="create_booking" 
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-lg transition-colors">
              <i class="fas fa-save mr-2"></i> Create Booking
            </button>
          </div>
        </form>
      </div>
      
      <div class="bg-zinc-900 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-zinc-800">
          <h2 class="text-xl font-semibold">
            <i class="fas fa-list mr-2 text-green-400"></i> Manage Existing Bookings
          </h2>
          <p class="text-sm text-zinc-500 mt-1">Cancel or amend existing bookings</p>
        </div>
        
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="bg-zinc-800 text-zinc-400">
              <tr>
                <th class="px-5 py-3">ID</th>
                <th class="px-5 py-3">Date</th>
                <th class="px-5 py-3">Field</th>
                <th class="px-5 py-3">Slot</th>
                <th class="px-5 py-3">Customer</th>
                <th class="px-5 py-3">Phone</th>
                <th class="px-5 py-3">Amount</th>
                <th class="px-5 py-3 text-center">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800">
              <?php if ($all_bookings && $all_bookings->num_rows > 0): ?>
                <?php while ($booking = $all_bookings->fetch_assoc()): ?>
                  <tr class="hover:bg-zinc-800/50">
                    <td class="px-5 py-4 font-mono text-green-400">#<?= $booking['id'] ?></td>
                    <td class="px-5 py-4"><?= date('d M Y', strtotime($booking['booking_date'])) ?></td>
                    <td class="px-5 py-4"><?= ucfirst($booking['field_type']) ?>-side</td>
                    <td class="px-5 py-4 font-mono"><?= htmlspecialchars($booking['slot_time']) ?></td>
                    <td class="px-5 py-4"><?= htmlspecialchars($booking['customer_name']) ?></td>
                    <td class="px-5 py-4"><?= htmlspecialchars($booking['customer_phone']) ?></td>
                    <td class="px-5 py-4 font-bold text-green-400">RM<?= $booking['price'] ?></td>
                    <td class="px-5 py-4 text-center">
                      <div class="flex gap-2 justify-center">
                        <button onclick="openCancelModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['customer_name']) ?>')" 
                                class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-lg text-xs transition-colors">
                          <i class="fas fa-trash mr-1"></i> Cancel
                        </button>
                        <button onclick="openAmendModal(<?= $booking['id'] ?>, '<?= $booking['booking_date'] ?>', '<?= $booking['slot_time'] ?>', '<?= $booking['field_type'] ?>')" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg text-xs transition-colors">
                          <i class="fas fa-edit mr-1"></i> Amend
                        </button>
                      </div>
                    </td>
                   </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="8" class="px-5 py-12 text-center text-zinc-500">No bookings found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    
    <!-- Cancel Modal -->
    <div id="cancelModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden modal">
      <div class="bg-zinc-900 rounded-2xl max-w-md w-full mx-4 modal-content">
        <div class="p-6">
          <h3 class="text-xl font-bold text-red-400 mb-4">
            <i class="fas fa-exclamation-triangle mr-2"></i> Cancel Booking
          </h3>
          <form method="POST">
            <input type="hidden" name="booking_id" id="cancel_booking_id">
            <p class="text-zinc-300 mb-4">Are you sure you want to cancel booking for <span id="cancel_customer_name" class="font-semibold text-white"></span>?</p>
            <div class="mb-4">
              <label class="block text-sm font-medium text-zinc-400 mb-2">Reason for cancellation (optional)</label>
              <textarea name="cancel_reason" rows="3" class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 focus:border-green-500 focus:outline-none" placeholder="Enter reason..."></textarea>
            </div>
            <div class="flex gap-3">
              <button type="button" onclick="closeCancelModal()" class="flex-1 bg-zinc-700 hover:bg-zinc-600 text-white py-2 rounded-lg transition-colors">Cancel</button>
              <button type="submit" name="cancel_booking" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg transition-colors">Confirm Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    
    <!-- Amend Modal -->
    <div id="amendModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden modal">
      <div class="bg-zinc-900 rounded-2xl max-w-md w-full mx-4 modal-content">
        <div class="p-6">
          <h3 class="text-xl font-bold text-blue-400 mb-4">
            <i class="fas fa-edit mr-2"></i> Amend Booking
          </h3>
          <form method="POST">
            <input type="hidden" name="booking_id" id="amend_booking_id">
            <input type="hidden" name="field_type" id="amend_field_type">
            <div class="mb-4">
              <label class="block text-sm font-medium text-zinc-400 mb-2">New Booking Date *</label>
              <div class="date-input-wrapper">
                <i class="fas fa-calendar-alt calendar-icon" onclick="this.nextElementSibling.showPicker();"></i>
                <input type="date" name="new_booking_date" id="amend_date" required 
                       class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 pl-10 focus:border-green-500 focus:outline-none">
              </div>
            </div>
            <div class="mb-4">
              <label class="block text-sm font-medium text-zinc-400 mb-2">New Slot Time *</label>
              <select name="new_slot_time" id="amend_slot" required class="w-full bg-black border border-zinc-700 rounded-lg px-4 py-2 focus:border-green-500 focus:outline-none">
                <?php $slots = $conn->query("SELECT * FROM slot_prices ORDER BY slot_time"); while($s = $slots->fetch_assoc()): ?>
                  <option value="<?= $s['slot_time'] ?>"><?= $s['slot_time'] ?> (RM<?= $s['price'] ?>)</option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="flex gap-3">
              <button type="button" onclick="closeAmendModal()" class="flex-1 bg-zinc-700 hover:bg-zinc-600 text-white py-2 rounded-lg transition-colors">Cancel</button>
              <button type="submit" name="amend_booking" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg transition-colors">Confirm Amend</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    
    <script>
      function openCancelModal(bookingId, customerName) {
        document.getElementById('cancel_booking_id').value = bookingId;
        document.getElementById('cancel_customer_name').textContent = customerName;
        document.getElementById('cancelModal').classList.remove('hidden');
      }
      
      function closeCancelModal() {
        document.getElementById('cancelModal').classList.add('hidden');
      }
      
      function openAmendModal(bookingId, currentDate, currentSlot, fieldType) {
        document.getElementById('amend_booking_id').value = bookingId;
        document.getElementById('amend_field_type').value = fieldType;
        document.getElementById('amend_date').value = currentDate;
        document.getElementById('amend_slot').value = currentSlot;
        document.getElementById('amendModal').classList.remove('hidden');
      }
      
      function closeAmendModal() {
        document.getElementById('amendModal').classList.add('hidden');
      }
      
      window.onclick = function(event) {
        const cancelModal = document.getElementById('cancelModal');
        const amendModal = document.getElementById('amendModal');
        if (event.target === cancelModal) closeCancelModal();
        if (event.target === amendModal) closeAmendModal();
      }
    </script>
  <?php endif; ?>

  <?php if ($active_tab === 'analytics'): ?>
    <!-- Analytics Tab Content (simplified) -->
    <div class="space-y-6">
      <div class="flex justify-between items-center">
        <h1 class="display-font text-3xl font-semibold">Analytics Dashboard</h1>
        <div class="relative">
          <form method="POST" class="inline-block">
            <select name="refresh_interval" onchange="this.form.submit()" class="bg-zinc-900 border border-zinc-700 rounded-lg px-3 py-2 text-sm focus:border-green-500">
              <option value="10" <?= $refresh_interval == 10 ? 'selected' : '' ?>>🔄 10 seconds</option>
              <option value="30" <?= $refresh_interval == 30 ? 'selected' : '' ?>>🔄 30 seconds</option>
              <option value="60" <?= $refresh_interval == 60 ? 'selected' : '' ?>>🔄 1 minute</option>
              <option value="300" <?= $refresh_interval == 300 ? 'selected' : '' ?>>🔄 5 minutes</option>
            </select>
            <input type="hidden" name="set_refresh_interval" value="1">
          </form>
        </div>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-6 stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-blue-100 text-sm">Total Bookings</p>
              <p class="text-3xl font-bold text-white mt-2"><?= number_format($overall_stats['total_all_bookings']) ?></p>
            </div>
            <i class="fas fa-calendar-check text-4xl text-blue-200 opacity-75"></i>
          </div>
        </div>
        
        <div class="bg-gradient-to-br from-green-600 to-green-800 rounded-2xl p-6 stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-green-100 text-sm">Total Revenue</p>
              <p class="text-3xl font-bold text-white mt-2">RM <?= number_format($overall_stats['total_all_income'], 2) ?></p>
            </div>
            <i class="fas fa-money-bill-wave text-4xl text-green-200 opacity-75"></i>
          </div>
        </div>
        
        <div class="bg-gradient-to-br from-purple-600 to-purple-800 rounded-2xl p-6 stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-purple-100 text-sm">7-a-side Bookings</p>
              <p class="text-3xl font-bold text-white mt-2"><?= number_format($overall_stats['total_7side']) ?></p>
            </div>
            <i class="fas fa-futbol text-4xl text-purple-200 opacity-75"></i>
          </div>
        </div>
        
        <div class="bg-gradient-to-br from-orange-600 to-orange-800 rounded-2xl p-6 stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-orange-100 text-sm">9-a-side Bookings</p>
              <p class="text-3xl font-bold text-white mt-2"><?= number_format($overall_stats['total_9side']) ?></p>
            </div>
            <i class="fas fa-futbol text-4xl text-orange-200 opacity-75"></i>
          </div>
        </div>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="bg-zinc-900 rounded-2xl p-6 border border-blue-500/30">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-blue-400">
              <i class="fas fa-calendar-day mr-2"></i> Current Month
            </h3>
            <span class="text-xs text-zinc-500"><?= date('F Y') ?></span>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-zinc-400 text-sm">Bookings</p>
              <p class="text-2xl font-bold text-white"><?= number_format($current_month_stats['current_bookings'] ?? 0) ?></p>
            </div>
            <div>
              <p class="text-zinc-400 text-sm">Revenue</p>
              <p class="text-2xl font-bold text-green-400">RM <?= number_format($current_month_stats['current_income'] ?? 0, 2) ?></p>
            </div>
          </div>
        </div>
        
        <div class="bg-zinc-900 rounded-2xl p-6 border border-purple-500/30">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-purple-400">
              <i class="fas fa-calendar-week mr-2"></i> Next Month
            </h3>
            <span class="text-xs text-zinc-500"><?= date('F Y', strtotime('+1 month')) ?></span>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-zinc-400 text-sm">Bookings</p>
              <p class="text-2xl font-bold text-white"><?= number_format($next_month_stats['next_bookings'] ?? 0) ?></p>
            </div>
            <div>
              <p class="text-zinc-400 text-sm">Revenue</p>
              <p class="text-2xl font-bold text-green-400">RM <?= number_format($next_month_stats['next_income'] ?? 0, 2) ?></p>
            </div>
          </div>
        </div>
      </div>
      
      <div class="bg-zinc-900 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-zinc-800">
          <h3 class="text-lg font-semibold">
            <i class="fas fa-chart-bar mr-2 text-green-400"></i> Monthly Breakdown
          </h3>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="bg-zinc-800 text-zinc-400">
              <tr>
                <th class="px-6 py-3">Month</th>
                <th class="px-6 py-3 text-center">Total Bookings</th>
                <th class="px-6 py-3 text-center">7-a-side</th>
                <th class="px-6 py-3 text-center">9-a-side</th>
                <th class="px-6 py-3 text-right">Total Revenue</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800">
              <?php if ($monthly_stats && $monthly_stats->num_rows > 0): ?>
                <?php while ($stat = $monthly_stats->fetch_assoc()): 
                  $is_current = ($stat['month'] == date('Y-m'));
                  $row_class = $is_current ? 'bg-blue-500/10' : '';
                ?>
                  <tr class="hover:bg-zinc-800/50 <?= $row_class ?>">
                    <td class="px-6 py-4 font-medium">
                      <?= htmlspecialchars($stat['month_name']) ?>
                      <?php if ($is_current): ?>
                        <span class="ml-2 text-xs bg-blue-500/20 text-blue-400 px-2 py-0.5 rounded-full">Current</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                      <span class="font-semibold text-white"><?= number_format($stat['total_bookings']) ?></span>
                    </td>
                    <td class="px-6 py-4 text-center">
                      <span class="text-purple-400"><?= number_format($stat['bookings_7side']) ?></span>
                    </td>
                    <td class="px-6 py-4 text-center">
                      <span class="text-orange-400"><?= number_format($stat['bookings_9side']) ?></span>
                    </td>
                    <td class="px-6 py-4 text-right font-bold text-green-400">
                      RM <?= number_format($stat['total_income'], 2) ?>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="5" class="px-6 py-12 text-center text-zinc-500">No booking data available</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($active_tab === 'settings'): ?>
    <!-- Settings Tab Content -->
    <div class="grid md:grid-cols-3 gap-5 mb-8">
        <div class="bg-zinc-900 p-5 rounded-2xl">
          <h3 class="text-amber-400 font-bold mb-4">Slot Price</h3>
          <form method="POST">
            <select name="slot_time" class="w-full bg-black border border-zinc-800 p-2 rounded mb-3">
              <?php $prices = $conn->query("SELECT * FROM slot_prices"); while($p = $prices->fetch_assoc()): ?>
                <option value="<?= $p['slot_time'] ?>"><?= $p['slot_time'] ?> (RM<?= $p['price'] ?>)</option>
              <?php endwhile; ?>
            </select>
            <input type="number" name="new_price" placeholder="Price" required class="w-full bg-black border border-zinc-800 p-2 rounded mb-3">
            <button type="submit" name="change_price" class="w-full bg-amber-500 text-black font-bold p-2 rounded">Update</button>
          </form>
        </div>

        <div class="bg-zinc-900 p-5 rounded-2xl">
          <h3 class="text-red-400 font-bold mb-4">Disable Date</h3>
          <form method="POST">
            <div class="date-input-wrapper mb-3">
              <i class="fas fa-calendar-alt calendar-icon" onclick="this.nextElementSibling.showPicker();"></i>
              <input type="date" name="disable_date_value" required 
                     class="w-full bg-black border border-zinc-800 p-2 rounded focus:border-green-500 focus:outline-none transition-colors">
            </div>
            <select name="field_type" class="w-full bg-black border border-zinc-800 p-2 rounded mb-3">
              <option value="7side">7-a-side</option>
              <option value="9side">9-a-side</option>
            </select>
            <textarea name="reason_date" rows="2" placeholder="Reason for disabling (optional)" 
                      class="w-full bg-black border border-zinc-800 p-2 rounded mb-3 focus:border-green-500 focus:outline-none transition-colors text-sm"></textarea>
            <button type="submit" name="btn_disable_date" class="w-full bg-red-600 font-bold p-2 rounded hover:bg-red-700 transition-colors">Disable Date</button>
          </form>
        </div>

        <div class="bg-zinc-900 p-5 rounded-2xl">
          <h3 class="text-red-400 font-bold mb-4">Disable Slot</h3>
          <form method="POST">
            <div class="date-input-wrapper mb-3">
              <i class="fas fa-calendar-alt calendar-icon" onclick="this.nextElementSibling.showPicker();"></i>
              <input type="date" name="disable_date_slot_val" required 
                     class="w-full bg-black border border-zinc-800 p-2 rounded focus:border-green-500 focus:outline-none transition-colors">
            </div>
            <select name="slot_time_val" class="w-full bg-black border border-zinc-800 p-2 rounded mb-3">
              <?php $slots = $conn->query("SELECT * FROM slot_prices"); while($s = $slots->fetch_assoc()): ?>
                <option value="<?= $s['slot_time'] ?>"><?= $s['slot_time'] ?></option>
              <?php endwhile; ?>
            </select>
            <select name="field_type_slot_val" class="w-full bg-black border border-zinc-800 p-2 rounded mb-3">
              <option value="7side">7-a-side</option>
              <option value="9side">9-a-side</option>
            </select>
            <textarea name="reason_slot" rows="2" placeholder="Reason for disabling (optional)" 
                      class="w-full bg-black border border-zinc-800 p-2 rounded mb-3 focus:border-green-500 focus:outline-none transition-colors text-sm"></textarea>
            <button type="submit" name="btn_disable_slot" class="w-full bg-red-600 font-bold p-2 rounded hover:bg-red-700 transition-colors">Disable Slot</button>
          </form>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
      <div class="bg-zinc-900 p-6 rounded-2xl border border-zinc-800">
        <h3 class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-5 flex items-center gap-2">
          <i class="fas fa-calendar-times text-red-500"></i> Disabled Dates
        </h3>
        <?php if($disabled_dates && $disabled_dates->num_rows > 0): ?>
          <div class="space-y-3">
            <?php while($d = $disabled_dates->fetch_assoc()): ?>
              <form method="POST" onsubmit="return confirm('Re-enable this date?');">
                <input type="hidden" name="enable_date_id" value="<?= $d['disable_date'] ?>">
                <input type="hidden" name="enable_field_type_id" value="<?= $d['field_type'] ?>">
                <button type="submit" name="btn_enable_date_process" 
                        class="w-full text-left bg-zinc-800/30 border border-zinc-700/50 p-3 rounded-xl hover:border-green-500/50 hover:bg-zinc-800 transition-all group">
                  <div class="flex items-center justify-between">
                    <div class="flex flex-col">
                      <span class="text-xs text-zinc-500 font-medium uppercase"><?= $d['field_type'] === '7side' ? '7-a-side' : '9-a-side' ?></span>
                      <span class="text-zinc-300 font-semibold"><?= date('D, d M Y', strtotime($d['disable_date'])) ?></span>
                      <?php if(!empty($d['reason'])): ?>
                        <span class="reason-text"><i class="fas fa-comment mr-1"></i><?= htmlspecialchars($d['reason']) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="bg-green-500/10 text-green-400 px-4 py-2 rounded-lg group-hover:bg-green-500 group-hover:text-black transition-all flex items-center gap-2">
                      <i class="fas fa-calendar-check"></i>
                      <span class="text-xs font-bold uppercase">Enable</span>
                    </div>
                  </div>
                </button>
              </form>
            <?php endwhile; ?>
          </div>
        <?php else: ?>
          <div class="py-10 text-center border-2 border-dashed border-zinc-800 rounded-2xl text-zinc-600 text-sm">No dates disabled</div>
        <?php endif; ?>
      </div>

      <div class="bg-zinc-900 p-6 rounded-2xl border border-zinc-800">
        <h3 class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-5 flex items-center gap-2">
          <i class="fas fa-clock text-red-500"></i> Disabled Slots
        </h3>
        <?php if($disabled_slots && $disabled_slots->num_rows > 0): ?>
          <div class="space-y-3">
            <?php while($ds = $disabled_slots->fetch_assoc()): ?>
              <form method="POST" onsubmit="return confirm('Re-enable this slot?');">
                <input type="hidden" name="enable_slot_db_id" value="<?= $ds['id'] ?>">
                <button type="submit" name="btn_enable_slot_process" 
                        class="w-full text-left bg-zinc-800/30 border border-zinc-700/50 p-3 rounded-xl hover:border-green-500/50 hover:bg-zinc-800 transition-all group">
                  <div class="flex items-center justify-between">
                    <div class="flex flex-col">
                      <span class="text-xs text-zinc-500 font-medium uppercase"><?= $ds['slot_time'] ?> • <?= $ds['field_type'] === '7side' ? '7-a-side' : '9-a-side' ?></span>
                      <span class="text-zinc-300 font-semibold"><?= $ds['formatted_date'] ?></span>
                      <?php if(!empty($ds['reason'])): ?>
                        <span class="reason-text"><i class="fas fa-comment mr-1"></i><?= htmlspecialchars($ds['reason']) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="bg-green-500/10 text-green-400 px-4 py-2 rounded-lg group-hover:bg-green-500 group-hover:text-black transition-all flex items-center gap-2">
                      <i class="fas fa-calendar-plus"></i>
                      <span class="text-xs font-bold uppercase">Enable</span>
                    </div>
                  </div>
                </button>
              </form>
            <?php endwhile; ?>
          </div>
        <?php else: ?>
          <div class="py-10 text-center border-2 border-dashed border-zinc-800 rounded-2xl text-zinc-600 text-sm">No slots disabled</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<div class="refresh-indicator" id="refreshIndicator">
  <i class="fas fa-sync-alt mr-2"></i>
  Auto-refresh: <span id="countdown" class="countdown"><?= $refresh_interval ?></span> seconds
</div>

<footer class="py-12 text-center text-zinc-600 text-sm">
  © 2026 Astromomo Football FIeldStreet 7side Football Field
</footer>

<script>
let refreshInterval = <?= $refresh_interval ?>;
let countdown = refreshInterval;

function updateCountdown() {
    const countdownElement = document.getElementById('countdown');
    if (countdownElement) {
        countdownElement.textContent = countdown;
    }
    
    if (countdown <= 0) {
        window.location.reload();
    } else {
        countdown--;
        setTimeout(updateCountdown, 1000);
    }
}

if (refreshInterval > 0) {
    updateCountdown();
}

document.addEventListener('DOMContentLoaded', function() {
    const calendarIcons = document.querySelectorAll('.calendar-icon');
    calendarIcons.forEach(icon => {
        icon.addEventListener('click', function(e) {
            e.preventDefault();
            const dateInput = this.nextElementSibling;
            if (dateInput && dateInput.type === 'date') {
                if (dateInput.showPicker) {
                    dateInput.showPicker();
                } else {
                    dateInput.focus();
                    dateInput.click();
                }
            }
        });
    });
});
</script>

</body>
</html>