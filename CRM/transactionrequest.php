<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

// ============================================
// SET ZONA WAKTU WIB (GMT+7)
// ============================================
date_default_timezone_set('Asia/Jakarta');

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// ============================================
// CEK AKSES HALAMAN
// ============================================
requirePermission('transaction_request', 'view');

// ============================================
// AMBIL MENU YANG BOLEH DIAKSES USER
// ============================================
$userMenus = getUserMenus();
$menuNames = array_column($userMenus, 'module_name');

// ============================================
// FUNGSI UNTUK MENGUBAH ROLE MENJADI LABEL DIVISI
// ============================================
function getRoleLabel($role) {
    $roleLabels = [
        'it_support' => 'IT Support',
        'admin' => 'Admin',
        'finance' => 'Finance',
        'direktur_utama' => 'Direktur Utama',
        'direktur_operasional' => 'Direktur Operasional',
        'direktur_sales' => 'Direktur Sales',
        'business' => 'Business',
        'sales_manager' => 'Sales Manager',
        'sales' => 'Sales'
    ];
    return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// ============================================
// CEK USER UNTUK AKSES
// ============================================
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? 'user';
$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

$fullAccessRoles = ['it_support', 'admin', 'finance', 'business', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$hasFullAccess = in_array($userRole, $fullAccessRoles);
$isDirektur = in_array($userRole, ['direktur_utama', 'direktur_sales', 'direktur_operasional']);

// ============================================
// APPROVAL FLOW - SAMA DENGAN detailtr.php
// ============================================
$approvalLevels = [
    1 => ['role' => 'sales_manager', 'label' => 'Sales Manager'],
    2 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    3 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    4 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];
$totalApprovalLevels = count($approvalLevels);

function getCurrentApproverForTR(PDO $db, string $trNumber, array $approvalLevels, int $totalApprovalLevels): string
{
    try {
        // Ambil detail TR TERBARU, persis berdasarkan TR Number.
        $stmt = $db->prepare("SELECT status FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$trNumber]);
        $detailTR = $stmt->fetch(PDO::FETCH_ASSOC);

        // Jika detail TR belum ada, flow di detailtr.php memulai dari Sales Manager.
        if (!$detailTR) {
            return $approvalLevels[1]['label'];
        }

        // Ambil approval history untuk TR Number yang sama.
        $stmt = $db->prepare("SELECT approval_order, approval_role, status FROM tr_approval_history WHERE trf_number = ? ORDER BY approval_order ASC");
        $stmt->execute([$trNumber]);
        $approvalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $approvedByOrder = [];
        $rejectedByCurrentFlow = false;

        foreach ($approvalHistory as $approval) {
            $order = (int)($approval['approval_order'] ?? 0);
            if (!isset($approvalLevels[$order])) {
                continue;
            }
            if (($approval['approval_role'] ?? '') !== $approvalLevels[$order]['role']) {
                continue;
            }

            if (($approval['status'] ?? '') === 'approved') {
                $approvedByOrder[$order] = true;
            } elseif (($approval['status'] ?? '') === 'rejected') {
                $rejectedByCurrentFlow = true;
            }
        }

        if ($rejectedByCurrentFlow || ($detailTR['status'] ?? '') === 'rejected') {
            return 'No More Approval';
        }

        if (($detailTR['status'] ?? '') === 'approved') {
            return 'No More Approval';
        }

        $lastApprovedOrder = 0;
        for ($order = 1; $order <= $totalApprovalLevels; $order++) {
            if (!empty($approvedByOrder[$order])) {
                $lastApprovedOrder = $order;
            } else {
                break;
            }
        }

        $currentApprovalOrder = $lastApprovedOrder + 1;
        return $currentApprovalOrder <= $totalApprovalLevels
            ? $approvalLevels[$currentApprovalOrder]['label']
            : 'No More Approval';
    } catch (Exception $e) {
        return '-';
    }
}

// ============================================
// FILTER & PAGINATION
// ============================================
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// ============================================
// AMBIL DATA TR NUMBER DARI ACTIVITY_DETAILS
// ============================================
$where = "WHERE ad.tr_number IS NOT NULL AND ad.tr_number != ''";
$params = [];

if ($userRole === 'sales') {
    $where .= " AND sa.sales_id = ?";
    $params[] = $userId;
}

if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where .= " AND (
            NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci)
            OR EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'pending')
        )";
    } elseif ($status_filter === 'approved') {
        $where .= " AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'approved')
                    AND NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status IN ('pending', 'rejected'))";
    } elseif ($status_filter === 'rejected') {
        $where .= " AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'rejected')";
    }
}

