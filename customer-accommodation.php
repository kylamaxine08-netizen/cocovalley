<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ============================================================
      CUSTOMER ACCESS ONLY
============================================================ */
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id = (int) $_SESSION['user_id'];

require_once '../admin/handlers/db_connect.php';

$current_page = basename($_SERVER['PHP_SELF']);

/* ============================================================
      FETCH ACCOMMODATIONS
============================================================ */

$accommodations = [];

$sql = "
  SELECT 
    id,
    category,
    package,
    description,
    inclusions,
    capacity,
    availability_status,
    day_price,
    night_price,
    price_10hrs,
    price_22hrs,
    image_url,
    created_at
  FROM accommodations
  WHERE availability_status = 'available'
  ORDER BY created_at DESC
";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {

    /* Prepare gallery fetcher */
    $galleryStmt = $conn->prepare("
        SELECT file_path 
        FROM accommodation_photos 
        WHERE accommodation_id = ?
        ORDER BY id ASC
    ");

    while ($row = $result->fetch_assoc()) {

        /* ============================================================
              CATEGORY NORMALIZATION
        ============================================================= */
        $categoryRaw = trim($row['category'] ?? '');
        $category    = ucfirst(strtolower($categoryRaw)); 
        $typeClass   = strtolower($category);    

        if (!in_array($typeClass, ['cottage','room','event'])) {
            $typeClass = "other";
        }

        /* ============================================================
              PRICING LOGIC
        ============================================================= */
        $day   = (float)($row['day_price'] ?? 0);
        $night = (float)($row['night_price'] ?? 0);
        $p10   = (float)($row['price_10hrs'] ?? 0);
        $p22   = (float)($row['price_22hrs'] ?? 0);

        $display_price = 0;
        $price_info = "";

        if ($category === 'Cottage') {

            $price_info = "
                <strong>Day Price:</strong> ₱" . number_format($day, 2) . "<br>
                <strong>Night Price:</strong> ₱" . number_format($night, 2);

            $display_price = $day ?: $night;

        } elseif ($category === 'Room') {

            $price_info = "
                <strong>10 Hours:</strong> ₱" . number_format($p10, 2) . "<br>
                <strong>22 Hours:</strong> ₱" . number_format($p22, 2);

            $display_price = $p10 ?: $p22;

        } elseif ($category === 'Event') {

            $price_info = "
                <strong>10 Hours (if available):</strong> ₱" . number_format($p10, 2) . "<br>
                <strong>22 Hours (Event):</strong> ₱" . number_format($p22, 2);

            $display_price = $p22;

        } else {

            $price_info = "<em>No price data available.</em>";
            $display_price = 0;
        }

        /* ============================================================
              BUILD FULL IMAGE SET
        ============================================================= */
        $images = [];

        // MAIN IMAGE
        if (!empty($row['image_url'])) {
            $images[] = "../admin/" . ltrim($row['image_url'], '/');
        }

        // GALLERY IMAGES
        if ($galleryStmt) {
            $accId = (int)$row['id'];
            $galleryStmt->bind_param("i", $accId);
            $galleryStmt->execute();
            $gRes = $galleryStmt->get_result();

            while ($g = $gRes->fetch_assoc()) {
                if (!empty($g['file_path'])) {
                    $images[] = "../admin/" . ltrim($g['file_path'], '/');
                }
            }
        }

        // FALLBACK IMAGE
        if (empty($images)) {
            $images[] = "../images/default.jpg";
        }

        /* ============================================================
              FINAL STRUCTURED ACCOM ARRAY
        ============================================================= */
        $accommodations[] = [
            "id"                  => (int)$row['id'],
            "category"            => htmlspecialchars($category),
            "type"                => $typeClass,
            "name"                => htmlspecialchars($row['package'] ?? ''),
            "price"               => $display_price,
            "day_price"           => $day,
            "night_price"         => $night,
            "price_10hrs"         => $p10,
            "price_22hrs"         => $p22,
            "capacity"            => (int)($row['capacity'] ?? 0),
            "description"         => htmlspecialchars($row['description'] ?? ''),
            "details"             => "
                <strong>Category:</strong> {$category}<br>
                <strong>Status:</strong> " . htmlspecialchars($row['availability_status'] ?? '-') . "<br><br>
                {$price_info}<br><br>
                <strong>Inclusions:</strong><br>" . nl2br(htmlspecialchars($row['inclusions'] ?? '')),
            "images"              => $images,
            "availability_status" => htmlspecialchars($row['availability_status'] ?? '')
        ];
    }

    if ($galleryStmt) { $galleryStmt->close(); }
}

