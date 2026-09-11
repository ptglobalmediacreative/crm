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
    // activity_details menggunakan tr_number.
    // Tabel Transaction Request menggunakan trf_number.
    $tableColumns = [
        'detail_transaction_requests' => 'trf_number',
        'transaction_requests' => 'trf_number',
        'tr_additional_costs' => 'trf_number',
        'tr_additional_cost_items' => 'trf_number',
        'tr_approval_history' => 'trf_number',
        'tr_cost_calculations' => 'trf_number',
        'tr_detail_units' => 'trf_number',
        'tr_mediators' => 'trf_number',
        'tr_product_supports' => 'trf_number',
        'tr_term_of_payments' => 'trf_number'
    ];

    foreach ($tableColumns as $table => $column) {
        $stmt = $db->prepare(
            "DELETE FROM `{$table}` WHERE `{$column}` = ?"
        );
        $stmt->execute([$trNumber]);
    }
}

// ============================================
// RENUMBER SEMUA TR SETELAH ADA TR YANG DIHAPUS
// Format: 0001/GET-TR/JKT/IX/2026
// Nomor diurutkan ulang per bulan/periode berdasarkan
// created_at paling awal.
// ============================================
function renumberAllTransactionRequests($db, $periods = null) {
    // Jika period diberikan, hanya periode tersebut yang dirapikan.
    // Ini mencegah penghapusan satu TR di bulan tertentu mengubah nomor bulan lain.
    if ($periods !== null) {
        $periods = array_values(array_unique(array_filter(array_map('strval', (array)$periods))));
        foreach ($periods as $period) {
            if (!preg_match('/^[IVXLCDM]+\\/\\d{4}$/', $period)) {
                throw new RuntimeException('Periode TR tidak valid: ' . $period);
            }
        }
        if (!$periods) return 0;
    }

    $where = "ad.tr_number IS NOT NULL AND TRIM(ad.tr_number) <> ''";
    $params = [];

    if ($periods !== null) {
        $placeholders = implode(',', array_fill(0, count($periods), '?'));
        $where .= " AND SUBSTRING_INDEX(ad.tr_number, '/GET-TR/JKT/', -1) IN ($placeholders)";
        $params = $periods;
    }

    $stmt = $db->prepare("\n        SELECT\n            ad.tr_number AS old_tr,\n            SUBSTRING_INDEX(ad.tr_number, '/GET-TR/JKT/', -1) AS period,\n            MIN(ad.created_at) AS first_created_at\n        FROM activity_details ad\n        WHERE {$where}\n        GROUP BY ad.tr_number\n        ORDER BY period ASC, first_created_at ASC, old_tr ASC\n    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    $mapping = [];
    $sequenceByPeriod = [];

    foreach ($rows as $row) {
        $period = $row['period'];
        if (!preg_match('/^[IVXLCDM]+\\/\\d{4}$/', $period)) {
            throw new RuntimeException('Ditemukan TR dengan periode tidak valid: ' . $period);
        }

        if (!isset($sequenceByPeriod[$period])) {
            $sequenceByPeriod[$period] = 1;
        }

        $newTr = str_pad((string)$sequenceByPeriod[$period], 4, '0', STR_PAD_LEFT)
            . '/GET-TR/JKT/' . $period;

        if (strlen($newTr) > 50) {
            throw new RuntimeException('TR baru melebihi batas VARCHAR(50): ' . $newTr);
        }

        $mapping[$row['old_tr']] = $newTr;
        $sequenceByPeriod[$period]++;
    }

    $tableColumns = [
        'activity_details' => 'tr_number',
        'detail_transaction_requests' => 'trf_number',
        'transaction_requests' => 'trf_number',
        'tr_additional_costs' => 'trf_number',
        'tr_additional_cost_items' => 'trf_number',
        'tr_approval_history' => 'trf_number',
        'tr_cost_calculations' => 'trf_number',
        'tr_detail_units' => 'trf_number',
        'tr_mediators' => 'trf_number',
        'tr_product_supports' => 'trf_number',
        'tr_term_of_payments' => 'trf_number'
    ];

    // Temporary key sengaja dibuat < 50 karakter.
    // Format: __TRTMP_ + 16 hex + _ + 24 hex = 49 karakter.
    $token = '__TRTMP_' . bin2hex(random_bytes(8)) . '_';
    if (strlen($token) + 24 > 50) {
        throw new RuntimeException('Temporary TR key melebihi batas database.');
    }

    $temporaryMap = [];

    // Tahap 1: nomor lama -> nomor sementara.
    foreach ($mapping as $oldTr => $newTr) {
        $temporaryTr = $token . substr(hash('sha256', $oldTr), 0, 24);
        if (strlen($temporaryTr) > 50) {
            throw new RuntimeException('Temporary TR value melebihi VARCHAR(50).');
        }
        $temporaryMap[$oldTr] = $temporaryTr;

        foreach ($tableColumns as $table => $column) {
            $stmt = $db->prepare(
                "UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?"
            );
            $stmt->execute([$temporaryTr, $oldTr]);
        }
    }

    // Tahap 2: nomor sementara -> nomor final.
    foreach ($mapping as $oldTr => $newTr) {
        $temporaryTr = $temporaryMap[$oldTr];

        foreach ($tableColumns as $table => $column) {
            $stmt = $db->prepare(
                "UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?"
            );
            $stmt->execute([$newTr, $temporaryTr]);
        }
    }

    // SAFETY CHECK: tidak boleh ada temporary marker yang tertinggal.
    // Jika ada, lempar exception agar transaction di caller melakukan rollback.
    foreach ($tableColumns as $table => $column) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE ?"
        );
        $stmt->execute([$token . '%']);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException(
                "Renumber TR gagal: temporary marker masih tersisa di {$table}. Perubahan dibatalkan."
            );
        }
    }

    // Audit mapping bersifat opsional.
    try {
        if ($periods === null) {
            $db->exec("DELETE FROM tr_renumber_map");
        }

        $insertMap = $db->prepare(
            "INSERT INTO tr_renumber_map (old_tr, new_tr) VALUES (?, ?)"
        );
        foreach ($mapping as $oldTr => $newTr) {
            $insertMap->execute([$oldTr, $newTr]);
        }
    } catch (PDOException $e) {
        // Tabel audit tidak wajib tersedia.
    }

    return count($mapping);
}

