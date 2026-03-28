<?php
$pdo=new PDO('mysql:host=127.0.0.1;dbname=cocovalley_admin;charset=utf8mb4','root','');
$email='admin@example.com';
$plain='admin123';
$u=$pdo->prepare("SELECT email,role,status,password_hash FROM users WHERE email=:e"); $u->execute([':e'=>$email]); $row=$u->fetch();
var_dump($row && password_verify($plain,$row['password_hash']));
