<?php
session_start();
include 'handlers/db_connect.php';

$current = basename($_SERVER['PHP_SELF']);

/* ============================================================
   FETCH ALL ACCOMMODATIONS
============================================================ */
$sql = "SELECT * FROM accommodations ORDER BY created_at DESC";
$result = $conn->query($sql);

$accommodations = [];

if ($result) {
  while ($row = $result->fetch_assoc()) {
    $accommodations[] = [
      'id'                  => $row['id'],
      'category'            => $row['category'],
      'package'             => $row['package'],
      'description'         => $row['description'],
      'capacity'            => $row['capacity'],
      'availability_status' => $row['availability_status'],
      'day_price'           => $row['day_price'],
      'night_price'         => $row['night_price'],
      'price_10hrs'         => $row['price_10hrs'],
      'price_22hrs'         => $row['price_22hrs'],
      'image_url'           => $row['image_url'],  // main image
      'inclusions'          => $row['inclusions'] ?? '',
      'created_at'          => $row['created_at'],
      'updated_at'          => $row['updated_at'],
    ];
  }
}

/* ============================================================
   FETCH ALL GALLERY PHOTOS
============================================================ */
$gallery_sql = "
    SELECT id, accommodation_id, file_path, created_at
    FROM accommodation_photos
    ORDER BY created_at DESC
";
$gallery_res = $conn->query($gallery_sql);

$gallery_photos = [];

if ($gallery_res) {
  while ($p = $gallery_res->fetch_assoc()) {
    $gallery_photos[] = [
      'id'               => $p['id'],
      'accommodation_id' => $p['accommodation_id'],
      'file_path'        => $p['file_path'],
      'created_at'       => $p['created_at']
    ];
  }
}

/* ============================================================
   CREATE POPUP NOTIF AFTER ACCOM ACTION (OPTIONAL)
============================================================ */
if (isset($_SESSION['accom_action'])) {

  $action_type = $_SESSION['accom_action']['type']; 
  $accom_name  = $_SESSION['accom_action']['package'];
  $now         = date('Y-m-d H:i:s');

  $msg = "Accommodation package '$accom_name' has been {$action_type} by the admin.";

  $stmt = $conn->prepare("
      INSERT INTO notifications (item_name, message, type, status, created_at, popup)
      VALUES (?, ?, 'popup', 'unread', ?, 1)
  ");
  $stmt->bind_param("sss", $accom_name, $msg, $now);
  $stmt->execute();
  $stmt->close();

  unset($_SESSION['accom_action']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin • Accommodations</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
/* =========================================================
   ROOT VARIABLES (aligned sa dashboard)
========================================================= */
:root {
  --primary: #222222;
  --accent: #0b72d1;
  --accent-soft: rgba(11, 114, 209, 0.08);
  --bg: #f7f7f7;
  --bg-soft: #ffffff;
  --border: #e5e7eb;
  --border-soft: #f0f0f0;
  --text: #111827;
  --muted: #6b7280;
  --shadow-soft: 0 14px 30px rgba(0,0,0,0.08);
  --sidebar-w: 260px;
}

/* =========================================================
   GLOBAL RESET
========================================================= */
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}
body {
  background: var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  overflow-x: hidden;
}
a {
  text-decoration: none;
  color: inherit;
}

/* =========================================================
   SIDEBAR (same structure feel)
========================================================= */
.sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: var(--sidebar-w);
  background: #ffffff;
  border-right: 1px solid var(--border-soft);
  color: #111827;
  display: flex;
  flex-direction: column;
  padding: 20px 18px;
  z-index: 30;
}
.sb-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.sb-logo {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  object-fit: cover;
  box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}
.sb-title {
  font-size: 18px;
  font-weight: 800;
  color: #111827;
}
.sb-tag {
  font-size: 13px;
  color: var(--muted);
}

/* SIDEBAR NAV */
.nav {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 8px;
}
.nav-item,
.nav-toggle {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
  padding: 9px 10px;
  border-radius: 999px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
  transition: background 0.16s ease, transform 0.12s ease;
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
  color: var(--accent);
  font-weight: 600;
  box-shadow: 0 10px 20px rgba(11, 114, 209, 0.25);
}
.nav-toggle {
  justify-content: space-between;
}
.nav-toggle .label {
  display: flex;
  align-items: center;
  gap: 10px;
}
.chev {
  font-size: 12px;
  transition: transform 0.2s ease;
  color: #9ca3af;
}
.chev.open {
  transform: rotate(180deg);
}
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
.submenu a:hover {
  background: #f3f4f6;
}

/* =========================================================
   MAIN CONTENT WRAPPER (dashboard style)
========================================================= */
.main {
  margin-left: var(--sidebar-w);
  min-height: 100vh;
  padding: 26px 34px 40px;
}

/* TOPBAR (same structure as dashboard) */
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border-radius: 999px;
  padding: 8px 16px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.06);
  border: 1px solid rgba(15,23,42,0.04);
  margin-bottom: 22px;
  position: sticky;
  top: 14px;
  z-index: 10;
}
.topbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.menu-btn {
  border: none;
  background: transparent;
  border-radius: 999px;
  width: 34px;
  height: 34px;
  display: none;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.menu-btn i {
  font-size: 18px;
  color: var(--primary);
}
.topbar-left h1 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 18px;
  font-weight: 700;
  color: var(--primary);
}
.topbar-left h1 i {
  background: var(--accent-soft);
  color: var(--accent);
  border-radius: 999px;
  padding: 7px;
  font-size: 14px;
}

