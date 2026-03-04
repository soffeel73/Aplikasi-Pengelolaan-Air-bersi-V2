<?php
/**
 * API Tagihan - Smart Air Desa
 * Handles billing data with tunggakan management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once 'util/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {
    case 'GET':
        if ($action === 'detail' && $id) {
            getTagihanDetail($pdo, $id);
        }
        else {
            getTagihanList($pdo);
        }
        break;

    case 'PUT':
        if ($action === 'tunggakan' && $id) {
            updateTunggakan($pdo, $id);
        }
        elseif ($action === 'cicilan' && $id) {
            updateCicilan($pdo, $id);
        }
        elseif ($action === 'add_tunggakan' && $id) {
            addTunggakan($pdo, $id);
        }
        elseif ($action === 'status' && $id) {
            updateStatus($pdo, $id);
        }
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Action and ID diperlukan']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

/**
 * Get list of billing data from input_meters joined with tagihans
 */
function getTagihanList($pdo)
{
    try {
        $year = isset($_GET['year']) ? intval($_GET['year']) : null;
        $month = isset($_GET['month']) ? intval($_GET['month']) : null;
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $search = isset($_GET['search']) ? $_GET['search'] : '';

        // Pagination
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;
        $exportAll = isset($_GET['export']) && $_GET['export'] === 'all';

        // Base filter clauses
        $whereSql = " WHERE 1=1";
        $params = [];

        if ($year) {
            $whereSql .= " AND m.period_year = :year";
            $params[':year'] = $year;
        }
        if ($month) {
            $whereSql .= " AND m.period_month = :month";
            $params[':month'] = $month;
        }
        if ($status && $status !== 'all') {
            $whereSql .= " AND COALESCE(t.status, 'unpaid') = :status";
            $params[':status'] = $status;
        }
        if ($search) {
            $whereSql .= " AND (p.customer_id LIKE :search OR p.name LIKE :search2)";
            $params[':search'] = "%$search%";
            $params[':search2'] = "%$search%";
        }

        // ========================================
        // 1. GET TOTAL COUNT (For Pagination)
        // ========================================
        $countSql = "SELECT COUNT(*) FROM input_meters m JOIN pelanggans p ON m.pelanggan_id = p.id LEFT JOIN tagihans t ON t.input_meter_id = m.id" . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = intval($countStmt->fetchColumn());
        $totalPages = $exportAll ? 1 : ceil($totalItems / $limit);

        // ========================================
        // 2. GET AGGREGATE STATS (For filtered set)
        // ========================================
        $statsSql = "SELECT 
                        COUNT(*) as count,
                        SUM(m.total_biaya) as total_pendapatan_kotor,
                        SUM(CASE WHEN COALESCE(t.status, 'unpaid') = 'unpaid' THEN (m.total_biaya + COALESCE(t.tunggakan, 0)) ELSE 0 END) as total_tunggakan
                     FROM input_meters m 
                     JOIN pelanggans p ON m.pelanggan_id = p.id 
                     LEFT JOIN tagihans t ON t.input_meter_id = m.id" . $whereSql;

        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute($params);
        $statsData = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $totalCount = intval($statsData['count']);
        $totalAdmin = $totalCount * 2000;
        $totalPendapatan = floatval($statsData['total_pendapatan_kotor']); // This includes admin
        $totalBiayaAir = $totalPendapatan - $totalAdmin;
        $totalTunggakan = floatval($statsData['total_tunggakan']);

        // ========================================
        // 3. GET DATA (paginated or all)
        // ========================================
        $sql = "SELECT 
                    m.id as input_meter_id,
                    m.pelanggan_id,
                    m.period_year,
                    m.period_month,
                    m.meter_awal,
                    m.meter_akhir,
                    m.jumlah_pakai,
                    m.total_biaya as biaya_pemakaian,
                    p.customer_id,
                    p.name as pelanggan_name,
                    p.address as pelanggan_address,
                    p.phone as pelanggan_phone,
                    COALESCE(t.tunggakan, 0) as tunggakan_manual,
                    COALESCE(t.status, 'unpaid') as status,
                    t.id as tagihan_id,
                    t.paid_at
                FROM input_meters m
                JOIN pelanggans p ON m.pelanggan_id = p.id
                LEFT JOIN tagihans t ON t.input_meter_id = m.id" .
            $whereSql .
            " ORDER BY m.period_year DESC, m.period_month DESC, p.address ASC";

        if (!$exportAll) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        if (!$exportAll) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Post-processing for each row (Sync/Arrears)
        foreach ($data as &$row) {
            $accumulated = calculateAccumulatedArrears(
                $pdo,
                $row['pelanggan_id'],
                $row['period_year'],
                $row['period_month']
            );

            $autoCalculatedTunggakan = $accumulated['total'];

            if ($row['tagihan_id'] && floatval($row['tunggakan_manual']) > 0) {
                $row['tunggakan'] = floatval($row['tunggakan_manual']);
            }
            else {
                $row['tunggakan'] = $autoCalculatedTunggakan;
            }

            $row['total_tagihan'] = floatval($row['biaya_pemakaian']) + $row['tunggakan'];

            if (!$row['tagihan_id']) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO tagihans (
                        pelanggan_id, input_meter_id, month, 
                        initial_meter, final_meter, usage_amount, 
                        amount, tunggakan, total_tagihan, 
                        status, created_at, updated_at
                    ) VALUES (
                        :pelanggan_id, :input_meter_id, :month,
                        :initial, :final, :usage,
                        :amount, :tunggakan, :total,
                        'unpaid', NOW(), NOW()
                    )
                ");

                $monthStr = $row['period_year'] . '-' . str_pad($row['period_month'], 2, '0', STR_PAD_LEFT);
                $insertStmt->execute([
                    ':pelanggan_id' => $row['pelanggan_id'],
                    ':input_meter_id' => $row['input_meter_id'],
                    ':month' => $monthStr,
                    ':initial' => $row['meter_awal'],
                    ':final' => $row['meter_akhir'],
                    ':usage' => $row['jumlah_pakai'],
                    ':amount' => $row['biaya_pemakaian'],
                    ':tunggakan' => $row['tunggakan'],
                    ':total' => $row['total_tagihan']
                ]);
                $row['tagihan_id'] = $pdo->lastInsertId();
            }
        }

        echo json_encode([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'limit' => $limit
            ],
            'stats' => [
                'total_biaya_air' => $totalBiayaAir,
                'total_admin' => $totalAdmin,
                'total_pendapatan' => $totalPendapatan,
                'total_tunggakan' => $totalTunggakan
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data tagihan: ' . $e->getMessage()]);
    }
}

/**
 * Get single tagihan detail
 */
function getTagihanDetail($pdo, $inputMeterId)
{
    try {
        $sql = "SELECT 
                    m.id as input_meter_id,
                    m.pelanggan_id,
                    m.period_year,
                    m.period_month,
                    m.jumlah_pakai,
                    m.total_biaya as biaya_pemakaian,
                    p.customer_id,
                    p.name as pelanggan_name,
                    p.address as pelanggan_address,
                    COALESCE(t.tunggakan, 0) as tunggakan,
                    COALESCE(t.status, 'unpaid') as status,
                    t.id as tagihan_id
                FROM input_meters m
                JOIN pelanggans p ON m.pelanggan_id = p.id
                LEFT JOIN tagihans t ON t.input_meter_id = m.id
                WHERE m.id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $inputMeterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['total_tagihan'] = floatval($row['biaya_pemakaian']) + floatval($row['tunggakan']);
            echo json_encode(['success' => true, 'data' => $row]);
        }
        else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
        }
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data']);
    }
}