// ============================================
// HAPUS SALES ACTIVITY + DETAIL + TR TERKAIT
// LALU RENUMBER TR SECARA OTOMATIS
// ============================================
function deleteSalesActivityAndRelatedData($db, $salesActivityId) {
    $db->beginTransaction();

    try {
        // Pastikan activity ada dan lock row selama proses.
        $stmt = $db->prepare("
            SELECT id
            FROM sales_activities
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$salesActivityId]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Sales Activity tidak ditemukan.');
        }

        // Simpan daftar TR sebelum detail dihapus.
        $stmt = $db->prepare("
            SELECT DISTINCT tr_number
            FROM activity_details
            WHERE sales_activity_id = ?
              AND tr_number IS NOT NULL
              AND TRIM(tr_number) <> ''
        ");
        $stmt->execute([$salesActivityId]);
        $trNumbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Hitung detail untuk feedback.
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM activity_details
            WHERE sales_activity_id = ?
        ");
        $stmt->execute([$salesActivityId]);
        $detailCount = (int)$stmt->fetchColumn();

        // 1. Hapus detail.
        $stmt = $db->prepare("
            DELETE FROM activity_details
            WHERE sales_activity_id = ?
        ");
        $stmt->execute([$salesActivityId]);

        // 2. Hapus activity induk.
        $stmt = $db->prepare("
            DELETE FROM sales_activities
            WHERE id = ?
        ");
        $stmt->execute([$salesActivityId]);

        // 3. Hapus TR yang sudah tidak digunakan activity lain.
        foreach ($trNumbers as $trNumber) {
            $check = $db->prepare("
                SELECT COUNT(*)
                FROM activity_details
                WHERE tr_number = ?
            ");
            $check->execute([$trNumber]);

            if ((int)$check->fetchColumn() === 0) {
                deleteTransactionRequestData($db, $trNumber);
            }
        }

        // 4. Renumber hanya periode TR yang terdampak.
        $trPeriods = [];
        foreach ($trNumbers as $trNumber) {
            if (preg_match('#^[0-9]{4}/GET-TR/JKT/([IVXLCDM]+/[0-9]{4})$#', trim($trNumber), $m)) {
                $trPeriods[] = $m[1];
            }
        }
        $trPeriods = array_values(array_unique($trPeriods));

        if ($trPeriods) {
            renumberAllTransactionRequests($db, $trPeriods);
        }

        // 5. Renumber Activity Number.
        renumberAllActivityNumbers($db);

        // Semua perubahan baru dipermanenkan di sini.
        $db->commit();

        return [
            'success' => true,
            'tr_count' => count($trNumbers),
            'detail_count' => $detailCount
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
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
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
$totalPages = max(1, (int)ceil($totalData / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales Activity - PT Ganda Elang Tangguh</title>
<link rel="icon" type="image/webp" href="images/favicon.webp">
<link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
.app{min-height:100vh}
.topbar{height:72px;border-bottom:1px solid var(--line);background:rgba(5,9,20,.88);backdrop-filter:blur(18px);display:flex;align-items:center;padding:0 26px;gap:24px;position:sticky;top:0;z-index:50}
.brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px}.brand img{width:38px;height:38px;object-fit:contain}.brand strong{font-size:17px;letter-spacing:-.4px}.brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px}
.top-actions{display:flex;align-items:center;gap:10px;margin-left:auto}.icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}.notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}.avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5)}
.shell{display:flex;min-height:calc(100vh - 72px)}
.rail{width:245px;position:fixed;top:72px;bottom:0;left:0;background:rgba(5,10,21,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px;gap:6px;z-index:40;overflow-y:auto}.rail::-webkit-scrollbar{width:4px}.rail::-webkit-scrollbar-thumb{background:rgba(96,165,250,.25);border-radius:10px}.rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}.rail a{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex;align-items:center;gap:12px;text-decoration:none;transition:.2s;padding:0 13px;font-size:11px;font-weight:600}.rail a i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.rail a:hover,.rail a.active{color:#fff;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06));box-shadow:inset 2px 0 0 #60a5fa}.rail a.active i{color:#60a5fa}.rail .spacer{flex:1;min-height:20px}.rail-user{margin:8px 4px 4px;padding:12px;border:1px solid rgba(148,163,184,.10);background:rgba(10,18,34,.7);border-radius:13px;display:flex;align-items:center;gap:10px}.rail-user .mini-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}.rail-user strong{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rail-user span{display:block;font-size:8px;color:#66758f;margin-top:2px}.rail .logout-link{color:#8794aa}.rail .logout-link:hover{color:#fb7185;background:rgba(251,113,133,.08);box-shadow:none}
.content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px}
.hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px}.eyebrow{font-size:10px;color:#6f80a0;text-transform:uppercase;letter-spacing:1.6px;font-weight:700;margin-bottom:7px}.hero h1{font-size:26px;letter-spacing:-1px;font-weight:800;margin:0}.hero p{font-size:12px;color:var(--muted);margin-top:7px}.filters{display:flex;gap:8px;align-items:center}.filter{height:38px;border:1px solid var(--line);background:rgba(10,17,33,.85);color:#cdd7e7;border-radius:11px;padding:0 12px;font-size:11px;outline:none}.filter option{background:#0b1222}.btn-add{height:38px;border:0;border-radius:11px;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:11px;font-weight:700;padding:0 15px;box-shadow:0 0 24px rgba(59,130,246,.25);display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.btn-add:hover{color:#fff;transform:translateY(-1px);box-shadow:0 0 28px rgba(59,130,246,.35)}
.header-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn-export{height:38px;border:1px solid rgba(52,211,153,.25);border-radius:11px;background:rgba(52,211,153,.08);color:#6ee7b7;font-size:11px;font-weight:700;padding:0 14px;display:inline-flex;align-items:center;text-decoration:none}.btn-export:hover{color:#a7f3d0;background:rgba(52,211,153,.14);transform:translateY(-1px)}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}.chart-card{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s}.chart-card:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}.chart-card h6{height:58px;margin:0;padding:0 18px;display:flex;align-items:center;font-size:13px;font-weight:700;color:#f7f9ff;border-bottom:1px solid rgba(148,163,184,.10)}.chart-card h6 i{color:#60a5fa!important;margin-right:9px}.chart-wrapper{height:270px;width:100%;position:relative;padding:10px 16px 14px}
.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s}.card-custom:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}.card-custom .card-header-custom{padding:15px 18px;border-bottom:1px solid rgba(148,163,184,.10);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}.card-custom .card-header-custom h6{font-weight:700;color:#f7f9ff;margin:0;font-size:13px}.card-custom .card-header-custom h6 i{color:#60a5fa;margin-right:8px}.card-custom .card-body-custom{padding:0;overflow-x:auto}
.table-responsive{border-radius:0}.table-custom{margin-bottom:0;font-size:10px;color:#cbd5e1;--bs-table-bg:transparent;--bs-table-color:#cbd5e1}.table-custom th{font-weight:700;font-size:9px;text-transform:uppercase;letter-spacing:.45px;color:#66758f;border-bottom:1px solid rgba(148,163,184,.10);padding:13px 14px;background:rgba(5,12,25,.48);white-space:nowrap}.table-custom td{padding:13px 14px;vertical-align:middle;border-bottom:1px solid rgba(148,163,184,.07);color:#cbd5e1;background:transparent}.table-custom tbody tr{transition:.15s}.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)}.table-custom tr:last-child td{border-bottom:none}.table-custom a{color:#60a5fa!important;text-decoration:none;font-weight:700}.table-custom a:hover{color:#93c5fd!important}.text-muted{color:#64748b!important}
.badge-prospek,.badge-status-prospek{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid transparent}.badge-prospek.suspect{background:rgba(96,165,250,.10);color:#93c5fd;border-color:rgba(96,165,250,.15)}.badge-prospek.prospect{background:rgba(167,139,250,.10);color:#c4b5fd;border-color:rgba(167,139,250,.15)}.badge-prospek.hot-prospect{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}.badge-prospek.deal-prospek{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}.badge-prospek.lost-deal{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}.badge-status-prospek.in-progress{background:rgba(34,211,238,.10);color:#67e8f9;border-color:rgba(34,211,238,.15)}.badge-status-prospek.completed{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}.badge-status-prospek.overdue{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}
.btn-action{width:29px;height:29px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;transition:.2s;font-size:10px;cursor:pointer}.btn-action:hover{transform:translateY(-1px) scale(1.04)}.btn-action.detail{background:rgba(96,165,250,.10);color:#60a5fa;border-color:rgba(96,165,250,.12)}.btn-action.detail:hover{background:rgba(96,165,250,.18)}.btn-action.delete{background:rgba(251,113,133,.09);color:#fb7185;border-color:rgba(251,113,133,.12)}.btn-action.delete:hover{background:rgba(251,113,133,.16)}
.card-footer{background:rgba(5,12,25,.35)!important;border-top:1px solid rgba(148,163,184,.08)!important}.pagination{gap:4px}.pagination .page-link{background:#0a1427;border:1px solid rgba(148,163,184,.12);color:#8492aa;border-radius:8px!important;font-size:9px;padding:6px 9px}.pagination .page-link:hover{background:#10203a;color:#fff;border-color:rgba(96,165,250,.25)}.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 0 15px rgba(59,130,246,.22)}
.form-label{font-weight:600;font-size:11px;color:#aebbd0}.form-control,.form-select{border-radius:9px;padding:9px 11px;border:1px solid rgba(148,163,184,.16);background:#0a1427;color:#dbe5f5;font-size:11px;transition:.2s}.form-control::placeholder{color:#52627d}.form-control:focus,.form-select:focus{border-color:rgba(96,165,250,.55);box-shadow:0 0 0 3px rgba(59,130,246,.10);background:#0b172d;color:#fff}.form-control[readonly]{background:#0a1325;color:#7f8da5;cursor:not-allowed}.form-select option{background:#0b1222;color:#dbe5f5}.btn-primary-custom{background:linear-gradient(135deg,#3b82f6,#6366f1);border:0;border-radius:9px;padding:9px 15px;font-weight:700;font-size:11px;transition:.2s;color:#fff}.btn-primary-custom:hover{background:linear-gradient(135deg,#4f8df7,#6d70f3);transform:translateY(-1px);box-shadow:0 8px 22px rgba(59,130,246,.2);color:#fff}.btn-primary-custom i{margin-right:6px}.btn-secondary-custom{background:#111d31;border:1px solid rgba(148,163,184,.13);border-radius:9px;padding:9px 15px;font-weight:600;font-size:11px;color:#8f9db4;transition:.2s}.btn-secondary-custom:hover{background:#17253d;color:#fff;border-color:rgba(148,163,184,.22)}.btn-danger{background:#dc3545!important;border:0;border-radius:9px;font-size:11px;font-weight:700}.alert{border-radius:10px;border:1px solid rgba(96,165,250,.14);padding:10px 13px;font-size:11px;background:#0c1830;color:#cbd5e1}.detail-item{display:flex;padding:10px 0;border-bottom:1px solid rgba(148,163,184,.08)}.detail-item:last-child{border-bottom:none}.detail-item .detail-label{font-weight:600;color:#6f7f98;width:160px;flex-shrink:0;font-size:10px}.detail-item .detail-value{color:#dbe5f5;font-size:10px;word-break:break-word}.leads-number-display{background:rgba(59,130,246,.08);border:1px solid rgba(96,165,250,.14);padding:10px 13px;border-radius:9px;font-weight:700;color:#60a5fa;text-align:center;font-size:14px;letter-spacing:.5px}
.modal-content{background:linear-gradient(145deg,#0c172b,#07101f);border:1px solid var(--line);border-radius:15px;color:#dbe5f5;box-shadow:0 24px 70px rgba(0,0,0,.45)}.modal-header{border-bottom:1px solid rgba(148,163,184,.10);padding:16px 20px}.modal-header .modal-title{font-weight:700;font-size:14px;color:#f7f9ff}.modal-header .modal-title i{color:#60a5fa!important;margin-right:8px}.modal-footer{border-top:1px solid rgba(148,163,184,.10);padding:12px 20px}.modal-body{padding:18px 20px}.btn-close{filter:invert(1) grayscale(1);opacity:.55}.btn-close:hover{opacity:1}.select2-container--default .select2-selection--single{height:40px;border-radius:9px;border:1px solid rgba(148,163,184,.16);background:#0a1427;color:#dbe5f5;padding:6px 10px;font-size:11px}.select2-container--default .select2-selection--single .select2-selection__rendered{color:#dbe5f5;line-height:26px}.select2-container--default .select2-selection--single .select2-selection__arrow{height:38px}.select2-container--default .select2-selection--single:focus{border-color:rgba(96,165,250,.55)}.select2-dropdown{background:#0b1426;border:1px solid rgba(148,163,184,.18);color:#dbe5f5}.select2-container--default .select2-search--dropdown .select2-search__field{background:#07101f;border:1px solid rgba(148,163,184,.16);color:#fff}.select2-container--default .select2-results__option{font-size:11px}.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#2563eb}.select2-container--default .select2-results__option[aria-selected=true]{background:#10203a;color:#fff}
.footer-text,.footer{text-align:center;color:#44536c;font-size:9px;margin-top:20px}.footer-text a{color:#6b7a94;text-decoration:none}.footer-text a:hover{color:#60a5fa}.mobile-toggle{display:none}
@media(max-width:1050px){.chart-grid{grid-template-columns:1fr}.brand{min-width:190px}.content{padding:22px 20px 45px}}
@media(max-width:800px){.rail.open{display:flex;position:fixed;top:64px;bottom:0;left:0;width:245px}.topbar{padding:0 16px}.brand{min-width:0}.brand div{display:none}.mobile-toggle{display:inline-flex;width:36px;height:36px;border-radius:9px;border:1px solid var(--line);background:#0a1427;color:#60a5fa;align-items:center;justify-content:center;margin-right:8px}.content{margin-left:0;width:100%;padding:20px 14px 40px}.rail{display:none}.hero-row{align-items:flex-start;flex-direction:column}.filters{width:100%;flex-wrap:wrap}.filter{flex:1;min-width:130px}.header-actions{width:100%}.header-actions a,.header-actions button{flex:1}.card-custom .card-header-custom{align-items:flex-start}.card-custom .card-header-custom form{width:100%}.table-custom{font-size:9px}.table-custom th,.table-custom td{padding:10px 9px}}
@media(max-width:480px){.topbar{height:64px}.shell{min-height:calc(100vh - 64px)}.content{padding:18px 10px 35px}.hero h1{font-size:22px}.hero p{font-size:10px}.chart-wrapper{height:235px;padding:8px}.chart-card h6{height:52px;padding:0 14px}.card-custom .card-header-custom{padding:13px}.form-control,.form-select{font-size:10px}.detail-item{flex-direction:column}.detail-item .detail-label{width:100%;margin-bottom:3px}.topbar .mobile-toggle{display:inline-flex}}
</style>
</head>
<body>
<div class="app">
<header class="topbar">
    <button class="mobile-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu"><i class="fas fa-bars"></i></button>
    <a class="brand" href="dashboard.php"><img src="images/logo.webp" alt="GET"><div><strong>PT Ganda Elang Tangguh</strong><small>Customer Relationship Management</small></div></a>
    <div class="top-actions"><div class="avatar"><?= strtoupper(substr($fullName,0,1)) ?></div></div>
</header>
<div class="shell">
<aside class="rail" id="sidebar">
    <div class="rail-label">Main Menu</div>
    <a href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <?php if(in_array('sales_activity',$menuNames)): ?><a class="active" href="salesactivity.php"><i class="fas fa-chart-line"></i><span>Sales Activity</span></a><?php endif; ?>
    <?php if(in_array('account_management',$menuNames)): ?><a href="account_management.php"><i class="fas fa-building"></i><span>Account Management</span></a><?php endif; ?>
    <?php if(in_array('transaction_request',$menuNames)): ?><a href="transactionrequest.php"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a><?php endif; ?>
    <?php if(in_array('produk',$menuNames)): ?><a href="produk.php"><i class="fas fa-box"></i><span>Produk</span></a><?php endif; ?>
    <?php if(in_array('delivery_order',$menuNames)): ?><a href="deliveryinstruction.php"><i class="fas fa-truck-moving"></i><span>Delivery Order</span></a><?php endif; ?>
    <div class="rail-label">Administration</div>
    <?php if(in_array('data_user',$menuNames)): ?><a href="data_user.php"><i class="fas fa-users"></i><span>Data User</span></a><?php endif; ?>
    <?php if(in_array('data_sales',$menuNames) && file_exists('data_sales.php')): ?><a href="data_sales.php"><i class="fas fa-user-tie"></i><span>Data Sales</span></a><?php endif; ?>
    <div class="spacer"></div>
    <div class="rail-user"><div class="mini-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div><div><strong><?= htmlspecialchars($fullName) ?></strong><span><?= htmlspecialchars(getRoleLabel($role)) ?></span></div></div>
    <a class="logout-link" href="logout.php"><i class="fas fa-power-off"></i><span>Logout</span></a>
</aside>
<main class="content">
<section class="hero-row">
    <div class="hero">
        <div class="eyebrow">PT Ganda Elang Tangguh · Customer Relationship Management</div>
        <h1>Sales Activity</h1>
        <p>Monitor customer activities, prospect stages and sales performance.</p>
    </div>
    <div class="header-actions">
        <a href="salesactivity.php?export=excel&month=<?= urlencode($filterMonth) ?>&sales_id=<?= $filterSalesId ?>&jenis_prospek=<?= urlencode($filterJenisProspek) ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>" class="btn-export"><i class="fas fa-file-excel me-2"></i>Export Excel</a>
        <?php if (canAdd('sales_activity')): ?><button class="btn-add" data-bs-toggle="modal" data-bs-target="#modalActivity"><i class="fas fa-plus me-2"></i>Tambah Aktivitas</button><?php endif; ?>
    </div>
</section>
<div class="chart-grid">
    <div class="chart-card"><h6><i class="fas fa-chart-pie"></i> Rekap Jenis Prospek</h6><div class="chart-wrapper"><canvas id="chartJenisProspek"></canvas></div></div>
    <div class="chart-card"><h6><i class="fas fa-tasks"></i> Rekap Status</h6><div class="chart-wrapper"><canvas id="chartStatus"></canvas></div></div>
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

    
<div class="footer-text">&copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> · Heavy Equipment Dealer CRM</div>
</main>
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
</body>
</html>