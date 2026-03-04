<?php
/**
 * API Rekapitulasi - Smart Air Desa
 * Handles yearly aggregation reports for payments, arrears, and usage
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once 'util/db.php';

$type = isset($_GET['type']) ? $_GET['type'] : '';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$pelangganId = isset($_GET['pelanggan_id']) ? intval($_GET['pelanggan_id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($type) {
        case 'pembayaran':
            if ($pelangganId) {
                getDetailPembayaran($pdo, $pelangganId, $year);
            }
            else {
                getRekapPembayaran($pdo, $year);
            }
            break;

        case 'tunggakan':
            if ($pelangganId) {
                getDetailTunggakan($pdo, $pelangganId, $year);
            }
            else {
                getRekapTunggakan($pdo, $year);
            }
            break;

        case 'pemakaian':
            if ($pelangganId) {
                getDetailPemakaian($pdo, $pelangganId, $year);
            }
            else {
                getRekapPemakaian($pdo, $year);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Type parameter required: pembayaran, tunggakan, or pemakaian']);
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Handle tunggakan update (cicilan)
    $inputMeterId = isset($_GET['input_meter_id']) ? intval($_GET['input_meter_id']) : null;
    if ($inputMeterId) {
        updateTunggakanCicilan($pdo, $inputMeterId);
    }
    else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'input_meter_id diperlukan']);
    }
}
else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

/**
 * Get Rekap Pembayaran - Summary per customer with Pagination & Stats
 */
function getRekapPembayaran($pdo, $year)
{
    try {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;

        // 1. Get Stats (Total for the year)
        $statsSql = "SELECT SUM(m.total_biaya) as total_pembayaran 
                     FROM input_meters m 
                     JOIN tagihans t ON t.input_meter_id = m.id
                     WHERE m.period_year = :year AND t.status = 'paid'";
        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute([':year' => $year]);
        $totalPembayaran = floatval($statsStmt->fetchColumn() ?: 0);

        // 2. Get Count
        $countSql = "SELECT COUNT(DISTINCT p.id) 
                     FROM pelanggans p
                     JOIN input_meters m ON m.pelanggan_id = p.id AND m.period_year = :year";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([':year' => $year]);
        $totalItems = intval($countStmt->fetchColumn());

        // 3. Get Paginated Data
        $sql = "SELECT 
                    p.id as pelanggan_id,
                    p.customer_id,
                    p.name,
                    p.address,
                    MAX(m.period_month) as last_month,
                    :year as year,
                    SUM(m.total_biaya) as total_tagihan,
                    SUM(COALESCE(t.tunggakan, 0)) as total_tunggakan,
                    SUM(m.total_biaya + COALESCE(t.tunggakan, 0)) as grand_total
                FROM pelanggans p
                JOIN input_meters m ON m.pelanggan_id = p.id AND m.period_year = :year2
                LEFT JOIN tagihans t ON t.input_meter_id = m.id
                GROUP BY p.id, p.customer_id, p.name, p.address
                ORDER BY p.address ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year2', $year, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $data,
            'year' => $year,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => ceil($totalItems / $limit),
                'current_page' => $page,
                'limit' => $limit
            ],
            'stats' => [
                'total_pembayaran' => $totalPembayaran
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data: ' . $e->getMessage()]);
    }
}

/**
 * Get Detail Pembayaran - Monthly breakdown for a customer
 */
function getDetailPembayaran($pdo, $pelangganId, $year)
{
    try {
        // Get customer info
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $pelangganId]);
        $pelanggan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pelanggan) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan']);
            return;
        }

        // Get monthly data
        $sql = "SELECT 
                    m.period_month,
                    m.total_biaya as biaya_pemakaian,
                    COALESCE(t.tunggakan, 0) as tunggakan,
                    m.total_biaya + COALESCE(t.tunggakan, 0) as total_tagihan,
                    COALESCE(t.status, 'unpaid') as status
                FROM input_meters m
                LEFT JOIN tagihans t ON t.input_meter_id = m.id
                WHERE m.pelanggan_id = :pelanggan_id AND m.period_year = :year
                ORDER BY m.period_month ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pelanggan_id' => $pelangganId, ':year' => $year]);
        $months = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate totals
        $totalTagihan = array_sum(array_column($months, 'total_tagihan'));

        echo json_encode([
            'success' => true,
            'pelanggan' => $pelanggan,
            'year' => $year,
            'months' => $months,
            'total_tagihan_tahunan' => $totalTagihan
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data']);
    }
}

/**
 * Get Rekap Tunggakan - Summary per customer with Pagination & Stats
 */
