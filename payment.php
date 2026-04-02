<?php
// ====================== DATABASE CONNECTION ======================
$host = 'localhost';
$db   = 'ubistreet';
$user = 'root';
$pass = '';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ====================== PROCESS PAYMENT ======================
$success = false;
$booking_id = 0;

if (isset($_POST['pay_now'])) {
    $date   = $_POST['date'];
    $field  = $_POST['field'];
    $slot   = $_POST['slot'];
    $price  = (int)$_POST['price'];
    $name   = trim($_POST['name']);
    $phone  = trim($_POST['phone']);
    $email  = trim($_POST['email'] ?? '');

    $stmt = $conn->prepare("INSERT INTO bookings 
        (booking_date, field_type, slot_time, customer_name, customer_phone, customer_email, price, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed')");

    $stmt->bind_param("ssssssi", $date, $field, $slot, $name, $phone, $email, $price);

    if ($stmt->execute()) {
        $booking_id = $conn->insert_id;
        $success = true;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment • Ubi Street 7side</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap');
    .tail-container { font-family: 'Inter', system-ui, sans-serif; }
    .display-font { font-family: 'Space Grotesk', sans-serif; }
  </style>
</head>
<body class="tail-container bg-zinc-950 text-white min-h-screen">

  <!-- Header -->
  <nav class="bg-black border-b border-green-500/20 py-5">
    <div class="max-w-5xl mx-auto px-6 flex justify-between items-center">
      <div class="flex items-center gap-3">
        <a href="index.html">
          <img src="logo/file.jpg" alt="Ubi Street Logo" class="h-12 w-auto object-contain hover:scale-105 transition-transform">
        </a>
        <div>
          <div id="logo-title" class="text-xl font-semibold display-font">Ubi Street</div>
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

        <a href="booking.php" class="text-green-400 hover:text-green-300 flex items-center gap-2">
          ← <span id="back-booking">Back to Booking</span>
        </a>
      </div>
    </div>
  </nav>

  <div class="max-w-2xl mx-auto px-6 py-12">

    <?php if ($success): ?>
      <!-- SUCCESS MESSAGE -->
      <div class="text-center py-20">
        <div class="text-8xl mb-6">🎉</div>
        <h1 id="success-title" class="display-font text-5xl font-semibold text-green-400 mb-4">Booking Confirmed!</h1>
        <p id="success-subtitle" class="text-2xl text-zinc-300 mb-10">Your slot has been successfully reserved.</p>

        <div class="bg-zinc-900 rounded-3xl p-10 mb-12 max-w-md mx-auto text-left">
          <p class="text-zinc-400 text-sm" id="ref-label">Booking Reference</p>
          <p class="text-4xl font-bold text-green-400">#UBI-<?php echo str_pad($booking_id, 5, '0', STR_PAD_LEFT); ?></p>

          <div class="mt-10 grid grid-cols-2 gap-y-5 text-sm">
            <div class="text-zinc-400" id="date-label">Date</div>
            <div class="font-medium"><?php echo date('d F Y', strtotime($_POST['date'])); ?></div>
            
            <div class="text-zinc-400" id="field-label">Field</div>
            <div class="font-medium"><?php echo $_POST['field'] === '7side' ? '7-a-side' : '9-a-side'; ?></div>
            
            <div class="text-zinc-400" id="slot-label">Time Slot</div>
            <div class="font-medium"><?php echo $_POST['label']; ?></div>
            
            <div class="text-zinc-400" id="total-label">Total Amount</div>
            <div class="font-bold text-2xl text-green-400">RM<?php echo $_POST['price']; ?></div>
          </div>
        </div>

        <a href="index.html" class="inline-block bg-green-500 hover:bg-green-400 text-black font-semibold px-14 py-5 rounded-3xl text-xl transition">
          <span id="return-home">Return to Homepage</span>
        </a>
      </div>

    <?php else: 
      // Payment Form
      $date  = $_GET['date'] ?? '';
      $field = $_GET['field'] ?? '';
      $slot  = $_GET['slot'] ?? '';
      $price = $_GET['price'] ?? 0;
      $label = $_GET['label'] ?? '';
    ?>

      <div class="bg-zinc-900 rounded-3xl p-10">
        <h1 id="payment-title" class="display-font text-4xl font-semibold mb-8">Complete Your Booking</h1>

        <!-- Booking Summary -->
        <div class="bg-black rounded-2xl p-8 mb-10">
          <div class="grid grid-cols-2 gap-6 text-sm">
            <div class="text-zinc-400" id="summary-date">Date</div>
            <div class="font-medium"><?php echo $date ? date('d F Y', strtotime($date)) : '-'; ?></div>

            <div class="text-zinc-400" id="summary-field">Field</div>
            <div class="font-medium"><?php echo $field === '7side' ? '7-a-side Field' : '9-a-side Field'; ?></div>

            <div class="text-zinc-400" id="summary-slot">Slot</div>
            <div class="font-medium"><?php echo htmlspecialchars($label); ?></div>

            <div class="text-zinc-400" id="summary-total">Total</div>
            <div class="text-4xl font-bold text-green-400">RM<?php echo $price; ?></div>
          </div>
        </div>

        <form method="POST" class="space-y-8">
          <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
          <input type="hidden" name="field" value="<?php echo htmlspecialchars($field); ?>">
          <input type="hidden" name="slot" value="<?php echo htmlspecialchars($slot); ?>">
          <input type="hidden" name="price" value="<?php echo $price; ?>">
          <input type="hidden" name="label" value="<?php echo htmlspecialchars($label); ?>">

          <div>
            <label class="block text-sm text-zinc-400 mb-2" id="name-label">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="name" required 
                   class="w-full bg-black border border-zinc-700 rounded-2xl px-6 py-5 focus:outline-none focus:border-green-500 text-lg">
          </div>

          <div class="grid md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm text-zinc-400 mb-2" id="phone-label">Phone Number <span class="text-red-500">*</span></label>
              <input type="tel" name="phone" required 
                     class="w-full bg-black border border-zinc-700 rounded-2xl px-6 py-5 focus:outline-none focus:border-green-500 text-lg">
            </div>
            <div>
              <label class="block text-sm text-zinc-400 mb-2" id="email-label">Email (Optional)</label>
              <input type="email" name="email" 
                     class="w-full bg-black border border-zinc-700 rounded-2xl px-6 py-5 focus:outline-none focus:border-green-500 text-lg">
            </div>
          </div>

          <!-- Mock Payment Section -->
          <div class="border border-zinc-700 rounded-3xl p-8 bg-black/50">
            <h3 class="font-semibold mb-6 flex items-center gap-3 text-lg" id="payment-label">
              <i class="fas fa-credit-card"></i> Payment
            </h3>
            <div class="flex justify-center gap-8 text-5xl mb-8 text-zinc-400">
              <i class="fab fa-cc-visa"></i>
              <i class="fab fa-cc-mastercard"></i>
            </div>

            <button type="submit" name="pay_now" 
                    class="w-full bg-green-500 hover:bg-green-400 text-black font-bold text-2xl py-6 rounded-3xl transition-all">
              Pay RM<?php echo $price; ?> Now
            </button>
            <p class="text-center text-xs text-zinc-500 mt-4" id="mock-note">This is a demonstration (mock payment)</p>
          </div>
        </form>
      </div>

    <?php endif; ?>
  </div>

  <script>
    let isEnglish = true;

    const translations = {
      en: {
        "logo-subtitle": "7SIDE FOOTBALL FIELD",
        "payment-title": "Complete Your Booking",
        "summary-date": "Date",
        "summary-field": "Field",
        "summary-slot": "Slot",
        "summary-total": "Total",
        "name-label": "Full Name",
        "phone-label": "Phone Number",
        "email-label": "Email (Optional)",
        "payment-label": "Payment",
        "mock-note": "This is a demonstration (mock payment)",
        "success-title": "Booking Confirmed!",
        "success-subtitle": "Your slot has been successfully reserved.",
        "ref-label": "Booking Reference",
        "date-label": "Date",
        "field-label": "Field",
        "slot-label": "Time Slot",
        "total-label": "Total Amount",
        "return-home": "Return to Homepage",
        "back-booking": "Back to Booking"
      },
      ms: {
        "logo-subtitle": "PADANG BOLA 7 SEPIHAK",
        "payment-title": "Selesaikan Tempahan Anda",
        "summary-date": "Tarikh",
        "summary-field": "Padang",
        "summary-slot": "Slot",
        "summary-total": "Jumlah",
        "name-label": "Nama Penuh",
        "phone-label": "Nombor Telefon",
        "email-label": "Emel (Pilihan)",
        "payment-label": "Pembayaran",
        "mock-note": "Ini adalah simulasi pembayaran (mock payment)",
        "success-title": "Tempahan Berjaya!",
        "success-subtitle": "Slot anda telah berjaya ditempah.",
        "ref-label": "Rujukan Tempahan",
        "date-label": "Tarikh",
        "field-label": "Padang",
        "slot-label": "Slot Masa",
        "total-label": "Jumlah Bayaran",
        "return-home": "Kembali ke Laman Utama",
        "back-booking": "Kembali ke Tempahan"
      }
    };

    function toggleLanguage() {
      isEnglish = !isEnglish;
      const lang = isEnglish ? 'en' : 'ms';
      document.getElementById('current-lang').textContent = isEnglish ? 'ENG' : 'BM';

      document.querySelectorAll('[id]').forEach(el => {
        const key = el.id;
        if (translations[lang][key]) {
          el.textContent = translations[lang][key];
        }
      });
    }

    // Initialize
    window.onload = () => {
      document.getElementById('current-lang').textContent = 'ENG';
    };
  </script>

</body>
</html>