/* ============================================================
      BACK FROM RESERVATION (AUTO-OPEN)
============================================================ */
$autoOpen = false;
if (!empty($_GET['category']) &&
    !empty($_GET['package'])  &&
    !empty($_GET['date'])     &&
    !empty($_GET['time_slot'])) {

    $autoOpen   = true;
    $back_category  = htmlspecialchars($_GET['category']);
    $back_package   = htmlspecialchars($_GET['package']);
    $back_date      = htmlspecialchars($_GET['date']);
    $back_slot      = htmlspecialchars($_GET['slot'] ?? '');
    $back_time      = htmlspecialchars($_GET['time_slot']);
    $back_image     = htmlspecialchars($_GET['image'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Coco Valley • Accommodations</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet"/>
<style>
:root {
  --primary:#004d40;
  --secondary:#26a69a;
  --panel:#ffffff;
  --text:#1e293b;
  --muted:#64748b;
  --shadow:0 6px 18px rgba(0,0,0,0.08);
}

body {
  background:linear-gradient(135deg,#f6f9f8,#e8f5f3);
  font-family:"Inter","Segoe UI",sans-serif;
  color:var(--text);
  overflow-x:hidden;
}

/* Sidebar */
.sidebar {
  position:fixed;
  inset:0 auto 0 0;
  width:250px;
  background:linear-gradient(180deg,var(--primary),#00332d);
  color:#fff;
  padding:1rem;
  display:flex;
  flex-direction:column;
  z-index:200;
}

.brand {
  display:flex;
  align-items:center;
  gap:12px;
  padding:.8rem;
  margin-bottom:1.5rem;
  background:rgba(255,255,255,0.05);
  border-radius:12px;
}

.brand img {
  width:44px;
  height:44px;
  border-radius:12px;
  object-fit:cover;
}

.brand .name {
  font-weight:800;
  font-size:1.1rem;
  color:#fff;
}

.navlink {
  color:#d1fae5;
  text-decoration:none;
  padding:.75rem .9rem;
  border-radius:10px;
  margin-bottom:.4rem;
  display:flex;
  align-items:center;
  gap:10px;
  transition:.25s;
}

.navlink:hover {
  background:rgba(255,255,255,0.15);
  color:#fff;
}

.navlink.active {
  background:linear-gradient(90deg,var(--secondary),var(--primary));
  color:#fff;
  font-weight:600;
  border-left:4px solid var(--secondary);
}

/* Main Layout */
.main {
  margin-left:250px;
  position:relative;
  z-index:1;
}

.header {
  background:#fff;
  box-shadow:var(--shadow);
  padding:1rem 1.4rem;
  display:flex;
  align-items:center;
  justify-content:space-between;
  position:relative;
  z-index:10;
}

.header h4 {
  font-weight:700;
  color:var(--primary);
  margin:0;
}

/* Container for cards */
.container-acc {
  max-width:1300px;
  margin:0 auto;
  padding:0 20px 30px 20px;
  position:relative;
  z-index:1;
}

/* Grid Layout */
.grid {
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(270px,1fr));
  gap:1.5rem;
  margin-top:1.5rem;
}

/* Card Design */
.card-acc {
  background:var(--panel);
  border-radius:16px;
  overflow:hidden;
  box-shadow:var(--shadow);
  transition:.2s;
  position:relative;
  cursor:default;
}

.card-acc:hover {
  transform:translateY(-4px);
  box-shadow:0 6px 20px rgba(0,0,0,0.1);
}

.card-image {
  width:100%;
  height:220px;
  overflow:hidden;
}

.card-image img {
  width:100%;
  height:100%;
  object-fit:cover;
}

/* Card body */
.card-body {
  padding:1rem;
}

.price {
  font-weight:700;
  color:var(--secondary);
}

.desc {
  color:var(--muted);
  font-size:0.9rem;
}

/* Stars */
.stars i {
  color:#f4b400;
  font-size:0.9rem;
  margin-right:2px;
}

/* View Button Fix */
.view-btn {
  position:relative;
  pointer-events:auto !important;
  cursor:pointer;
}

/* Loading Overlay */
#loadingOverlay {
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.8);
  display:none;
  align-items:center;
  justify-content:center;
  flex-direction:column;
  z-index:9999;
}

#loadingOverlay.show-loader {
  display:flex;
  opacity:1;
}

.spinner-border {
  color:#00b894 !important;
}

/* Modal */
.modal-dialog {
  max-width:900px !important;
}

.modal-content {
  border-radius:18px;
  border:none;
  box-shadow:0 12px 28px rgba(0,0,0,0.15);
}

.modal-header {
  background:linear-gradient(90deg,var(--primary),var(--secondary));
  border:none;
  padding:1rem 1.5rem;
}

.modal-title {
  font-weight:700;
  font-size:1.15rem;
}

.modal-body {
  background:#f9fcfa;
  padding:1.8rem;
}

/* MODAL DETAILS CARD */
.details-card {
  background:#fff;
  border-radius:14px;
  box-shadow:0 4px 14px rgba(0,0,0,0.06);
  border-left:4px solid var(--secondary);
  padding:1rem 1.2rem;
}

/* Carousel */
#modalCarouselInner img {
  display:block;
  margin:0 auto;
  max-height:320px;
  width:auto;
  max-width:100%;
  object-fit:cover;
  border-radius:14px;
  box-shadow:0 4px 14px rgba(0,0,0,0.1);
}

