<?php
declare(strict_types=1);

session_start();

/* ============================================================
   CUSTOMER ACCESS ONLY
============================================================ */
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id = (int) ($_SESSION['user_id'] ?? 0);

require_once '../admin/handlers/db_connect.php';

/* ============================================================
   OPTIONAL: Enable auto-cancel ONLY if you want it here
============================================================ */
define('ALLOW_AUTO_CANCEL', true);
require_once '../admin/handlers/auto_cancel_unpaid.php';

/* ============================================================
   FIXED: Use the correct session variable
============================================================ */
$user_id = $customer_id;

/* ========= HELPER: PRICE FORMAT ========= */
function cv_format_price($value) {
    if ($value === null || $value == 0) {
        return '₱ —';
    }
    return '₱' . number_format((float)$value, 2);
}

/* ============================================================
      FETCH USER INFO
============================================================ */
$stmt = $conn->prepare("
    SELECT first_name, last_name, avatar_path
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$_SESSION['first_name']  = $user['first_name'] ?? '';
$_SESSION['last_name']   = $user['last_name'] ?? '';
$_SESSION['avatar_path'] = $user['avatar_path'] ?? '';

$name = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);

/* Avatar fallback */
$avatar = (!empty($_SESSION['avatar_path']))
    ? '../admin/' . $_SESSION['avatar_path']
    : 'https://ui-avatars.com/api/?name=' . urlencode($name ?: 'Customer') . '&background=004d40&color=fff';

/* ============================================================
   FEATURED ACCOMMODATIONS (4 featured rooms/cottages)
============================================================ */
$sql = "
    SELECT 
        a.id,
        a.package,
        a.description,
        a.inclusions,
        a.capacity,
        a.day_price,
        a.night_price,
        a.price_10hrs,
        a.price_22hrs,
        a.image_url,

        (SELECT file_path 
         FROM accommodation_photos
         WHERE accommodation_id = a.id
         ORDER BY id ASC LIMIT 1) AS photo_fallback,

        COUNT(r.id) AS total_bookings

    FROM accommodations a
    LEFT JOIN reservations r 
        ON r.package = a.package
        AND MONTH(r.start_date) = MONTH(CURRENT_DATE())
        AND YEAR(r.start_date) = YEAR(CURRENT_DATE())
        AND r.status IN ('pending','approved')

    WHERE a.availability_status = 'available'

    GROUP BY a.id
    ORDER BY FIELD(a.package,
        'Lane Cottage',
        'Big Kubo Classic',
        'Cavana',
        'Barkada Room'
    )
    LIMIT 4
";

$result = $conn->query($sql);

$accommodations = [];
$accRows = [];
$accIds  = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $accRows[] = $row;
        $accIds[]  = (int) $row['id'];
    }
}

/* ===== FETCH ALL PHOTOS FOR ACCOMMODATIONS ===== */
$photosByAccId = [];

if (!empty($accIds)) {
    $idList = implode(',', array_map('intval', $accIds));

    $photoSql = "
        SELECT accommodation_id, file_path
        FROM accommodation_photos
        WHERE accommodation_id IN ($idList)
        ORDER BY id ASC
    ";
    $photoResult = $conn->query($photoSql);

    if ($photoResult && $photoResult->num_rows > 0) {
        while ($p = $photoResult->fetch_assoc()) {
            $aid  = (int) $p['accommodation_id'];
            $path = '../admin/' . ltrim($p['file_path'], '/');
            $photosByAccId[$aid][] = $path;
        }
    }
}

/* ===== BUILD FINAL ACCOM ARRAY ===== */
foreach ($accRows as $row) {

    $id = (int) $row['id'];

    // ALL PHOTOS FROM accommodation_photos
    $photos = $photosByAccId[$id] ?? [];

    // If accommodation.image_url exists, use as main image
    if (!empty($row['image_url'])) {
        $mainImage = $row['image_url'];
        array_unshift($photos, $mainImage);
    }

    // Fallback if no photos
    if (empty($photos)) {
        $photos[] = '../images/default_accommodation.jpg';
    }

    // CARD IMAGE = first photo
    $cardImage = $photos[0];

    $accommodations[] = [
        'id'           => $id,
        'name'         => $row['package'],
        'description'  => $row['description'],
        'inclusions'   => $row['inclusions'] ?? '',
        'capacity'     => (int) ($row['capacity'] ?? 0),

        'price'        => cv_format_price($row['day_price']),
        'price_day'    => cv_format_price($row['day_price']),
        'price_night'  => cv_format_price($row['night_price']),
        'price_10hrs'  => cv_format_price($row['price_10hrs']),
        'price_22hrs'  => cv_format_price($row['price_22hrs']),

        'image_url'    => $cardImage,
        'photos'       => $photos
    ];
}

/* ============================================================
      FETCH ANNOUNCEMENTS
============================================================ */
$announcements = [];

