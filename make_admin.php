<?php
/***********************************************
 * AUTO-CREATE ADMIN ACCOUNT (Safe Installer)
 ***********************************************/

declare(strict_types=1);

$DB_HOST = "localhost";
$DB_NAME = "cocovalley_admin";
$DB_USER = "root";
$DB_PASS = "";

// === CONNECT TO DATABASE ===
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Throwable $e) {
    die("❌ Database connection failed: " . $e->getMessage());
}

// === CONFIGURE ADMIN DETAILS ===
$FIRST = "System";
$LAST  = "Administrator";
$EMAIL = "admin@gmail.com";
$PASS  = "admin123";   // ← you can change this any time

// === CHECK IF ADMIN ALREADY EXISTS ===
$check = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
$check->execute([':e' => $EMAIL]);

if ($check->fetch()) {
    die("⚠️ Admin already exists! <br>Email: $EMAIL <br>Delete it in phpMyAdmin if you want to recreate.");
}

// === CREATE PASSWORD HASH ===
$HASH = password_hash($PASS, PASSWORD_DEFAULT);

// === INSERT ADMIN ACCOUNT ===
$insert = $pdo->prepare("
    INSERT INTO users (first_name, last_name, email, password_hash, role, status)
    VALUES (:f, :l, :e, :h, 'admin', 1)
");

$insert->execute([
    ':f' => $FIRST,
    ':l' => $LAST,
    ':e' => $EMAIL,
    ':h' => $HASH
]);

// === SUCCESS OUTPUT ===
echo "<h2>✅ ADMIN ACCOUNT CREATED SUCCESSFULLY!</h2>";
echo "<p><strong>Email:</strong> $EMAIL</p>";
echo "<p><strong>Password:</strong> $PASS</p>";
echo "<p><strong>Hash:</strong><br>$HASH</p>";
