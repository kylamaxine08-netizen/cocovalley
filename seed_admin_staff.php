<?php
declare(strict_types=1);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cocovalley_admin;charset=utf8mb4','root','',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES=>false,
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  phone VARCHAR(32) NULL,
  first_name VARCHAR(100) NULL,
  last_name  VARCHAR(100) NULL,
  password_hash VARCHAR(255) NOT NULL,
  birthdate DATE NULL,
  gender VARCHAR(20) NULL,
  role ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$adminHash = password_hash('admin123', PASSWORD_DEFAULT);
$staffHash = password_hash('staff123', PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
INSERT INTO users (email, phone, first_name, last_name, password_hash, role, status)
VALUES (:email,:phone,:fn,:ln,:ph,:role,1)
ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=VALUES(role), status=1
");

$stmt->execute([':email'=>'admin@example.com', ':phone'=>'09000000000', ':fn'=>'Admin', ':ln'=>'User', ':ph'=>$adminHash, ':role'=>'admin']);
$stmt->execute([':email'=>'staff1@example.com', ':phone'=>'09111111111', ':fn'=>'Staff', ':ln'=>'One', ':ph'=>$staffHash, ':role'=>'staff']);

echo "Done. Admin: admin@example.com / admin123 — Staff: staff1@example.com / staff123";