if (!empty($search)) {
    $where .= " AND (ad.tr_number LIKE ? OR a.nama_pt LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%"]);
}

$countSql = "SELECT COUNT(DISTINCT ad.tr_number) 
             FROM activity_details ad
             LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
             LEFT JOIN accounts a ON sa.account_id = a.id
             $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

$sql = "SELECT ad.tr_number, 
               ad.due_date,
               MIN(ad.created_at) as request_date,
               a.nama_pt, 
               a.badan_usaha,
               u.full_name as sales_name,
               sa.sales_id,
               sa.id as sales_activity_id,
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'rejected'
                   ) THEN 'rejected'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'pending'
                   ) THEN 'pending'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'approved'
                   ) THEN 'approved'
                   ELSE 'pending'
               END as status
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        $where
        GROUP BY ad.tr_number, sa.sales_id, sa.id
        ORDER BY request_date DESC
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// ============================================
// STATISTIK
// ============================================
$statWhere = "WHERE ad.tr_number IS NOT NULL AND ad.tr_number != ''";
$statParams = [];

if ($userRole === 'sales') {
    $statWhere .= " AND sa.sales_id = ?";
    $statParams[] = $userId;
}

$sqlPending = "SELECT COUNT(DISTINCT ad.tr_number) FROM activity_details ad
               LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
               $statWhere 
               AND (
                   NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci)
                   OR EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'pending')
               )";
$stmt = $db->prepare($sqlPending);
$stmt->execute($statParams);
$totalPending = $stmt->fetchColumn();

$sqlApproved = "SELECT COUNT(DISTINCT ad.tr_number) FROM activity_details ad
                LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                $statWhere 
                AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'approved')
                AND NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status IN ('pending', 'rejected'))";
$stmt = $db->prepare($sqlApproved);
$stmt->execute($statParams);
$totalApproved = $stmt->fetchColumn();

$sqlRejected = "SELECT COUNT(DISTINCT ad.tr_number) FROM activity_details ad
                LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                $statWhere 
                AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'rejected')";
$stmt = $db->prepare($sqlRejected);
$stmt->execute($statParams);
$totalRejected = $stmt->fetchColumn();

