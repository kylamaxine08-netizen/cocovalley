<?php
$pdo = new PDO("mysql:host=localhost;dbname=cocovalley_admin","root","");
$stmt = $pdo->query("SHOW CREATE TABLE reservations");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<pre>";
print_r($row);
echo "</pre>";
