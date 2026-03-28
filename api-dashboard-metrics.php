<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

try {
  $pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=cocovalley_admin;charset=utf8mb4',
    'root','',
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );

  $out = [
    'res_total'     => (int)$pdo->query("SELECT COUNT(*) FROM reservations")->fetchColumn(),
    'res_pending'   => (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='pending'")->fetchColumn(),
    'res_approved'  => (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='approved'")->fetchColumn(),
    'res_cancelled' => (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='cancelled'")->fetchColumn(),
    'pay_submitted' => (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status='submitted'")->fetchColumn(),
    'pay_approved'  => (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status='approved'")->fetchColumn(),
    'pay_rejected'  => (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status='rejected'")->fetchColumn(),
    'pay_approved_sum' => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved'")->fetchColumn(),
  ];

  $out['latest_payments'] = $pdo->query("
    SELECT p.id, p.amount, p.status, p.method, p.paid_at, p.created_at,
           r.id AS reservation_id, r.code, r.customer_name
    FROM payments p
    LEFT JOIN reservations r ON r.id = p.reservation_id
    ORDER BY p.paid_at DESC, p.id DESC
    LIMIT 5
  ")->fetchAll();

  echo json_encode($out);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}