/* Add button */
.btn-primary {
  background: linear-gradient(135deg,#0b72d1,#0a5eb0);
  border: none;
  padding: 9px 16px;
  border-radius: 999px;
  color: #fff;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 10px 18px rgba(11,114,209,0.25);
  transition: .15s;
  font-size: 14px;
}
.btn-primary:hover {
  filter: brightness(1.04);
  transform: translateY(-1px);
}

/* =========================================================
   CARD WRAPPER FOR ACCOMMODATION GRID
========================================================= */
.card {
  background: #ffffff;
  border-radius: 22px;
  border: 1px solid #e5e7eb;
  box-shadow: 0 18px 40px rgba(0,0,0,0.08);
  padding: 16px 18px 20px;
}
.card header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 12px;
}
.card header h2 {
  font-size: 16px;
  font-weight: 700;
  color: #111827;
  display: flex;
  align-items: center;
  gap: 8px;
}
.card header h2 i {
  color: var(--accent);
}
.card header .sub {
  font-size: 13px;
  color: var(--muted);
}

/* =========================================================
   ACCOMMODATION CARDS (aligned but Airbnb/card style)
========================================================= */
.acc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 18px;
  margin-top: 8px;
}

.acc-card {
  width: 100%;
  border-radius: 16px;
  overflow: hidden;
  background: #ffffff;
  box-shadow: 0 12px 26px rgba(15,23,42,0.08);
  border: 1px solid #e5e7eb;
  transition: transform 0.22s ease, box-shadow 0.22s ease, border 0.22s ease;
  cursor: pointer;
}
.acc-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 18px 40px rgba(15,23,42,0.12);
  border-color: rgba(11,114,209,0.4);
}

/* THUMBNAIL SECTION */
.thumb-wrap {
  position: relative;
}
.thumb-wrap img {
  width: 100%;
  height: 190px;
  object-fit: cover;
  display: block;
}
.thumb-more {
  position: absolute;
  bottom: 10px;
  right: 10px;
  background: rgba(15,23,42,0.75);
  padding: 5px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  color: #fff;
}

/* CARD CONTENT */
.acc-content {
  padding: 14px 14px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.acc-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}
.acc-content h3 {
  font-size: 16px;
  font-weight: 700;
  color: #111827;
}
.badge-cat {
  font-size: 11px;
  padding: 3px 8px;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: .08em;
  background: #eff6ff;
  color: #1d4ed8;
}
.acc-content p {
  font-size: 13px;
  color: #4b5563;
  line-height: 1.35;
}
.acc-info {
  font-size: 12.5px;
  color: #6b7280;
}