/**
 * Update tunggakan for an input_meter record
 */
function updateTunggakan($pdo, $inputMeterId)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['tunggakan'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Tunggakan wajib diisi']);
            return;
        }

        $tunggakan = floatval($input['tunggakan']);

        // Get input_meter data
        $stmt = $pdo->prepare("SELECT m.*, p.customer_id FROM input_meters m JOIN pelanggans p ON m.pelanggan_id = p.id WHERE m.id = :id");
        $stmt->execute([':id' => $inputMeterId]);
        $meter = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$meter) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data input meter tidak ditemukan']);
            return;
        }

        $totalTagihan = floatval($meter['total_biaya']) + $tunggakan;

        // Check if tagihan record exists
        $stmt = $pdo->prepare("SELECT id FROM tagihans WHERE input_meter_id = :id");
        $stmt->execute([':id' => $inputMeterId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing record
            $stmt = $pdo->prepare("UPDATE tagihans SET tunggakan = :tunggakan, total_tagihan = :total, amount = :amount, updated_at = NOW() WHERE input_meter_id = :id");
            $stmt->execute([
                ':tunggakan' => $tunggakan,
                ':total' => $totalTagihan,
                ':amount' => $meter['total_biaya'],
                ':id' => $inputMeterId
            ]);
        }
        else {
            // Create new tagihan record
            $stmt = $pdo->prepare("INSERT INTO tagihans (pelanggan_id, input_meter_id, month, initial_meter, final_meter, usage_amount, amount, tunggakan, total_tagihan, status, created_at, updated_at) VALUES (:pelanggan_id, :input_meter_id, :month, :initial, :final, :usage, :amount, :tunggakan, :total, 'unpaid', NOW(), NOW())");
            $stmt->execute([
                ':pelanggan_id' => $meter['pelanggan_id'],
                ':input_meter_id' => $inputMeterId,
                ':month' => $meter['period_year'] . '-' . str_pad($meter['period_month'], 2, '0', STR_PAD_LEFT),
                ':initial' => $meter['meter_awal'],
                ':final' => $meter['meter_akhir'],
                ':usage' => $meter['jumlah_pakai'],
                ':amount' => $meter['total_biaya'],
                ':tunggakan' => $tunggakan,
                ':total' => $totalTagihan
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Tunggakan berhasil ditambahkan, Total Tagihan telah diperbarui.',
            'data' => [
                'input_meter_id' => $inputMeterId,
                'tunggakan' => $tunggakan,
                'total_tagihan' => $totalTagihan
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan tunggakan: ' . $e->getMessage()]);
    }
}

/**
 * Add manual tunggakan (arrears) to a billing record
 * This allows admin to add extra arrears manually
 */
function addTunggakan($pdo, $inputMeterId)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['tunggakan_tambahan']) || floatval($input['tunggakan_tambahan']) <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nominal tunggakan harus lebih dari 0']);
            return;
        }

        $tunggakanTambahan = floatval($input['tunggakan_tambahan']);
        $keterangan = isset($input['keterangan']) ? trim($input['keterangan']) : 'Tunggakan manual';

        // Get current tagihan
        $stmt = $pdo->prepare("
            SELECT t.*, m.pelanggan_id, m.period_month, m.period_year, m.total_biaya
            FROM tagihans t 
            JOIN input_meters m ON m.id = t.input_meter_id 
            WHERE t.input_meter_id = :id
        ");
        $stmt->execute([':id' => $inputMeterId]);
        $tagihan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tagihan) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tagihan tidak ditemukan']);
            return;
        }

        // Calculate new values
        $currentTunggakan = floatval($tagihan['tunggakan'] ?? 0);
        $newTunggakan = $currentTunggakan + $tunggakanTambahan;
        $biayaPemakaian = floatval($tagihan['total_biaya'] ?? $tagihan['amount'] ?? 0);
        $newTotalTagihan = $biayaPemakaian + $newTunggakan;

        // Update tagihan
        $updateStmt = $pdo->prepare("
            UPDATE tagihans 
            SET tunggakan = :tunggakan, 
                total_tagihan = :total,
                updated_at = NOW()
            WHERE input_meter_id = :id
        ");
        $updateStmt->execute([
            ':tunggakan' => $newTunggakan,
            ':total' => $newTotalTagihan,
            ':id' => $inputMeterId
        ]);

        // Cascade update to future months for this customer
        cascadeUpdateFutureTunggakan($pdo, $tagihan['pelanggan_id'], $tagihan['period_year'], $tagihan['period_month']);

        echo json_encode([
            'success' => true,
            'message' => "Tunggakan berhasil ditambahkan (Rp " . number_format($tunggakanTambahan, 0, ',', '.') . "). Total tunggakan sekarang: Rp " . number_format($newTunggakan, 0, ',', '.'),
            'data' => [
                'input_meter_id' => $inputMeterId,
                'tunggakan_tambahan' => $tunggakanTambahan,
                'tunggakan_baru' => $newTunggakan,
                'total_tagihan_baru' => $newTotalTagihan,
                'keterangan' => $keterangan
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan tunggakan: ' . $e->getMessage()]);
    }
}

/**
 * Cascade update future months' tunggakan when a previous month changes
 */
function cascadeUpdateFutureTunggakan($pdo, $pelangganId, $year, $month)
{
    try {
        // Get all unpaid months after this period
        $stmt = $pdo->prepare("
            SELECT t.id, t.input_meter_id, m.period_year, m.period_month, m.total_biaya
            FROM tagihans t
            JOIN input_meters m ON m.id = t.input_meter_id
            WHERE m.pelanggan_id = :pelanggan_id
            AND t.status = 'unpaid'
            AND (m.period_year > :year OR (m.period_year = :year2 AND m.period_month > :month))
            ORDER BY m.period_year ASC, m.period_month ASC
        ");
        $stmt->execute([
            ':pelanggan_id' => $pelangganId,
            ':year' => $year,
            ':year2' => $year,
            ':month' => $month
        ]);
        $futureMonths = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($futureMonths as $future) {
            // Recalculate tunggakan for each future month
            $tunggakanStmt = $pdo->prepare("
                SELECT SUM(t.total_tagihan) as accumulated
                FROM tagihans t
                JOIN input_meters m ON m.id = t.input_meter_id
                WHERE m.pelanggan_id = :pelanggan_id
                AND t.status = 'unpaid'
                AND (
                    m.period_year < :year 
                    OR (m.period_year = :year2 AND m.period_month < :month)
                )
            ");
            $tunggakanStmt->execute([
                ':pelanggan_id' => $pelangganId,
                ':year' => $future['period_year'],
                ':year2' => $future['period_year'],
                ':month' => $future['period_month']
            ]);
            $accum = $tunggakanStmt->fetch(PDO::FETCH_ASSOC);
            $newTunggakan = floatval($accum['accumulated'] ?? 0);
            $newTotal = floatval($future['total_biaya']) + $newTunggakan;

            $updateStmt = $pdo->prepare("
                UPDATE tagihans 
                SET tunggakan = :tunggakan, total_tagihan = :total 
                WHERE input_meter_id = :id
            ");
            $updateStmt->execute([
                ':tunggakan' => $newTunggakan,
                ':total' => $newTotal,
                ':id' => $future['input_meter_id']
            ]);
        }
    }
    catch (PDOException $e) {
        error_log("cascadeUpdateFutureTunggakan error: " . $e->getMessage());
    }
}

/**
 * Update payment status
 * Auto-records to transaksis for financial reports
 */
function updateStatus($pdo, $inputMeterId)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['status']) || !in_array($input['status'], ['paid', 'unpaid'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
            return;
        }

        $status = $input['status'];
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;

        // Get input_meter data with pelanggan info
        $stmt = $pdo->prepare("
            SELECT m.*, p.customer_id, p.name as pelanggan_name 
            FROM input_meters m 
            JOIN pelanggans p ON m.pelanggan_id = p.id 
            WHERE m.id = :id
        ");
        $stmt->execute([':id' => $inputMeterId]);
        $meter = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$meter) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
            return;
        }

        // Check if tagihan exists
        $stmt = $pdo->prepare("SELECT id, tunggakan FROM tagihans WHERE input_meter_id = :id");
        $stmt->execute([':id' => $inputMeterId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $tagihanId = null;
        $totalTagihan = floatval($meter['total_biaya']);
        $tunggakan = 0;

        if ($existing) {
            $tagihanId = $existing['id'];
            $tunggakan = floatval($existing['tunggakan']);
            $totalTagihan = floatval($meter['total_biaya']) + $tunggakan;

            $stmt = $pdo->prepare("UPDATE tagihans SET status = :status, paid_at = :paid_at, updated_at = NOW() WHERE input_meter_id = :id");
            $stmt->execute([
                ':status' => $status,
                ':paid_at' => $paidAt,
                ':id' => $inputMeterId
            ]);
        }
        else {
            // Create new tagihan record
            $stmt = $pdo->prepare("INSERT INTO tagihans (pelanggan_id, input_meter_id, month, initial_meter, final_meter, usage_amount, amount, tunggakan, total_tagihan, status, paid_at, created_at, updated_at) VALUES (:pelanggan_id, :input_meter_id, :month, :initial, :final, :usage, :amount, 0, :amount, :status, :paid_at, NOW(), NOW())");
            $stmt->execute([
                ':pelanggan_id' => $meter['pelanggan_id'],
                ':input_meter_id' => $inputMeterId,
                ':month' => $meter['period_year'] . '-' . str_pad($meter['period_month'], 2, '0', STR_PAD_LEFT),
                ':initial' => $meter['meter_awal'],
                ':final' => $meter['meter_akhir'],
                ':usage' => $meter['jumlah_pakai'],
                ':amount' => $meter['total_biaya'],
                ':status' => $status,
                ':paid_at' => $paidAt
            ]);
            $tagihanId = $pdo->lastInsertId();
        }

        // ========================================
        // AUTO-RECORD TO TRANSAKSIS FOR LAPORAN KEUANGAN
        // ========================================

        $MONTH_NAMES = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        $periode = $MONTH_NAMES[intval($meter['period_month'])] . ' ' . $meter['period_year'];

        if ($status === 'paid') {
            // Check if transaksi already exists for this tagihan (prevent duplicate)
            $stmt = $pdo->prepare("SELECT id FROM transaksis WHERE tagihan_id = :tagihan_id");
            $stmt->execute([':tagihan_id' => $tagihanId]);
            $existingTransaksi = $stmt->fetch();

            if (!$existingTransaksi) {
                // Create pemasukan entry
                $nama = "Pembayaran Air - " . $meter['pelanggan_name'];
                $keterangan = "ID: " . $meter['customer_id'] . " | Periode: " . $periode . " | Pemakaian: " . $meter['jumlah_pakai'] . " m³";

                $stmt = $pdo->prepare("
                    INSERT INTO transaksis (tagihan_id, tipe, nama, kategori, nominal, tanggal, keterangan, period_year, period_month)
                    VALUES (:tagihan_id, 'pemasukan', :nama, 'Tagihan Air Bulanan', :nominal, :tanggal, :keterangan, :period_year, :period_month)
                ");
                $stmt->execute([
                    ':tagihan_id' => $tagihanId,
                    ':nama' => $nama,
                    ':nominal' => $totalTagihan,
                    ':tanggal' => date('Y-m-d'),
                    ':keterangan' => $keterangan,
                    ':period_year' => intval($meter['period_year']),
                    ':period_month' => intval($meter['period_month'])
                ]);
            }
        }
        else {
            // Status changed to unpaid - delete the transaksi entry if exists
            $stmt = $pdo->prepare("DELETE FROM transaksis WHERE tagihan_id = :tagihan_id");
            $stmt->execute([':tagihan_id' => $tagihanId]);
        }

        $message = $status === 'paid'
            ? 'Tagihan telah ditandai Lunas. Data otomatis ditambahkan ke Laporan Keuangan.'
            : 'Status tagihan diubah ke Belum Bayar';

        echo json_encode([
            'success' => true,
            'message' => $message,
            'auto_recorded' => $status === 'paid',
            'data' => [
                'input_meter_id' => $inputMeterId,
                'tagihan_id' => $tagihanId,
                'status' => $status,
                'paid_at' => $paidAt,
                'total' => $totalTagihan
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengubah status: ' . $e->getMessage()]);
    }
}

/**
 * Update Cicilan (Partial Payment) - Reduce tunggakan with installment payment
 */
function updateCicilan($pdo, $inputMeterId)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['cicilan_amount']) || !isset($input['new_tunggakan'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Cicilan amount dan new tunggakan wajib diisi']);
            return;
        }

        $cicilanAmount = floatval($input['cicilan_amount']);
        $newTunggakan = floatval($input['new_tunggakan']);

        // Get current tagihan with pelanggan info
        $stmt = $pdo->prepare("
            SELECT t.*, m.pelanggan_id, m.period_year, m.period_month, m.total_biaya,
                   p.customer_id, p.name as pelanggan_name
            FROM tagihans t
            JOIN input_meters m ON m.id = t.input_meter_id
            JOIN pelanggans p ON p.id = m.pelanggan_id
            WHERE t.input_meter_id = :id
        ");
        $stmt->execute([':id' => $inputMeterId]);
        $tagihan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tagihan) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tagihan tidak ditemukan']);
            return;
        }

        // CRITICAL FIX: Calculate auto-accumulated tunggakan (same logic as getTagihanList)
        // The raw database value may be 0 or stale, but the frontend shows auto-calculated value
        $accumulated = calculateAccumulatedArrears(
            $pdo,
            $tagihan['pelanggan_id'],
            $tagihan['period_year'],
            $tagihan['period_month']
        );

        // Use auto-calculated if database value is 0, otherwise use database value
        $dbTunggakan = floatval($tagihan['tunggakan']);
        $currentTunggakan = ($dbTunggakan > 0) ? $dbTunggakan : $accumulated['total'];

        // Validation
        if ($cicilanAmount <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nominal cicilan harus lebih dari 0']);
            return;
        }

        if ($cicilanAmount > $currentTunggakan) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nominal cicilan tidak boleh lebih dari tunggakan saat ini']);
            return;
        }

        if ($newTunggakan < 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Sisa tunggakan tidak boleh negatif']);
            return;
        }

        // Calculate new total - FIX: use amount from tagihan, not total_biaya
        $biayaPemakaian = floatval($tagihan['amount']); // This is the monthly usage cost
        $newTotalTagihan = $biayaPemakaian + $newTunggakan;

        // Update status if fully paid
        $newStatus = $newTunggakan == 0 ? 'paid' : $tagihan['status'];
        $paidAt = $newTunggakan == 0 ? date('Y-m-d H:i:s') : $tagihan['paid_at'];

        // CRITICAL FIX: Direct UPDATE to database
        $updateStmt = $pdo->prepare("
            UPDATE tagihans 
            SET tunggakan = :tunggakan, 
                total_tagihan = :total, 
                status = :status,
                paid_at = :paid_at,
                updated_at = NOW() 
            WHERE input_meter_id = :id
        ");

        $updateResult = $updateStmt->execute([
            ':tunggakan' => $newTunggakan,
            ':total' => $newTotalTagihan,
            ':status' => $newStatus,
            ':paid_at' => $paidAt,
            ':id' => $inputMeterId
        ]);

        if (!$updateResult) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal update database']);
            return;
        }

        // AUTO-RECORD CICILAN TO TRANSAKSIS
        $MONTH_NAMES = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        $periode = $MONTH_NAMES[intval($tagihan['period_month'])] . ' ' . $tagihan['period_year'];
        $nama = "Cicilan Tagihan Air - " . $tagihan['pelanggan_name'];
        $keterangan = "ID: " . $tagihan['customer_id'] . " | Cicilan untuk Periode: " . $periode . " | Dibayar tgl: " . date('d/m/Y');

        // Determine category based on whether it's now fully paid
        $kategori = ($newTunggakan == 0) ? 'Tagihan Air Bulanan' : 'Cicilan Tunggakan';

        $stmt = $pdo->prepare("
            INSERT INTO transaksis (tagihan_id, tipe, nama, kategori, nominal, tanggal, keterangan, period_year, period_month)
            VALUES (:tagihan_id, 'pemasukan', :nama, :kategori, :nominal, :tanggal, :keterangan, :period_year, :period_month)
        ");
        $stmt->execute([
            ':tagihan_id' => $tagihan['id'],
            ':nama' => $nama,
            ':kategori' => $kategori,
            ':nominal' => $cicilanAmount,
            ':tanggal' => date('Y-m-d'),
            ':keterangan' => $keterangan,
            ':period_year' => intval($tagihan['period_year']),
            ':period_month' => intval($tagihan['period_month'])
        ]);

        // The payment has already been recorded above with the correct amount and category.
        // No need for a second insert here, as it would cause double-counting of cash.

        // RECALCULATE FUTURE MONTHS - Important for auto-accumulation
        $stmt = $pdo->prepare("
            SELECT t.input_meter_id, m.period_year, m.period_month, m.total_biaya
            FROM tagihans t
            JOIN input_meters m ON m.id = t.input_meter_id
            WHERE m.pelanggan_id = :pelanggan_id
              AND (
                  (m.period_year > :year) OR
                  (m.period_year = :year2 AND m.period_month > :month)
              )
            ORDER BY m.period_year ASC, m.period_month ASC
        ");
        $stmt->execute([
            ':pelanggan_id' => $tagihan['pelanggan_id'],
            ':year' => $tagihan['period_year'],
            ':year2' => $tagihan['period_year'],
            ':month' => $tagihan['period_month']
        ]);
        $futureMonths = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($futureMonths as $future) {
            $accumulated = calculateAccumulatedArrears(
                $pdo,
                $tagihan['pelanggan_id'],
                $future['period_year'],
                $future['period_month']
            );

            $newFutureTunggakan = $accumulated['total'];
            $newFutureTotal = floatval($future['total_biaya']) + $newFutureTunggakan;

            $stmt = $pdo->prepare("
                UPDATE tagihans 
                SET tunggakan = :tunggakan, total_tagihan = :total 
                WHERE input_meter_id = :id
            ");
            $stmt->execute([
                ':tunggakan' => $newFutureTunggakan,
                ':total' => $newFutureTotal,
                ':id' => $future['input_meter_id']
            ]);
        }

        $message = $newTunggakan == 0
            ? 'Tunggakan telah lunas! Bulan-bulan berikutnya telah diperbarui.'
            : 'Cicilan berhasil dibayar (Rp ' . number_format($cicilanAmount, 0, ',', '.') . '). Sisa tunggakan: Rp ' . number_format($newTunggakan, 0, ',', '.') . '. Bulan-bulan berikutnya telah diperbarui.';

        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => [
                'new_tunggakan' => $newTunggakan,
                'total_tagihan' => $newTotalTagihan,
                'status' => $newStatus,
                'future_months_updated' => count($futureMonths)
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan cicilan: ' . $e->getMessage()]);
    }
}

/**
 * Helper function to calculate accumulated arrears
 */
function calculateAccumulatedArrears($pdo, $pelangganId, $periodYear, $periodMonth)
{
    $stmt = $pdo->prepare("
        SELECT SUM(t.total_tagihan) as total
        FROM tagihans t
        JOIN input_meters m ON m.id = t.input_meter_id
        WHERE m.pelanggan_id = :pelanggan_id
          AND t.status = 'unpaid'
          AND (
              (m.period_year < :year) OR
              (m.period_year = :year2 AND m.period_month < :month)
          )
    ");
    $stmt->execute([
        ':pelanggan_id' => $pelangganId,
        ':year' => $periodYear,
        ':year2' => $periodYear,
        ':month' => $periodMonth
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total' => floatval($result['total'] ?? 0)
    ];
}
?>
