<?php
include '../admin/handlers/db_connect.php';

if (!isset($_GET['id'])) {
    die("Invalid request");
}

$acc_id = (int)$_GET['id'];

// Step 1 — Get accommodation package name
$stmt = $conn->prepare("SELECT package FROM accommodations WHERE id=? LIMIT 1");
$stmt->bind_param("i", $acc_id);
$stmt->execute();
$acc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$acc) {
    die("Accommodation not found.");
}

$package = $acc['package'];

// Step 2 — DELETE payments related to reservations using this accommodation
$sql = "
    DELETE p FROM payments p
    INNER JOIN reservations r ON p.reservation_id = r.id
    WHERE r.package = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $package);
$stmt->execute();
$stmt->close();

// Step 3 — DELETE reservations for this accommodation
$stmt = $conn->prepare("DELETE FROM reservations WHERE package=?");
$stmt->bind_param("s", $package);
$stmt->execute();
$stmt->close();

// Step 4 — Delete accommodation
$stmt = $conn->prepare("DELETE FROM accommodations WHERE id=?");
$stmt->bind_param("i", $acc_id);
$stmt->execute();
$stmt->close();

echo "
<script>
alert('Accommodation and all related reservations have been deleted.');
window.location.href='admin-accommodations.php';
</script>
";
?>
