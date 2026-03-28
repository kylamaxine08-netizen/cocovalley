<?php
require_once __DIR__ . "/handlers/db_connect.php";

if (!$conn) {
    die("❌ DB connection object missing");
}

echo "DB CONNECTED SUCCESSFULLY<br><br>";

$email = "admin@cocovalley.com";

$stmt = $conn->prepare("SELECT id, email, password_hash FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo "USER FOUND:<br>";
    print_r($row);
} else {
    echo "❌ USER NOT FOUND IN DATABASE.";
}
