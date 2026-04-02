<?php
// ====================== DATABASE CONNECTION ======================
require_once 'db.php'; 

// Ensure charset is set (Note: If this is already in db.php, you can remove this line)
$conn->set_charset("utf8mb4");

// ====================== FIXED SLOTS ======================
$slots = [
    ['label' => '4:30 PM - 6:00 PM',   'time' => '16:30-18:00', 'price' => 280],
    ['label' => '6:00 PM - 7:30 PM',   'time' => '18:00-19:30', 'price' => 300],
    ['label' => '8:00 PM - 9:30 PM',   'time' => '20:00-21:30', 'price' => 400],
    ['label' => '9:30 PM - 11:00 PM',  'time' => '21:30-23:00', 'price' => 400],
    ['label' => '11:00 PM - 12:30 AM', 'time' => '23:00-00:30', 'price' => 350],
    ['label' => '12:30 AM - 2:00 AM',  'time' => '00:30-02:00', 'price' => 300],
];

$error_message = '';
$show_slots = false;
$date = '';
$field = '';
$booked_slots = [];
$disabled_slots_list = [];
$is_date_disabled = false;

// ====================== CHECK AVAILABILITY ======================
if (isset($_POST['check_availability'])) {
    $date = $conn->real_escape_string($_POST['booking_date']);
    $field = $conn->real_escape_string($_POST['field_type']);
    
    // Check if the entire date is disabled for this specific field
    $stmt = $conn->prepare("SELECT reason FROM disabled_dates WHERE disable_date = ? AND field_type = ?");
    $stmt->bind_param("ss", $date, $field);
    $stmt->execute();
    $disabled_date_result = $stmt->get_result();
    
    if ($disabled_date_result->num_rows > 0) {
        $disabled_data = $disabled_date_result->fetch_assoc();
        $error_message = "This " . ($field === '7side' ? '7-a-side' : '9-a-side') . " field is disabled on " . date('d F Y', strtotime($date)) . ". " . ($disabled_data['reason'] ? "Reason: " . $disabled_data['reason'] : "Please choose another date or field.");
        $show_slots = false;
    } else {
        // Check for booked slots
        $stmt = $conn->prepare("SELECT slot_time FROM bookings WHERE booking_date = ? AND field_type = ? AND status IN ('confirmed', 'pending')");
        $stmt->bind_param("ss", $date, $field);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $booked_slots = [];
        while ($row = $result->fetch_assoc()) {
            $booked_slots[] = $row['slot_time'];
        }
        $stmt->close();
        
        // Check for disabled slots for this specific field
        $stmt = $conn->prepare("SELECT slot_time FROM disabled_slots WHERE disable_date = ? AND field_type = ?");
        $stmt->bind_param("ss", $date, $field);
        $stmt->execute();
        $disabled_slots_result = $stmt->get_result();
        
        $disabled_slots_list = [];
        while ($row = $disabled_slots_result->fetch_assoc()) {
            $disabled_slots_list[] = $row['slot_time'];
        }
        $stmt->close();
        
        $show_slots = true;
    }
}

// Get current prices from database
$price_map = [];
$price_result = $conn->query("SELECT slot_time, price FROM slot_prices");
while ($row = $price_result->fetch_assoc()) {
    $price_map[$row['slot_time']] = $row['price'];
}