.carousel-control-prev-icon,
.carousel-control-next-icon {
  filter:invert(1);
}

/* =========================
   2D MAP UI UPGRADE
========================= */
#mapSection{
  margin-top:1.5rem;
}

.map-header{
  display:flex;
  align-items:flex-start;
  gap:14px;
  background:linear-gradient(135deg,#f0faf7,#eaf4ee);
  padding:20px 24px;
  border-radius:16px;
  border:1px solid #dbe7e3;
  box-shadow:0 4px 14px rgba(0,0,0,0.04);
}

.icon-wrap{
  width:48px;
  height:48px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(135deg,#26a69a,#004d40);
  box-shadow:0 2px 8px rgba(0,0,0,0.10);
}

.icon-wrap i{
  color:white;
  font-size:1.45rem;
}

.section-title{
  font-size:1.28rem;
  font-weight:700;
  color:#004d40;
  letter-spacing:0.2px;
}

.section-subtitle{
  font-size:0.95rem;
  color:#4b5563;
  line-height:1.45;
  margin:0;
}

.section-subtitle .highlight{
  font-weight:600;
  color:#004d40;
}

.section-subtitle .muted{
  color:#6b7280;
}

/* Divider */
.section-divider{
  border:none;
  border-top:1px solid #dbe7e3;
  margin:20px 0 26px;
  width:100%;
  opacity:0.9;
}

.map2d-wrap{
  position:relative;
  border-radius:18px;
  overflow:hidden;
  background:#eaf4ee;
  border:1px solid #dbe7e3;
  box-shadow:0 10px 26px rgba(15,118,110,0.15);
  min-height:420px;
}

.map2d-wrap object{
  width:100%;
  height:600px;
  display:block;
}

.pin-layer{
  position:absolute;
  inset:0;
  pointer-events:none;
}

/* Pins */
.map-pin{
  position:absolute;
  width:22px;
  height:22px;
  border-radius:999px;
  border:3px solid #ffffff;
  box-shadow:0 2px 6px rgba(0,0,0,0.35);
  transform:translate(-50%,-100%);
  pointer-events:auto;
  cursor:pointer;
}

.map-pin::after{
  content:"";
  position:absolute;
  left:50%;
  top:50%;
  transform:translate(-50%,-50%) scale(.7);
  width:26px;
  height:26px;
  border-radius:999px;
  background:rgba(0,0,0,.08);
  animation:pulsePin 1.6s infinite;
}

@keyframes pulsePin{
  0%{transform:translate(-50%,-50%) scale(.6);opacity:.9}
  100%{transform:translate(-50%,-50%) scale(1.4);opacity:0}
}

/* Color per category */
.map-pin.room{background:#1e90ff;}
.map-pin.cottage{background:#ff7f50;}
.map-pin.pool{background:#00bfff;}
.map-pin.event{background:#8a2be2;}
.map-pin.parking{background:#4b5563;}
.map-pin.grounds{background:#22c55e;}
.map-pin.other{background:#9ca3af;}

/* LEGEND */
.legend{
  display:flex;
  justify-content:center;
  flex-wrap:wrap;
  gap:14px;
  margin-top:14px;
}

.legend .item{
  display:flex;
  align-items:center;
  gap:6px;
  font-size:.9rem;
  color:#4b5563;
}

.legend .dot{
  width:14px;
  height:14px;
  border-radius:999px;
  border:2px solid #ffffff;
  box-shadow:0 0 0 1px #cbd5e1;
}

.legend .dot.room{background:#1e90ff;}
.legend .dot.cottage{background:#ff7f50;}
.legend .dot.pool{background:#00bfff;}
.legend .dot.event{background:#8a2be2;}
.legend .dot.parking{background:#4b5563;}
.legend .dot.grounds{background:#22c55e;}
.legend .dot.other{background:#9ca3af;}

.map-note{
  font-size:.88rem;
  color:#6b7280;
  text-align:center;
  margin-top:10px;
}

/* Responsive */
@media (max-width:768px){
  .modal-dialog{
    max-width:95% !important;
  }
  #modalCarouselInner img{
    max-height:240px;
  }
  .filter-buttons{
    flex-wrap:wrap;
    gap:8px;
    padding-left:0;
  }
  .filter-buttons .btn{
    flex:1 1 auto;
    font-size:0.85rem;
  }
  .grid{
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
  }
  .map2d-wrap object{
    height:430px;
  }
}
</style>
<style>
/* Filter buttons (separate style block for clarity) */
.filter-buttons {
  display:flex;
  justify-content:flex-start;
  align-items:center;
  gap:10px;
  margin:15px 0 5px 0;
  padding-left:15px;
}
.filter-buttons .btn {
  border-radius:50px;
  padding:7px 18px;
  font-weight:600;
  font-size:0.9rem;
  border:2px solid var(--secondary);
  color:var(--secondary);
  background:#fff;
  transition:all 0.25s ease;
  box-shadow:none;
}
.filter-buttons .btn:hover,
.filter-buttons .btn.active {
  background:var(--primary);
  color:#fff;
  border-color:var(--primary);
  transform:translateY(-1px);
  box-shadow:0 2px 6px rgba(0,77,64,0.25);
}
</style>
</head>

<?php include 'sidebar-customer.php'; ?>

<main class="main">

  <!-- HEADER -->
  <header class="header">
    <h4><i class="bx bx-bed me-2"></i>Accommodations</h4>
  </header>

  <div class="container-acc">

    <!-- FILTER BUTTONS -->
    <div class="filter-buttons mb-3">
      <button class="btn btn-success filter-btn active" data-filter="all">All</button>
      <button class="btn btn-outline-success filter-btn" data-filter="cottage">Cottage</button>
      <button class="btn btn-outline-success filter-btn" data-filter="room">Room</button>
      <button class="btn btn-outline-success filter-btn" data-filter="event">Event</button>
      <button class="btn btn-outline-success filter-btn" data-filter="map">2D Map</button>
    </div>

    <!-- ACCOMMODATION GRID -->
    <div class="grid" id="cardGrid">

      <?php if (!empty($accommodations)): ?>
        <?php foreach ($accommodations as $i => $a): ?>

          <?php $preview = $a['images']; ?>

          <article class="card-acc <?= htmlspecialchars(strtolower($a['type'])) ?>">

            <!-- SINGLE TOP IMAGE -->
            <div class="card-image">
              <img src="<?= htmlspecialchars($preview[0] ?? '../images/default.jpg') ?>"
                   alt="<?= htmlspecialchars($a['name']) ?>">
            </div>

            <!-- CARD BODY -->
            <div class="card-body p-3">

              <div class="d-flex justify-content-between align-items-start mb-1">
                <h6 class="fw-semibold mb-0"><?= htmlspecialchars($a['name']) ?></h6>
                <span class="price">₱<?= number_format((float)$a['price']) ?></span>
              </div>

              <!-- ⭐ STAR RATING PER CATEGORY -->
              <div class="stars mb-2">
                <?php if ($a['type'] === 'cottage'): ?>
                  <!-- Cottage = 4.5 stars -->
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star-half'></i>
                <?php else: ?>
                  <!-- Room / Event = 5.0 stars -->
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star'></i>
                  <i class='bx bxs-star'></i>
                <?php endif; ?>
              </div>

              <p class="desc mb-2"><?= htmlspecialchars($a['description']) ?></p>

              <!-- VIEW BUTTON -->
              <button
                class="btn btn-success btn-sm view-btn"
                data-id="<?= (int)$a['id'] ?>"

                data-name="<?= htmlspecialchars($a['name']) ?>"
                data-type="<?= htmlspecialchars(strtolower($a['type'])) ?>"
                data-category="<?= htmlspecialchars($a['category']) ?>"

                data-details='<?= $a['details'] ?>'
                data-imgs='<?= json_encode($a['images'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'

                data-price="<?= (float)$a['price'] ?>"
                data-day="<?= (float)$a['day_price'] ?>"
                data-night="<?= (float)$a['night_price'] ?>"
                data-p10="<?= (float)$a['price_10hrs'] ?>"
                data-p22="<?= (float)$a['price_22hrs'] ?>"
                data-capacity="<?= (int)$a['capacity'] ?>"
                data-status="<?= htmlspecialchars($a['availability_status']) ?>"
              >
                View
              </button>

            </div>

          </article>

        <?php endforeach; ?>

      <?php else: ?>

        <p class="text-center text-muted mt-5">No accommodations available at the moment.</p>

      <?php endif; ?>

    </div>

    <!-- 2D MAP SECTION (hidden until "2D Map" filter is clicked) -->
    <div id="mapSection" style="display:none;">

      <div class="map-header mb-3">
        <div class="icon-wrap me-2">
          <i class='bx bx-map-alt'></i>
        </div>
        <div>
          <h4 class="section-title mb-1">Explore via 2D Map</h4>
          <p class="section-subtitle">
            Click the pins on the map to preview each location in the resort.
            <span class="highlight">Rooms, cottages, and event halls</span> open the same reservation window as the cards.
            <span class="muted">Pools, parking, grounds, and others are for viewing only.</span>
          </p>
        </div>
      </div>

      <hr class="section-divider">

      <div class="map2d-wrap" id="mapWrapper">
        <!-- Same SVG used by admin-2dmap -->
        <object id="resortMap" data="2d_map/2d/2d.svg" type="image/svg+xml"></object>
        <div class="pin-layer" id="pinLayer"></div>
      </div>

      <div class="legend">
        <div class="item"><span class="dot room"></span> Room</div>
        <div class="item"><span class="dot cottage"></span> Cottage</div>
        <div class="item"><span class="dot pool"></span> Pool</div>
        <div class="item"><span class="dot event"></span> Event Hall</div>
        <div class="item"><span class="dot parking"></span> Parking</div>
        <div class="item"><span class="dot grounds"></span> Grounds</div>
        <div class="item"><span class="dot other"></span> Other</div>
      </div>

      <p class="map-note">
        Note: Pins and photos are synchronized with the admin 2D map. When the admin updates or removes an image,
        the changes automatically appear here.
      </p>
    </div>

  </div>
</main>


<!-- VIEW MODAL (shared for cards and map pins) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <!-- MODAL HEADER -->
      <div class="modal-header text-white">
        <h5 class="modal-title fw-bold" id="modalTitle">Accommodation Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <!-- MODAL BODY -->
      <div class="modal-body">
        <div class="row g-4">

          <!-- LEFT: IMAGE CAROUSEL -->
          <div class="col-lg-6 text-center">
            <div id="modalCarousel" class="carousel slide">
              <div class="carousel-inner" id="modalCarouselInner"></div>

              <button class="carousel-control-prev" type="button" data-bs-target="#modalCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon bg-dark rounded-circle p-2"></span>
              </button>

              <button class="carousel-control-next" type="button" data-bs-target="#modalCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon bg-dark rounded-circle p-2"></span>
              </button>
            </div>
          </div>

          <!-- RIGHT: DETAILS -->
          <div class="col-lg-6">
            <div class="details-card p-3">

              <h5 class="fw-bold text-primary mb-2" id="modalType"></h5>
              <p class="mb-3" id="modalDetails">Loading...</p>

              <label class="form-label fw-semibold" for="selectedDate" id="dateLabel">Select Reservation Date</label>
              <input type="date" id="selectedDate" class="form-control mb-3 shadow-sm">

              <div id="rateSelector" class="mb-3" style="display:none;">
                <label class="form-label fw-semibold">Select Rate</label>
                <select id="rateOption" class="form-select shadow-sm"></select>
              </div>

              <div id="slotContainer" class="d-flex flex-wrap gap-2"></div>
              <div id="availabilityMessage" class="mt-3"></div>

              <div class="text-end mt-4">
                <button class="btn btn-secondary me-2" data-bs-dismiss="modal">Close</button>
                <button id="reserveNowBtn" class="btn btn-success px-4 disabled">Reserve Now</button>
              </div>

            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>


<!-- LOADING OVERLAY -->
<div id="loadingOverlay">
  <div class="loader-container text-center">
    <div class="spinner-border"></div>
    <p class="mt-3 text-white fw-semibold">Processing your reservation...</p>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", () => {

  /* ==========================================================
     GLOBAL ELEMENTS
  ========================================================== */
  const modal          = new bootstrap.Modal(document.getElementById("viewModal"));
  const loader         = document.getElementById("loadingOverlay");
  const dateInput      = document.getElementById("selectedDate");
  const dateLabel      = document.getElementById("dateLabel");
  const reserveBtn     = document.getElementById("reserveNowBtn");
  const msg            = document.getElementById("availabilityMessage");
  const slotContainer  = document.getElementById("slotContainer");
  const rateSelector   = document.getElementById("rateSelector");
  const rateOption     = document.getElementById("rateOption");
  const cardGrid       = document.getElementById("cardGrid");
  const mapSection     = document.getElementById("mapSection");

  const RESERVABLE_TYPES = ["room","cottage","event"];
  const VIEW_ONLY_TYPES  = ["pool","parking","grounds","other"];

  let currentData = {};

  /* ==========================================================
     OPEN MODAL FOR ACCOMMODATION CARDS
  ========================================================== */
  document.querySelectorAll(".view-btn").forEach(btn => {
    btn.addEventListener("click", () => openModalWith(btn.dataset));
  });

  function resetReservationUI() {
    // show controls (for reservable)
    if (dateLabel) dateLabel.style.display = "";
    if (dateInput) {
      dateInput.style.display = "";
      dateInput.value = "";
    }
    if (rateSelector) {
      rateSelector.style.display = "none"; // default hidden, only for room
    }
    if (slotContainer) {
      slotContainer.innerHTML = "";
    }
    if (reserveBtn) {
      reserveBtn.classList.add("disabled");
      reserveBtn.style.display = "";
    }
    if (msg) msg.innerHTML = "";
  }

  function openModalWith(data)
  {
    resetReservationUI();

    try {
      currentData = {
        id: data.id || "",
        name: data.name,
        type: (data.type || "").toLowerCase(),
        category: data.category || "",
        details: data.details || "",
        price: parseFloat(data.price || "0"),
        day: parseFloat(data.day || "0"),
        night: parseFloat(data.night || "0"),
        p10: parseFloat(data.p10 || "0"),
        p22: parseFloat(data.p22 || "0"),
        capacity: parseInt(data.capacity || "0"),
        imgs: JSON.parse(data.imgs || "[]"),
        availability_status: data.status || "",
        slot: "",
        time_slot: ""
      };
    } catch(e){
      currentData.imgs = [];
    }

    // If somehow we opened modal for a view-only type via data, force view-only handler
    if (VIEW_ONLY_TYPES.includes(currentData.type)) {
      openViewOnlyFromPin({
        label: currentData.name,
        type:  currentData.type,
        desc:  currentData.details,
        photos: []
      });
      return;
    }

    // UI SETUP FOR RESERVABLE TYPES
    document.getElementById("modalTitle").textContent = currentData.name || "Details";
    document.getElementById("modalDetails").innerHTML = currentData.details || "";
    document.getElementById("modalType").textContent  = currentData.category || currentData.type;

    // Images
    const imagesForModal = (currentData.imgs && currentData.imgs.length)
      ? currentData.imgs
      : ["../images/default.jpg"];

    const inner = document.getElementById("modalCarouselInner");
    inner.innerHTML = imagesForModal.map((src,i)=>`
      <div class="carousel-item ${i===0?"active":""}">
        <img src="${src}" class="d-block mx-auto rounded" style="max-height:330px;width:auto;object-fit:cover;">
      </div>
    `).join("");

    // Rate section reset
    rateOption.innerHTML = "";
    rateSelector.style.display = "none";

    // ROOM: Select Rate (10/22 hours)
    if(currentData.type==="room"){
      let opt="";
      if(currentData.p10>0){
        opt += `<option value="${currentData.p10}|10">₱${Number(currentData.p10).toLocaleString()} / 10 hours</option>`;
      }
      if(currentData.p22>0){
        opt += `<option value="${currentData.p22}|22">₱${Number(currentData.p22).toLocaleString()} / 22 hours</option>`;
      }
      if(opt){
        rateSelector.style.display = "block";
        rateOption.innerHTML = opt;
      }
    }

    // EVENT: always 22 hours if set
    if(currentData.type==="event" && currentData.p22>0){
      currentData.price = currentData.p22;
    }

    modal.show();
  }

  /* ==========================================================
     DATE & AVAILABILITY (RESERVABLE ONLY)
  ========================================================== */
  if(dateInput){
    const today = new Date().toISOString().split("T")[0];
    dateInput.min = today;

    dateInput.addEventListener("change", async ()=>{

      if (!RESERVABLE_TYPES.includes(currentData.type)) {
        // safety: if view-only somehow, ignore
        return;
      }

      const date = dateInput.value;
      const type = currentData.type;
      const pkg  = currentData.name;

      msg.innerHTML = "";
      slotContainer.innerHTML = "";
      reserveBtn.classList.add("disabled");
      currentData.slot = "";
      currentData.time_slot = "";

      if(!date || date < today){
        alert("You cannot pick a past date.");
        dateInput.value = "";
        return;
      }

      msg.innerHTML = `<div class="alert alert-secondary">Checking availability...</div>`;

      try{
        const res = await fetch(`check_availability.php?type=${encodeURIComponent(type)}&package=${encodeURIComponent(pkg)}&date=${encodeURIComponent(date)}`);
        const data = await res.json();

        /* ===== COTTAGE: slots per cottage number & time slot ===== */
        if(type === "cottage"){
          const dayRes   = data.day_reserved   || [];
          const nightRes = data.night_reserved || [];

          msg.innerHTML = `<div class="alert alert-info mt-2">Select cottage number and time slot:</div>`;
          slotContainer.innerHTML = "";
          let free = false;

          for(let i=1;i<=10;i++){
            const dTaken = dayRes.includes(i);
            const nTaken = nightRes.includes(i);

            let html = `<div class="fw-semibold mb-2">Cottage #${i}</div><div class="d-flex gap-2">`;

            if(dTaken && nTaken){
              html+=`<button class="btn btn-sm btn-secondary flex-fill" disabled>Day (Full)</button>
                     <button class="btn btn-sm btn-secondary flex-fill" disabled>Night (Full)</button>`;
            } else {
              free = true;
              // Day
              html+= dTaken
                ? `<button class="btn btn-sm btn-secondary flex-fill" disabled>Day (Full)</button>`
                : `<button type="button" class="btn btn-sm btn-outline-success flex-fill choose-slot" data-slot="${i}" data-time="Day">Day</button>`;
              // Night
              html+= nTaken
                ? `<button class="btn btn-sm btn-secondary flex-fill" disabled>Night (Full)</button>`
                : `<button type="button" class="btn btn-sm btn-outline-primary flex-fill choose-slot" data-slot="${i}" data-time="Night">Night</button>`;
            }

            const wrap = document.createElement("div");
            wrap.className = "slotBox border rounded p-2 mb-2";
            wrap.style.background = "#f9fbfb";
            wrap.innerHTML = html;
            slotContainer.appendChild(wrap);
          }

          if(!free){
            msg.innerHTML = `<div class="alert alert-danger mt-2">All cottages are fully booked on this date.</div>`;
            reserveBtn.classList.add("disabled");
            return;
          }

          // click listeners
          document.querySelectorAll(".choose-slot").forEach(b=>{
            b.addEventListener("click",()=>{
              document.querySelectorAll(".choose-slot").forEach(x=>{
                x.classList.remove("active-slot","btn-success","btn-primary","text-white");
                if(x.dataset.time==="Day") x.classList.add("btn-outline-success");
                else x.classList.add("btn-outline-primary");
              });

              const slot = b.dataset.slot;
              const time = b.dataset.time;

              b.classList.add("active-slot","text-white");
              b.classList.remove("btn-outline-success","btn-outline-primary");
              b.classList.add(time==="Day" ? "btn-success" : "btn-primary");

              currentData.slot      = slot;
              currentData.time_slot = time;

              if(time==="Day") currentData.price = currentData.day;
              if(time==="Night") currentData.price = currentData.night;

              reserveBtn.classList.remove("disabled");
              msg.innerHTML = `<div class="alert alert-success mt-2">Selected Cottage #${slot} (${time})</div>`;
            });
          });

          return;
        }

        /* ===== ROOM / EVENT ===== */
        if(data.available){
          msg.innerHTML = `<div class="alert alert-success mt-2">Available on this date.</div>`;
          reserveBtn.classList.remove("disabled");
        } else {
          msg.innerHTML = `<div class="alert alert-danger mt-2">Fully booked on this date.</div>`;
          reserveBtn.classList.add("disabled");
        }

      } catch(e){
        console.error(e);
        msg.innerHTML = `<div class="alert alert-warning mt-2">Error checking availability. Please try again.</div>`;
      }
    });
  }

  /* ==========================================================
      RESERVE BUTTON (RESERVABLE ONLY)
  ========================================================== */
  if(reserveBtn){
    reserveBtn.addEventListener("click", ()=>{
      if(reserveBtn.classList.contains("disabled")) return;

      if (!RESERVABLE_TYPES.includes(currentData.type)) {
        return;
      }

      const date = dateInput.value;
      if(!date){
        alert("Please select a date.");
        return;
      }

      let price = currentData.price;
      let hours = "";

      if(currentData.type==="room" && rateOption.value){
        const [rate,hr] = rateOption.value.split("|");
        price = parseFloat(rate);
        hours = (hr==="10" ? "10 Hours" : "22 Hours");
      }
      else if(currentData.type==="event"){
        hours = "22 Hours";
        price = currentData.p22;
      }
      else if(currentData.type==="cottage"){
        hours = currentData.time_slot;
        if(!hours || !currentData.slot){
          alert("Please select a cottage and time slot.");
          return;
        }
      }

      const img = (currentData.imgs && currentData.imgs[0]) ? currentData.imgs[0] : "";

      const params = new URLSearchParams({
        category: currentData.type,
        package:  currentData.name,
        slot:     currentData.slot || "",
        pax:      currentData.capacity,
        price:    price,
        image:    img,
        date:     date,
        time_slot:hours
      });

      loader.classList.add("show-loader");
      setTimeout(()=>{
        loader.classList.remove("show-loader");
        window.location.href = "reservation-form.php?" + params.toString();
      },700);
    });
  }

  /* ==========================================================
     FILTER BUTTONS (All/Cottage/Room/Event/Map)
  ========================================================== */
  document.querySelectorAll(".filter-btn").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      const filter = btn.dataset.filter;

      // active state
      document.querySelectorAll(".filter-btn").forEach(b=>{
        b.classList.remove("active","btn-success");
        b.classList.add("btn-outline-success");
      });
      btn.classList.remove("btn-outline-success");
      btn.classList.add("btn-success","active");

      if(filter === "map"){
        // show map, hide cards
        if(cardGrid) cardGrid.style.display = "none";
        if(mapSection) mapSection.style.display = "block";
        return;
      }

      // show cards, hide map
      if(cardGrid) cardGrid.style.display = "grid";
      if(mapSection) mapSection.style.display = "none";

      document.querySelectorAll(".grid article.card-acc").forEach(card=>{
        card.style.display =
          filter==="all" || card.classList.contains(filter)
          ? "block"
          : "none";
      });
    });
  });

  /* ==========================================================
     AUTO-OPEN MODAL (BACK from reservation)
  ========================================================== */
  <?php if($autoOpen): ?>
  const targetBtn = Array.from(document.querySelectorAll(".view-btn"))
    .find(b => b.dataset.type === "<?= strtolower($back_category) ?>"
          && b.dataset.name === "<?= $back_package ?>");

  if(targetBtn){
    openModalWith(targetBtn.dataset);

    if (dateInput) {
      dateInput.value = "<?= $back_date ?>";
      dateInput.dispatchEvent(new Event("change"));
    }

    setTimeout(()=>{

      if("<?= $back_slot ?>" !== ""){
        const btn = document.querySelector(`.choose-slot[data-slot="<?= $back_slot ?>"][data-time="<?= $back_time ?>"]`);
        if(btn){
          btn.click();
        }
      }

      if(currentData.type==="room"){
        const opts = Array.from(rateOption.options);
        const pick = opts.find(x=> x.textContent.includes("<?= $back_time ?>"));
        if(pick){
          rateOption.value = pick.value;
        }
      }

    },700);
  }
  <?php endif; ?>

  /* ==========================================================
     2D MAP PINS (DB-SYNCED WITH ADMIN)
  ========================================================== */
  const pinLayer   = document.getElementById("pinLayer");
  const resortMap  = document.getElementById("resortMap");
  const IMG_BASE   = "../map/uploads/";
  let MAP_PINS     = [];

  function loadPinsFromDB(){
    if(!pinLayer) return;
    fetch("../map/get_pins.php")
      .then(res => res.json())
      .then(data => {
        MAP_PINS = Array.isArray(data) ? data : [];
        renderPins();
      })
      .catch(err => console.error("Pin Load Error:", err));
  }

  function renderPins(){
    if(!pinLayer) return;
    pinLayer.innerHTML = "";
    if(!MAP_PINS.length) return;

    MAP_PINS.forEach(p => {
      if(p.x == null || p.y == null) return;

      const btn = document.createElement("button");
      const type = (p.type || "other").toLowerCase();

      btn.className = "map-pin " + type;
      btn.style.left = (p.x * 100) + "%";
      btn.style.top  = (p.y * 100) + "%";
      btn.title = p.label || "";
      btn.setAttribute("aria-label", p.label || type);

      btn.addEventListener("click", () => handlePinClick(p));

      pinLayer.appendChild(btn);
    });
  }

  function findMatchingCardForPin(pinType, pinLabel){
    const label = (pinLabel || "").toLowerCase();
    const buttons = Array.from(document.querySelectorAll(".view-btn"))
      .filter(b => (b.dataset.type || "").toLowerCase() === pinType);

    // 1. Exact match
    let match = buttons.find(b => (b.dataset.name || "").toLowerCase() === label);
    if (match) return match;

    // 2. Label contained inside card name or vice versa
    match = buttons.find(b => {
      const nm = (b.dataset.name || "").toLowerCase();
      return nm.includes(label) || label.includes(nm);
    });
    if (match) return match;

    // 3. Fallback: match by category text if very simple mapping
    match = buttons.find(b => {
      const cat = (b.dataset.category || "").toLowerCase();
      return cat.includes(pinType) || pinType.includes(cat);
    });

    return match || null;
  }

  function handlePinClick(pin){
    const type  = (pin.type || "").toLowerCase();

    if (RESERVABLE_TYPES.includes(type)) {
      const btnMatch = findMatchingCardForPin(type, pin.label);
      if(btnMatch){
        openModalWith(btnMatch.dataset);
        return;
      }
      // if walang match sa cards, treat as view-only but still show info/photos
      openViewOnlyFromPin(pin);
      return;
    }

    // Non-reservable types: always view-only
    openViewOnlyFromPin(pin);
  }

  function openViewOnlyFromPin(pin){
    // update some state (optional)
    currentData = {
      id: "",
      name: pin.label || "Area",
      type: (pin.type || "other").toLowerCase(),
      category: pin.type || "Area",
      details: pin.desc || "",
      imgs: []
    };

    // basic info
    document.getElementById("modalTitle").textContent = pin.label || "Area";
    document.getElementById("modalType").textContent  = (pin.type || "Area");
    document.getElementById("modalDetails").textContent = pin.desc || "";

    // gallery
    let photos = Array.isArray(pin.photos) ? pin.photos : [];
    const inner = document.getElementById("modalCarouselInner");

    if(photos.length){
      inner.innerHTML = photos.map((file,i)=>`
        <div class="carousel-item ${i===0?"active":""}">
          <img src="${IMG_BASE + file}" class="d-block mx-auto rounded"
               style="max-height:330px;width:auto;object-fit:cover;">
        </div>
      `).join("");
    } else {
      inner.innerHTML = `
        <div class="carousel-item active">
          <img src="../images/default.jpg" class="d-block mx-auto rounded"
               style="max-height:330px;width:auto;object-fit:cover;">
        </div>
      `;
    }

    // hide reservation controls
    if(dateLabel) dateLabel.style.display = "none";
    if(dateInput){
      dateInput.style.display = "none";
      dateInput.value = "";
    }
    if(rateSelector) rateSelector.style.display = "none";
    if(slotContainer) slotContainer.innerHTML = "";
    if(reserveBtn){
      reserveBtn.classList.add("disabled");
      reserveBtn.style.display = "none";
    }

    msg.innerHTML = `
      <div class="alert alert-info mt-2">
        This area is for viewing only. Reservation is not available here.
      </div>
    `;

    modal.show();

    // restore controls after modal closes (so next time for rooms/cottages/events, full reservation appears)
    const modalEl = document.getElementById("viewModal");
    const resetViewOnly = () => {
      resetReservationUI();
      modalEl.removeEventListener("hidden.bs.modal", resetViewOnly);
    };
    modalEl.addEventListener("hidden.bs.modal", resetViewOnly);
  }

  // load pins once map is present
  if(resortMap && pinLayer){
    loadPinsFromDB();
  }

});
</script>
</body>
</html>
