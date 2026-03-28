<?php
session_start();
include '../admin/handlers/db_connect.php';

/********************************************
 * REQUIRE CUSTOMER LOGIN
 ********************************************/
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: ../login.php");
    exit;
}

/********************************************
 * CUSTOMER INFO
 ********************************************/
$customer_email = strtolower(trim($_SESSION['email'] ?? ''));
$customer_name  = $_SESSION['name'] ?? 'Customer';

$notifications = [];

/********************************************
 * 1️⃣ FETCH ACTIVE ANNOUNCEMENTS
 ********************************************/
$annSql = "
  SELECT 
    id,
    title AS item_name,
    message,
    image_url,
    created_at
  FROM announcements
  WHERE CURDATE() BETWEEN start_date AND end_date
  ORDER BY created_at DESC
";

$annRes = $conn->query($annSql);

while ($row = $annRes->fetch_assoc()) {
    $notifications[] = [
        'id'           => 'A-' . $row['id'],
        'item_name'    => $row['item_name'],
        'message'      => $row['message'],
        'created_at'   => $row['created_at'],
        'type'         => 'announcement',
        'category'     => 'Announcement',
        'status'       => 'unread',
        'posted_by'    => 'Admin Team',
        'image_url'    => $row['image_url'],
        'redirect_url' => ''
    ];
}

/********************************************
 * 2️⃣ FETCH CUSTOMER + GLOBAL NOTIFICATIONS
 ********************************************/
$stmt = $conn->prepare("
    SELECT 
        id, email, item_name, message, image_url, redirect_url,
        type, status, created_at, posted_by
    FROM notifications
    WHERE 
         LOWER(TRIM(email)) = LOWER(TRIM(?))
      OR email = 'global'
    ORDER BY created_at DESC
");
$stmt->bind_param("s", $customer_email);
$stmt->execute();
$notifRes = $stmt->get_result();

while ($n = $notifRes->fetch_assoc()) {
    $rawType = strtolower(trim($n['type'] ?? ''));

    $category = match (true) {
        str_contains($rawType, 'reservation')   => 'Reservation',
        str_contains($rawType, 'payment')       => 'Payment',
        str_contains($rawType, 'cancel')        => 'Reservation',
        str_contains($rawType, 'announcement')  => 'Announcement',
        str_contains($rawType, 'promo')         => 'Promo',
        default                                 => 'Notification',
    };

    $notifications[] = [
        'id'           => 'N-' . $n['id'],
        'item_name'    => $n['item_name'],
        'message'      => $n['message'],
        'created_at'   => $n['created_at'],
        'type'         => $rawType,
        'category'     => $category,
        'status'       => $n['status'] ?? 'unread',
        'posted_by'    => ucfirst($n['posted_by'] ?? 'System'),
        'image_url'    => $n['image_url'],
        'redirect_url' => $n['redirect_url']
    ];
}

$stmt->close();

/********************************************
 * 3️⃣ SORT (NEWEST FIRST)
 ********************************************/
usort($notifications, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

/********************************************
 * 4️⃣ JSON OUTPUT
 ********************************************/
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode($notifications);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Notifications • Coco Valley</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<style>
:root {
  --cv-primary:#00796b;
  --cv-soft:#e0f2f1;
  --cv-panel:#ffffff;
  --cv-text:#00332e;
  --border:#d6e2df;
}

body{
  background:#f4fbfa;
  font-family:"Inter", sans-serif;
  color:var(--cv-text);
}

.main{
  margin-left:250px;
  padding:28px;
}

.page-title{
  font-size:2rem;
  font-weight:800;
  color:var(--cv-primary);
}

/* NEW CARD THEME */
.notif-card{
  background:var(--cv-panel);
  border:1px solid var(--border);
  border-left:6px solid var(--cv-primary);
  border-radius:14px;
  padding:18px;
  margin-bottom:14px;
  box-shadow:0 8px 20px rgba(0,0,0,0.05);
  transition:.2s;
}

.notif-card:hover{
  translate:0 -2px;
  box-shadow:0 12px 26px rgba(0,0,0,0.09);
}

.notif-title{
  font-weight:700;
  font-size:1.1rem;
  color:var(--cv-primary);
}

.notif-time{
  font-size:.8rem;
  color:#5b6b68;
}

.filter-btn.active{
  background:var(--cv-primary)!important;
  color:#fff!important;
}

</style>
</head>
<body>

<?php include 'sidebar-customer.php'; ?>

<main class="main">

<h2 class="page-title"><i class="bx bx-bell"></i> Notifications</h2>

<div class="d-flex flex-wrap gap-2 mt-3 mb-3">
  <button class="btn btn-outline-success filter-btn active" data-filter="all">All</button>
  <button class="btn btn-outline-success filter-btn" data-filter="unread">Unread</button>
  <button class="btn btn-outline-success filter-btn" data-filter="reservation">Reservation</button>
  <button class="btn btn-outline-success filter-btn" data-filter="payment">Payment</button>
  <button class="btn btn-outline-success filter-btn" data-filter="announcement">Announcement</button>
  <button class="btn btn-outline-success filter-btn" data-filter="promo">Promo</button>
</div>

<input type="text" id="searchInput" class="form-control mb-3" placeholder="Search...">

<div id="notifList">

<?php if(empty($notifications)): ?>
  <div class="alert alert-light text-center py-4">
    <i class="bx bx-bell-off fs-2"></i><br> No notifications yet.
  </div>
<?php else: ?>

  <?php foreach($notifications as $n): ?>

    <div class="notif-card"
         data-category="<?= strtolower($n['category']) ?>"
         data-status="<?= strtolower($n['status']) ?>"
         data-id="<?= $n['id'] ?>">

        <div class="notif-title"><?= $n['item_name'] ?></div>
        <div><?= $n['message'] ?></div>

        <?php if(!empty($n['image_url'])): ?>
            <img src="<?= $n['image_url'] ?>" class="img-fluid rounded mt-2" style="max-width:220px;">
        <?php endif; ?>

        <div class="notif-time mt-2">
          <i class="bx bx-time-five"></i>
          <?= date('M d, Y g:i A', strtotime($n['created_at'])) ?>
          • <?= htmlspecialchars($n['posted_by']) ?>
        </div>

        <button class="btn btn-sm btn-outline-primary mt-2 mark-toggle"
                data-id="<?= $n['id'] ?>"
                data-status="<?= strtolower($n['status']) ?>">
            <?= $n['status']==='unread' ? 'Mark as read' : 'Mark as unread' ?>
        </button>

        <?php if(!empty($n['redirect_url'])): ?>
          <a href="<?= $n['redirect_url'] ?>" class="btn btn-sm btn-primary mt-2">View</a>
        <?php endif; ?>

    </div>

  <?php endforeach; ?>

<?php endif; ?>

</div>

</main>

<script>
// FILTERS
document.querySelectorAll('.filter-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
     document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
     btn.classList.add('active');
     const f = btn.dataset.filter;

     document.querySelectorAll('.notif-card').forEach(c=>{
        const show =
          f === 'all' ||
          c.dataset.category === f ||
          (f === 'unread' && c.dataset.status === 'unread');

        c.style.display = show ? 'block' : 'none';
     });
  });
});

// SEARCH
document.getElementById('searchInput').addEventListener('input', e=>{
   const q = e.target.value.toLowerCase();

   document.querySelectorAll('.notif-card').forEach(c=>{
      c.style.display = c.innerText.toLowerCase().includes(q) ? 'block':'none';
   });
});
</script>

</body>
</html>