function getRekapTunggakan($pdo, $year)
{
    try {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;

        // 1. Get Stats (Total global tunggakan)
        $statsSql = "SELECT SUM(t.total_tagihan) as total_tunggakan 
                     FROM tagihans t 
                     WHERE t.status = 'unpaid'";
        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute();
        $totalTunggakan = floatval($statsStmt->fetchColumn() ?: 0);

        // 2. Get Count of customers with arrears
        $countSql = "SELECT COUNT(DISTINCT m.pelanggan_id) 
                     FROM tagihans t 
                     JOIN input_meters m ON m.id = t.input_meter_id
                     WHERE t.status = 'unpaid' AND m.period_year <= :year";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([':year' => $year]);
        $totalItems = intval($countStmt->fetchColumn());

        // 3. Get Paginated Data
        $sql = "SELECT 
                    p.id as pelanggan_id,
                    p.customer_id,
                    p.name,
                    p.address,
                    MAX(m.period_month) as last_month,
                    :year as year,
                    SUM(t.total_tagihan) as total_tunggakan
                FROM tagihans t
                JOIN input_meters m ON m.id = t.input_meter_id
                JOIN pelanggans p ON p.id = m.pelanggan_id
                WHERE t.status = 'unpaid' AND m.period_year <= :year2
                GROUP BY p.id, p.customer_id, p.name, p.address
                ORDER BY total_tunggakan DESC, p.address ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year2', $year, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $data,
            'year' => $year,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => ceil($totalItems / $limit),
                'current_page' => $page,
                'limit' => $limit
            ],
            'stats' => [
                'total_tunggakan' => $totalTunggakan
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data: ' . $e->getMessage()]);
    }
}

/**
 * Get Detail Tunggakan - Monthly breakdown for a customer
 */
function getDetailTunggakan($pdo, $pelangganId, $year)
{
    try {
        // Get customer info
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $pelangganId]);
        $pelanggan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pelanggan) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan']);
            return;
        }

        // Get monthly tunggakan data with input_meter_id for editing
        $sql = "SELECT 
                    m.id as input_meter_id,
                    m.period_month,
                    CASE WHEN COALESCE(t.status, 'unpaid') = 'unpaid' 
                         THEN COALESCE(t.total_tagihan, 0) 
                         ELSE 0 
                    END as tunggakan,
                    COALESCE(t.status, 'unpaid') as status,
                    t.id as tagihan_id
                FROM input_meters m
                LEFT JOIN tagihans t ON t.input_meter_id = m.id
                WHERE m.pelanggan_id = :pelanggan_id AND m.period_year = :year
                ORDER BY m.period_month ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pelanggan_id' => $pelangganId, ':year' => $year]);
        $months = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate total
        $totalTunggakan = array_sum(array_column($months, 'tunggakan'));

        echo json_encode([
            'success' => true,
            'pelanggan' => $pelanggan,
            'year' => $year,
            'months' => $months,
            'total_tunggakan_tahunan' => $totalTunggakan
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data']);
    }
}

/**
 * Get Rekap Pemakaian - Summary per customer with Pagination & Stats
 */
function getRekapPemakaian($pdo, $year)
{
    try {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;

        // 1. Get Stats (Total pemakaian for the year)
        $statsSql = "SELECT SUM(jumlah_pakai) FROM input_meters WHERE period_year = :year";
        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute([':year' => $year]);
        $totalPemakaian = intval($statsStmt->fetchColumn() ?: 0);

        // 2. Get Count
        $countSql = "SELECT COUNT(DISTINCT pelanggan_id) FROM input_meters WHERE period_year = :year";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([':year' => $year]);
        $totalItems = intval($countStmt->fetchColumn());

        // 3. Get Paginated Data
        $sql = "SELECT 
                    p.id as pelanggan_id,
                    p.customer_id,
                    p.name,
                    p.address,
                    MAX(m.period_month) as last_month,
                    :year as year,
                    SUM(m.jumlah_pakai) as total_pemakaian
                FROM pelanggans p
                JOIN input_meters m ON m.pelanggan_id = p.id AND m.period_year = :year2
                GROUP BY p.id, p.customer_id, p.name, p.address
                ORDER BY p.address ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        $stmt->bindValue(':year2', $year, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $data,
            'year' => $year,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => ceil($totalItems / $limit),
                'current_page' => $page,
                'limit' => $limit
            ],
            'stats' => [
                'total_pemakaian' => $totalPemakaian
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data']);
    }
}

/**
 * Get Detail Pemakaian - Monthly breakdown for a customer
 */
function getDetailPemakaian($pdo, $pelangganId, $year)
{
    try {
        // Get customer info
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $pelangganId]);
        $pelanggan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pelanggan) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan']);
            return;
        }

        // Get monthly usage data
        $sql = "SELECT 
                    m.period_month,
                    m.meter_awal,
                    m.meter_akhir,
                    m.jumlah_pakai
                FROM input_meters m
                WHERE m.pelanggan_id = :pelanggan_id AND m.period_year = :year
                ORDER BY m.period_month ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pelanggan_id' => $pelangganId, ':year' => $year]);
        $months = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate total
        $totalPemakaian = array_sum(array_column($months, 'jumlah_pakai'));

        echo json_encode([
            'success' => true,
            'pelanggan' => $pelanggan,
            'year' => $year,
            'months' => $months,
            'total_pemakaian_tahunan' => $totalPemakaian
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data']);
    }
}