$totalRequests = $totalPending + $totalApproved + $totalRejected;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transaction Request - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
:root{
 --bg:#060b18;--panel:#0b1222;--panel2:#0d1730;--line:rgba(148,163,184,.16);
 --text:#f7f9ff;--muted:#8e9bb5;--blue:#3b82f6;--blue2:#60a5fa;--cyan:#22d3ee;
 --green:#34d399;--red:#fb7185;--amber:#fbbf24;--purple:#a78bfa;
}
*{box-sizing:border-box;margin:0;padding:0}
html{background:#060b18}
body{font-family:Inter,Arial,sans-serif;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c);color:var(--text);min-height:100vh;overflow-x:hidden;padding-bottom:0}
a{color:inherit}
.topbar{height:72px;border-bottom:1px solid var(--line);background:rgba(5,9,20,.88);backdrop-filter:blur(18px);display:flex;align-items:center;padding:0 26px;gap:24px;position:sticky;top:0;z-index:1100}
.top-brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px}.top-brand img{width:38px;height:38px;object-fit:contain}.top-brand strong{font-size:17px;letter-spacing:-.4px;font-weight:800;display:block;line-height:1.15}.top-brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px;line-height:1}
.top-actions{display:flex;align-items:center;gap:10px;margin-left:auto}.icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}.notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}.top-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5);color:#fff}.top-mobile-toggle{display:none}
.sidebar{width:245px;position:fixed;top:72px;bottom:0;left:0;background:rgba(5,10,21,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px;gap:6px;z-index:1000;overflow-y:auto}.sidebar::-webkit-scrollbar{width:4px}.sidebar::-webkit-scrollbar-thumb{background:rgba(96,165,250,.25);border-radius:10px}.sidebar .brand{display:none!important}.rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}.sidebar .nav-item{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex;align-items:center;gap:12px;text-decoration:none;transition:.2s;padding:0 13px;font-size:11px;font-weight:600;margin:0}.sidebar .nav-item i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.sidebar .nav-item:hover,.sidebar .nav-item.active{color:#fff;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06));box-shadow:inset 2px 0 0 #60a5fa}.sidebar .nav-item.active i{color:#60a5fa}.sidebar-spacer{flex:1;min-height:20px}.sidebar .user-profile{margin:8px 4px 4px;padding:12px;border:1px solid rgba(148,163,184,.10);background:rgba(10,18,34,.7);border-radius:13px;display:flex;align-items:center;gap:10px}.sidebar .avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}.user-info{min-width:0}.user-info .name{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-info .role{display:block;font-size:8px;color:#66758f;margin-top:2px}.sidebar .logout-btn{width:100%;height:43px;border-radius:11px;color:#8794aa;background:transparent;border:0;display:flex;align-items:center;gap:10px;text-align:left;text-decoration:none;transition:.2s;padding:0 13px;margin:0;font-size:11px;font-weight:600}.sidebar .logout-btn i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.sidebar .logout-btn:hover{color:#fb7185;background:rgba(251,113,133,.08)}.sidebar .logout-btn:hover i{color:#fb7185}
.main-content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px;min-height:calc(100vh - 72px);max-width:none}.page-header{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px;flex-wrap:wrap}.page-header>div:first-child{display:flex;gap:12px;align-items:center}.page-header h4{display:flex;align-items:center;gap:10px;font-size:25px;line-height:1.1;font-weight:800;letter-spacing:-.4px;color:#f7f9ff;margin:0}.page-header h4 span{width:38px;height:38px;border-radius:11px;background:rgba(96,165,250,.10);border:1px solid rgba(96,165,250,.15);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}.page-header h4 span i{font-size:15px;color:#60a5fa;margin:0}.page-header p{font-size:12px;color:var(--muted);margin-top:7px}.eyebrow{font-size:10px;color:#6f80a0;text-transform:uppercase;letter-spacing:1.6px;font-weight:700;margin-bottom:7px}.mobile-toggle{display:none}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat-card{min-height:128px;background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);padding:18px 19px;transition:.25s;color:#eaf0f8}.stat-card:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25);transform:translateY(-1px)}.stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:0}.stat-icon.gold{background:rgba(212,160,23,.12);color:#e0b53d}.stat-icon.blue{background:rgba(59,130,246,.12);color:#60a5fa}.stat-icon.green{background:rgba(52,211,153,.12);color:#34d399}.stat-icon.red{background:rgba(251,113,133,.10);color:#fb7185}.stat-number{color:#f7f9ff;font-size:24px;font-weight:800;line-height:1;margin-top:15px;margin-bottom:6px}.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:700;color:#70809b}
.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s}.card-custom:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}.card-header-custom{min-height:62px;padding:13px 17px;border-bottom:1px solid rgba(148,163,184,.10);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.card-header-custom h6{font-weight:700;color:#f7f9ff;margin:0;font-size:12px}.card-header-custom h6 i{color:#60a5fa;margin-right:8px}.card-header-custom form{display:flex;align-items:center;gap:7px}.card-header-custom input{height:36px;width:230px;background:#0a1427!important;border:1px solid rgba(148,163,184,.16)!important;color:#dbe5f5!important;border-radius:9px!important;font-size:11px}.card-header-custom input::placeholder{color:#52627d}.btn-primary-custom{background:linear-gradient(135deg,#3b82f6,#6366f1);border:0;border-radius:9px;padding:9px 15px;font-weight:700;font-size:11px;transition:.2s;color:#fff}.btn-primary-custom:hover{background:linear-gradient(135deg,#4f8df7,#6d70f3);transform:translateY(-1px);box-shadow:0 8px 22px rgba(59,130,246,.2);color:#fff}.btn-secondary-custom{background:#111d31;border:1px solid rgba(148,163,184,.13);border-radius:9px;padding:9px 15px;font-weight:600;font-size:11px;color:#8f9db4;transition:.2s}.btn-secondary-custom:hover{background:#17253d;color:#fff;border-color:rgba(148,163,184,.22)}
.border-bottom{border-color:rgba(148,163,184,.10)!important}.filter-buttons{display:flex;gap:8px;flex-wrap:wrap}.btn-filter{padding:7px 12px;border:1px solid rgba(148,163,184,.12);background:rgba(10,17,33,.85);border-radius:9px;color:#8492aa;text-decoration:none;font-size:10px;font-weight:600;transition:.2s}.btn-filter:hover{color:#fff;border-color:rgba(96,165,250,.25);background:#10203a}.btn-filter.active{background:rgba(37,99,235,.15);border-color:rgba(96,165,250,.3);color:#9fc5ff}.btn-filter .count{background:rgba(255,255,255,.05);padding:2px 6px;border-radius:10px;margin-left:4px}.card-body-custom{padding:0}.table-responsive{background:transparent;overflow-x:auto}.table-custom{margin-bottom:0!important;width:100%;min-width:1080px;font-size:10px;color:#cbd5e1;--bs-table-bg:transparent;--bs-table-color:#cbd5e1;--bs-table-border-color:transparent}.table-custom th{height:43px;font-weight:700;font-size:8.5px;text-transform:uppercase;letter-spacing:.6px;color:#66758f!important;border-bottom:1px solid rgba(148,163,184,.10)!important;padding:11px 13px!important;background:rgba(5,12,25,.48)!important;white-space:nowrap}.table-custom td{height:54px;padding:10px 13px!important;vertical-align:middle;border-bottom:1px solid rgba(148,163,184,.07)!important;color:#cbd5e1!important;background:transparent!important}.table-custom tbody tr{transition:.15s}.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)!important;color:#e8eef7!important}.table-custom tr:last-child td{border-bottom:none!important}.table-custom a{color:#60a5fa!important;text-decoration:none;font-weight:700}.table-custom a:hover{color:#93c5fd!important}.table-custom th:first-child,.table-custom td:first-child{width:48px;text-align:center}.table-custom th:nth-child(2){min-width:120px}.table-custom th:nth-child(3){min-width:190px}.table-custom th:nth-child(4){min-width:105px}.table-custom th:nth-child(5){min-width:150px}.table-custom th:nth-child(6){min-width:135px}.table-custom th:nth-child(7){min-width:105px}.table-custom th:nth-child(8){width:92px;text-align:center}.current-approver{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:5px 9px;border-radius:8px;background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.14);color:#cbd5e1;font-size:9px;font-weight:700;white-space:nowrap}.table-custom th:last-child,.table-custom td:last-child{width:92px;text-align:center}.text-muted{color:#64748b!important}
.badge-status-tr{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid transparent}.badge-status-tr.pending{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}.badge-status-tr.approved{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}.badge-status-tr.rejected{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}.btn-pdf{background:rgba(52,211,153,.10);border:1px solid rgba(52,211,153,.15);border-radius:8px;padding:6px 10px;color:#6ee7b7;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;transition:.2s}.btn-pdf:hover{color:#a7f3d0;background:rgba(52,211,153,.14);transform:translateY(-1px)}.btn-pdf-disabled{background:rgba(100,116,139,.08);border:1px solid rgba(100,116,139,.12);border-radius:8px;padding:6px 10px;color:#59677d;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;cursor:not-allowed}
.card-footer{background:rgba(5,12,25,.35)!important;border-top:1px solid rgba(148,163,184,.08)!important}.pagination{gap:4px}.pagination .page-link{background:#0a1427;border:1px solid rgba(148,163,184,.12);color:#8492aa;border-radius:8px!important;font-size:9px;padding:6px 9px}.pagination .page-link:hover{background:#10203a;color:#fff;border-color:rgba(96,165,250,.25)}.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 0 15px rgba(59,130,246,.22)}.alert{border-radius:10px;border:1px solid rgba(96,165,250,.14);padding:10px 13px;font-size:11px;background:#0c1830;color:#cbd5e1}.footer-text{text-align:center;color:#44536c;font-size:9px;margin-top:20px}.footer-text a{color:#6b7a94;text-decoration:none}.footer-text a:hover{color:#60a5fa}
html,body{scrollbar-color:rgba(96,165,250,.32) #060b18;scrollbar-width:thin}html::-webkit-scrollbar,body::-webkit-scrollbar{width:7px;height:7px}html::-webkit-scrollbar-track,body::-webkit-scrollbar-track{background:#060b18}html::-webkit-scrollbar-thumb,body::-webkit-scrollbar-thumb{background:rgba(96,165,250,.30);border-radius:999px;border:1px solid rgba(6,11,24,.9)}html::-webkit-scrollbar-thumb:hover,body::-webkit-scrollbar-thumb:hover{background:rgba(96,165,250,.48)}
@media(max-width:991px){.topbar{padding:0 16px}.top-mobile-toggle{display:flex;width:36px;height:36px;margin-right:10px;border:1px solid var(--line);background:#0a1427;color:#60a5fa;border-radius:9px;align-items:center;justify-content:center}.sidebar{transform:translateX(-100%);transition:.25s}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0;width:100%;padding:92px 18px 24px}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.page-header{align-items:flex-start}}
@media(max-width:800px){.topbar{padding:0 16px}.top-brand{min-width:0}.top-brand>div{display:none}.sidebar{top:72px}.main-content{padding:20px 14px 40px}.page-header{align-items:flex-start;flex-direction:column}.mobile-toggle{display:none}.table-custom{min-width:1080px}}
@media(max-width:520px){.topbar{height:64px;padding:0 12px}.top-brand img{width:32px;height:32px}.main-content{padding:17px 10px 34px}.sidebar{top:64px}.page-header h4{font-size:20px}.page-header h4 span{width:34px;height:34px;border-radius:9px}.stat-grid{gap:9px}.stat-card{min-height:110px;padding:13px;border-radius:14px}.stat-number{font-size:20px}.card-header-custom{align-items:flex-start;padding:13px}.card-header-custom form{width:100%}.card-header-custom input{flex:1;width:auto}.filter-buttons{overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px}.btn-filter{white-space:nowrap}}

.current-approver{display:inline-flex;align-items:center;min-height:28px;padding:5px 9px;border-radius:8px;background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.14);color:#cbd5e1;font-size:9px;font-weight:700;white-space:nowrap}
@media(max-width:800px){.table-custom{min-width:1080px}}
<style>.account-name{display:flex;align-items:center;gap:2px;min-width:0;white-space:nowrap}.account-name strong{font-size:10px;font-weight:700;color:#e8eef7}.account-name span{font-size:10px;font-weight:600;color:#8e9bb5}</style>
</head>
<body>
<header class="topbar">
    <button class="top-mobile-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu"><i class="fas fa-bars"></i></button>
    <a class="top-brand" href="dashboard.php"><img src="images/logo.webp" alt="GET"><div><strong>PT Ganda Elang Tangguh</strong><small>Customer Relationship Management</small></div></a>
    <div class="top-actions"><button class="icon-btn" type="button" aria-label="Notifications"><i class="far fa-bell"></i><span class="notif">!</span></button><div class="top-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div></div>
</header>
<!-- SIDEBAR MODERN -->
<nav class="sidebar" id="sidebar">
    <div class="rail-label">Main Menu</div>
    <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <?php if (in_array('sales_activity', $menuNames)): ?>
        <a href="salesactivity.php" class="nav-item"><i class="fas fa-chart-line"></i><span>Sales Activity</span></a>
    <?php endif; ?>
    <?php if (in_array('account_management', $menuNames)): ?>
        <a href="account_management.php" class="nav-item"><i class="fas fa-building"></i><span>Account Management</span></a>
    <?php endif; ?>
    <?php if (in_array('transaction_request', $menuNames)): ?>
        <a href="transactionrequest.php" class="nav-item active"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a>
    <?php endif; ?>
    <?php if (in_array('produk', $menuNames)): ?>
        <a href="produk.php" class="nav-item"><i class="fas fa-box"></i><span>Produk</span></a>
    <?php endif; ?>
    <?php if (in_array('delivery_order', $menuNames)): ?>
        <a href="deliveryinstruction.php" class="nav-item"><i class="fas fa-truck-moving"></i><span>Delivery Order</span></a>
    <?php endif; ?>

    <div class="rail-label">Administration</div>
    <?php if (in_array('data_user', $menuNames)): ?>
        <a href="data_user.php" class="nav-item"><i class="fas fa-users"></i><span>Data User</span></a>
    <?php endif; ?>

    <div class="sidebar-spacer"></div>
    <div class="user-profile">
        <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
        <div class="user-info">
            <div class="name"><?= htmlspecialchars($fullName) ?></div>
            <div class="role"><?= getRoleLabel($role) ?></div>
        </div>
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-power-off"></i><span>Logout</span></a>
</nav>
<div class="main-content">
<!-- PAGE HEADER -->
<div class="page-header">
    <div style="display:flex; gap:15px; align-items:center;">
        <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu">
            <i class="fas fa-bars"></i>
        </button>
        <div>
            <h4><span><i class="fas fa-file-signature"></i></span> Transaction Request</h4>
        </div>
    </div>
</div>
<!-- STATISTIK -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-file-signature"></i></div>
                <div class="stat-number"><?= number_format($totalRequests) ?></div>
                <div class="stat-label">Total Request</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?= number_format($totalPending) ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?= number_format($totalApproved) ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                <div class="stat-number"><?= number_format($totalRejected) ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Transaction Request</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
                    <button type="submit" class="btn btn-primary-custom" style="padding: 6px 16px;"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="transactionrequest.php" class="btn btn-secondary-custom" style="padding: 6px 16px;"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Filter Status -->
            <div class="px-3 pt-3 pb-2 border-bottom">
                <div class="filter-buttons">
                    <a href="?status=all&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'all' ? 'active' : '' ?>">
                        Semua <span class="count"><?= $totalRequests ?></span>
                    </a>
                    <a href="?status=pending&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'pending' ? 'active' : '' ?>">
                        <i class="fas fa-clock fa-fw" style="color:#f39c12;"></i> Pending <span class="count"><?= $totalPending ?></span>
                    </a>
                    <a href="?status=approved&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'approved' ? 'active' : '' ?>">
                        <i class="fas fa-check-circle fa-fw" style="color:#2980b9;"></i> Approved <span class="count"><?= $totalApproved ?></span>
                    </a>
                    <a href="?status=rejected&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'rejected' ? 'active' : '' ?>">
                        <i class="fas fa-times-circle fa-fw" style="color:#e74c3c;"></i> Rejected <span class="count"><?= $totalRejected ?></span>
                    </a>
                </div>
            </div>
            
            <div class="card-body-custom">
                <?= showFlash() ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>TR Number</th>
                                <th>Account</th>
                                <th>Request Date</th>
                                <th>Sales</th>
                                <th>Next Approver</th>
                                <th>Status</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($requests) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php 
                                    $statusLabel = ucfirst($request['status']);
                                    $statusClass = $request['status'];
                                    $isApproved = ($request['status'] == 'approved');
                                    ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <a href="detailtr.php?tr_number=<?= urlencode($request['tr_number']) ?>" class="tr-number-link">
                                                <?= htmlspecialchars($request['tr_number']) ?>
                                            </a>
                                        </td>
                                        <td>
    <div class="account-name">
        <strong><?= htmlspecialchars($request['nama_pt'] ?? '-') ?></strong>
        <?php if (!empty($request['badan_usaha'])): ?>
            <span>, <?= htmlspecialchars($request['badan_usaha']) ?></span>
        <?php endif; ?>
    </div>
</td>
                                        <td><?= date('d/m/Y', strtotime($request['request_date'])) ?></td>
                                        <td><?= htmlspecialchars($request['sales_name'] ?? '-') ?></td>
                                        <td><span class="current-approver"><?= htmlspecialchars(getCurrentApproverForTR($db, (string)($request['tr_number'] ?? ''), $approvalLevels, $totalApprovalLevels)) ?></span></td>
                                        <td>
                                            <span class="badge-status-tr <?= $statusClass ?>">
                                                <?php if ($request['status'] == 'pending'): ?>
                                                    <i class="fas fa-clock"></i>
                                                <?php elseif ($request['status'] == 'approved'): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php elseif ($request['status'] == 'rejected'): ?>
                                                    <i class="fas fa-times-circle"></i>
                                                <?php endif; ?>
                                                <?= $statusLabel ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($userRole !== 'sales'): ?>
                                                <?php if ($isApproved): ?>
                                                    <a href="export_detail_pdf.php?tr_number=<?= urlencode($request['tr_number']) ?>" 
                                                       class="btn-pdf" 
                                                       target="_blank"
                                                       title="Download PDF Detail TR">
                                                        <i class="fas fa-file-pdf"></i> PDF
                                                    </a>
                                                <?php else: ?>
                                                    <span class="btn-pdf-disabled" title="PDF hanya tersedia untuk TR yang sudah Approved">
                                                        <i class="fas fa-file-pdf"></i> PDF
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data transaction request
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-transparent border-top p-3">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

        <!-- FOOTER -->
        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM
        </div>

    
</div>
    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>