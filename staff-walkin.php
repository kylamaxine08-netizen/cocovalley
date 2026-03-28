<?php
declare(strict_types=1);

// ================= SESSION + ACCESS ==================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'staff')) {
    header('Location: admin-login.php');
    exit;
}

$meId   = (int)($_SESSION['user_id'] ?? 0);
$meName = $_SESSION['name'] ?? 'Coco Staff';

// DB
require_once __DIR__ . '/../admin/handlers/db_connect.php';

// ================ CSRF TOKEN =========================
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// ================ HELPERS ============================
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function peso(float $n): string {
    return '₱' . number_format($n, 2);
}

function generateReservationCode(string $prefix = 'CVR'): string {
    $rand = random_int(1000, 9999);
    return $prefix . '-' . $rand;
}

// ================ POST: SAVE WALK-IN =================
$errors     = [];
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid form token. Please refresh the page.';
    } else {
        $full_name   = trim($_POST['full_name']   ?? '');
        $phone       = trim($_POST['phone']       ?? '');
        $email       = trim($_POST['email']       ?? '');
        $visit_date  = trim($_POST['visit_date']  ?? '');
        $category    = strtolower(trim($_POST['category'] ?? ''));
        $accId       = (int)($_POST['accommodation_id'] ?? 0);
        $timeSlotKey = $_POST['time_slot'] ?? '';
        $payOption   = $_POST['payment_option'] ?? '100'; // '50' or '100'

        if ($full_name === '') {
            $errors[] = 'Customer name is required.';
        }
        if ($phone === '') {
            $errors[] = 'Contact number is required.';
        }
        if ($visit_date === '') {
            $errors[] = 'Visit date is required.';
        }
        if (!in_array($category, ['cottage', 'room', 'event'], true)) {
            $errors[] = 'Invalid category.';
        }
        if (!$accId) {
            $errors[] = 'Please select a package.';
        }
        if (!in_array($timeSlotKey, ['day','night','10_hrs','22_hrs'], true)) {
            $errors[] = 'Invalid time slot.';
        }
        if (!in_array($payOption, ['50','100'], true)) {
            $errors[] = 'Invalid payment option.';
        }

        if (!$errors) {
            try {
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $conn->begin_transaction();

                // Fetch accommodation
                $stmt = $conn->prepare("
                    SELECT 
                        id,
                        category,
                        package,
                        capacity,
                        day_price,
                        night_price,
                        price_10hrs,
                        price_22hrs
                    FROM accommodations
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->bind_param('i', $accId);
                $stmt->execute();
                $acc = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$acc) {
                    throw new Exception('Selected package not found.');
                }

                $dbCategory = strtolower($acc['category']);
                if ($dbCategory !== $category) {
                    throw new Exception('Category / package mismatch.');
                }

                // Determine time slot label + price
                $slotLabel = '';
                $fullPrice = 0.0;

                if ($dbCategory === 'cottage') {
                    if ($timeSlotKey === 'day') {
                        $slotLabel = 'Day';
                        $fullPrice = (float)$acc['day_price'];
                    } elseif ($timeSlotKey === 'night') {
                        $slotLabel = 'Night';
                        $fullPrice = (float)$acc['night_price'];
                    } else {
                        throw new Exception('Invalid time slot for cottage.');
                    }
                } else {
                    if ($timeSlotKey === '10_hrs') {
                        $slotLabel = '10 Hours';
                        $fullPrice = (float)$acc['price_10hrs'];
                    } elseif ($timeSlotKey === '22_hrs') {
                        $slotLabel = '22 Hours';
                        $fullPrice = (float)$acc['price_22hrs'];
                    } else {
                        throw new Exception('Invalid time slot for room/event.');
                    }
                }

                if ($fullPrice <= 0) {
                    throw new Exception('Invalid price for this package/slot.');
                }

                $capacity = (int)$acc['capacity'];

                // Payment calculation
                $paymentPercent = ($payOption === '50') ? 50 : 100;
                $collectAmount  = $fullPrice * ($paymentPercent / 100);

                // Insert reservation
                $code      = generateReservationCode('CVR');
                $startDate = $visit_date;
                $endDate   = $visit_date;

                $stmt = $conn->prepare("
                    INSERT INTO reservations
                        (code, customer_id, customer_name, type, package, time_slot,
                         pax, total_price, start_date, end_date,
                         status, payment_status, payment_percent,
                         created_at, updated_at)
                    VALUES
                        (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?,
                         'approved', 'approved', ?, NOW(), NOW())
                ");
                $typeLabel = ucfirst($dbCategory);
                $pax       = $capacity;

                $stmt->bind_param(
                    'sssssidssd',
                    $code,
                    $full_name,
                    $typeLabel,
                    $acc['package'],
                    $slotLabel,
                    $pax,
                    $fullPrice,
                    $startDate,
                    $endDate,
                    $paymentPercent
                );
                $stmt->execute();
                $reservationId = (int)$stmt->insert_id;
                $stmt->close();

                // Insert walk-in customer (link to reservation)
                $stmt = $conn->prepare("
                    INSERT INTO walkin_customers
                        (reservation_id, full_name, customer_email, phone, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param('isss', $reservationId, $full_name, $email, $phone);
                $stmt->execute();
                $stmt->close();

                // Insert payment record (cash)
                $method         = 'Cash';
                $paymentStatus  = 'approved';
                $rowStatus      = 'approved';
                $reviewedRole   = 'staff';

                $stmt = $conn->prepare("
                    INSERT INTO payments
                        (reservation_id, amount, method, method_option,
                         payment_status, payment_percent,
                         verified_by, status, reviewed_by_role)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'idssdisis',
                    $reservationId,
                    $collectAmount,
                    $method,
                    $paymentPercent,
                    $paymentStatus,
                    $paymentPercent,
                    $meId,
                    $rowStatus,
                    $reviewedRole
                );
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                // ✅ DIRECT REDIRECT TO RECEIPT AFTER SUCCESS
                header("Location: staff-walkin-receipt.php?id=" . $reservationId);
                exit;

            } catch (Throwable $e) {
                if ($conn->errno) {
                    $conn->rollback();
                }
                $errors[] = 'Error saving reservation: ' . $e->getMessage();
            }
        }
    }
}

// ================ FETCH ACCOMMODATIONS FOR FORM ==============
$accommodations = [];
try {
    $q = "
        SELECT 
            id,
            category,
            package,
            capacity,
            day_price,
            night_price,
            price_10hrs,
            price_22hrs
        FROM accommodations
        WHERE availability_status = 'available'
        ORDER BY category, package
    ";
    $res = $conn->query($q);
    while ($row = $res->fetch_assoc()) {
        $accommodations[] = $row;
    }
} catch (Throwable $e) {
    $errors[] = 'Error loading accommodations: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Walk-in Reservation | Cocovalley Staff Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />

  <style>
    :root {
      --primary: #004d99;
      --primary-dark: #00366c;
      --accent: #0ea5e9;
      --accent-soft: rgba(14,165,233,0.08);
      --bg: #f5f7fb;
      --bg-soft: #ffffff;
      --border: #e5e7eb;
      --muted: #6b7280;
      --text: #111827;
      --danger: #ef4444;
      --shadow-soft: 0 18px 40px rgba(15,23,42,0.08);
      --sidebar-w: 260px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    body {
      background: radial-gradient(circle at top left, #e0f2fe, #f5f7fb 45%, #eef2ff 90%);
      min-height: 100vh;
      color: var(--text);
      overflow-x: hidden;
    }

    a { text-decoration: none; color: inherit; }

    /* SIDEBAR */
    .sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      width: var(--sidebar-w);
      background: #ffffff;
      border-right: 1px solid var(--border);
      padding: 20px 18px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      z-index: 20;
      box-shadow: 8px 0 30px rgba(15,23,42,0.04);
    }

    .sb-head {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
    }

    .sb-logo {
      width: 44px;
      height: 44px;
      border-radius: 14px;
      object-fit: cover;
      box-shadow: 0 10px 22px rgba(0,0,0,0.4);
    }

    .sb-title {
      font-size: 18px;
      font-weight: 800;
      color: var(--primary);
    }

    .sb-tag {
      font-size: 13px;
      color: var(--muted);
    }

    .nav {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-top: 10px;
    }

    .nav-item,
    .nav-toggle {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 10px;
      padding: 9px 11px;
      border-radius: 999px;
      font-size: 14px;
      color: #374151;
      cursor: pointer;
      transition: background 0.16s ease, transform 0.12s ease, box-shadow 0.16s ease;
    }

    .nav-item i,
    .nav-toggle i.fa-calendar-days {
      width: 16px;
      text-align: center;
    }

    .nav-item:hover,
    .nav-toggle:hover {
      background: #f3f4f6;
      transform: translateY(-1px);
    }

    .nav-item.active {
      background: var(--accent-soft);
      color: var(--primary);
      font-weight: 600;
      box-shadow: 0 10px 24px rgba(14,165,233,0.2);
    }

    .nav-group { display: flex; flex-direction: column; gap: 4px; }

    .nav-toggle { justify-content: space-between; }

    .nav-toggle .label {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .chev {
      font-size: 12px;
      color: #9ca3af;
      transition: transform 0.2s ease;
    }

    .chev.open { transform: rotate(180deg); }

    .submenu {
      display: none;
      flex-direction: column;
      gap: 4px;
      margin: 4px 0 8px 26px;
    }

    .submenu a {
      padding: 7px 10px;
      border-radius: 999px;
      font-size: 13px;
      color: #4b5563;
    }

    .submenu a:hover { background: #f3f4f6; }

    .submenu a.active {
      background: var(--accent-soft);
      color: var(--primary);
      font-weight: 600;
    }

    /* MAIN LAYOUT */
    .main {
      margin-left: var(--sidebar-w);
      padding: 26px 34px 40px;
      min-height: 100vh;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-radius: 999px;
      padding: 10px 20px;
      background: rgba(255,255,255,0.9);
      backdrop-filter: blur(18px);
      border: 1px solid rgba(148,163,184,0.25);
      box-shadow: 0 18px 40px rgba(15,23,42,0.14);
      margin-bottom: 24px;
    }

    .topbar-left {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .topbar-title {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 18px;
      font-weight: 700;
      color: #0f172a;
    }

    .topbar-title span.icon {
      width: 32px;
      height: 32px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg,#0ea5e9,#6366f1);
      color: #eff6ff;
      box-shadow: 0 10px 24px rgba(37,99,235,0.35);
    }

    .topbar-sub {
      font-size: 13px;
      color: var(--muted);
    }

    .profile {
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
      padding: 5px 10px;
      border-radius: 999px;
      transition: background 0.16s ease;
      position: relative;
    }

    .profile:hover { background:#e5e7eb; }

    .avatar {
      width: 34px;
      height: 34px;
      border-radius: 999px;
      background: linear-gradient(135deg, #bfdbfe, #1d4ed8);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      color: #eff6ff;
      font-weight: 700;
      text-transform: uppercase;
      box-shadow: 0 10px 22px rgba(37,99,235,0.4);
    }

    .dropdown {
      position: absolute;
      top: 42px;
      right: 0;
      min-width: 150px;
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 16px 40px rgba(15,23,42,0.2);
      border: 1px solid #e5e7eb;
      display: none;
      flex-direction: column;
      overflow: hidden;
      z-index: 50;
    }

    .dropdown a {
      padding: 9px 12px;
      font-size: 14px;
    }

    .dropdown a:hover { background:#f3f4f6; }

    /* FORM CARD */
    .card {
      max-width: 960px;
      margin: 0 auto;
      background: rgba(255,255,255,0.98);
      border-radius: 26px;
      padding: 24px 24px 26px;
      box-shadow: var(--shadow-soft);
      border: 1px solid rgba(148,163,184,0.2);
      position: relative;
      overflow: hidden;
    }

    .card::before {
      content:'';
      position:absolute;
      inset:-40%;
      background:
        radial-gradient(circle at top left, rgba(59,130,246,0.13), transparent 55%),
        radial-gradient(circle at bottom right, rgba(52,211,153,0.13), transparent 55%);
      opacity:0.7;
      pointer-events:none;
    }

    .card-inner {
      position: relative;
      z-index: 1;
    }

    .card-header {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      margin-bottom:18px;
    }

    .card-title-group {
      display:flex;
      flex-direction:column;
      gap:4px;
    }

    .card-title {
      font-size:18px;
      font-weight:700;
      color:#0f172a;
    }

    .card-sub {
      font-size:13px;
      color:#6b7280;
    }

    .tag-pill {
      font-size:11px;
      text-transform:uppercase;
      letter-spacing:0.08em;
      padding:4px 10px;
      border-radius:999px;
      background:rgba(15,23,42,0.04);
      color:#4b5563;
      border:1px solid rgba(148,163,184,0.4);
    }

    .grid {
      display:grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap:14px 16px;
      margin-top:8px;
    }

    .field {
      display:flex;
      flex-direction:column;
      gap:4px;
    }

    .field label {
      font-size:13px;
      font-weight:600;
      color:#111827;
    }

    .field small {
      font-size:11px;
      color:#9ca3af;
    }

    .input,
    .select {
      width:100%;
      padding:9px 11px;
      border-radius:11px;
      border:1px solid rgba(148,163,184,0.7);
      background:rgba(248,250,252,0.9);
      font-size:14px;
      color:#111827;
      outline:none;
      transition:border 0.16s ease, box-shadow 0.16s ease, background 0.16s ease, transform 0.08s ease;
    }

    .input:focus,
    .select:focus {
      border-color:#2563eb;
      box-shadow:0 0 0 1px rgba(37,99,235,0.4);
      background:#ffffff;
      transform:translateY(-1px);
    }

    .input[readonly] {
      background:#f3f4f6;
      color:#4b5563;
    }

    .muted {
      font-size:12px;
      color:#6b7280;
      margin-top:6px;
    }

    .section-divider {
      margin:18px 0 12px;
      border:none;
      border-top:1px dashed rgba(148,163,184,0.6);
    }

    .section-label {
      font-size:13px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:0.08em;
      color:#6b7280;
      margin-bottom:10px;
    }

    .payment-row {
      display:grid;
      grid-template-columns: 2fr 1fr;
      gap:14px;
      align-items:flex-start;
    }

    .radio-row {
      display:flex;
      align-items:center;
      gap:12px;
      padding:8px 10px;
      border-radius:14px;
      background:rgba(248,250,252,0.95);
      border:1px solid rgba(148,163,184,0.6);
    }

    .radio-row label {
      display:flex;
      align-items:center;
      gap:6px;
      font-size:13px;
      color:#111827;
      cursor:pointer;
    }

    .summary-strip {
      display:flex;
      justify-content:space-between;
      align-items:center;
      padding:10px 12px;
      border-radius:14px;
      background:linear-gradient(135deg,#0f172a,#020617);
      color:#e5e7eb;
      font-size:13px;
    }

    .summary-strip span strong {
      font-size:14px;
      color:#facc15;
    }

    .summary-strip small {
      font-size:12px;
      opacity:0.9;
    }

    .btn-primary {
      margin-top:18px;
      width:100%;
      padding:11px 14px;
      border-radius:999px;
      border:none;
      background:linear-gradient(135deg,#0f172a,#1d4ed8);
      color:#eff6ff;
      font-size:14px;
      font-weight:700;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      cursor:pointer;
      box-shadow:0 18px 40px rgba(15,23,42,0.45);
      transition:transform 0.1s ease, box-shadow 0.12s ease, filter 0.12s ease;
    }

    .btn-primary i { font-size:13px; }

    .btn-primary:hover {
      transform:translateY(-1px);
      filter:brightness(1.03);
      box-shadow:0 22px 50px rgba(15,23,42,0.6);
    }

    .btn-primary:active {
      transform:translateY(0);
      box-shadow:0 12px 30px rgba(15,23,42,0.5);
    }

    /* ALERTS */
    .alert {
      max-width:960px;
      margin:0 auto 16px;
      padding:10px 12px;
      border-radius:14px;
      font-size:13px;
      display:flex;
      align-items:flex-start;
      gap:8px;
    }

    .alert.error {
      background:rgba(248,113,113,0.08);
      color:#b91c1c;
      border:1px solid rgba(248,113,113,0.8);
    }

    .alert.success {
      background:rgba(45,212,191,0.08);
      color:#047857;
      border:1px solid rgba(45,212,191,0.8);
    }

    .alert ul {
      margin-left:16px;
      margin-top:4px;
    }

    @media (max-width: 900px) {
      .sidebar { display:none; }
      .main { margin-left:0; padding:18px 16px 30px; }
      .topbar { border-radius:18px; }
      .card { padding:18px 18px 22px; border-radius:22px; }
      .grid { grid-template-columns:1fr; }
      .payment-row { grid-template-columns:1fr; }
    }
  </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" class="sb-logo" alt="Cocovalley Logo">
    <div>
      <div class="sb-title">Cocovalley</div>
      <div class="sb-tag">Staff Portal</div>
    </div>
  </div>

  <nav class="nav">
    <a href="staff-dashboard.php" class="nav-item">
      <i class="fa-solid fa-house"></i> Dashboard
    </a>

    <div class="nav-group">
      <div class="nav-toggle" id="resToggle">
        <div class="label">
          <i class="fa-solid fa-calendar-days"></i>
          <span>Reservations</span>
        </div>
        <i class="fa-solid fa-chevron-down chev" id="chev"></i>
      </div>
      <div class="submenu" id="resMenu">
        <a href="staff-calendar.php">Calendar View</a>
        <a href="staff-reservation-list.php">List View</a>
        <a href="staff-walkin.php" class="active">Walk-in</a>
      </div>
    </div>

    <a href="staff-payment.php" class="nav-item">
      <i class="fa-solid fa-receipt"></i> Payment Proofs
    </a>
    <a href="staff-customer-list.php" class="nav-item">
      <i class="fa-solid fa-users"></i> Customer List
    </a>
    <a href="staff-notification.php" class="nav-item">
      <i class="fa-solid fa-bell"></i> Notifications
    </a>
    <a href="staff-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i> Announcements
    </a>
  </nav>
</aside>

<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-left">
      <div class="topbar-title">
        <span class="icon"><i class="fa-solid fa-person-walking-arrow-right"></i></span>
        <span>Walk-in Reservation</span>
      </div>
      <div class="topbar-sub">
        Book on-site guests quickly — prices, pax and time slots load automatically from the accommodations database.
      </div>
    </div>

    <div class="profile" id="profileBtn">
      <div class="avatar"><?= strtoupper(e(substr(trim($meName),0,1))) ?></div>
      <span><?= e($meName) ?></span>
      <i class="fa-solid fa-chevron-down"></i>

      <div class="dropdown" id="dropdown">
        <a href="staff-logout.php">Logout</a>
      </div>
    </div>
  </div>

  <!-- ALERTS (errors only, success handled by redirect now) -->
  <?php if ($errors): ?>
    <div class="alert error">
      <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;"></i>
      <div>
        <strong>There were some issues:</strong>
        <ul>
          <?php foreach ($errors as $er): ?>
            <li><?= e($er) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <!-- FORM CARD -->
  <section class="card">
    <div class="card-inner">
      <div class="card-header">
        <div class="card-title-group">
          <div class="card-title">Walk-in Reservation</div>
          <div class="card-sub">
            Fill in the customer details and choose an available package. Prices and capacity will auto-fill from the accommodations table.
          </div>
        </div>
        <span class="tag-pill">
          On-site Guest • Auto-approved
        </span>
      </div>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($CSRF) ?>"/>

        <!-- CUSTOMER + DATE -->
        <div class="grid">
          <div class="field">
            <label>Customer Name <span style="color:#dc2626">*</span></label>
            <input
              type="text"
              name="full_name"
              class="input"
              placeholder="e.g. Juan Dela Cruz"
              value="<?= e($_POST['full_name'] ?? '') ?>"
              required
            >
          </div>

          <div class="field">
            <label>Visit Date <span style="color:#dc2626">*</span></label>
            <input
              type="date"
              name="visit_date"
              class="input"
              value="<?= e($_POST['visit_date'] ?? date('Y-m-d')) ?>"
              required
            >
          </div>

          <div class="field">
            <label>Contact Number <span style="color:#dc2626">*</span></label>
            <input
              type="text"
              name="phone"
              class="input"
              placeholder="09XXXXXXXXX"
              value="<?= e($_POST['phone'] ?? '') ?>"
              required
            >
          </div>

          <div class="field">
            <label>Email <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
            <input
              type="email"
              name="email"
              class="input"
              placeholder="customer@example.com"
              value="<?= e($_POST['email'] ?? '') ?>"
            >
          </div>
        </div>

        <hr class="section-divider">

        <div class="section-label">Package &amp; Schedule</div>

        <div class="grid">
          <div class="field">
            <label>Category</label>
            <select name="category" id="categorySelect" class="select">
              <option value="cottage" <?= (($_POST['category'] ?? '') === 'room' || ($_POST['category'] ?? '') === 'event') ? '' : 'selected' ?>>Cottage</option>
              <option value="room"    <?= (($_POST['category'] ?? '') === 'room') ? 'selected' : '' ?>>Room</option>
              <option value="event"   <?= (($_POST['category'] ?? '') === 'event') ? 'selected' : '' ?>>Event</option>
            </select>
            <small>This controls which packages and time slots will appear.</small>
          </div>

          <div class="field">
            <label>Package</label>
            <select name="accommodation_id" id="packageSelect" class="select">
              <option value="">Select package</option>
            </select>
            <small>Loaded directly from the accommodations table.</small>
          </div>

          <div class="field">
            <label>Time Slot</label>
            <select name="time_slot" id="timeSlotSelect" class="select">
              <!-- JS will fill options based on category -->
            </select>
            <small id="timeSlotHint">
              Cottage: Day / Night • Room &amp; Event: 10 Hours / 22 Hours
            </small>
          </div>

          <div class="field">
            <label>Pax (capacity)</label>
            <input
              type="number"
              id="paxInput"
              class="input"
              name="pax_view"
              value=""
              readonly
            >
            <small>Auto-loaded from the selected package capacity.</small>
          </div>
        </div>

        <hr class="section-divider">

        <div class="section-label">Payment Summary</div>

        <div class="payment-row">
          <div class="field">
            <label>Base Price</label>
            <input
              type="text"
              id="basePriceInput"
              class="input"
              value="₱0.00"
              readonly
            >
            <small>Full price for the selected package & time slot.</small>

            <p class="muted">
              Amount to collect now is based on the payment option (50% down payment or full payment).
            </p>
          </div>

          <div class="field">
            <label>Payment Option</label>
            <div class="radio-row">
              <label>
                <input type="radio" name="payment_option" value="50"
                  <?= (($_POST['payment_option'] ?? '') === '50') ? 'checked' : '' ?>>
                <span>50% Down Payment</span>
              </label>
              <label>
                <input type="radio" name="payment_option" value="100"
                  <?= (($_POST['payment_option'] ?? '100') === '100') ? 'checked' : '' ?>>
                <span>Full Payment (100%)</span>
              </label>
            </div>
          </div>
        </div>

        <div class="summary-strip" style="margin-top:10px;">
          <span>
            Amount to collect now: <strong id="collectNowText">₱0.00</strong><br>
            <small>Automatically computed from the base price & payment option.</small>
          </span>
          <span>
            Full package price:<br>
            <strong id="fullPriceText">₱0.00</strong>
          </span>
        </div>

        <!-- Hidden fields (for reference, backend still recomputes) -->
        <input type="hidden" id="hiddenFullPrice" name="hidden_full_price" value="0">
        <input type="hidden" id="hiddenCollectAmount" name="hidden_collect_amount" value="0">
        <input type="hidden" id="hiddenPax" name="hidden_pax" value="0">

        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-floppy-disk"></i>
          <span>Save Walk-in Reservation</span>
        </button>
      </form>
    </div>
  </section>
</main>

<script>
document.addEventListener("DOMContentLoaded", () => {
  // Sidebar Reservations submenu open by default
  const resToggle = document.getElementById("resToggle");
  const resMenu   = document.getElementById("resMenu");
  const chev      = document.getElementById("chev");

  if (resToggle && resMenu && chev) {
    resToggle.addEventListener("click", () => {
      const open = resMenu.style.display === "flex";
      resMenu.style.display = open ? "none" : "flex";
      chev.classList.toggle("open", !open);
    });
    resMenu.style.display = "flex";
    chev.classList.add("open");
  }

  // Profile dropdown
  const profileBtn = document.getElementById("profileBtn");
  const dropdown   = document.getElementById("dropdown");

  if (profileBtn && dropdown) {
    profileBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      dropdown.style.display = dropdown.style.display === "flex" ? "none" : "flex";
    });

    document.addEventListener("click", (e) => {
      if (!profileBtn.contains(e.target)) {
        dropdown.style.display = "none";
      }
    });
  }

  // Accommodations data from PHP
  const accommodations = <?=
    json_encode(
      $accommodations,
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
  ?>;

  const categorySelect = document.getElementById("categorySelect");
  const packageSelect  = document.getElementById("packageSelect");
  const timeSlotSelect = document.getElementById("timeSlotSelect");
  const paxInput       = document.getElementById("paxInput");
  const basePriceInput = document.getElementById("basePriceInput");
  const collectNowText = document.getElementById("collectNowText");
  const fullPriceText  = document.getElementById("fullPriceText");
  const hiddenFull     = document.getElementById("hiddenFullPrice");
  const hiddenCollect  = document.getElementById("hiddenCollectAmount");
  const hiddenPax      = document.getElementById("hiddenPax");

  const payRadios = document.querySelectorAll("input[name='payment_option']");

  function getSelectedCategory() {
    return (categorySelect.value || "cottage").toLowerCase();
  }

  function filterPackagesByCategory(cat) {
    return accommodations.filter(acc => (acc.category || "").toLowerCase() === cat);
  }

  function loadPackages() {
    const cat = getSelectedCategory();
    const list = filterPackagesByCategory(cat);

    packageSelect.innerHTML = '<option value="">Select package</option>';

    list.forEach(acc => {
      const opt = document.createElement("option");
      opt.value = acc.id;
      opt.textContent = acc.package + (acc.capacity ? ` (Pax: ${acc.capacity})` : "");
      opt.dataset.capacity  = acc.capacity || 0;
      opt.dataset.dayPrice  = acc.day_price || 0;
      opt.dataset.nightPrice= acc.night_price || 0;
      opt.dataset.price10   = acc.price_10hrs || 0;
      opt.dataset.price22   = acc.price_22hrs || 0;
      packageSelect.appendChild(opt);
    });

    packageSelect.selectedIndex = 0;
    paxInput.value   = "";
    hiddenPax.value  = "0";
    resetPrices();
  }

  function loadTimeSlots() {
    const cat = getSelectedCategory();

    timeSlotSelect.innerHTML = "";

    if (cat === "cottage") {
      timeSlotSelect.innerHTML = `
        <option value="day">Day</option>
        <option value="night">Night</option>
      `;
    } else { // room or event
      timeSlotSelect.innerHTML = `
        <option value="10_hrs">10 Hours</option>
        <option value="22_hrs">22 Hours</option>
      `;
    }
  }

  function getSelectedPackageOption() {
    const val = packageSelect.value;
    if (!val) return null;
    return packageSelect.querySelector(`option[value="${val}"]`);
  }

  function calcPrice() {
    const cat  = getSelectedCategory();
    const slot = timeSlotSelect.value;
    const opt  = getSelectedPackageOption();

    if (!opt || !slot) {
      resetPrices();
      return;
    }

    let fullPrice = 0;

    if (cat === "cottage") {
      fullPrice = (slot === "day")
        ? parseFloat(opt.dataset.dayPrice || 0)
        : parseFloat(opt.dataset.nightPrice || 0);
    } else {
      fullPrice = (slot === "10_hrs")
        ? parseFloat(opt.dataset.price10 || 0)
        : parseFloat(opt.dataset.price22 || 0);
    }

    const capacity = parseInt(opt.dataset.capacity || "0", 10);
    paxInput.value  = capacity || "";
    hiddenPax.value = capacity || "0";

    if (!isFinite(fullPrice) || fullPrice <= 0) {
      resetPrices();
      return;
    }

    const paymentOption = document.querySelector("input[name='payment_option']:checked")?.value || "100";
    const percent       = paymentOption === "50" ? 0.5 : 1;
    const collect       = fullPrice * percent;

    basePriceInput.value = "₱" + fullPrice.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    fullPriceText.textContent = basePriceInput.value;
    collectNowText.textContent = "₱" + collect.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});

    hiddenFull.value    = fullPrice.toFixed(2);
    hiddenCollect.value = collect.toFixed(2);
  }

  function resetPrices() {
    basePriceInput.value = "₱0.00";
    fullPriceText.textContent = "₱0.00";
    collectNowText.textContent = "₱0.00";
    hiddenFull.value    = "0";
    hiddenCollect.value = "0";
  }

  // INIT
  loadPackages();
  loadTimeSlots();
  calcPrice();

  // Events
  categorySelect.addEventListener("change", () => {
    loadPackages();
    loadTimeSlots();
    calcPrice();
  });

  packageSelect.addEventListener("change", calcPrice);
  timeSlotSelect.addEventListener("change", calcPrice);

  payRadios.forEach(r => {
    r.addEventListener("change", calcPrice);
  });

  // Preselect previously chosen package/time slot (after validation error)
  (function restoreSelectionsFromPost() {
    const catPost  = "<?= e($_POST['category'] ?? '') ?>".toLowerCase();
    const accPost  = "<?= e($_POST['accommodation_id'] ?? '') ?>";
    const slotPost = "<?= e($_POST['time_slot'] ?? '') ?>";

    if (catPost) {
      categorySelect.value = catPost;
      loadPackages();
      loadTimeSlots();
    }

    if (accPost) {
      packageSelect.value = accPost;
    }

    if (slotPost) {
      timeSlotSelect.value = slotPost;
    }

    calcPrice();
  })();
});
</script>
</body>
</html>
