<?php
require_once '../config.php';

header('Content-Type: application/json');

// Cek method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Cek login & permission
if (!isLoggedIn() || !canManageUser()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$roleName = $data['role_name'] ?? '';
$permissions = $data['permissions'] ?? [];

if (empty($roleName) || empty($permissions)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

try {
    $db->beginTransaction();
    
    foreach ($permissions as $perm) {
        $module = $perm['module'];
        $action = $perm['perm'];
        $value = $perm['value'];
        
        // Cari module_id
        $stmt = $db->prepare("SELECT id FROM modules WHERE module_name = ?");
        $stmt->execute([$module]);
        $moduleId = $stmt->fetchColumn();
        
        if (!$moduleId) continue;
        
        // Update permission berdasarkan role_name
        $field = 'can_' . $action;
        $stmt = $db->prepare("
            UPDATE permissions 
            SET $field = ? 
            WHERE module_id = ? AND role_name = ?
        ");
        $stmt->execute([$value, $moduleId, $roleName]);
    }
    
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Permission berhasil disimpan!']);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}