<?php
// staff-api.php

// ----------------------------------------
// Basic API headers
// ----------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// IMPORTANT: do NOT echo PHP warnings/notices to JSON output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ----------------------------------------
// Session + Auth (Admin only)
// ----------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If you want API to be admin-only (recommended):
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF stored on your page as $_SESSION['csrf']
$sessionCsrf = $_SESSION['csrf'] ?? '';

require __DIR__ . '/db.php';    // This must define $pdo (PDO instance)

// ----------------------------------------
// Helpers
// ----------------------------------------
function json_out($a) {
    echo json_encode($a);
    exit;
}

function get($k, $d = null) {
    return isset($_GET[$k]) ? trim($_GET[$k]) : $d;
}

function post($k, $d = null) {
    return isset($_POST[$k]) ? trim($_POST[$k]) : $d;
}

function is_email($e) {
    return (bool) filter_var($e, FILTER_VALIDATE_EMAIL);
}

// Require valid CSRF for ALL POST actions
function require_csrf($sessionCsrf) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = post('csrf', '');
    if (!$token || !$sessionCsrf || !hash_equals($sessionCsrf, $token)) {
        json_out(['ok' => false, 'error' => 'Invalid CSRF token']);
    }
}

// Last-admin protection helpers
function at_least_one_admin_left(PDO $pdo, $excludeId = null) {
    $sql = "SELECT COUNT(*) FROM staff_users WHERE role='admin' AND status='active'";
    if ($excludeId) {
        $stmt = $pdo->prepare($sql . " AND id <> ?");
        $stmt->execute([$excludeId]);
        return (int) $stmt->fetchColumn() > 0;
    }
    return (int) $pdo->query($sql)->fetchColumn() > 0;
}

// ----------------------------------------
// Dispatch
// ----------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(get('action', $method === 'POST' ? post('action', '') : ''));

// Enforce CSRF for POST actions
require_csrf($sessionCsrf);

// ----------------------------------------
// action: seed_if_empty  (GET)
// ----------------------------------------
if ($action === 'seed_if_empty') {
    try {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM staff_users")->fetchColumn();

        if ($n === 0) {
            $now = date('Y-m-d H:i:s');

            $ins = $pdo->prepare("
                INSERT INTO staff_users (
                    name, email, username, phone, role, status,
                    temp_password_hash, previous_login_at, created_at
                )
                VALUES
                ('System Admin','admin@cocovalley.ph','admin','+63 900 000 0000',
                 'admin','active', :h1, :now, :now),
                ('Frontdesk Staff','frontdesk@cocovalley.ph','frontdesk','+63 911 111 1111',
                 'staff','active', :h2, DATE_SUB(:now2, INTERVAL 4 HOUR),
                 DATE_SUB(:now3, INTERVAL 4 HOUR))
            ");

            $ins->execute([
                ':h1'  => password_hash('Admin@123!',  PASSWORD_DEFAULT),
                ':h2'  => password_hash('Staff@123!',  PASSWORD_DEFAULT),
                ':now' => $now,
                ':now2'=> $now,
                ':now3'=> $now
            ]);
        }

        json_out(['ok' => true, 'seeded' => $n === 0]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Seed failed']);
    }
}

// ----------------------------------------
// action: list (GET)
// ----------------------------------------
if ($action === 'list') {
    try {
        $q    = get('q', '');
        $role = get('role', '');
        $sort = get('sort', 'created_at');
        $dir  = strtolower(get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $allowSort = [
            'name','role','email','username','phone',
            'previous_login_at','status','created_at'
        ];
        if (!in_array($sort, $allowSort, true)) {
            $sort = 'created_at';
        }

        $where = [];
        $bind  = [];

        if ($q !== '') {
            $where[] = "(name LIKE :q OR email LIKE :q OR username LIKE :q OR phone LIKE :q)";
            $bind[':q'] = "%{$q}%";
        }
        if ($role !== '') {
            $where[] = "role = :role";
            $bind[':role'] = $role;
        }

        $sql = "
            SELECT
                id, name, email, username, phone,
                role, status, previous_login_at, created_at, updated_at
            FROM staff_users
        ";

        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY {$sort} {$dir}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'rows' => $rows]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Failed to list accounts']);
    }
}

// ----------------------------------------
// action: create (POST)
// ----------------------------------------
if ($action === 'create' && $method === 'POST') {
    try {
        $name     = post('name', '');
        $email    = post('email', '');
        $username = post('username', '');
        $phone    = post('phone', '');
        $role     = post('role', 'staff');
        // ✅ FIXED: matches frontend key `temp_password`
        $temp     = post('temp_password', '');

        if ($name === '') {
            json_out(['ok' => false, 'error' => 'Name required']);
        }
        if (!is_email($email)) {
            json_out(['ok' => false, 'error' => 'Invalid email']);
        }
        if ($username === '') {
            json_out(['ok' => false, 'error' => 'Username required']);
        }
        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
        }

        // Back-end minimum (front-end already enforces strong 12+ rule)
        if (strlen($temp) < 8) {
            json_out(['ok' => false, 'error' => 'Temp password too short']);
        }

        // Unique email/username check
        $dup = $pdo->prepare("SELECT 1 FROM staff_users WHERE email = ? OR username = ? LIMIT 1");
        $dup->execute([$email, $username]);

        if ($dup->fetch()) {
            json_out(['ok' => false, 'error' => 'Email or username already exists']);
        }

        $sql = "
            INSERT INTO staff_users (
                name, email, username, phone,
                role, status,
                temp_password_hash,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $name,
            $email,
            $username,
            $phone,
            $role,
            password_hash($temp, PASSWORD_DEFAULT)
        ]);

        $id = (int) $pdo->lastInsertId();

        $stmt2 = $pdo->prepare("
            SELECT
                id, name, email, username, phone,
                role, status, previous_login_at, created_at, updated_at
            FROM staff_users
            WHERE id = ?
        ");
        $stmt2->execute([$id]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'row' => $row]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Create failed']);
    }
}

// ----------------------------------------
// action: update (POST)
// ----------------------------------------
if ($action === 'update' && $method === 'POST') {
    try {
        $id = (int) post('id', 0);
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid id']);
        }

        $name     = post('name', '');
        $email    = post('email', '');
        $username = post('username', '');
        $phone    = post('phone', '');
        $role     = post('role', 'staff');
        $status   = post('status', 'active');

        if ($name === '') {
            json_out(['ok' => false, 'error' => 'Name required']);
        }
        if (!is_email($email)) {
            json_out(['ok' => false, 'error' => 'Invalid email']);
        }
        if ($username === '') {
            json_out(['ok' => false, 'error' => 'Username required']);
        }
        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'active';
        }

        // Unique checks excluding the current user
        $dup = $pdo->prepare("
            SELECT 1
            FROM staff_users
            WHERE (email = ? OR username = ?)
              AND id <> ?
            LIMIT 1
        ");
        $dup->execute([$email, $username, $id]);
        if ($dup->fetch()) {
            json_out(['ok' => false, 'error' => 'Email or username already exists']);
        }

        // Check current row
        $cur = $pdo->prepare("SELECT role, status FROM staff_users WHERE id = ?");
        $cur->execute([$id]);
        $curRow = $cur->fetch(PDO::FETCH_ASSOC);

        if (!$curRow) {
            json_out(['ok' => false, 'error' => 'Not found']);
        }

        // If this row is an admin and we are either:
        //  - changing role admin -> staff
        //  - changing status active -> disabled
        // ensure at least one other active admin exists.
        if (
            $curRow['role'] === 'admin' &&
            (
                $role !== 'admin' ||
                ($curRow['status'] === 'active' && $status !== 'active')
            )
        ) {
            if (!at_least_one_admin_left($pdo, $id)) {
                json_out(['ok' => false, 'error' => 'Cannot remove/disable the last active Admin']);
            }
        }

        $stmt = $pdo->prepare("
            UPDATE staff_users
            SET
                name = ?,
                email = ?,
                username = ?,
                phone = ?,
                role = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $username, $phone, $role, $status, $id]);

        $stmt2 = $pdo->prepare("
            SELECT
                id, name, email, username, phone,
                role, status, previous_login_at, created_at, updated_at
            FROM staff_users
            WHERE id = ?
        ");
        $stmt2->execute([$id]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'row' => $row]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Update failed']);
    }
}