/* STATUS TAG */
.status-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  text-transform: capitalize;
  border: 1px solid transparent;
}
.status-pill.available {
  background: #dcfce7;
  color: #166534;
  border-color: #bbf7d0;
}
.status-pill.unavailable,
.status-pill.maintenance {
  background: #fef3c7;
  color: #92400e;
  border-color: #fde68a;
}

/* PRICE + CAPACITY LINE */
.acc-meta-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 12.5px;
  color: #4b5563;
}

/* BUTTONS */
.acc-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}
.pill {
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: #fff;
  border: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.pill.edit {
  background: linear-gradient(135deg,#0ea5e9,#0284c7);
}
.pill.del {
  background: linear-gradient(135deg,#ef4444,#b91c1c);
}

/* =========================================================
   MODALS (same as before, cleaned to fit dashboard)
========================================================= */
.modal {
  position: fixed;
  inset: 0;
  background: rgba(15,23,42,0.45);
  display: none;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 20px;
  z-index: 200;
  overflow-y: auto;
}
.sheet {
  background: #ffffff;
  border-radius: 16px;
  width: 850px;
  max-width: 95%;
  padding: 24px 26px 26px;
  border: 1px solid #e5e7eb;
  box-shadow: 0 24px 48px rgba(0,0,0,0.22);
  animation: fadeIn 0.25s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to   { opacity: 1; transform: translateY(0); }
}
.sheet header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #e5e7eb;
  padding-bottom: 8px;
  margin-bottom: 14px;
}
.sheet header h3 {
  font-size: 18px;
  font-weight: 700;
  color: var(--primary);
  display: flex;
  align-items: center;
  gap: 8px;
}
.sheet header h3 i {
  color: var(--accent);
}
.sheet header .x {
  font-size: 22px;
  background: none;
  border: none;
  cursor: pointer;
  color: #9ca3af;
}
.sheet header .x:hover {
  color: var(--primary);
}

/* FORM LAYOUT */
.modal-body {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}
.row-2 {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
.field {
  display: flex;
  flex-direction: column;
}
.field label {
  font-weight: 600;
  font-size: 0.9rem;
  margin-bottom: 4px;
}
.field input,
.field select,
.field textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  font-size: 0.93rem;
  margin-bottom: 6px;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.field textarea {
  min-height: 80px;
  resize: vertical;
}
.field input:focus,
.field select:focus,
.field textarea:focus {
  border-color: var(--accent);
  outline: none;
  box-shadow: 0 0 0 2px rgba(37,99,235,0.18);
}

/* GALLERY PREVIEW */
.gallery-preview {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
}
.gallery-thumb {
  width: 70px;
  height: 70px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
}
.gallery-item {
  position: relative;
}
.delete-photo {
  position: absolute;
  top: -6px;
  right: -6px;
  background: #e11d48;
  color: #fff;
  border: none;
  width: 22px;
  height: 22px;
  font-size: 14px;
  border-radius: 999px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ACTION BUTTONS */
.actions {
  grid-column: 1 / -1;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 6px;
}
.actions .btn {
  padding: 9px 18px;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  font-size: 14px;
}
.actions .btn.primary {
  background: linear-gradient(135deg,#0b72d1,#0a5eb0);
  color: #fff;
  border: none;
}
.actions .btn.primary:hover {
  filter: brightness(1.03);
}
.actions .btn.cancel {
  background: #e5e7eb;
  color: #111827;
  border: none;
}
.actions .btn.cancel:hover {
  background: #d1d5db;
}

/* =========================================================
   RESPONSIVE
========================================================= */
@media (max-width: 900px) {
  .main {
    margin-left: 0;
    padding: 18px 16px 30px;
  }
  .sidebar {
    transform: translateX(-100%);
    transition: transform 0.25s ease-out;
  }
  .sidebar.open {
    transform: translateX(0);
  }
  .menu-btn {
    display: inline-flex;
  }
}
@media (max-width: 768px) {
  .modal-body {
    grid-template-columns: 1fr;
  }
  .row-2 {
    grid-template-columns: 1fr;
  }
  .actions {
    flex-direction: column;
  }
  .actions .btn {
    width: 100%;
  }
}
  </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" class="sb-logo" alt="Cocovalley Logo">
    <div>
      <div class="sb-title">Cocovalley</div>
      <div class="sb-tag">Admin Portal</div>
    </div>
  </div>

  <nav class="nav">
    <a href="admin-dashboard.php" class="nav-item <?= $current==='admin-dashboard.php'?'active':'' ?>">
      <i class="fa-solid fa-house"></i>Dashboard
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
        <a href="admin-calendar.php" class="<?= $current==='admin-calendar.php'?'active':'' ?>">Calendar View</a>
        <a href="admin-reservation-list.php" class="<?= $current==='admin-reservation-list.php'?'active':'' ?>">List View</a>
        <a href="admin-2dmap.php" class="<?= $current==='admin-2dmap.php'?'active':'' ?>">2D Map</a>
      </div>
    </div>

    <a href="admin-payment.php" class="nav-item <?= $current==='admin-payment.php'?'active':'' ?>">
      <i class="fa-solid fa-receipt"></i>Payment Proofs
    </a>
    <a href="admin-customer-list.php" class="nav-item <?= $current==='admin-customer-list.php'?'active':'' ?>">
      <i class="fa-solid fa-users"></i>Customer List
    </a>
    <a href="admin-notification.php" class="nav-item <?= $current==='admin-notification.php'?'active':'' ?>">
      <i class="fa-solid fa-bell"></i>Notifications
    </a>
    <a href="admin-announcement.php" class="nav-item <?= $current==='admin-announcement.php'?'active':'' ?>">
      <i class="fa-solid fa-bullhorn"></i>Announcements
    </a>
    <a href="admin-accommodations.php" class="nav-item <?= $current==='admin-accommodations.php'?'active':'' ?>">
      <i class="fa-solid fa-bed"></i>Accommodations
    </a>
    <a href="admin-reports.php" class="nav-item <?= $current==='admin-reports.php'?'active':'' ?>">
      <i class="fa-solid fa-chart-column"></i>Reports
    </a>
    <a href="admin-archive.php" class="nav-item <?= $current==='admin-archive.php'?'active':'' ?>">
      <i class="fa-solid fa-box-archive"></i>Archive
    </a>
    <a href="admin-system-settings.php" class="nav-item <?= $current==='admin-system-settings.php'?'active':'' ?>">
      <i class="fa-solid fa-gear"></i>System Settings
    </a>
  </nav>
</aside>

<!-- MAIN CONTENT -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-btn" id="menuBtn" aria-label="Open sidebar">
        <i class="fa-solid fa-bars"></i>
      </button>
      <h1>
        <i class="fa-solid fa-bed"></i>
        Accommodations
      </h1>
    </div>

    <button class="btn-primary" onclick="openAddModal()">
      <i class="fa-solid fa-plus"></i> Add Accommodation
    </button>
  </div>

  <!-- CARD WRAPPER + GRID -->
  <section class="card">
    <header>
      <h2><i class="fa-solid fa-layer-group"></i> All Accommodation Packages</h2>
      <span class="sub">
        Manage rooms, cottages, and event halls available for reservation.
      </span>
    </header>

    <div class="acc-grid">
      <?php if (empty($accommodations)): ?>
        <div style="grid-column:1 / -1; text-align:center; color:#6b7280; padding:20px; font-style:italic;">
          No accommodations found.
        </div>
      <?php else: ?>
        <?php foreach ($accommodations as $acc): ?>
          <?php
            // Fetch gallery photos for this accommodation
            $photos = array_values(array_filter(
              $gallery_photos,
              fn($p) => $p['accommodation_id'] == $acc['id']
            ));
            $photoCount = count($photos);

            if ($photoCount > 0) {
              $thumb = htmlspecialchars($photos[0]['file_path']);
            } else {
              $thumb = htmlspecialchars($acc['image_url']);
            }
            if ($thumb && !str_starts_with($thumb, "uploads/")) {
              $thumb = "uploads/" . $thumb;
            }

            $catLabel = ucfirst($acc['category']);
            $statusClass = strtolower($acc['availability_status'] ?? 'available');
          ?>
          <div class="acc-card" onclick="editAcc(<?= (int)$acc['id'] ?>)">

            <div class="thumb-wrap">
              <?php if ($thumb): ?>
                <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($acc['package']) ?>">
              <?php else: ?>
                <img src="https://via.placeholder.com/600x400/edf2ff/1e293b?text=No+Image" alt="No image">
              <?php endif; ?>

              <?php if ($photoCount > 1): ?>
                <div class="thumb-more">+<?= $photoCount - 1 ?></div>
              <?php endif; ?>
            </div>

            <div class="acc-content">
              <div class="acc-header-row">
                <h3><?= htmlspecialchars($acc['package']) ?></h3>
                <span class="badge-cat"><?= htmlspecialchars($catLabel) ?></span>
              </div>

              <p><?= htmlspecialchars(substr($acc['description'], 0, 80)) ?>...</p>

              <div class="acc-meta-row">
                <span>
                  <?php if ($acc['category'] === 'cottage'): ?>
                    Day: ₱<?= number_format((float)$acc['day_price'], 2) ?> •
                    Night: ₱<?= number_format((float)$acc['night_price'], 2) ?>
                  <?php elseif ($acc['category'] === 'room' || $acc['category'] === 'event'): ?>
                    10hrs: ₱<?= number_format((float)$acc['price_10hrs'], 2) ?> •
                    22hrs: ₱<?= number_format((float)$acc['price_22hrs'], 2) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </span>

                <span>Max: <?= (int)$acc['capacity'] ?> pax</span>
              </div>

              <div class="acc-meta-row" style="margin-top:4px;">
                <span class="status-pill <?= $statusClass ?>">
                  <?= htmlspecialchars($acc['availability_status']) ?>
                </span>
              </div>

              <div class="acc-actions" onclick="event.stopPropagation();">
                <button class="pill edit" onclick="editAcc(<?= (int)$acc['id'] ?>)">
                  <i class="fa-solid fa-pen"></i> Edit
                </button>
                <button class="pill del" onclick="deleteAcc(<?= (int)$acc['id'] ?>)">
                  <i class="fa-solid fa-trash"></i> Delete
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</main>

<!-- ADD ACCOMMODATION MODAL -->
<div class="modal" id="addModal">
  <div class="sheet sheet-airbnb">

    <header class="modal-header">
      <h3><i class="fa-solid fa-bed"></i> Add Accommodation</h3>
      <button class="x" onclick="closeModal('addModal')">&times;</button>
    </header>

    <form id="addForm" enctype="multipart/form-data" class="modal-body">

      <div class="row-2">
        <div class="field">
          <label>Category</label>
          <select name="category" id="categorySelect" required onchange="showPriceFields()">
            <option value="">-- Select Category --</option>
            <option value="cottage">Cottage</option>
            <option value="room">Room</option>
            <option value="event">Event</option>
          </select>
        </div>

        <div class="field">
          <label>Package Name</label>
          <input type="text" name="package" placeholder="Lane / Standard Room / Event Hall" required>
        </div>
      </div>

      <div class="field">
        <label>Description</label>
        <textarea name="description" rows="3" required></textarea>
      </div>

      <div class="field">
        <label>Inclusions</label>
        <textarea name="inclusions" rows="3"></textarea>
      </div>

      <!-- Dynamic Price Fields -->
      <div id="priceFields" class="row-2"></div>

      <div class="row-2">
        <div class="field">
          <label>Maximum Occupancy</label>
          <input type="number" name="capacity" required>
        </div>

        <div class="field">
          <label>Gallery Photos</label>
          <input type="file" name="photos[]" accept="image/*" multiple onchange="previewAddGallery(event)">
          <div id="addGalleryPreview" class="gallery-preview"></div>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn cancel" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn primary">Add</button>
      </div>

    </form>
  </div>
</div>

<!-- EDIT ACCOMMODATION MODAL -->
<div class="modal" id="editModal">
  <div class="sheet sheet-airbnb">

    <header class="modal-header">
      <h3><i class="fa-solid fa-pen"></i> Edit Accommodation</h3>
      <button class="x" onclick="closeModal('editModal')">&times;</button>
    </header>

    <form id="editForm" enctype="multipart/form-data" class="modal-body">

      <!-- Hidden Fields -->
      <input type="hidden" name="id" id="editId">
      <input type="hidden" name="current_image" id="editCurrentImage">

      <!-- ROW 1 : CATEGORY + PACKAGE -->
      <div class="row-2">
        <div class="field">
          <label>Category</label>
          <select name="category" id="editCategory" required></select>
        </div>

        <div class="field">
          <label>Package Name</label>
          <input type="text" name="package" id="editPackage" required>
        </div>
      </div>

      <!-- DESCRIPTION -->
      <div class="field">
        <label>Description</label>
        <textarea name="description" id="editDescription" rows="3"></textarea>
      </div>

      <!-- INCLUSIONS -->
      <div class="field">
        <label>Inclusions</label>
        <textarea name="inclusions" id="editInclusions" rows="3"></textarea>
      </div>

      <!-- DYNAMIC PRICE FIELDS -->
      <div id="editPriceFields" class="row-2"></div>

      <!-- ROW 2 : MAX OCCUPANCY + AVAILABILITY -->
      <div class="row-2">
        <div class="field">
          <label>Maximum Occupancy</label>
          <input type="number" name="capacity" id="editCapacity" required>
        </div>

        <div class="field">
          <label>Availability Status</label>
          <select name="availability_status" id="editStatus">
            <option value="available">Available</option>
            <option value="unavailable">Unavailable</option>
            <option value="maintenance">Maintenance</option>
          </select>
        </div>
      </div>

      <!-- MAIN IMAGE -->
      <div class="field">
        <label>Main Image</label>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <img id="editMainImagePreview"
               src=""
               style="width:150px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;">
          <input type="file" name="image_url" accept="image/*" onchange="previewEditMain(event)">
        </div>
      </div>

      <!-- CURRENT GALLERY -->
      <div class="field">
        <label>Current Gallery</label>
        <div id="editGallery" class="gallery-preview"></div>
      </div>

      <!-- ADD MORE PHOTOS -->
      <div class="field">
        <label>Add More Photos</label>
        <input type="file" name="photos[]" accept="image/*" multiple onchange="previewEditNew(event)">
        <div id="editNewPreview" class="gallery-preview"></div>
      </div>

      <!-- ACTION BUTTONS -->
      <div class="actions">
        <button type="button" class="btn cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn primary">Save Changes</button>
      </div>

    </form>
  </div>
</div>

<script>
/* ============================================================
   LOAD ACCOMMODATION DATA FROM PHP
============================================================ */
const accommodations = <?= json_encode(
  $accommodations,
  JSON_UNESCAPED_UNICODE |
  JSON_HEX_TAG |
  JSON_HEX_AMP |
  JSON_HEX_QUOT |
  JSON_HEX_APOS
) ?> || [];

/* ============================================================
   SIDEBAR RESERVATION SUBMENU
============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  const resToggle = document.getElementById("resToggle");
  const resMenu   = document.getElementById("resMenu");
  const chev      = document.getElementById("chev");

  if (resToggle && resMenu && chev) {
    resToggle.addEventListener("click", () => {
      const open = resMenu.style.display === "flex";
      resMenu.style.display = open ? "none" : "flex";
      chev.classList.toggle("open", !open);
    });
    // default open on desktop
    resMenu.style.display = "flex";
    chev.classList.add("open");
  }

  // Mobile sidebar toggle
  const sidebar = document.getElementById("sidebar");
  const menuBtn = document.getElementById("menuBtn");

  if (menuBtn && sidebar) {
    menuBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      sidebar.classList.toggle("open");
    });

    document.addEventListener("click", (e) => {
      if (window.innerWidth <= 900) {
        if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
          sidebar.classList.remove("open");
        }
      }
    });
  }
});

/* ============================================================
   OPEN/CLOSE MODAL
============================================================ */
function openAddModal() {
  document.getElementById("addForm").reset();
  document.getElementById("priceFields").innerHTML = "";
  document.getElementById("addGalleryPreview").innerHTML = "";
  document.getElementById("addModal").style.display = "flex";
}
function closeModal(id) {
  document.getElementById(id).style.display = "none";
}

/* ============================================================
   ADD — PREVIEW GALLERY
============================================================ */
function previewAddGallery(event) {
  const preview = document.getElementById("addGalleryPreview");
  preview.innerHTML = "";

  [...event.target.files].forEach(f => {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.createElement("img");
      img.src = e.target.result;
      img.classList.add("gallery-thumb");
      preview.appendChild(img);
    };
    reader.readAsDataURL(f);
  });
}

/* ============================================================
   EDIT — PREVIEW NEW PHOTOS
============================================================ */
function previewEditNew(event) {
  const preview = document.getElementById("editNewPreview");
  preview.innerHTML = "";

  [...event.target.files].forEach(f => {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.createElement("img");
      img.src = e.target.result;
      img.classList.add("gallery-thumb");
      preview.appendChild(img);
    };
    reader.readAsDataURL(f);
  });
}

/* ============================================================
   EDIT — PREVIEW MAIN IMAGE
============================================================ */
function previewEditMain(event) {
  const img = document.getElementById("editMainImagePreview");
  img.src = URL.createObjectURL(event.target.files[0]);
}

/* ============================================================
   LOAD EXISTING GALLERY
============================================================ */
async function loadGallery(accId) {
  const container = document.getElementById("editGallery");
  container.innerHTML = "Loading...";

  const res = await fetch("handlers/gallery-fetch.php?id=" + accId);
  const json = await res.json();

  container.innerHTML = "";

  if (!json.length) {
    container.innerHTML = "<p style='color:#777;font-size:13px;'>No photos yet.</p>";
    return;
  }

  json.forEach(photo => {
    let file = photo.file_path.replace(/^uploads\//, "");
    file = "uploads/" + file;

    const div = document.createElement("div");
    div.classList.add("gallery-item");

    div.innerHTML = `
      <img src="${file}" class="gallery-thumb">
      <button class="delete-photo" onclick="deletePhoto(${photo.id}, ${accId})">&times;</button>
    `;

    container.appendChild(div);
  });
}

/* ============================================================
   DELETE GALLERY PHOTO
============================================================ */
async function deletePhoto(photoId, accId) {
  if (!confirm("Delete this photo?")) return;

  const form = new FormData();
  form.append("photo_id", photoId);

  const res = await fetch("handlers/gallery-delete.php", {
    method: "POST",
    body: form
  });

  const json = await res.json();

  if (json.status === "success") {
    loadGallery(accId);
  } else {
    alert(json.message || "Delete failed.");
  }
}

/* ============================================================
   PRICE FIELDS (ADD)
============================================================ */
function showPriceFields() {
  const category = document.getElementById("categorySelect").value.toLowerCase();
  const box = document.getElementById("priceFields");

  if (category === "cottage") {
    box.innerHTML = `
      <div class="field">
        <label>Day Price (₱)</label>
        <input type="number" name="day_price" required step="0.01">
      </div>
      <div class="field">
        <label>Night Price (₱)</label>
        <input type="number" name="night_price" required step="0.01">
      </div>
    `;
  } else {
    box.innerHTML = `
      <div class="field">
        <label>10 Hours Price (₱)</label>
        <input type="number" name="price_10hrs" required step="0.01">
      </div>
      <div class="field">
        <label>22 Hours Price (₱)</label>
        <input type="number" name="price_22hrs" required step="0.01">
      </div>
    `;
  }
}

/* ============================================================
   PRICE FIELDS (EDIT)
============================================================ */
function showEditPriceFields(category, acc = {}) {
  const box = document.getElementById("editPriceFields");

  const day = acc.day_price || "";
  const night = acc.night_price || "";
  const p10 = acc.price_10hrs || "";
  const p22 = acc.price_22hrs || "";

  if (category.toLowerCase() === "cottage") {
    box.innerHTML = `
      <div class="field">
        <label>Day Price (₱)</label>
        <input type="number" name="day_price" value="${day}" step="0.01" required>
      </div>
      <div class="field">
        <label>Night Price (₱)</label>
        <input type="number" name="night_price" value="${night}" step="0.01" required>
      </div>
    `;
  } else {
    box.innerHTML = `
      <div class="field">
        <label>10 Hours Price (₱)</label>
        <input type="number" name="price_10hrs" value="${p10}" step="0.01" required>
      </div>
      <div class="field">
        <label>22 Hours Price (₱)</label>
        <input type="number" name="price_22hrs" value="${p22}" step="0.01" required>
      </div>
    `;
  }
}

/* ============================================================
   OPEN EDIT MODAL
============================================================ */
function editAcc(id) {
  const acc = accommodations.find(a => a.id == id);
  if (!acc) return alert("Accommodation not found.");

  document.getElementById("editModal").style.display = "flex";

  document.getElementById("editId").value = acc.id;
  document.getElementById("editPackage").value = acc.package;
  document.getElementById("editDescription").value = acc.description;
  document.getElementById("editInclusions").value = acc.inclusions;
  document.getElementById("editCapacity").value = acc.capacity;
  document.getElementById("editStatus").value = acc.availability_status;

  // Category select
  const sel = document.getElementById("editCategory");
  sel.innerHTML = `
    <option value="cottage">Cottage</option>
    <option value="room">Room</option>
    <option value="event">Event</option>
  `;
  sel.value = acc.category;

  showEditPriceFields(acc.category, acc);
  sel.onchange = () => showEditPriceFields(sel.value, acc);

  // Main image path
  let img = acc.image_url || "";
  img = img.replace(/^uploads\//, "");
  img = "uploads/" + img;

  document.getElementById("editMainImagePreview").src = img;
  document.getElementById("editCurrentImage").value = acc.image_url;

  // Load gallery
  loadGallery(acc.id);
}

/* ============================================================
   ADD ACCOMMODATION (AJAX)
============================================================ */
document.getElementById("addForm").addEventListener("submit", async e => {
  e.preventDefault();

  const form = new FormData(e.target);
  form.append("action", "add");

  const res = await fetch("handlers/accommodation-handler.php", {
    method: "POST",
    body: form
  });

  const json = await res.json();

  if (json.status === "success") {
    alert("Accommodation added!");
    closeModal("addModal");
    location.reload();
  } else {
    alert(json.message || "Add failed.");
  }
});

/* ============================================================
   EDIT ACCOMMODATION (AJAX)
============================================================ */
document.getElementById("editForm").addEventListener("submit", async e => {
  e.preventDefault();

  const form = new FormData(e.target);
  form.append("action", "edit");

  const res = await fetch("handlers/accommodation-handler.php", {
    method: "POST",
    body: form
  });

  const json = await res.json();

  if (json.status === "success") {
    alert("Updated successfully!");
    closeModal("editModal");
    location.reload();
  } else {
    alert(json.message || "Update failed.");
  }
});

/* ============================================================
   DELETE ACCOMMODATION
============================================================ */
function deleteAcc(id) {
  if (!confirm("Delete this accommodation?")) return;

  const form = new FormData();
  form.append("action", "delete");
  form.append("id", id);

  fetch("handlers/accommodation-handler.php", {
    method: "POST",
    body: form
  })
    .then(r => r.json())
    .then(json => {
      if (json.status === "success") {
        alert("Accommodation deleted.");
        location.reload();
      } else {
        alert(json.message || "Delete failed.");
      }
    });
}
</script>
</body>
</html>