/**
 * Update Tunggakan Cicilan - For installment payments
 * Auto-records cicilan to transaksis for financial reports
 */
function updateTunggakanCicilan($pdo, $inputMeterId)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['tunggakan'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nominal tunggakan baru wajib diisi']);
            return;
        }

        $newTunggakan = floatval($input['tunggakan']);

        // Get current tunggakan value with pelanggan info
        $stmt = $pdo->prepare("
            SELECT t.*, m.total_biaya, m.pelanggan_id, m.period_year, m.period_month, p.customer_id, p.name as pelanggan_name 
            FROM tagihans t 
            JOIN input_meters m ON m.id = t.input_meter_id 
            JOIN pelanggans p ON p.id = m.pelanggan_id
            WHERE t.input_meter_id = :id
        ");
        $stmt->execute([':id' => $inputMeterId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tagihan tidak ditemukan']);
            return;
        }

        $currentTunggakan = floatval($existing['tunggakan']);
        $tagihanId = $existing['id'];

        // Validation: new value cannot be greater than current
        if ($newTunggakan > $currentTunggakan) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Nominal tunggakan baru tidak boleh lebih besar dari tunggakan sebelumnya (Rp ' . number_format($currentTunggakan, 0, ',', '.') . ')'
            ]);
            return;
        }

        // Validation: cannot be negative
        if ($newTunggakan < 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nominal tunggakan tidak boleh negatif']);
            return;
        }

        // Calculate new total
        $newTotalTagihan = floatval($existing['total_biaya']) + $newTunggakan;

        // Calculate cicilan amount (how much was paid)
        $cicilanAmount = $currentTunggakan - $newTunggakan;

        // If tunggakan becomes 0, mark as paid
        $newStatus = $newTunggakan == 0 ? 'paid' : 'unpaid';
        $paidAt = $newTunggakan == 0 ? date('Y-m-d H:i:s') : null;

        // Update tagihan
        $stmt = $pdo->prepare("UPDATE tagihans SET tunggakan = :tunggakan, total_tagihan = :total, status = :status, paid_at = :paid_at, updated_at = NOW() WHERE input_meter_id = :id");
        $stmt->execute([
            ':tunggakan' => $newTunggakan,
            ':total' => $newTotalTagihan,
            ':status' => $newStatus,
            ':paid_at' => $paidAt,
            ':id' => $inputMeterId
        ]);

        // ========================================
        // AUTO-RECORD CICILAN TO TRANSAKSIS
        // ========================================

        if ($cicilanAmount > 0) {
            $MONTH_NAMES = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

            $periode = $MONTH_NAMES[intval($existing['period_month'])] . ' ' . $existing['period_year'];
            $nama = "Cicilan Tunggakan - " . $existing['pelanggan_name'];
            $keterangan = "ID: " . $existing['customer_id'] . " | Periode: " . $periode . " | Sisa: Rp " . number_format($newTunggakan, 0, ',', '.');

            // Determine category based on whether it's now fully paid
            $kategori = ($newTunggakan == 0) ? 'Tagihan Air Bulanan' : 'Cicilan Tunggakan';

            $stmt = $pdo->prepare("
                INSERT INTO transaksis (tagihan_id, tipe, nama, kategori, nominal, tanggal, keterangan, period_year, period_month)
                VALUES (:tagihan_id, 'pemasukan', :nama, :kategori, :nominal, :tanggal, :keterangan, :period_year, :period_month)
            ");
            $stmt->execute([
                ':tagihan_id' => $tagihanId,
                ':nama' => $nama,
                ':kategori' => $kategori,
                ':nominal' => $cicilanAmount,
                ':tanggal' => date('Y-m-d'),
                ':keterangan' => $keterangan,
                ':period_year' => intval(date('Y')),
                ':period_month' => intval(date('n'))
            ]);
        }

        // The payment has already been recorded above with the correct amount and category.
        // No need for a second insert here, as it would cause double-counting of cash.

        // Return success response
        $message = $newTunggakan == 0
            ? 'Tunggakan telah lunas! Data otomatis ditambahkan ke Laporan Keuangan.'
            : 'Cicilan berhasil dicatat (Rp ' . number_format($cicilanAmount, 0, ',', '.') . '). Data otomatis ditambahkan ke Laporan Keuangan.';

        echo json_encode([
            'success' => true,
            'message' => $message,
            'auto_recorded' => true,
            'data' => [
                'input_meter_id' => $inputMeterId,
                'old_tunggakan' => $currentTunggakan,
                'cicilan' => $cicilanAmount,
                'new_tunggakan' => $newTunggakan,
                'total_tagihan' => $newTotalTagihan,
                'status' => $newStatus
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan cicilan: ' . $e->getMessage()]);
    }
}
?>
