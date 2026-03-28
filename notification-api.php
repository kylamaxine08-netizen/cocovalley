<?php
header('Content-Type: application/json');
require_once 'db_connect.php'; // make sure this points to your correct path

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'list') {
    $rows = [];
    $sql = "SELECT * FROM notifications ORDER BY created_at DESC";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

if ($action === 'mark_read' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $conn->query("UPDATE notifications SET status='read' WHERE id=$id");
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'mark_unread' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $conn->query("UPDATE notifications SET status='unread' WHERE id=$id");
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action']);
