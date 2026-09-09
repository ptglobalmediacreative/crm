<?php
require_once 'config.php';

// Set timezone ke WIB
date_default_timezone_set('Asia/Jakarta');

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// ============================================
// CEK AKSES HALAMAN
// ============================================
requirePermission('sales_activity', 'view');

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
// FUNGSI GENERATE LEADS NUMBER
// ============================================
function generateLeadsNumber($db) {
    $tahun = date('Y');
    $bulan = date('n');
    $bulanRomawi = getBulanRomawi($bulan);

    // Ambil nomor terbesar pada periode berjalan.
    // Tidak bergantung pada ID terakhir sehingga tetap benar setelah penghapusan.
    $pattern = "%/GET-ACT/JKT/{$bulanRomawi}/{$tahun}";

    $stmt = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(leads_number, '/', 1) AS UNSIGNED)), 0)
        FROM sales_activities
        WHERE leads_number LIKE ?
    ");
    $stmt->execute([$pattern]);
    $lastSequence = (int)$stmt->fetchColumn();

    $sequence = str_pad((string)($lastSequence + 1), 4, '0', STR_PAD_LEFT);

    return "{$sequence}/GET-ACT/JKT/{$bulanRomawi}/{$tahun}";
}

// ============================================
// FUNGSI KONVERSI BULAN KE ROMAWI
// ============================================
function getBulanRomawi($month) {
    $romawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $romawi[(int)$month];
}

