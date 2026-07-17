<?php
require_once '../config.php';

header('Content-Type: application/json');

// Cek login
if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized', 'modules' => []]);
    exit;
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$userId) {
    echo json_encode(['error' => 'User ID required', 'modules' => []]);
    exit;
}

// Ambil data user
$stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['error' => 'User not found', 'modules' => []]);
    exit;
}

// Ambil semua modules dengan permission berdasarkan ROLE user
$sql = "SELECT 
            m.id, 
            m.module_name, 
            m.module_label,
            COALESCE(p.can_view, 0) as can_view,
            COALESCE(p.can_add, 0) as can_add,
            COALESCE(p.can_edit, 0) as can_edit,
            COALESCE(p.can_delete, 0) as can_delete
        FROM modules m
        LEFT JOIN permissions p ON p.module_id = m.id AND p.role_name = ?
        ORDER BY m.module_order";

$stmt = $db->prepare($sql);
$stmt->execute([$user['role']]);
$modules = $stmt->fetchAll();

echo json_encode([
    'username' => $user['username'],
    'role' => $user['role'],
    'modules' => $modules
]);