// Update slot prices if they exist in database
foreach ($slots as &$slot) {
    if (isset($price_map[$slot['time']])) {
        $slot['price'] = $price_map[$slot['time']];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book Your Slot • Ubi Street 7side</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap');
    .tail-container { font-family: 'Inter', system-ui, sans-serif; }
    .display-font { font-family: 'Space Grotesk', sans-serif; }
    
    /* Custom styles for date input with green calendar icon */
    .date-input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .date-input-wrapper input[type="date"] {
      width: 100%;
      background-color: #000000;
      border: 1px solid #3f3f46;
      border-radius: 1rem;
      padding: 1rem 1rem 1rem 3rem;
      color: white;
      font-size: 1rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .date-input-wrapper input[type="date"]:focus {
      outline: none;
      border-color: #4ade80;
      box-shadow: 0 0 0 2px rgba(74, 222, 128, 0.2);
    }
    .date-input-wrapper input[type="date"]::-webkit-calendar-picker-indicator {
      opacity: 0;
      position: absolute;
      right: 0;
      width: 100%;
      height: 100%;
      cursor: pointer;
    }
    .date-icon {
      position: absolute;
      left: 1rem;
      color: #4ade80;
      font-size: 1.25rem;
      pointer-events: none;
      z-index: 1;
    }
    /* For browsers that don't support custom styling, keep fallback */
    .date-input-wrapper input[type="date"] {
      color-scheme: dark;
    }
  </style>
</head>
<body class="tail-container bg-zinc-950 text-white min-h-screen">

  <!-- Header -->
  <nav class="bg-black border-b border-green-500/20 py-5 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
      <div class="flex items-center gap-3">
        <a href="index.html">
          <img src="logo/file.jpg" alt="Ubi Street Logo" class="h-12 w-auto object-contain hover:scale-105 transition-transform">
        </a>
        <div>
          <div id="logo-title" class="text-2xl font-semibold display-font tracking-tight">Ubi Street</div>
          <div id="logo-subtitle" class="text-xs text-green-400 -mt-1">7SIDE FOOTBALL FIELD</div>
        </div>
      </div>

      <div class="flex items-center gap-4">
        <!-- Language Switcher -->
        <button onclick="toggleLanguage()" 
          class="bg-zinc-800 hover:bg-zinc-700 text-white text-sm font-medium px-4 py-2 rounded-2xl flex items-center gap-2 border border-green-500/30">
          <span id="current-lang">ENG</span>
          <i class="fas fa-chevron-down text-xs"></i>
        </button>

        <a href="index.html" class="flex items-center gap-2 text-green-400 hover:text-green-300 font-medium">
          <i class="fas fa-arrow-left"></i> <span id="back-home">Back to Home</span>
        </a>
      </div>
    </div>
  </nav>

  <div class="max-w-5xl mx-auto px-6 py-12">

    <h1 id="page-title" class="display-font text-5xl font-semibold tracking-tighter text-center mb-2">Book Your Turf</h1>
    <p id="page-subtitle" class="text-center text-zinc-400 mb-12">Choose your date and field to check availability</p>

    <!-- Step 1: Choose Date & Field -->
    <form method="POST" class="bg-zinc-900 rounded-3xl p-8 mb-12">
      <div class="grid md:grid-cols-3 gap-6">
        <div>
          <label class="block text-sm text-zinc-400 mb-2 font-medium" id="label-date">Select Date</label>
          <div class="date-input-wrapper">
            <i class="fas fa-calendar-alt date-icon"></i>
            <input type="date" name="booking_date" id="date" 
                   class="w-full bg-black border border-zinc-700 rounded-2xl px-6 py-4 focus:outline-none focus:border-green-500"
                   required 
                   value="<?php echo isset($_POST['booking_date']) ? $_POST['booking_date'] : date('Y-m-d', strtotime('+1 day')); ?>"
                   min="<?php echo date('Y-m-d'); ?>">
          </div>
        </div>
        <div>
          <label class="block text-sm text-zinc-400 mb-2 font-medium" id="label-field">Field Type</label>
          <select name="field_type" class="w-full bg-black border border-zinc-700 rounded-2xl px-6 py-4 focus:outline-none focus:border-green-500" required>
            <option value="7side" <?php echo (isset($_POST['field_type']) && $_POST['field_type']=='7side') ? 'selected' : ''; ?>>7-a-side Field</option>
            <option value="9side" <?php echo (isset($_POST['field_type']) && $_POST['field_type']=='9side') ? 'selected' : ''; ?>>9-a-side Field</option>
          </select>
        </div>
        <div class="flex items-end">
          <button type="submit" name="check_availability" 
                  class="w-full bg-green-500 hover:bg-green-400 text-black font-semibold py-4 rounded-2xl text-lg transition-all flex items-center justify-center gap-2">
            <i class="fas fa-search"></i> <span id="btn-check">Check Availability</span>
          </button>
        </div>
      </div>
    </form>

    <?php if ($error_message): ?>
      <div class="bg-red-500/20 border border-red-500 text-red-400 p-4 rounded-2xl mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($error_message); ?>
      </div>
    <?php endif; ?>

    <?php if ($show_slots): ?>
    <div class="mb-8">
      <h2 id="availability-title" class="text-3xl font-semibold mb-1">Slots for <span class="text-green-400"><?php echo date('d F Y', strtotime($date)); ?></span></h2>
      <p id="field-selected" class="text-zinc-400">Field: <strong><?php echo $field === '7side' ? '7-a-side' : '9-a-side'; ?></strong></p>
    </div>

    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
      <?php foreach ($slots as $slot): 
          $isBooked = in_array($slot['time'], $booked_slots);
          $isDisabled = in_array($slot['time'], $disabled_slots_list);
          $isUnavailable = $isBooked || $isDisabled;
      ?>
        <div class="bg-zinc-900 border <?php 
            echo $isUnavailable ? 'border-red-500/30 opacity-75' : 'border-green-500/30 hover:border-green-500'; 
        ?> rounded-3xl p-7 transition-all">
          <div class="flex justify-between">
            <div>
              <div class="font-semibold text-lg"><?php echo $slot['label']; ?></div>
              <div class="text-4xl font-bold text-green-400 mt-2">RM<?php echo $slot['price']; ?></div>
            </div>
            <div>
              <?php if ($isBooked): ?>
                <span class="px-5 py-2 bg-red-500/10 text-red-400 text-sm font-medium rounded-2xl">BOOKED</span>
              <?php elseif ($isDisabled): ?>
                <span class="px-5 py-2 bg-orange-500/10 text-orange-400 text-sm font-medium rounded-2xl">DISABLED</span>
              <?php else: ?>
                <span class="px-5 py-2 bg-green-500/10 text-green-400 text-sm font-medium rounded-2xl">AVAILABLE</span>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$isUnavailable): ?>
            <a href="payment.php?date=<?php echo $date; ?>&field=<?php echo $field; ?>&slot=<?php echo urlencode($slot['time']); ?>&price=<?php echo $slot['price']; ?>&label=<?php echo urlencode($slot['label']); ?>" 
               class="mt-8 block text-center bg-green-500 hover:bg-green-400 text-black font-semibold py-4 rounded-2xl transition-all">
              <span id="select-slot">Select This Slot</span>
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>

  </div>

  <footer class="bg-black py-10 text-center text-zinc-500">
    © 2026 Ubi Street 7side Football Field • Kuantan, Pahang
  </footer>

  <script>
    let isEnglish = true;

    const translations = {
      en: {
        "logo-subtitle": "7SIDE FOOTBALL FIELD",
        "page-title": "Book Your Turf",
        "page-subtitle": "Choose your date and field to check availability",
        "label-date": "Select Date",
        "label-field": "Field Type",
        "btn-check": "Check Availability",
        "availability-title": "Slots for",
        "field-selected": "Field:",
        "select-slot": "Select This Slot",
        "back-home": "Back to Home"
      },
      ms: {
        "logo-subtitle": "PADANG BOLA 7 SEPIHAK",
        "page-title": "Tempah Padang Anda",
        "page-subtitle": "Pilih tarikh dan jenis padang untuk semak ketersediaan",
        "label-date": "Pilih Tarikh",
        "label-field": "Jenis Padang",
        "btn-check": "Semak Ketersediaan",
        "availability-title": "Slot untuk",
        "field-selected": "Padang:",
        "select-slot": "Pilih Slot Ini",
        "back-home": "Kembali ke Laman Utama"
      }
    };

    function toggleLanguage() {
      isEnglish = !isEnglish;
      const lang = isEnglish ? 'en' : 'ms';
      document.getElementById('current-lang').textContent = isEnglish ? 'ENG' : 'BM';

      document.querySelectorAll('[id]').forEach(el => {
        const key = el.id;
        if (translations[lang][key]) {
          if (key === "availability-title") {
            // Special handling because it has dynamic date
            const dateSpan = document.querySelector('#availability-title .text-green-400');
            if (dateSpan) {
              const dateText = dateSpan.textContent;
              el.innerHTML = translations[lang][key] + " <span class='text-green-400'>" + dateText + "</span>";
            } else {
              el.textContent = translations[lang][key];
            }
          } else {
            el.textContent = translations[lang][key];
          }
        }
      });
    }

    // Initialize language
    window.onload = () => {
      document.getElementById('current-lang').textContent = 'ENG';
    };
  </script>

</body>
</html>