<?php
require_once '../config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$userId = $input['user_id'] ?? 0;
$permissions = $input['permissions'] ?? [];

if (!$userId || empty($permissions)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

// Ambil role user
$stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
    exit;
}

$roleName = $user['role'];

// Hapus permission lama untuk role ini
$stmt = $db->prepare("DELETE FROM permissions WHERE role_name = ?");
$stmt->execute([$roleName]);

// Insert permission baru
foreach ($permissions as $perm) {
    $moduleName = $perm['module'];
    $permType = $perm['perm'];
    $value = $perm['value'];
    
    // Cek module_id
    $stmt = $db->prepare("SELECT id FROM modules WHERE module_name = ?");
    $stmt->execute([$moduleName]);
    $module = $stmt->fetch();
    
    if ($module) {
        $moduleId = $module['id'];
        
        // Cek apakah permission sudah ada
        $stmt = $db->prepare("SELECT id FROM permissions WHERE module_id = ? AND role_name = ?");
        $stmt->execute([$moduleId, $roleName]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update
            $sql = "UPDATE permissions SET can_$permType = ? WHERE module_id = ? AND role_name = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$value, $moduleId, $roleName]);
        } else {
            // Insert dengan semua permission 0 dulu
            $sql = "INSERT INTO permissions (module_id, role_name, can_view, can_add, can_edit, can_delete) VALUES (?, ?, 0, 0, 0, 0)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$moduleId, $roleName]);
            
            // Update permission yang dipilih
            $sql = "UPDATE permissions SET can_$permType = ? WHERE module_id = ? AND role_name = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$value, $moduleId, $roleName]);
        }
    }
}

echo json_encode(['success' => true]);