// ============================================
// RENUMBER SEMUA ACTIVITY NUMBER
// Format: 0001/GET-ACT/JKT/IX/2026
// Diurutkan PER PERIODE berdasarkan created_at, lalu id.
// ============================================
function renumberAllActivityNumbers($db) {
    $stmt = $db->query("
        SELECT id, leads_number,
               SUBSTRING_INDEX(leads_number, '/GET-ACT/JKT/', -1) AS period
        FROM sales_activities
        WHERE leads_number IS NOT NULL
          AND TRIM(leads_number) <> ''
        ORDER BY period ASC, created_at ASC, id ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    $sequenceByPeriod = [];
    $mapping = [];

    foreach ($rows as $row) {
        $period = $row['period'];
        if (!isset($sequenceByPeriod[$period])) {
            $sequenceByPeriod[$period] = 1;
        }

        $mapping[(int)$row['id']] = [
            'old' => $row['leads_number'],
            'new' => str_pad((string)$sequenceByPeriod[$period], 4, '0', STR_PAD_LEFT)
                   . '/GET-ACT/JKT/' . $period
        ];
        $sequenceByPeriod[$period]++;
    }

    $needsUpdate = false;
    foreach ($mapping as $item) {
        if ($item['old'] !== $item['new']) {
            $needsUpdate = true;
            break;
        }
    }
    if (!$needsUpdate) return 0;

    // Nomor sementara mencegah benturan UNIQUE KEY saat 0002 -> 0001, dst.
    $token = '__ACT_RENUMBER_' . bin2hex(random_bytes(8)) . '__';

    $stmtTemp = $db->prepare("UPDATE sales_activities SET leads_number = ? WHERE id = ?");
    foreach ($mapping as $id => $item) {
        $stmtTemp->execute([$token . $id, $id]);
    }

    $stmtFinal = $db->prepare("UPDATE sales_activities SET leads_number = ? WHERE id = ?");
    foreach ($mapping as $id => $item) {
        $stmtFinal->execute([$item['new'], $id]);
    }

    return count($mapping);
}


// ============================================
// HAPUS SEMUA DATA TRANSACTION REQUEST
// YANG TERKAIT DENGAN TR TERTENTU
// ============================================
function deleteTransactionRequestData($db, $trNumber) {
    $tables = [
        'detail_transaction_requests',
        'transaction_requests',
        'tr_additional_costs',
        'tr_additional_cost_items',
        'tr_approval_history',
        'tr_cost_calculations',
        'tr_detail_units',
        'tr_mediators',
        'tr_product_supports',
        'tr_term_of_payments'
    ];

    foreach ($tables as $table) {
        try {
            $stmt = $db->prepare("DELETE FROM `{$table}` WHERE trf_number = ?");
            $stmt->execute([$trNumber]);
        } catch (PDOException $e) {
            // Jangan hentikan proses apabila tabel tertentu tidak ada.
            // Tabel yang memang ada akan tetap dibersihkan.
        }
    }
}

// ============================================
// RENUMBER SEMUA TR SETELAH ADA TR YANG DIHAPUS
// Format: 0001/GET-TR/JKT/IX/2026
// Nomor diurutkan ulang per bulan/periode berdasarkan
// created_at paling awal.
// ============================================
function renumberAllTransactionRequests($db) {
    $stmt = $db->query("\n        SELECT\n            ad.tr_number AS old_tr,\n            SUBSTRING_INDEX(ad.tr_number, '/GET-TR/JKT/', -1) AS period,\n            MIN(ad.created_at) AS first_created_at\n        FROM activity_details ad\n        WHERE ad.tr_number IS NOT NULL\n          AND TRIM(ad.tr_number) <> ''\n        GROUP BY ad.tr_number\n        ORDER BY period ASC, first_created_at ASC, old_tr ASC\n    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return 0;
    }

    $mapping = [];
    $sequenceByPeriod = [];

    foreach ($rows as $row) {
        $period = $row['period'];
        if (!isset($sequenceByPeriod[$period])) {
            $sequenceByPeriod[$period] = 1;
        }

        $newTr = str_pad((string)$sequenceByPeriod[$period], 4, '0', STR_PAD_LEFT)
               . '/GET-TR/JKT/' . $period;

        $mapping[$row['old_tr']] = $newTr;
        $sequenceByPeriod[$period]++;
    }

    $tables = [
        'activity_details',
        'detail_transaction_requests',
        'transaction_requests',
        'tr_additional_costs',
        'tr_additional_cost_items',
        'tr_approval_history',
        'tr_cost_calculations',
        'tr_detail_units',
        'tr_mediators',
        'tr_product_supports',
        'tr_term_of_payments'
    ];

    // Gunakan prefix sementara supaya tidak terjadi benturan nama
    // saat 0002 harus menjadi 0001, 0003 menjadi 0002, dst.
    $token = '__TR_RENUMBER_' . bin2hex(random_bytes(6)) . '__';

    // Mapping lama -> sementara.
    foreach ($mapping as $oldTr => $newTr) {
        $temporaryTr = $token . hash('sha256', $oldTr);
        foreach ($tables as $table) {
            try {
                $stmt = $db->prepare("UPDATE `{$table}` SET tr_number = ? WHERE tr_number = ?");
                $stmt->execute([$temporaryTr, $oldTr]);
            } catch (PDOException $e) {
                // Tabel menggunakan nama kolom trf_number, bukan tr_number.
                try {
                    $stmt = $db->prepare("UPDATE `{$table}` SET trf_number = ? WHERE trf_number = ?");
                    $stmt->execute([$temporaryTr, $oldTr]);
                } catch (PDOException $ignored) {
                    // Abaikan tabel/kolom yang tidak tersedia.
                }
            }
        }
    }

    // Mapping sementara -> nomor TR final.
    foreach ($mapping as $oldTr => $newTr) {
        $temporaryTr = $token . hash('sha256', $oldTr);
        foreach ($tables as $table) {
            try {
                $stmt = $db->prepare("UPDATE `{$table}` SET tr_number = ? WHERE tr_number = ?");
                $stmt->execute([$newTr, $temporaryTr]);
            } catch (PDOException $e) {
                try {
                    $stmt = $db->prepare("UPDATE `{$table}` SET trf_number = ? WHERE trf_number = ?");
                    $stmt->execute([$newTr, $temporaryTr]);
                } catch (PDOException $ignored) {
                    // Abaikan tabel/kolom yang tidak tersedia.
                }
            }
        }
    }

    // Simpan mapping jika tabel audit sudah dibuat.
    try {
        $db->exec("DELETE FROM tr_renumber_map");
        $insertMap = $db->prepare("INSERT INTO tr_renumber_map (old_tr, new_tr) VALUES (?, ?)");
        foreach ($mapping as $oldTr => $newTr) {
            $insertMap->execute([$oldTr, $newTr]);
        }
    } catch (PDOException $e) {
        // Opsional; proses utama tidak bergantung pada tabel mapping.
    }

    return count($mapping);
}

// ============================================
// HAPUS SALES ACTIVITY + DETAIL + TR TERKAIT
// LALU RENUMBER TR SECARA OTOMATIS
// ============================================
function deleteSalesActivityAndRelatedData($db, $salesActivityId) {
    // Ambil seluruh TR yang pernah digunakan oleh activity ini.
    $stmt = $db->prepare("\n        SELECT DISTINCT tr_number\n        FROM activity_details\n        WHERE sales_activity_id = ?\n          AND tr_number IS NOT NULL\n          AND TRIM(tr_number) <> ''\n    ");
    $stmt->execute([$salesActivityId]);
    $trNumbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Ambil seluruh ID detail activity agar bisa dibersihkan juga.
    $stmt = $db->prepare("SELECT id FROM activity_details WHERE sales_activity_id = ?");
    $stmt->execute([$salesActivityId]);
    $detailIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $db->beginTransaction();

    try {
        // 1. Hapus detail aktivitas.
        // Jika ada attachment yang tersimpan sebagai file, database saja yang
        // dibersihkan di sini; file fisik tidak disentuh agar aman.
        $stmt = $db->prepare("DELETE FROM activity_details WHERE sales_activity_id = ?");
        $stmt->execute([$salesActivityId]);

        // 2. Hapus sales activity induknya.
        $stmt = $db->prepare("DELETE FROM sales_activities WHERE id = ?");
        $stmt->execute([$salesActivityId]);

        // 3. Hapus data TR hanya apabila TR tersebut sudah tidak dipakai
        // oleh activity_details lain.
        foreach ($trNumbers as $trNumber) {
            $check = $db->prepare("\n                SELECT COUNT(*)\n                FROM activity_details\n                WHERE tr_number = ?\n            ");
            $check->execute([$trNumber]);
            $remaining = (int)$check->fetchColumn();

            if ($remaining === 0) {
                deleteTransactionRequestData($db, $trNumber);
            }
        }

        // 4. Rapikan nomor TR seluruh database setelah penghapusan.
        renumberAllTransactionRequests($db);

        $db->commit();

        return [
            'success' => true,
            'tr_count' => count($trNumbers),
            'detail_count' => count($detailIds)
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// ============================================
// FUNGSI MENENTUKAN JENIS PROSPEK
// ============================================
function getJenisProspek($db, $salesActivityId) {
    $stmt = $db->prepare("SELECT ad.* FROM activity_details ad 
                          WHERE ad.sales_activity_id = ? 
                          ORDER BY ad.id DESC LIMIT 1");
    $stmt->execute([$salesActivityId]);
    $lastActivity = $stmt->fetch();
    
    if (!$lastActivity) {
        return null;
    }
    
    $jenis_tugas = $lastActivity['jenis_tugas'];
    $customer_deal = $lastActivity['customer_deal'];
    
    // Delivery Order: Cek Customer Deal (Yes = Deal, No = Lost Deal)
    if ($jenis_tugas === 'Delivery Order') {
        if ($customer_deal === 'Yes') {
            return 'Deal';
        } elseif ($customer_deal === 'No') {
            return 'Lost Deal';
        }
        return null;
    }
    
    // Negosiasi = Hot Prospect
    if ($jenis_tugas === 'Negosiasi') {
        return 'Hot Prospect';
    }
    
    // Kontrak = Hot Prospect
    if ($jenis_tugas === 'Kontrak') {
        return 'Hot Prospect';
    }
    
    // Prospecting = Prospect
    if ($jenis_tugas === 'Prospecting') {
        return 'Prospect';
    }
    
    // Perkenalan = Suspect
    if ($jenis_tugas === 'Perkenalan') {
        return 'Suspect';
    }
    
    // Visit/Meeting = Suspect
    if ($jenis_tugas === 'Visit/Meeting') {
        return 'Suspect';
    }
    
    // After Sales = Deal
    if ($jenis_tugas === 'After Sales') {
        return 'Deal';
    }
    
    return null;
}

// ============================================
// FUNGSI MENENTUKAN STATUS
// ============================================
function getStatusProspek($db, $salesActivityId) {
    $stmt = $db->prepare("SELECT ad.status FROM activity_details ad 
                          WHERE ad.sales_activity_id = ? 
                          ORDER BY ad.id DESC");
    $stmt->execute([$salesActivityId]);
    $allStatus = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($allStatus)) {
        return null;
    }
    
    if (in_array('overdue', $allStatus)) {
        return 'Overdue';
    }
    
    if (in_array('in_progress', $allStatus)) {
        return 'In Progress';
    }
    
    return 'Completed';
}

// ============================================
// FILTER & PAGINATION
// ============================================
$userRole = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';
$filterMonth = isset($_GET['month']) ? bersihkan($_GET['month']) : date('Y-m');
$filterSalesId = isset($_GET['sales_id']) ? (int)$_GET['sales_id'] : 0;
$filterJenisProspek = isset($_GET['jenis_prospek']) ? bersihkan($_GET['jenis_prospek']) : '';
$filterStatus = isset($_GET['status']) ? bersihkan($_GET['status']) : '';

$where = "WHERE 1=1";
$params = [];

if ($userRole === 'sales') {
    $where .= " AND sa.sales_id = ?";
    $params[] = $userId;
} elseif ($filterSalesId > 0) {
    $where .= " AND sa.sales_id = ?";
    $params[] = $filterSalesId;
}

if (!empty($search)) {
    $where .= " AND (sa.leads_number LIKE ? OR a.nama_pt LIKE ? OR a.nama_pic LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

if (!empty($filterMonth)) {
    $where .= " AND DATE_FORMAT(sa.created_at, '%Y-%m') = ?";
    $params[] = $filterMonth;
}

// Filter Jenis Prospek
if (!empty($filterJenisProspek)) {
    $where .= " AND sa.jenis_prospek = ?";
    $params[] = $filterJenisProspek;
}

// Filter Status
if (!empty($filterStatus)) {
    $where .= " AND sa.status = ?";
    $params[] = $filterStatus;
}

// ============================================
// EXPORT TO EXCEL
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Data_Sales_Activity_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $exportSql = "SELECT sa.*, a.nama_pt, a.badan_usaha, a.bidang_usaha, a.nama_pic, a.no_hp_pic, a.email_pic, u.full_name as sales_name
            FROM sales_activities sa 
            LEFT JOIN accounts a ON sa.account_id = a.id 
            LEFT JOIN users u ON sa.sales_id = u.id
            $where 
            ORDER BY sa.created_at DESC";
    $stmt = $db->prepare($exportSql);
    $stmt->execute($params);
    $exportActivities = $stmt->fetchAll();
    
    echo '<html>';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<h2>Data Sales Activity - PT Ganda Elang Tangguh</h2>';
    echo '<p>Tanggal Export: ' . date('d-m-Y H:i:s') . ' WIB</p>';
    echo '<p>Filter Bulan: ' . date('F Y', strtotime($filterMonth . '-01')) . '</p>';
    if (!empty($filterJenisProspek)) {
        echo '<p>Filter Jenis Prospek: ' . htmlspecialchars($filterJenisProspek) . '</p>';
    }
    if (!empty($filterStatus)) {
        echo '<p>Filter Status: ' . htmlspecialchars($filterStatus) . '</p>';
    }
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr style="background-color: #1a1a2e; color: #ffffff;">';
    echo '<th>No</th>';
    echo '<th>Leads Number</th>';
    echo '<th>Nama PT</th>';
    echo '<th>Badan Usaha</th>';
    echo '<th>Business Segment</th>';
    echo '<th>Jenis Prospek</th>';
    echo '<th>Status</th>';
    echo '<th>Nama PIC</th>';
    echo '<th>Contact Mobile Phone</th>';
    echo '<th>Sales</th>';
    echo '<th>Tanggal Dibuat</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $no = 1;
    foreach ($exportActivities as $act) {
        $jenisProspek = getJenisProspek($db, $act['id']) ?? '-';
        $statusProspek = getStatusProspek($db, $act['id']) ?? '-';
        
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td>' . htmlspecialchars($act['leads_number']) . '</td>';
        echo '<td>' . htmlspecialchars($act['nama_pt']) . '</td>';
        echo '<td>' . htmlspecialchars($act['badan_usaha'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($act['bidang_usaha'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($jenisProspek) . '</td>';
        echo '<td>' . htmlspecialchars($statusProspek) . '</td>';
        echo '<td>' . htmlspecialchars($act['nama_pic'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($act['no_hp_pic'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($act['sales_name'] ?? '-') . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($act['created_at'])) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}

// ============================================
// AMBIL SEMUA DATA UNTUK CHART (tanpa pagination)
// ============================================
$chartSql = "SELECT sa.id FROM sales_activities sa 
             LEFT JOIN accounts a ON sa.account_id = a.id 
             $where";
$stmt = $db->prepare($chartSql);
$stmt->execute($params);
$chartActivities = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Hitung rekap untuk chart
$prospekCounts = [
    'Suspect' => 0,
    'Prospect' => 0,
    'Hot Prospect' => 0,
    'Deal' => 0,
    'Lost Deal' => 0
];

$statusCounts = [
    'In Progress' => 0,
    'Completed' => 0,
    'Overdue' => 0
];

foreach ($chartActivities as $saId) {
    $jp = getJenisProspek($db, $saId);
    if ($jp && isset($prospekCounts[$jp])) {
        $prospekCounts[$jp]++;
    }
    
    $sp = getStatusProspek($db, $saId);
    if ($sp && isset($statusCounts[$sp])) {
        $statusCounts[$sp]++;
    }
}

$countSql = "SELECT COUNT(*) FROM sales_activities sa LEFT JOIN accounts a ON sa.account_id = a.id $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

$sql = "SELECT sa.*, a.nama_pt, a.badan_usaha, a.bidang_usaha, a.nama_pic, a.no_hp_pic, a.email_pic, u.full_name as sales_name
        FROM sales_activities sa 
        LEFT JOIN accounts a ON sa.account_id = a.id 
        LEFT JOIN users u ON sa.sales_id = u.id
        $where 
        ORDER BY sa.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

foreach ($activities as &$act) {
    $act['jenis_prospek'] = getJenisProspek($db, $act['id']);
    $act['status_prospek'] = getStatusProspek($db, $act['id']);
    $stmt = $db->prepare("UPDATE sales_activities SET jenis_prospek = ?, status = ? WHERE id = ?");
    $stmt->execute([$act['jenis_prospek'], $act['status_prospek'], $act['id']]);
}
unset($act);

// ============================================
// AMBIL DATA ACCOUNTS UNTUK DROPDOWN
// ============================================
if ($userRole === 'sales') {
    $sqlAccounts = "SELECT id, nama_pt, badan_usaha, bidang_usaha, nama_pic, no_hp_pic, npwp, alamat, email_pic, sales_id 
                    FROM accounts 
                    WHERE sales_id = ? 
                    ORDER BY nama_pt ASC";
    $stmt = $db->prepare($sqlAccounts);
    $stmt->execute([$userId]);
    $accountsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sqlAccounts = "SELECT id, nama_pt, badan_usaha, bidang_usaha, nama_pic, no_hp_pic, npwp, alamat, email_pic, sales_id 
                    FROM accounts ORDER BY nama_pt ASC";
    $accountsList = $db->query($sqlAccounts)->fetchAll(PDO::FETCH_ASSOC);
}

$salesUsers = $db->query("SELECT id, full_name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROSES TAMBAH SALES ACTIVITY
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        if (!canAdd('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menambah aktivitas!', 'danger');
            redirect('salesactivity.php');
        }
        
        $account_id = (int)$_POST['account_id'];
        
        $stmt = $db->prepare("SELECT sales_id FROM accounts WHERE id = ?");
        $stmt->execute([$account_id]);
        $accountSalesId = $stmt->fetchColumn();
        
        if ($userRole === 'sales') {
            if ($accountSalesId != $userId) {
                setFlash('Anda tidak bisa menambahkan aktivitas untuk account milik sales lain!', 'danger');
                redirect('salesactivity.php');
            }
            $sales_id = $userId;
        } else {
            $sales_id = $accountSalesId ? (int)$accountSalesId : NULL;
        }
        
        $leads_number = generateLeadsNumber($db);
        
        $errors = [];
        if (empty($account_id)) $errors[] = 'Account wajib dipilih!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO sales_activities (leads_number, account_id, sales_id) VALUES (?, ?, ?)");
            $stmt->execute([$leads_number, $account_id, $sales_id]);
            
            setFlash('Sales Activity berhasil ditambahkan! Leads Number: ' . $leads_number, 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
            redirect('salesactivity.php');
        }
    }
    
    if ($action === 'delete') {
        if (!canDelete('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menghapus aktivitas!', 'danger');
            redirect('salesactivity.php');
        }
        
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            setFlash('ID Sales Activity tidak valid!', 'danger');
            redirect('salesactivity.php');
        }

        try {
            $result = deleteSalesActivityAndRelatedData($db, $id);

            // Setelah activity dihapus, rapikan Activity Number per periode.
            renumberAllActivityNumbers($db);

            setFlash(
                'Sales Activity berhasil dihapus. Data Detail Aktivitas dan Transaction Request terkait sudah dibersihkan, lalu nomor TR dan Activity Number yang tersisa sudah dirapikan kembali.',
                'success'
            );
        } catch (Throwable $e) {
            error_log('Gagal menghapus Sales Activity #' . $id . ': ' . $e->getMessage());
            setFlash('Gagal menghapus Sales Activity dan data TR terkait. Tidak ada perubahan yang disimpan. Silakan cek error log server.', 'danger');
        }

        redirect('salesactivity.php');
    }
}

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sales Activity - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding-bottom: 70px;
        }
        
        .sidebar {
            width: 260px;
            height: 100vh;
            background: #0e1a2b;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            padding: 30px 20px;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s ease;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 215, 0, 0.3); border-radius: 10px; }

        .sidebar .brand { 
            display: flex; align-items: center; gap: 12px; margin-bottom: 40px; text-decoration: none; 
            padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar .brand .logo-wrapper { width: 42px; height: 42px; }
        .sidebar .brand .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar .brand .brand-text h5 { font-weight: 800; margin: 0; color: #fff; letter-spacing: 0.5px; font-size: 16px; }
        .sidebar .brand .brand-text h5 span { color: #ffd700; }
        .sidebar .brand .brand-text small { font-size: 10px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; }

        .sidebar .nav-item { 
            display: flex; align-items: center; padding: 12px 16px; 
            color: rgba(255,255,255,0.6); text-decoration: none; 
            border-radius: 10px; margin-bottom: 5px; transition: all 0.2s ease; font-weight: 500; 
            font-size: 14px; position: relative;
        }
        .sidebar .nav-item i { width: 24px; font-size: 16px; margin-right: 12px; text-align: center; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .sidebar .nav-item.active { 
            background: rgba(255, 215, 0, 0.1); 
            color: #ffd700; 
            box-shadow: inset 3px 0 0 #ffd700;
        }
        
        .sidebar .user-profile { 
            margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); 
            display: flex; align-items: center; gap: 12px; 
        }
        .sidebar .user-profile .avatar { 
            width: 42px; height: 42px; border-radius: 50%; 
            background: linear-gradient(135deg, #1a1a2e, #16213e); 
            color: #ffd700; display: flex; align-items: center; justify-content: center; 
            font-weight: 700; font-size: 16px; border: 2px solid rgba(255,215,0,0.2);
        }
        .sidebar .user-profile .user-info .name { font-size: 14px; font-weight: 600; color: #fff; }
        .sidebar .user-profile .user-info .role { font-size: 12px; color: rgba(255,255,255,0.4); }

        .sidebar .logout-btn {
            display: block; text-align: center; margin-top: 15px; 
            padding: 10px; border-radius: 10px; color: #e74c3c; text-decoration: none; 
            font-weight: 600; font-size: 14px; background: rgba(231, 76, 60, 0.1); 
            transition: all 0.2s;
        }
        .sidebar .logout-btn:hover { background: rgba(231, 76, 60, 0.2); }

        .main-content { margin-left: 260px; padding: 30px; width: 100%; }

        .page-header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 30px; flex-wrap: wrap; gap: 15px; 
        }
        .page-header h4 { 
            font-weight: 800; color: #0e1a2b; font-size: 24px; margin:0; 
            letter-spacing: -0.5px;
        }
        .page-header h4 span { color: #ffd700; }

        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 991px) {
            .chart-grid { grid-template-columns: 1fr; }
        }
        
        .chart-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
            transition: all 0.3s ease;
        }
        .chart-card:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        .chart-card h6 {
            font-weight: 700;
            color: #0e1a2b;
            margin-bottom: 20px;
            font-size: 16px;
        }
        .chart-card h6 i { margin-right: 8px; }
        .chart-wrapper { height: 280px; width: 100%; position: relative; }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
            transition: all 0.3s ease;
        }
        .card-custom:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        
        .card-custom .card-header-custom {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-custom .card-header-custom h6 {
            font-weight: 600;
            color: #0e1a2b;
            margin: 0;
            font-size: 16px;
        }
        .card-custom .card-header-custom h6 i { color: #ffd700; margin-right: 8px; }
        .card-custom .card-body-custom { padding: 0; overflow-x: auto; }
        
        .table-custom { margin-bottom: 0; font-size: 13px; }
        .table-custom th {
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #7f8c8d;
            border-bottom: 1px solid #f0f2f5;
            padding: 12px 16px;
            background: #fafafa;
            white-space: nowrap;
        }
        .table-custom td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }
        .table-custom tr:last-child td { border-bottom: none; }
        .table-custom tr:hover { background: #f8f9fa; }

        .badge-prospek {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-prospek.suspect { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-prospek.prospect { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .badge-prospek.hot-prospect { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        .badge-prospek.deal-prospek { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-prospek.lost-deal { background: rgba(231, 76, 60, 0.12); color: #c0392b; }

        .badge-status-prospek {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-status-prospek.in-progress { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-status-prospek.completed { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-status-prospek.overdue { background: rgba(231, 76, 60, 0.12); color: #c0392b; }

        .btn-action {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: all 0.3s ease;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-action:hover { transform: scale(1.1); }
        .btn-action.detail { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
        .btn-action.detail:hover { background: rgba(46, 204, 113, 0.2); }
        .btn-action.delete { background: rgba(231, 76, 60, 0.1); color: #c0392b; }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }

        .modal-content { border: none; border-radius: 12px; }
        .modal-header { border-bottom: 1px solid #f0f2f5; padding: 18px 24px; }
        .modal-header .modal-title { font-weight: 700; font-size: 18px; color: #0e1a2b; }
        .modal-header .modal-title i { color: #ffd700; margin-right: 8px; }
        .modal-body { padding: 20px 24px; }
        .modal-footer { border-top: 1px solid #f0f2f5; padding: 14px 24px; }

        .form-label { font-weight: 600; font-size: 13px; color: #333; }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 2px solid #e8edf2;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #ffd700;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
        }
        .form-control[readonly] { background: #f8f9fa; cursor: not-allowed; }

        .btn-primary-custom {
            background: #0e1a2b;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-primary-custom:hover {
            background: #1a2d4a;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(14, 26, 43, 0.3);
            color: #fff;
        }
        .btn-primary-custom i { margin-right: 6px; }

        .btn-secondary-custom {
            background: #f0f2f5;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #555;
        }
        .btn-secondary-custom:hover { background: #e8edf2; color: #333; }

        .btn-success-custom {
            background: #27ae60;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-success-custom:hover {
            background: #219a52;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
            color: #fff;
        }

        .alert { border-radius: 10px; border: none; padding: 12px 16px; font-size: 14px; }
        .detail-item { display: flex; padding: 10px 0; border-bottom: 1px solid #f0f2f5; }
        .detail-item:last-child { border-bottom: none; }
        .detail-item .detail-label { font-weight: 600; color: #555; width: 160px; flex-shrink: 0; font-size: 13px; }
        .detail-item .detail-value { color: #0e1a2b; font-size: 13px; word-break: break-word; }

        .leads-number-display {
            background: rgba(255, 215, 0, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 700;
            color: #d4a017;
            text-align: center;
            font-size: 16px;
            letter-spacing: 0.5px;
        }

        .mobile-toggle { display: none; }
        .footer-text { text-align: center; padding: 16px 0 8px; color: #999; font-size: 11px; }
        .footer-text a { color: #16213e; text-decoration: none; font-weight: 500; }
        .footer-text a:hover { color: #ffd700; }

        .select2-container--default .select2-selection--single {
            border-radius: 8px;
            padding: 6px 14px;
            border: 2px solid #e8edf2;
            font-size: 13px;
            min-height: 42px;
        }
        .select2-container--default .select2-selection--single:focus {
            border-color: #ffd700;
        }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { 
                display: flex !important; background: #0e1a2b; border: none; 
                width: 40px; height: 40px; border-radius: 8px; 
                color: #ffd700; font-size: 20px; align-items: center; justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .chart-wrapper { height: 220px; }
            .modal-body { padding: 14px 16px; }
            .modal-header { padding: 14px 16px; }
            .table-custom { font-size: 11px; }
            .table-custom th, .table-custom td { padding: 6px 8px; }
            .btn-action { width: 26px; height: 26px; font-size: 11px; }
            .detail-item { flex-direction: column; padding: 8px 0; }
            .detail-item .detail-label { width: 100%; font-size: 11px; color: #999; margin-bottom: 2px; }
            .detail-item .detail-value { font-size: 12px; }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR MODERN -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="GET"></div>
            <div class="brand-text">
                <h5>CUSTOMER <span>RELATIONSHIP</span></h5>
                <small>PT Ganda Elang Tangguh</small>
            </div>
        </a>

        <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
        
        <?php if (in_array('sales_activity', $menuNames)): ?>
            <a href="salesactivity.php" class="nav-item active"><i class="fas fa-chart-bar"></i> Sales Activity</a>
        <?php endif; ?>
        
        <?php if (in_array('account_management', $menuNames)): ?>
            <a href="account_management.php" class="nav-item"><i class="fas fa-building"></i> Account</a>
        <?php endif; ?>
        
        <?php if (in_array('transaction_request', $menuNames)): ?>
            <a href="transactionrequest.php" class="nav-item"><i class="fas fa-file-signature"></i> TR Request</a>
        <?php endif; ?>
        
        <?php if (in_array('produk', $menuNames)): ?>
            <a href="produk.php" class="nav-item"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
        
        <?php if (in_array('delivery_order', $menuNames)): ?>
            <a href="deliveryinstruction.php" class="nav-item"><i class="fas fa-tractor"></i> Delivery</a>
        <?php endif; ?>
        
        <?php if (in_array('data_user', $menuNames)): ?>
            <a href="data_user.php" class="nav-item"><i class="fas fa-users"></i> User</a>
        <?php endif; ?>

        <div class="user-profile">
            <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div style="display:flex; gap:15px; align-items:center;">
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4><span><i class="fas fa-chart-bar" style="color:#ffd700;"></i></span> Sales Activity</h4>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="salesactivity.php?export=excel&month=<?= urlencode($filterMonth) ?>&sales_id=<?= $filterSalesId ?>&jenis_prospek=<?= urlencode($filterJenisProspek) ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>" class="btn btn-success-custom">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <?php if (canAdd('sales_activity')): ?>
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalActivity">
                        <i class="fas fa-plus"></i> Tambah Aktivitas
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- CHART GRID -->
        <div class="chart-grid">
            <div class="chart-card">
                <h6><i class="fas fa-filter" style="color:#ffd700;"></i> Rekap Jenis Prospek</h6>
                <div class="chart-wrapper">
                    <canvas id="chartJenisProspek"></canvas>
                </div>
            </div>
            
            <div class="chart-card">
                <h6><i class="fas fa-tasks" style="color:#2980b9;"></i> Rekap Status</h6>
                <div class="chart-wrapper">
                    <canvas id="chartStatus"></canvas>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Sales Activity</h6>
                <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($filterMonth) ?>" style="width: 160px;" onchange="this.form.submit()">
                    
                    <select name="jenis_prospek" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                        <option value="">Semua Prospek</option>
                        <option value="Suspect" <?= $filterJenisProspek === 'Suspect' ? 'selected' : '' ?>>Suspect</option>
                        <option value="Prospect" <?= $filterJenisProspek === 'Prospect' ? 'selected' : '' ?>>Prospect</option>
                        <option value="Hot Prospect" <?= $filterJenisProspek === 'Hot Prospect' ? 'selected' : '' ?>>Hot Prospect</option>
                        <option value="Deal" <?= $filterJenisProspek === 'Deal' ? 'selected' : '' ?>>Deal</option>
                        <option value="Lost Deal" <?= $filterJenisProspek === 'Lost Deal' ? 'selected' : '' ?>>Lost Deal</option>
                    </select>
                    
                    <select name="status" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="In Progress" <?= $filterStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Overdue" <?= $filterStatus === 'Overdue' ? 'selected' : '' ?>>Overdue</option>
                    </select>
                    
                    <?php if ($userRole !== 'sales'): ?>
                    <select name="sales_id" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                        <option value="0">Semua Sales</option>
                        <?php foreach ($salesUsers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $filterSalesId == $s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 180px;">
                    <button type="submit" class="btn btn-primary-custom" style="padding: 6px 16px;"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search) || $filterMonth !== date('Y-m') || $filterSalesId > 0 || !empty($filterJenisProspek) || !empty($filterStatus)): ?>
                        <a href="salesactivity.php" class="btn btn-secondary-custom" style="padding: 6px 16px;"><i class="fas fa-times"></i> Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body-custom">
                <?= showFlash() ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Activity Number</th>
                                <th>Nama PT</th>
                                <th>Badan Usaha</th>
                                <th>Business Segment</th>
                                <th>Jenis Prospek</th>
                                <th>Status</th>
                                <th>Nama PIC</th>
                                <th>Contact Mobile Phone</th>
                                <th>Sales</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($activities) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <a href="detailaktivitas.php?leads_id=<?= $act['id'] ?>" style="color: #2980b9; text-decoration: none; font-weight: 700;">
                                                <?= htmlspecialchars($act['leads_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($act['nama_pt']) ?></td>
                                        <td><?= htmlspecialchars($act['badan_usaha'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($act['bidang_usaha'] ?? '-') ?></td>
                                        <td>
                                            <?php 
                                                $jenisProspek = $act['jenis_prospek'] ?? null;
                                                $badgeClass = '';
                                                switch ($jenisProspek) {
                                                    case 'Suspect': $badgeClass = 'suspect'; break;
                                                    case 'Prospect': $badgeClass = 'prospect'; break;
                                                    case 'Hot Prospect': $badgeClass = 'hot-prospect'; break;
                                                    case 'Deal': $badgeClass = 'deal-prospek'; break;
                                                    case 'Lost Deal': $badgeClass = 'lost-deal'; break;
                                                }
                                            ?>
                                            <?php if ($jenisProspek): ?>
                                                <span class="badge-prospek <?= $badgeClass ?>"><?= htmlspecialchars($jenisProspek) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $statusProspek = $act['status_prospek'] ?? null;
                                                $badgeStatusClass = '';
                                                switch ($statusProspek) {
                                                    case 'In Progress': $badgeStatusClass = 'in-progress'; break;
                                                    case 'Completed': $badgeStatusClass = 'completed'; break;
                                                    case 'Overdue': $badgeStatusClass = 'overdue'; break;
                                                }
                                            ?>
                                            <?php if ($statusProspek): ?>
                                                <span class="badge-status-prospek <?= $badgeStatusClass ?>"><?= htmlspecialchars($statusProspek) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($act['nama_pic'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($act['no_hp_pic'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($act['sales_name'] ?? '-') ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="detailActivity(<?= htmlspecialchars(json_encode($act)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (canDelete('sales_activity')): ?>
                                                    <button class="btn-action delete" onclick="deleteActivity(<?= $act['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data aktivitas
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
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&month=<?= urlencode($filterMonth) ?>&sales_id=<?= $filterSalesId ?>&jenis_prospek=<?= urlencode($filterJenisProspek) ?>&status=<?= urlencode($filterStatus) ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&month=<?= urlencode($filterMonth) ?>&sales_id=<?= $filterSalesId ?>&jenis_prospek=<?= urlencode($filterJenisProspek) ?>&status=<?= urlencode($filterStatus) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&month=<?= urlencode($filterMonth) ?>&sales_id=<?= $filterSalesId ?>&jenis_prospek=<?= urlencode($filterJenisProspek) ?>&status=<?= urlencode($filterStatus) ?>">Next</a></li>
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

    <!-- MODAL TAMBAH ACTIVITY -->
    <div class="modal fade" id="modalActivity" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Tambah Aktivitas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formActivity">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label class="form-label">Activity Number</label>
                            <div class="leads-number-display">
                                <?= generateLeadsNumber($db) ?>
                            </div>
                            <small class="text-muted">Generate otomatis saat disimpan</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Account Management <span class="text-danger">*</span></label>
                            <select name="account_id" id="account_id" class="form-select select2-account" required style="width: 100%;">
                                <option value="">-- Pilih Account (Ketik untuk mencari) --</option>
                                <?php foreach ($accountsList as $acc): ?>
                                    <option value="<?= $acc['id'] ?>" 
                                        data-badan_usaha="<?= htmlspecialchars($acc['badan_usaha'] ?? 'PT') ?>"
                                        data-bidang_usaha="<?= htmlspecialchars($acc['bidang_usaha'] ?? '-') ?>"
                                        data-nama_pic="<?= htmlspecialchars($acc['nama_pic'] ?? '-') ?>"
                                        data-no_hp_pic="<?= htmlspecialchars($acc['no_hp_pic'] ?? '-') ?>"
                                        data-sales_id="<?= $acc['sales_id'] ?? '' ?>">
                                        <?= htmlspecialchars($acc['nama_pt']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Badan Usaha</label>
                                <input type="text" id="badan_usaha" class="form-control" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Segment</label>
                                <input type="text" id="bidang_usaha" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama PIC</label>
                                <input type="text" id="nama_pic" class="form-control" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Mobile Phone</label>
                                <input type="text" id="no_hp_pic" class="form-control" readonly>
                            </div>
                        </div>
                        
                        <?php if ($userRole !== 'sales'): ?>
                        <div class="mb-3">
                            <label class="form-label">Sales <small class="text-muted">(Otomatis dari Account)</small></label>
                            <input type="text" id="sales_name_display" class="form-control" readonly>
                            <input type="hidden" name="sales_id" id="sales_id_hidden" value="">
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="sales_id" value="<?= $userId ?>">
                            <div class="mb-3">
                                <label class="form-label">Sales</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($fullName) ?> (Sales)" readonly>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-save"></i> Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL DETAIL -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-bar" style="color:#ffd700;"></i> Detail Aktivitas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DELETE -->
    <div class="modal fade" id="modalDelete" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus aktivitas ini?</p>
                    <p class="text-muted small">Data yang dihapus tidak dapat dikembalikan!</p>
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteId" value="">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2-account').select2({
                placeholder: '-- Pilih Account (Ketik untuk mencari) --',
                allowClear: true,
                dropdownParent: $('#modalActivity')
            });
            
            $('.select2-account').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                if (selectedOption.val()) {
                    $('#badan_usaha').val(selectedOption.data('badan_usaha'));
                    $('#bidang_usaha').val(selectedOption.data('bidang_usaha'));
                    $('#nama_pic').val(selectedOption.data('nama_pic'));
                    $('#no_hp_pic').val(selectedOption.data('no_hp_pic'));
                    
                    var salesId = selectedOption.data('sales_id');
                    if (salesId) {
                        $('#sales_id_hidden').val(salesId);
                        var salesName = '';
                        <?php foreach ($salesUsers as $s): ?>
                        if (salesId == <?= $s['id'] ?>) {
                            salesName = '<?= htmlspecialchars($s['full_name']) ?>';
                        }
                        <?php endforeach; ?>
                        $('#sales_name_display').val(salesName);
                    } else {
                        $('#sales_id_hidden').val('');
                        $('#sales_name_display').val('Tidak ada Sales terdaftar');
                    }
                } else {
                    $('#badan_usaha').val('');
                    $('#bidang_usaha').val('');
                    $('#nama_pic').val('');
                    $('#no_hp_pic').val('');
                    $('#sales_id_hidden').val('');
                    $('#sales_name_display').val('');
                }
            });
        });

        // CHART JENIS PROSPEK
        const ctxProspek = document.getElementById('chartJenisProspek').getContext('2d');
        new Chart(ctxProspek, {
            type: 'doughnut',
            data: {
                labels: [
                    'Suspect (<?= $prospekCounts['Suspect'] ?>)',
                    'Prospect (<?= $prospekCounts['Prospect'] ?>)',
                    'Hot Prospect (<?= $prospekCounts['Hot Prospect'] ?>)',
                    'Deal (<?= $prospekCounts['Deal'] ?>)',
                    'Lost Deal (<?= $prospekCounts['Lost Deal'] ?>)'
                ],
                datasets: [{
                    data: [
                        <?= $prospekCounts['Suspect'] ?>,
                        <?= $prospekCounts['Prospect'] ?>,
                        <?= $prospekCounts['Hot Prospect'] ?>,
                        <?= $prospekCounts['Deal'] ?>,
                        <?= $prospekCounts['Lost Deal'] ?>
                    ],
                    backgroundColor: ['#3498db', '#9b59b6', '#f39c12', '#27ae60', '#e74c3c'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 15, font: { family: 'Inter', size: 12, weight: '600' } }
                    }
                }
            }
        });

        // CHART STATUS
        const ctxStatus = document.getElementById('chartStatus').getContext('2d');
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: [
                    'In Progress (<?= $statusCounts['In Progress'] ?>)',
                    'Completed (<?= $statusCounts['Completed'] ?>)',
                    'Overdue (<?= $statusCounts['Overdue'] ?>)'
                ],
                datasets: [{
                    data: [
                        <?= $statusCounts['In Progress'] ?>,
                        <?= $statusCounts['Completed'] ?>,
                        <?= $statusCounts['Overdue'] ?>
                    ],
                    backgroundColor: ['#2980b9', '#27ae60', '#c0392b'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 15, font: { family: 'Inter', size: 12, weight: '600' } }
                    }
                }
            }
        });

        function detailActivity(data) {
            var html = `
                <div class="detail-item"><div class="detail-label">Leads Number</div><div class="detail-value"><strong>${data.leads_number}</strong></div></div>
                <div class="detail-item"><div class="detail-label">Jenis Prospek</div><div class="detail-value">${data.jenis_prospek || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value">${data.status_prospek || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Nama PT</div><div class="detail-value">${data.nama_pt || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Badan Usaha</div><div class="detail-value">${data.badan_usaha || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Business Segment</div><div class="detail-value">${data.bidang_usaha || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Nama PIC</div><div class="detail-value">${data.nama_pic || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Contact Mobile</div><div class="detail-value">${data.no_hp_pic || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Email PIC</div><div class="detail-value">${data.email_pic || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Sales</div><div class="detail-value">${data.sales_name || '-'}</div></div>
                <div class="detail-item"><div class="detail-label">Tanggal Dibuat</div><div class="detail-value">${new Date(data.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div></div>
            `;
            document.getElementById('detailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalDetail'));
            modal.show();
        }

        function deleteActivity(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
    </script>
</body>
</html>