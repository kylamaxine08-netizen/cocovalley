<?php
require 'config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
  switch ($action) {

    /* =====================
       ✅ APPROVE PAYMENT (ADMIN + STAFF)
    ====================== */
    case 'update_status': {
      $id = intval($_POST['id'] ?? 0);
      $status = strtolower(trim($_POST['status'] ?? ''));
      $csrf = $_POST['csrf'] ?? '';

      if ($csrf !== ($_SESSION['csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
      }

      if (!$id || $status !== 'approved') {
        echo json_encode(['ok' => false, 'error' => 'Invalid data']);
        exit;
      }

      $uid = intval($_SESSION['user_id'] ?? 0);

      try {
        $pdo->beginTransaction();

        // Find reservation linked to payment
        $stmt = $pdo->prepare("SELECT reservation_id FROM payments WHERE id = ?");
        $stmt->execute([$id]);
        $reservation_id = $stmt->fetchColumn();

        if ($reservation_id) {
          // Update payment
          $stmt = $pdo->prepare("
            UPDATE payments 
            SET status = 'approved',
                verified_by = :uid,
                paid_at = NOW()
            WHERE id = :id
          ");
          $stmt->execute([':uid' => $uid, ':id' => $id]);

          // Update reservation (Approved by Staff)
          $stmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'approved',
                approved_at = NOW(),
                approved_by = :uid
            WHERE id = :rid
          ");
          $stmt->execute([':uid' => $uid, ':rid' => $reservation_id]);

          $pdo->commit();
          echo json_encode(['ok' => true]);
        } else {
          $pdo->rollBack();
          echo json_encode(['ok' => false, 'error' => 'Reservation not found']);
        }

      } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
      }
      exit;
    }

    /* =====================
       📄 LIST PENDING PAYMENTS
    ====================== */
    case 'list_proofs': {
      try {
        $sql = "SELECT p.id, r.customer_name AS name, p.amount, DATE(p.paid_at) AS date,
                       p.proof_path AS image, p.status
                FROM payments p
                LEFT JOIN reservations r ON p.reservation_id = r.id
                WHERE p.status = 'pending'
                ORDER BY p.id DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        if ($rows && count($rows)) {
          echo json_encode(['ok'=>true, 'rows'=>$rows]);
        } else {
          // Dummy fallback (Pending Proofs)
          echo json_encode([
            'ok' => true,
            'rows' => [
              [
                'id'=>1,
                'name'=>'Juan Dela Cruz',
                'amount'=>1200,
                'date'=>'2025-10-28',
                'image'=>'uploads/proof1.jpg',
                'status'=>'pending'
              ],
              [
                'id'=>2,
                'name'=>'Maria Santos',
                'amount'=>1500,
                'date'=>'2025-10-29',
                'image'=>'uploads/proof2.jpg',
                'status'=>'pending'
              ]
            ]
          ]);
        }
      } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
      }
      exit;
    }

    /* =====================
       📋 LIST APPROVED RESERVATIONS (DATE + STAFF + TIME)
    ====================== */
    case 'list_reservations': {
      try {
        $sql = "SELECT 
                  r.customer_name AS name, 
                  r.status, 
                  CONCAT(
                    DATE_FORMAT(r.approved_at, '%Y-%m-%d'),
                    '<br>Staff<br>',
                    REPLACE(REPLACE(r.time_str, 'Day ', ''), 'Night ', '')
                  ) AS approvedAt,
                  REPLACE(REPLACE(r.time_str, 'Day ', ''), 'Night ', '') AS time,
                  r.pax
                FROM reservations r
                WHERE r.status = 'approved'
                ORDER BY r.approved_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        if ($rows && count($rows)) {
          echo json_encode(['ok'=>true, 'rows'=>$rows]);
        } else {
          // Dummy fallback
          echo json_encode([
            'ok'=>true,
            'rows'=>[
              [
                'name'=>'Maria Clara',
                'status'=>'approved',
                'approvedAt'=>'2025-10-29<br>Staff<br>08:00–17:00',
                'time'=>'08:00–17:00',
                'pax'=>4
              ],
              [
                'name'=>'Pedro Penduko',
                'status'=>'approved',
                'approvedAt'=>'2025-10-30<br>Staff<br>17:00–23:00',
                'time'=>'17:00–23:00',
                'pax'=>2
              ]
            ]
          ]);
        }
      } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
      }
      exit;
    }

    default:
      echo json_encode(['ok'=>false,'error'=>'Unknown action']);
      exit;
  }

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