// ----------------------------------------
// action: toggle_status (POST)
// ----------------------------------------
if ($action === 'toggle_status' && $method === 'POST') {
    try {
        $id = (int) post('id', 0);
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid id']);
        }

        $stmt = $pdo->prepare("SELECT role, status FROM staff_users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            json_out(['ok' => false, 'error' => 'Not found']);
        }

        $next = ($row['status'] === 'active') ? 'disabled' : 'active';

        if ($row['role'] === 'admin' && $next !== 'active') {
            if (!at_least_one_admin_left($pdo, $id)) {
                json_out(['ok' => false, 'error' => 'Cannot disable the last active Admin']);
            }
        }

        $up = $pdo->prepare("
            UPDATE staff_users
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $up->execute([$next, $id]);

        json_out(['ok' => true, 'status' => $next]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Toggle status failed']);
    }
}

// ----------------------------------------
// action: delete (POST)
// ----------------------------------------
if ($action === 'delete' && $method === 'POST') {
    try {
        $id = (int) post('id', 0);
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid id']);
        }

        $stmt = $pdo->prepare("SELECT role, status FROM staff_users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            json_out(['ok' => false, 'error' => 'Not found']);
        }

        if ($row['role'] === 'admin') {
            // Force: admin must NOT be active to be deleted
            if ($row['status'] === 'active') {
                json_out(['ok' => false, 'error' => 'Disable admin first']);
            }
            // Also keep at least one admin somewhere else
            if (!at_least_one_admin_left($pdo, $id)) {
                json_out(['ok' => false, 'error' => 'Cannot delete the last Admin']);
            }
        }

        $del = $pdo->prepare("DELETE FROM staff_users WHERE id = ?");
        $del->execute([$id]);

        json_out(['ok' => true]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Delete failed']);
    }
}

// ----------------------------------------
// Fallback: unknown action
// ----------------------------------------
json_out(['ok' => false, 'error' => 'Unknown action']);