$aquery = $conn->query("
    SELECT title, message, image_url, start_date, end_date, created_at
    FROM announcements
    WHERE status = 'active'
      AND audience_type IN ('all','customer')
    ORDER BY created_at DESC
    LIMIT 10
");

if ($aquery && $aquery->num_rows > 0) {
    while ($row = $aquery->fetch_assoc()) {

        $img = (!empty($row['image_url']))
            ? '../admin/' . $row['image_url']
            : '';

        $announcements[] = [
            'title'       => $row['title'],
            'description' => $row['message'],
            'image_url'   => $img,
            'start_date'  => $row['start_date'],
            'end_date'    => $row['end_date'],
            'created_at'  => $row['created_at']
        ];
    }
}

/* ============================================================
      GREETING MESSAGE
============================================================ */
$hour = (int) date('H');
$greet = ($hour < 12)
    ? 'Good morning'
    : (($hour < 18) ? 'Good afternoon' : 'Good evening');

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Coco Valley • Customer Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<style>
/* ======= ROOT COLORS ======= */
:root {
  --primary:   #004d40;
  --secondary: #26a69a;
  --panel:     #ffffff;
  --text:      #1e293b;
  --muted:     #64748b;
  --shadow:    0 8px 24px rgba(15,23,42,0.08);
}

/* ===== Background ===== */
body {
  background: radial-gradient(circle at top left,#f0f9ff,#e8f5f3 40%,#f9fafb 75%);
  font-family: "Inter","Segoe UI",sans-serif;
  color: var(--text);
  overflow-x: hidden;
}

/* ===== SIDEBAR ===== */
.sidebar {
  position: fixed;
  top:0; left:0; bottom:0;
  width:250px;
  background: linear-gradient(180deg,#004d40,#00332d);
  color:#fff;
  padding:1rem 1rem 1.5rem;
  display:flex;
  flex-direction:column;
  box-shadow:4px 0 24px rgba(15,23,42,0.25);
  z-index:200;
}

/* Brand */
.brand {
  display:flex;
  align-items:center;
  gap:12px;
  padding:.8rem;
  border-radius:14px;
  margin-bottom:1.5rem;
  background:rgba(255,255,255,0.06);
}
.brand img {
  width:44px; height:44px;
  border-radius:12px;
  object-fit:cover;
}
.brand .name {
  font-weight:800;
  font-size:1.1rem;
}

/* Sidebar links */
.navlink {
  color:#d1fae5;
  text-decoration:none;
  padding:.7rem .9rem;
  border-radius:10px;
  margin-bottom:.3rem;
  display:flex;
  align-items:center;
  gap:10px;
  font-size:0.92rem;
  transition:.22s;
}
.navlink i {
  font-size:1.2rem;
}
.navlink:hover {
  background:rgba(38,166,154,0.22);
  transform:translateX(4px);
}
.navlink.active {
  background:linear-gradient(90deg,var(--secondary),var(--primary));
  font-weight:600;
  box-shadow:0 8px 18px rgba(15,23,42,0.30);
}

/* ===== MAIN CONTENT WRAPPER ===== */
.main {
  margin-left:250px;
  min-height:100vh;
}

/* ===== HEADER (TOP BAR) ===== */
.header {
  background:rgba(255,255,255,0.98);
  backdrop-filter:blur(14px);
  box-shadow:0 12px 30px rgba(15,23,42,0.10);
  padding:0.9rem 1.6rem;
  display:flex;
  justify-content:space-between;
  align-items:center;
  position:sticky;
  top:0;
  z-index:150;
}
.header h5 {
  font-weight:700;
  color:var(--primary);
  margin-bottom:3px;
}
.header .subtext {
  margin:0;
  font-size:0.9rem;
  color:var(--muted);
}

/* PROFILE AREA TOP-RIGHT */
.header .profile {
  display:flex;
  align-items:center;
  gap:10px;
}
.header .profile img {
  width:44px; height:44px;
  border-radius:50%;
  object-fit:cover;
  border:2px solid var(--secondary);
  box-shadow:0 0 0 3px rgba(38,166,154,0.18);
}
.profile-info {
  display:flex;
  flex-direction:column;
  align-items:flex-end;
}
.profile-name {
  font-size:0.9rem;
  font-weight:600;
  color:var(--primary);
}
.logout-btn {
  margin-top:2px;
  padding:4px 12px;
  border-radius:999px;
  border:1px solid rgba(148,163,184,0.7);
  background:#fff;
  font-size:0.8rem;
  display:flex;
  align-items:center;
  gap:4px;
  cursor:pointer;
  color:#475569;
  transition:.18s;
}
.logout-btn i {
  font-size:1rem;
}
.logout-btn:hover {
  background:#fee2e2;
  border-color:#fecaca;
  color:#b91c1c;
}

/* ===== CONTAINER WIDTH ===== */
.dashboard-shell {
  max-width:1200px;
}

/* ===========================
   TOP 3 DASHBOARD CARDS (PREMIUM)
=========================== */
.dash-top-row {
  margin-top:1.6rem;
}

.dash-top-card {
  display:block;
  width:100%;
  background:#ffffff;
  border-radius:20px;
  padding:20px 18px;
  text-decoration:none;
  border:1px solid #e4f2ee;
  box-shadow:0 3px 10px rgba(15,23,42,0.04);
  transition:0.25s ease;
  height:100%;
  display:flex;
  flex-direction:column;
}

.dash-top-card:hover {
  transform:translateY(-4px);
  box-shadow:0 14px 30px rgba(0,77,64,0.18);
  border-color:#cce7e2;
  background:linear-gradient(135deg,#ffffff,#f3fbf9);
}

.dash-top-card i {
  font-size:2rem;
  color:var(--primary);
  margin-bottom:10px;
}

.dash-top-card .label {
  font-size:1.05rem;
  font-weight:800;
  color:var(--primary);
}

.dash-top-card .sub {
  font-size:0.86rem;
  color:#6b7280;
  margin-top:3px;
}

@media (max-width:768px){
  .dash-top-card {
    padding:18px 16px;
  }
}

/* ===== WELCOME SECTION ===== */
.welcome-section {
  background:linear-gradient(135deg,rgba(0,77,64,0.10),rgba(38,166,154,0.12));
  border-radius:22px;
  padding:22px 24px;
  box-shadow:var(--shadow);
  margin-top:1.5rem;
  position:relative;
  overflow:hidden;
}
.welcome-section::before {
  content:"";
  position:absolute;
  right:-40px;
  top:-60px;
  width:200px;
  height:200px;
  background:radial-gradient(circle at center,rgba(255,255,255,0.9),transparent 60%);
  opacity:0.45;
}
.welcome-text {
  font-weight:800;
  font-size:1.35rem;
  color:#022c22;
}
.welcome-sub {
  color:var(--muted);
  margin-top:4px;
  font-size:0.95rem;
}

/* ===== ACTION BUTTONS ===== */
.action-buttons {
  margin-top:1.1rem;
  display:flex;
  gap:.7rem;
  flex-wrap:wrap;
}
.action-btn {
  display:flex;
  align-items:center;
  gap:6px;
  padding:.5rem 1.15rem;
  border-radius:999px;
  background:#fff;
  color:var(--primary);
  font-weight:600;
  border:1px solid rgba(0,77,64,0.18);
  box-shadow:0 4px 10px rgba(0,0,0,0.06);
  text-decoration:none;
  font-size:0.9rem;
  transition:transform .18s, box-shadow .18s, background .18s;
}
.action-btn i {
  font-size:1.1rem;
}
.action-btn.primary {
  background:linear-gradient(135deg,#004d40,#00796b);
  color:#fff;
  border-color:transparent;
  box-shadow:0 10px 20px rgba(0,77,64,0.40);
}
.action-btn:hover {
  transform:translateY(-1px);
  box-shadow:0 8px 16px rgba(0,0,0,0.10);
}

/* ===== SECTION TITLES ===== */
.section-block {
  background:transparent;
  margin-bottom:1.9rem;
}
.section-title {
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:0.85rem;
}
.section-title-left {
  display:flex;
  align-items:center;
  gap:10px;
}
.section-title-left i {
  font-size:1.3rem;
  color:var(--primary);
}
.section-title-left span {
  font-size:1.05rem;
  font-weight:800;
  color:var(--primary);
}
.section-pill {
  font-size:0.78rem;
  padding:4px 10px;
  border-radius:999px;
  background:#e0f2f1;
  color:#00695c;
  text-transform:uppercase;
  letter-spacing:.06em;
}

/* ===== ACCOMMODATIONS GRID (2-column fixed) ===== */
.room-grid {
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:1.2rem;
}
@media (max-width:768px){
  .room-grid {
    grid-template-columns:1fr;
  }
}

.room-card {
  background:var(--panel);
  border-radius:20px;
  overflow:hidden;
  box-shadow:0 10px 26px rgba(15,23,42,0.10);
  transition:transform .22s, box-shadow .22s;
  display:flex;
  flex-direction:column;
  position:relative;
  cursor:pointer;
}
.room-card:hover {
  transform:translateY(-4px);
  box-shadow:0 18px 40px rgba(15,23,42,0.20);
}
.room-card img {
  width:100%;
  height:200px;
  object-fit:cover;
  border-bottom:3px solid rgba(38,166,154,0.7);
  transition:transform .25s ease;
}
.room-card:hover img {
  transform:scale(1.04);
}
.room-card .tag {
  position:absolute;
  top:12px;
  left:12px;
  padding:4px 10px;
  background:rgba(0,77,64,0.92);
  color:#e0f2f1;
  font-size:0.72rem;
  border-radius:999px;
  text-transform:uppercase;
  letter-spacing:.06em;
}
.room-card .content {
  padding:0.95rem 1rem 1rem;
  display:flex;
  flex-direction:column;
  gap:4px;
}
.room-card h6 {
  font-size:1rem;
  font-weight:800;
  margin-bottom:0;
  color:#0f172a;
}
.room-card p {
  font-size:0.88rem;
  color:var(--muted);
  margin-bottom:0;
}
.room-meta-line {
  margin-top:4px;
  font-size:0.8rem;
  color:#94a3b8;
}
.room-card-footer {
  margin-top:6px;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.room-card .price {
  font-weight:800;
  color:var(--secondary);
  font-size:0.98rem;
}
.room-card .pill {
  font-size:0.78rem;
  padding:4px 10px;
  border-radius:999px;
  background:#e0f2f1;
  color:#00695c;
}

/* ===== PROMO SIDE PANEL (ENDING SOON) ===== */
.feed-section {
  background:var(--panel);
  border-radius:22px;
  box-shadow:var(--shadow);
  padding:1.2rem 1.3rem 1rem;
  margin-bottom:2rem;
}
.feed-header {
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
  margin-bottom:0.75rem;
}
.feed-header-title {
  font-size:1rem;
  font-weight:800;
  color:var(--primary);
  display:flex;
  align-items:center;
  gap:8px;
}
.feed-header-title i {
  font-size:1.2rem;
}
.feed-sub {
  font-size:0.82rem;
  color:var(--muted);
}
.feed-list {
  display:flex;
  flex-direction:column;
  gap:0.9rem;
  margin-top:0.5rem;
}
.feed-item {
  display:flex;
  gap:0.75rem;
  padding:0.7rem 0.2rem;
  border-bottom:1px solid #eef2f4;
}
.feed-item:last-child {
  border-bottom:none;
}
.feed-thumb {
  width:70px;
  height:70px;
  border-radius:12px;
  overflow:hidden;
  flex-shrink:0;
  background:#e2f3f0;
}
.feed-thumb img {
  width:100%;
  height:100%;
  object-fit:cover;
}
.feed-body {
  flex:1;
}
.feed-title {
  font-size:0.92rem;
  font-weight:700;
  color:var(--primary);
  margin-bottom:2px;
}
.feed-text {
  font-size:0.82rem;
  color:var(--muted);
  margin-bottom:4px;
}
.feed-meta {
  font-size:0.75rem;
  color:#94a3b8;
}

/* ===== FACEBOOK-STYLE FEED (BOTTOM) ===== */
.post-feed {
  display:flex;
  flex-direction:column;
  gap:1rem;
  margin-top:0.75rem;
}
.post-card {
  background:#ffffff;
  border-radius:22px;
  box-shadow:var(--shadow);
  padding:0.95rem 1.1rem 1rem;
}
.post-header {
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:0.35rem;
}
.post-user {
  display:flex;
  gap:10px;
  align-items:center;
}
.post-avatar {
  width:38px;
  height:38px;
  border-radius:50%;
  background:#e0f2f1;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:18px;
  font-weight:800;
  color:var(--primary);
  overflow:hidden;
}
.post-avatar img {
  width:100%;
  height:100%;
  object-fit:cover;
  border-radius:50%;
}
.post-user-name {
  font-weight:700;
  font-size:0.95rem;
  color:var(--text);
}
.post-meta-line {
  font-size:0.78rem;
  color:var(--muted);
}
.post-badge {
  font-size:0.7rem;
  text-transform:uppercase;
  letter-spacing:.06em;
  padding:2px 7px;
  border-radius:999px;
  background:#e0f2f1;
  color:#00695c;
  font-weight:700;
}
.post-body {
  margin-top:0.35rem;
  font-size:0.9rem;
  color:var(--text);
  white-space:pre-line;
}

/* Picture */
.post-image {
  margin-top:0.6rem;
  border-radius:16px;
  width:45%;
  height:auto;
  max-height:320px;
  object-fit:cover;
  background:#f1f5f9;
  cursor:pointer;
}
.post-footer {
  margin-top:0.55rem;
  display:flex;
  justify-content:space-between;
  align-items:center;
  font-size:0.78rem;
  color:#94a3b8;
}
.post-valid {
  font-size:0.78rem;
  color:#94a3b8;
}

/* ===== Responsive ===== */
@media(max-width:992px){
  .sidebar{transform:translateX(-100%);}
  .sidebar.show{transform:translateX(0);}
  .main{margin-left:0;}
  .header {padding:0.85rem 1rem;}
}
@media(max-width:576px){
  .welcome-section {padding:16px 14px;}
  .action-buttons {gap:0.5rem;}
}

/* ===============================
   POPUP NOTIFICATION CSS
=============================== */
.cv-popup {
  position: fixed;
  right: 24px;
  bottom: 24px;
  width: 280px;
  background: #ffffff;
  padding: 14px 16px;
  border-radius: 14px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.14);
  border-left: 5px solid var(--primary);
  z-index: 9999;
  animation: cvSlideIn 0.35s ease-out;
  transition: opacity 0.4s ease, transform 0.4s ease;
}
.cv-popup-title {
  font-size:15px;
  font-weight:700;
  color:var(--primary);
  margin-bottom:6px;
}
.cv-popup-text {
  font-size:14px;
  color:var(--text);
}
@keyframes cvSlideIn {
  from { transform:translateY(40px); opacity:0; }
  to   { transform:translateY(0); opacity:1; }
}
@media(max-width:600px){
  .cv-popup { right:12px; bottom:12px; width:calc(100% - 24px); }
}

/* ===============================
   LOGOUT MODAL
=============================== */
.logout-modal-backdrop {
  position:fixed;
  inset:0;
  background:rgba(15,23,42,0.45);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:2000;
}
.logout-modal {
  background:#ffffff;
  width:100%;
  max-width:360px;
  padding:20px 22px 18px;
  border-radius:16px;
  box-shadow:0 18px 45px rgba(15,23,42,0.35);
}
.logout-modal h5 {
  margin-bottom:6px;
  font-size:1.05rem;
  font-weight:700;
  color:#0f172a;
}
.logout-modal p {
  font-size:0.9rem;
  color:#64748b;
  margin-bottom:16px;
}
.logout-modal-actions {
  display:flex;
  justify-content:flex-end;
  gap:10px;
}
.btn-cancel {
  padding:8px 16px;
  border-radius:999px;
  border:1px solid #cbd5e1;
  background:#ffffff;
  font-size:0.85rem;
  cursor:pointer;
  color:#475569;
}
.btn-confirm {
  padding:8px 16px;
  border-radius:999px;
  border:none;
  background:#ef4444;
  color:#ffffff;
  font-size:0.85rem;
  cursor:pointer;
  font-weight:600;
}
.btn-confirm:hover {
  filter:brightness(1.05);
}

/* ===============================
   ACCOMMODATION DETAIL MODAL
=============================== */
.acc-modal-backdrop {
  position:fixed;
  inset:0;
  background:rgba(15,23,42,0.55);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:2100;
}
.acc-modal {
  background:#ffffff;
  width:100%;
  max-width:900px;
  border-radius:20px;
  padding:18px 20px 18px;
  box-shadow:0 24px 60px rgba(15,23,42,0.5);
  display:flex;
  gap:18px;
}
.acc-modal-left {
  flex:1.1;
  display:flex;
  flex-direction:column;
  gap:10px;
}
.acc-modal-image-wrapper {
  border-radius:16px;
  overflow:hidden;
  background:#f1f5f9;
  max-height:360px;
}
.acc-modal-image-wrapper img {
  width:100%;
  height:100%;
  object-fit:cover;
}
.acc-modal-thumbs {
  display:flex;
  flex-wrap:wrap;
  gap:6px;
  max-height:120px;
  overflow-y:auto;
}
.acc-modal-thumbs img {
  width:70px;
  height:70px;
  border-radius:10px;
  object-fit:cover;
  cursor:pointer;
  border:2px solid transparent;
}
.acc-modal-thumbs img.active {
  border-color:var(--secondary);
}

.acc-modal-right {
  flex:1;
  display:flex;
  flex-direction:column;
  gap:6px;
}
.acc-modal-header {
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:10px;
}
.acc-modal-title {
  font-size:1.1rem;
  font-weight:700;
  color:#0f172a;
}
.acc-modal-close {
  border:none;
  background:transparent;
  font-size:1.4rem;
  line-height:1;
  cursor:pointer;
  color:#94a3b8;
}
.acc-modal-close:hover {
  color:#0f172a;
}
.acc-modal-desc {
  font-size:0.9rem;
  color:#64748b;
}
.acc-modal-meta {
  font-size:0.85rem;
  color:#475569;
  margin-top:2px;
}
.acc-modal-prices {
  margin-top:6px;
  padding:8px 10px;
  border-radius:12px;
  background:#f1f5f9;
  font-size:0.85rem;
}
.acc-price-row {
  margin-bottom:3px;
}
.acc-price-row:last-child {
  margin-bottom:0;
}

.acc-modal-inclusions {
  margin-top:8px;
}
.acc-modal-inclusions h6 {
  font-size:0.9rem;
  font-weight:700;
  margin-bottom:3px;
}
.acc-modal-inclusions div {
  font-size:0.85rem;
  color:#4b5563;
  max-height:120px;
  overflow-y:auto;
  white-space:pre-line;
}

@media(max-width:768px){
  .acc-modal {
    flex-direction:column;
    max-width:95%;
  }
}

/* ===============================
   ANNOUNCEMENT IMAGE MODAL
=============================== */
.image-modal-backdrop {
  position:fixed;
  inset:0;
  background:rgba(15,23,42,0.7);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:2100;
}
.image-modal {
  background:#0f172a;
  padding:12px 12px 14px;
  border-radius:18px;
  max-width:90%;
  max-height:90%;
  box-shadow:0 24px 60px rgba(15,23,42,0.8);
  display:flex;
  flex-direction:column;
  align-items:stretch;
}
.image-modal-header {
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:6px;
}
.image-modal-caption {
  font-size:0.9rem;
  color:#e5e7eb;
}
.image-modal-close {
  border:none;
  background:transparent;
  font-size:1.4rem;
  line-height:1;
  cursor:pointer;
  color:#9ca3af;
}
.image-modal-close:hover { color:#e5e7eb; }
.image-modal img {
  border-radius:12px;
  max-width:100%;
  max-height:75vh;
  object-fit:contain;
  background:#020617;
}
</style>
</head>

<body>

<?php include 'sidebar-customer.php'; ?>

<main class="main">

  <!-- HEADER -->
  <header class="header">
    <div>
      <h5><?= htmlspecialchars($greet) ?>, <?= htmlspecialchars($name ?: 'Customer') ?>!</h5>
      <p class="subtext">Welcome back to Coco Valley — plan your next splash.</p>
    </div>

    <div class="profile">
      <div class="profile-info">
        <button type="button" id="logoutBtn" class="logout-btn">
          <i class='bx bx-log-out'></i> Logout
        </button>
      </div>
      <img src="<?= htmlspecialchars($avatar) ?>" alt="Profile">
    </div>
  </header>

  <div class="container dashboard-shell py-4">

    <!-- TOP DASHBOARD CARDS -->
      <div class="row g-3">

        <!-- MY RESERVATION -->
        <div class="col-md-4">
          </a>
        </div>

        <!-- NOTIFICATIONS -->
        <div class="col-md-4">
          </a>
        </div>

        <!-- PENDING -->
        <div class="col-md-4">
          </a>
        </div>

      </div>
    </section>

    <!-- WELCOME -->
    <section class="welcome-section">
      <div class="welcome-text">Welcome to Coco Valley</div>
      <div class="welcome-sub">Reserve your rooms &amp; cottages before they’re fully booked.</div>

      <div class="action-buttons">
        <a href="customer-accommodation.php" class="action-btn primary">
          <i class='bx bx-plus-circle'></i> Reserve Now
        </a>
        <a href="customer-accommodation.php" class="action-btn">
          <i class='bx bx-map'></i> 2D Map
        </a>
        <a href="customer-accommodation.php" class="action-btn">
          <i class='bx bx-bed'></i> View Accommodations
        </a>
        <a href="customer-notification.php" class="action-btn">
          <i class='bx bx-bell'></i> Notifications</a>
      </div>
    </section>

    <!-- FEATURED ACCOMMODATIONS + PROMOS -->
    <section class="section-block mt-4">
      <div class="row g-3">

        <!-- LEFT: FEATURED ACCOMMODATIONS -->
        <div class="col-lg-8 col-md-12">
          <div class="section-title">
            <div class="section-title-left">
              <i class='bx bx-star'></i>
              <span>Featured Accommodations</span>
            </div>
            <div class="section-pill">Curated picks</div>
          </div>

          <?php if (empty($accommodations)): ?>
            <p class="text-muted">No featured accommodations available at the moment.</p>
          <?php else: ?>
            <div class="room-grid">
              <?php foreach ($accommodations as $a):
                $dataJson = htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8');

                // Simple label: Cottage vs Room/Event
                $isRoomOrEvent = preg_match('/room|event/i', $a['name'] ?? '') === 1;
                $chipLabel = $isRoomOrEvent ? 'Room / Event' : 'Cottage / Kubo';
              ?>
                <article class="room-card" data-acc='<?= $dataJson ?>'>
                  <span class="tag"><?= htmlspecialchars($chipLabel) ?></span>

                  <img src="<?= htmlspecialchars($a['image_url']) ?>"
                       alt="<?= htmlspecialchars($a['name']) ?>">

                  <div class="content">
                    <h6><?= htmlspecialchars($a['name']) ?></h6>
                    <p><?= htmlspecialchars($a['description']) ?></p>

                    <div class="room-meta-line">
                      Capacity: <?= (int)$a['capacity'] > 0 ? (int)$a['capacity'].' guests' : 'N/A' ?>
                    </div>

                    <div class="room-card-footer">
                      <div class="price"><?= htmlspecialchars($a['price']) ?></div>
                      <div class="pill">View details</div>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- RIGHT: ENDING-SOON PROMOS -->
        <div class="col-lg-4 col-md-12">
          <div class="feed-section h-100">
            <div class="feed-header">
              <div class="feed-header-title">
                <i class='bx bx-purchase-tag'></i>
                <span>Ending Soon Promos</span>
              </div>
              <div class="feed-sub">Promos that are about to end — hurry while they last!</div>
            </div>

            <?php
            $endingSoon = [];
            $todayTs = time();

            foreach ($announcements as $promo) {
              if (!empty($promo['end_date'])) {
                $endTs = strtotime($promo['end_date']);

                if ($endTs !== false && $endTs >= $todayTs) {
                  $daysLeft = ($endTs - $todayTs) / 86400;

                  if ($daysLeft <= 7) {
                    $promo['days_left'] = floor($daysLeft);
                    $endingSoon[] = $promo;
                  }
                }
              }
            }
            ?>

            <?php if (empty($endingSoon)): ?>
              <p class="text-muted mb-0">No promos are about to end soon.</p>

            <?php else: ?>
              <div class="feed-list">
                <?php foreach ($endingSoon as $promo): ?>
                  <article class="feed-item">
                    <div class="feed-thumb">
                      <?php if (!empty($promo['image_url'])): ?>
                        <img src="<?= htmlspecialchars($promo['image_url']) ?>"
                             alt="<?= htmlspecialchars($promo['title']) ?>">
                      <?php endif; ?>
                    </div>

                    <div class="feed-body">
                      <div class="feed-title"><?= htmlspecialchars($promo['title']) ?></div>
                      <div class="feed-text"><?= htmlspecialchars($promo['description']) ?></div>

                      <div class="feed-meta">
                        Ends on: <?= htmlspecialchars($promo['end_date']) ?> •

                        <?php if (($promo['days_left'] ?? 0) == 0): ?>
                          <span class="text-danger fw-semibold">Last day!</span>
                        <?php elseif (($promo['days_left'] ?? 0) == 1): ?>
                          <span class="text-danger fw-semibold">1 day left</span>
                        <?php else: ?>
                          <span class="text-danger fw-semibold"><?= (int)($promo['days_left'] ?? 0) ?> days left</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </section>

    <!-- ANNOUNCEMENTS FEED -->
    <section class="feed-section mt-4">
      <div class="feed-header">
        <div class="feed-header-title">
          <i class='bx bx-news'></i>
          <span>Announcements &amp; Promos</span>
        </div>
        <div class="feed-sub">
          Home-style feed — posts can be text-only or with photos, just like Facebook.
        </div>
      </div>

      <?php if (empty($announcements)): ?>
        <p class="text-muted mb-0">No announcements available at the moment.</p>

      <?php else: ?>
        <div class="post-feed">
          <?php foreach ($announcements as $an):
              $posted = $an['created_at'] ? date('M d, Y · g:i A', strtotime($an['created_at'])) : '';
              $valid  = ($an['start_date'] || $an['end_date'])
                        ? trim(($an['start_date'] ?: 'N/A') . ' – ' . ($an['end_date'] ?: ''))
                        : '';
          ?>
            <article class="post-card">
              <div class="post-header">
                <div class="post-user">
                  <div class="post-avatar">
                    CV
                  </div>
                  <div>
                    <div class="post-user-name">Coco Valley Admin</div>
                    <div class="post-meta-line">
                      <?= htmlspecialchars($posted) ?>
                    </div>
                  </div>
                </div>
                <div class="post-badge">
                  ANNOUNCEMENT
                </div>
              </div>

              <?php if (!empty($an['title'])): ?>
                <div class="fw-semibold mb-1">
                  <?= htmlspecialchars($an['title']) ?>
                </div>
              <?php endif; ?>

              <div class="post-body">
                <?= nl2br(htmlspecialchars($an['description'])) ?>
              </div>

              <?php if (!empty($an['image_url'])): ?>
                <img src="<?= htmlspecialchars($an['image_url']) ?>"
                     alt="<?= htmlspecialchars($an['title']) ?>"
                     class="post-image js-announcement-image">
              <?php endif; ?>

              <div class="post-footer">
                <div class="post-valid">
                  <?php if ($valid): ?>
                    Valid: <?= htmlspecialchars($valid) ?>
                  <?php else: ?>
                    Ongoing update
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </section>

  </div>
</main>

<!-- ACCOMMODATION DETAIL MODAL -->
<div id="accModal" class="acc-modal-backdrop">
  <div class="acc-modal">
    <div class="acc-modal-left">
      <div class="acc-modal-image-wrapper">
        <img id="accModalHero" src="" alt="">
      </div>
      <div id="accModalThumbs" class="acc-modal-thumbs"></div>
    </div>
    <div class="acc-modal-right">
      <div class="acc-modal-header">
        <div class="acc-modal-title" id="accModalTitle">Accommodation</div>
        <button type="button" class="acc-modal-close" data-close-acc>&times;</button>
      </div>
      <p class="acc-modal-desc" id="accModalDesc"></p>
      <div class="acc-modal-meta">
        Capacity: <span id="accModalCapacity"></span> guests
      </div>
      <div class="acc-modal-prices">
        <div id="wrapPriceDay" class="acc-price-row">
          <strong>Day rate:</strong> <span id="accModalPriceDay"></span>
        </div>
        <div id="wrapPriceNight" class="acc-price-row">
          <strong>Night rate:</strong> <span id="accModalPriceNight"></span>
        </div>
        <div id="wrapPrice10" class="acc-price-row">
          <strong>10 hours:</strong> <span id="accModalPrice10"></span>
        </div>
        <div id="wrapPrice22" class="acc-price-row">
          <strong>22 hours:</strong> <span id="accModalPrice22"></span>
        </div>
      </div>
      <div class="acc-modal-inclusions">
        <h6>Inclusions</h6>
        <div id="accModalInclusions"></div>
      </div>
    </div>
  </div>
</div>

<!-- ANNOUNCEMENT IMAGE MODAL -->
<div id="imgModal" class="image-modal-backdrop">
  <div class="image-modal">
    <div class="image-modal-header">
      <div class="image-modal-caption" id="imgModalCaption"></div>
      <button type="button" class="image-modal-close" data-close-img>&times;</button>
    </div>
    <img id="imgModalImg" src="" alt="">
  </div>
</div>

<!-- LOGOUT CONFIRMATION MODAL -->
<div id="logoutModal" class="logout-modal-backdrop">
  <div class="logout-modal">
      <h5>Log out of your account?</h5>
      <p>Are you sure you want to log out?</p>
      <div class="logout-modal-actions">
          <button type="button" class="btn-cancel" id="cancelLogout">No, stay</button>
          <button type="button" class="btn-confirm" id="confirmLogout">Yes, log out</button>
      </div>
  </div>
</div>

<!-- POPUP SOUND -->
<audio id="popupSound" src="../sounds/popup.mp3" preload="auto"></audio>

<script>
// ========================================
// 🔊 PLAY POPUP SOUND
// ========================================
function playPopupSound() {
  const audio = document.getElementById("popupSound");
  if (!audio) return;

  audio.volume = 0.6;

  audio.play().catch(() => {
    console.warn("Sound auto-play blocked by browser.");
  });
}

// ========================================
// 🔔 TRACK DISPLAYED POPUPS
// ========================================
let shownPopupIDs = new Set();

// ========================================
// 📡 CHECK POPUP NOTIFICATIONS
// ========================================
async function checkPopupNotifications() {
  try {
    const response = await fetch("handlers/notifications-popup.php", {
      method: "GET",
      cache: "no-cache"
    });

    if (!response.ok) {
      console.error("Network error:", response.status);
      return;
    }

    const notifications = await response.json();

    if (!Array.isArray(notifications)) {
      console.warn("Unexpected response:", notifications);
      return;
    }

    notifications.forEach(notif => {
      if (notif.id && !shownPopupIDs.has(notif.id)) {
        shownPopupIDs.add(notif.id);
        showPopup(notif);
      }
    });

  } catch (err) {
    console.error("Popup Fetch Error:", err);
  }
}

// 🔄 Check every 5 seconds
setInterval(checkPopupNotifications, 5000);

// ========================================
// 🟢 SHOW POPUP BOX
// ========================================
function showPopup(notif) {
  const box = document.createElement("div");
  box.className = "cv-popup";

  box.innerHTML = `
    <div class="cv-popup-title">🏡 New Accommodation Update</div>
    <div class="cv-popup-text">${notif.message}</div>
  `;

  document.body.appendChild(box);
  playPopupSound();

  setTimeout(() => {
    box.style.opacity = "0";
    box.style.transform = "translateX(25px)";
    setTimeout(() => box.remove(), 500);
  }, 3800);
}

// ========================================
// 🚪 LOGOUT POPUP LOGIC
// ========================================
const logoutBtn     = document.getElementById('logoutBtn');
const logoutModal   = document.getElementById('logoutModal');
const cancelLogout  = document.getElementById('cancelLogout');
const confirmLogout = document.getElementById('confirmLogout');

if (logoutBtn && logoutModal && cancelLogout && confirmLogout) {
  logoutBtn.addEventListener('click', () => {
    logoutModal.style.display = 'flex';
  });

  cancelLogout.addEventListener('click', () => {
    logoutModal.style.display = 'none';
  });

  confirmLogout.addEventListener('click', () => {
    window.location.href = "customer-logout.php";
  });

  logoutModal.addEventListener('click', (e) => {
    if (e.target === logoutModal) {
      logoutModal.style.display = 'none';
    }
  });
}

// ========================================
// 🏠 ACCOMMODATION DETAIL MODAL LOGIC
// ========================================
const accModalBackdrop = document.getElementById('accModal');
const accHero          = document.getElementById('accModalHero');
const accThumbs        = document.getElementById('accModalThumbs');
const accTitle         = document.getElementById('accModalTitle');
const accDesc          = document.getElementById('accModalDesc');
const accCapacity      = document.getElementById('accModalCapacity');
const accPriceDay      = document.getElementById('accModalPriceDay');
const accPriceNight    = document.getElementById('accModalPriceNight');
const accPrice10       = document.getElementById('accModalPrice10');
const accPrice22       = document.getElementById('accModalPrice22');
const accInclusions    = document.getElementById('accModalInclusions');

const wrapPriceDay   = document.getElementById('wrapPriceDay');
const wrapPriceNight = document.getElementById('wrapPriceNight');
const wrapPrice10    = document.getElementById('wrapPrice10');
const wrapPrice22    = document.getElementById('wrapPrice22');

function openAccModal(acc) {
  if (!accModalBackdrop) return;

  accTitle.textContent    = acc.name || '';
  accDesc.textContent     = acc.description || '';
  accCapacity.textContent = acc.capacity || '—';
  accPriceDay.textContent   = acc.price_day || '₱ —';
  accPriceNight.textContent = acc.price_night || '₱ —';
  accPrice10.textContent    = acc.price_10hrs || '₱ —';
  accPrice22.textContent    = acc.price_22hrs || '₱ —';

  accInclusions.innerHTML = acc.inclusions
      ? acc.inclusions.replace(/\n/g, '<br>')
      : 'No listed inclusions.';

  const photos = Array.isArray(acc.photos) ? acc.photos : [acc.image_url];

  // Set hero image
  if (photos.length > 0) {
    accHero.src = photos[0];
    accHero.alt = acc.name || '';
  }

  // Build thumbnails
  accThumbs.innerHTML = '';
  photos.forEach((p, idx) => {
    const img = document.createElement('img');
    img.src = p;
    img.alt = acc.name || '';
    if (idx === 0) img.classList.add('active');
    img.addEventListener('click', () => {
      accHero.src = p;
      Array.from(accThumbs.querySelectorAll('img')).forEach(th => th.classList.remove('active'));
      img.classList.add('active');
    });
    accThumbs.appendChild(img);
  });

  // ===== PRICE VISIBILITY LOGIC =====
  // Cottage: show Day/Night; Room/Event: show 10/22
  const name = (acc.name || '').toLowerCase();
  const isRoomOrEvent = name.includes('room') || name.includes('event');

  if (isRoomOrEvent) {
    // Room / Event → 10 & 22 hrs only
    if (wrapPriceDay)   wrapPriceDay.style.display = 'none';
    if (wrapPriceNight) wrapPriceNight.style.display = 'none';
    if (wrapPrice10)    wrapPrice10.style.display = 'block';
    if (wrapPrice22)    wrapPrice22.style.display = 'block';
  } else {
    // Cottage / Kubo / Gazebo → Day & Night only
    if (wrapPriceDay)   wrapPriceDay.style.display = 'block';
    if (wrapPriceNight) wrapPriceNight.style.display = 'block';
    if (wrapPrice10)    wrapPrice10.style.display = 'none';
    if (wrapPrice22)    wrapPrice22.style.display = 'none';
  }

  accModalBackdrop.style.display = 'flex';
}

function closeAccModal() {
  if (accModalBackdrop) accModalBackdrop.style.display = 'none';
}

document.querySelectorAll('[data-close-acc]').forEach(btn => {
  btn.addEventListener('click', closeAccModal);
});

if (accModalBackdrop) {
  accModalBackdrop.addEventListener('click', (e) => {
    if (e.target === accModalBackdrop) {
      closeAccModal();
    }
  });
}

document.querySelectorAll('.room-card').forEach(card => {
  card.addEventListener('click', () => {
    const json = card.getAttribute('data-acc');
    if (!json) return;
    try {
      const acc = JSON.parse(json);
      openAccModal(acc);
    } catch (e) {
      console.error('Failed to parse accommodation JSON', e);
    }
  });
});

// ========================================
// 🖼 ANNOUNCEMENT IMAGE MODAL LOGIC
// ========================================
const imgModalBackdrop = document.getElementById('imgModal');
const imgModalImg      = document.getElementById('imgModalImg');
const imgModalCaption  = document.getElementById('imgModalCaption');

function openImgModal(src, caption) {
  if (!imgModalBackdrop) return;
  imgModalImg.src = src;
  imgModalCaption.textContent = caption || '';
  imgModalBackdrop.style.display = 'flex';
}

function closeImgModal() {
  if (imgModalBackdrop) imgModalBackdrop.style.display = 'none';
}

document.querySelectorAll('[data-close-img]').forEach(btn => {
  btn.addEventListener('click', closeImgModal);
});

if (imgModalBackdrop) {
  imgModalBackdrop.addEventListener('click', (e) => {
    if (e.target === imgModalBackdrop) closeImgModal();
  });
}

document.querySelectorAll('.js-announcement-image').forEach(img => {
  img.addEventListener('click', () => {
    openImgModal(img.src, img.alt || 'Announcement image');
  });
});
</script>

</body>
</html>
