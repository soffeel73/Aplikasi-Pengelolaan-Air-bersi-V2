<?php
/**
 * API Input Meter - Smart Air Desa
 * Handles CRUD operations for water meter readings with progressive tariff calculation
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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

// Admin fee per customer
define('BIAYA_ADMIN', 2000);

switch ($method) {
    case 'GET':
        if ($action === 'pelanggans') {
            // Get all customers for dropdown
            getCustomers($pdo);
        }
        elseif ($action === 'meter_awal') {
            // Get meter_awal for a specific customer
            getMeterAwal($pdo);
        }
        elseif ($id) {
            getMeterReading($pdo, $id);
        }
        else {
            getMeterReadings($pdo);
        }
        break;

    case 'POST':
        createMeterReading($pdo);
        break;

    case 'PUT':
        if ($id) {
            updateMeterReading($pdo, $id);
        }
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID diperlukan']);
        }
        break;

    case 'DELETE':
        if ($id) {
            deleteMeterReading($pdo, $id);
        }
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID diperlukan']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

/**
 * Calculate progressive water tariff + admin fee
 * 0-5 m3: Rp 1.500/m3
 * >5-10 m3: Rp 2.000/m3
 * >10 m3: Rp 2.500/m3
 * + Biaya Admin: Rp 2.000
 */
function calculateTariff($usage)
{
    $total = 0;

    if ($usage <= 0) {
        return BIAYA_ADMIN; // Still charge admin fee
    }

    // Block 1: 0-5 m3 @ Rp 1.500
    if ($usage <= 5) {
        $total = $usage * 1500;
    }
    // Block 2: >5-10 m3 @ Rp 2.000
    elseif ($usage <= 10) {
        $total = (5 * 1500) + (($usage - 5) * 2000);
    }
    // Block 3: >10 m3 @ Rp 2.500
    else {
        $total = (5 * 1500) + (5 * 2000) + (($usage - 10) * 2500);
    }

    // Add admin fee
    $total += BIAYA_ADMIN;

    return $total;
}

// Get all customers for dropdown
function getCustomers($pdo)
{
    try {
        $stmt = $pdo->query("SELECT id, customer_id, name, address, type FROM pelanggans ORDER BY name ASC");
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $customers]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data pelanggan']);
    }
}

// Get meter_awal from previous period
function getMeterAwal($pdo)
{
    $pelangganId = isset($_GET['pelanggan_id']) ? intval($_GET['pelanggan_id']) : 0;
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');

    if (!$pelangganId) {
        echo json_encode(['success' => true, 'meter_awal' => 0]);
        return;
    }

    try {
        // Calculate previous period
        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear = $year - 1;
        }

        // Get meter_akhir from previous period
        $stmt = $pdo->prepare("SELECT meter_akhir FROM input_meters WHERE pelanggan_id = :pelanggan_id AND period_year = :year AND period_month = :month LIMIT 1");
        $stmt->execute([
            ':pelanggan_id' => $pelangganId,
            ':year' => $prevYear,
            ':month' => $prevMonth
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $meterAwal = $result ? (int)$result['meter_akhir'] : 0;
        echo json_encode(['success' => true, 'meter_awal' => $meterAwal]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil meter awal']);
    }
}

// Get all meter readings with Pagination & Stats
function getMeterReadings($pdo)
{
    try {
        $year = isset($_GET['year']) ? intval($_GET['year']) : null;
        $month = isset($_GET['month']) ? intval($_GET['month']) : null;

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;

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

        // 1. Get Stats & Count
        $statsSql = "SELECT COUNT(*) as count, SUM(m.jumlah_pakai) as total_pemakaian 
                     FROM input_meters m" . $whereSql;
        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute($params);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $totalItems = intval($stats['count']);
        $totalPemakaian = intval($stats['total_pemakaian'] ?: 0);

        // 2. Get Paginated Data
        $sql = "SELECT m.*, p.customer_id, p.name as pelanggan_name, p.address as pelanggan_address, p.type as pelanggan_type 
                FROM input_meters m 
                JOIN pelanggans p ON m.pelanggan_id = p.id " .
            $whereSql .
            " ORDER BY m.period_year DESC, m.period_month DESC, p.address ASC 
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $readings,
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
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data input meter: ' . $e->getMessage()]);
    }
}

// Get single meter reading
function getMeterReading($pdo, $id)
{
    try {
        $stmt = $pdo->prepare("SELECT m.*, p.customer_id, p.name as pelanggan_name, p.address as pelanggan_address 
                               FROM input_meters m 
                               JOIN pelanggans p ON m.pelanggan_id = p.id 
                               WHERE m.id = :id");
        $stmt->execute([':id' => $id]);
        $reading = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reading) {
            echo json_encode(['success' => true, 'data' => $reading]);
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

// Create new meter reading
function createMeterReading($pdo)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validation
        $errors = [];
        if (empty($input['pelanggan_id']))
            $errors['pelanggan_id'] = 'Pelanggan wajib dipilih';
        if (empty($input['period_year']))
            $errors['period_year'] = 'Tahun wajib diisi';
        if (empty($input['period_month']))
            $errors['period_month'] = 'Bulan wajib diisi';
        if (!isset($input['meter_akhir']) || $input['meter_akhir'] === '') {
            $errors['meter_akhir'] = 'Meter akhir wajib diisi';
        }

        $meterAwal = isset($input['meter_awal']) ? intval($input['meter_awal']) : 0;
        $meterAkhir = isset($input['meter_akhir']) ? intval($input['meter_akhir']) : 0;

        if ($meterAkhir < $meterAwal) {
            $errors['meter_akhir'] = 'Meter akhir tidak boleh lebih kecil dari meter awal';
        }

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Validasi gagal', 'errors' => $errors]);
            return;
        }

        // Check if period already exists
        $stmt = $pdo->prepare("SELECT id FROM input_meters WHERE pelanggan_id = :pelanggan_id AND period_year = :year AND period_month = :month");
        $stmt->execute([
            ':pelanggan_id' => $input['pelanggan_id'],
            ':year' => $input['period_year'],
            ':month' => $input['period_month']
        ]);
        if ($stmt->fetch()) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Data periode ini sudah ada untuk pelanggan tersebut']);
            return;
        }

        // Calculate usage and tariff
        $jumlahPakai = $meterAkhir - $meterAwal;
        $totalBiaya = calculateTariff($jumlahPakai);

        // Insert
        $stmt = $pdo->prepare("INSERT INTO input_meters (pelanggan_id, period_year, period_month, meter_awal, meter_akhir, jumlah_pakai, total_biaya) VALUES (:pelanggan_id, :year, :month, :meter_awal, :meter_akhir, :jumlah_pakai, :total_biaya)");
        $stmt->execute([
            ':pelanggan_id' => $input['pelanggan_id'],
            ':year' => $input['period_year'],
            ':month' => $input['period_month'],
            ':meter_awal' => $meterAwal,
            ':meter_akhir' => $meterAkhir,
            ':jumlah_pakai' => $jumlahPakai,
            ':total_biaya' => $totalBiaya
        ]);

        $newId = $pdo->lastInsertId();

        // ============================================
        // SYNC: Create tagihan record for new input_meter
        // ============================================
        syncTagihanFromInputMeter($pdo, $newId, $totalBiaya);

        // Return the created record with pelanggan data
        $stmt = $pdo->prepare("SELECT m.*, p.customer_id, p.name as pelanggan_name, p.address as pelanggan_address, p.type as pelanggan_type 
                               FROM input_meters m 
                               JOIN pelanggans p ON m.pelanggan_id = p.id 
                               WHERE m.id = :id");
        $stmt->execute([':id' => $newId]);
        $newReading = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Data input meter berhasil disimpan',
            'data' => $newReading
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
    }
}

// Update meter reading
function updateMeterReading($pdo, $id)
{
    try {
        // Check if exists
        $stmt = $pdo->prepare("SELECT * FROM input_meters WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        // Validation
        $errors = [];
        if (!isset($input['meter_akhir']) || $input['meter_akhir'] === '') {
            $errors['meter_akhir'] = 'Meter akhir wajib diisi';
        }

        $meterAwal = isset($input['meter_awal']) ? intval($input['meter_awal']) : intval($existing['meter_awal']);
        $meterAkhir = isset($input['meter_akhir']) ? intval($input['meter_akhir']) : 0;

        if ($meterAkhir < $meterAwal) {
            $errors['meter_akhir'] = 'Meter akhir tidak boleh lebih kecil dari meter awal';
        }

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Validasi gagal', 'errors' => $errors]);
            return;
        }

        // Calculate usage and tariff
        $jumlahPakai = $meterAkhir - $meterAwal;
        $totalBiaya = calculateTariff($jumlahPakai);

        // Update
        $stmt = $pdo->prepare("UPDATE input_meters SET meter_awal = :meter_awal, meter_akhir = :meter_akhir, jumlah_pakai = :jumlah_pakai, total_biaya = :total_biaya WHERE id = :id");
        $stmt->execute([
            ':meter_awal' => $meterAwal,
            ':meter_akhir' => $meterAkhir,
            ':jumlah_pakai' => $jumlahPakai,
            ':total_biaya' => $totalBiaya,
            ':id' => $id
        ]);


        // CRITICAL: AUTO-SYNC meter_awal to next month
        // Ensure meter continuity: next_month.meter_awal = current_month.meter_akhir
        $pelangganId = intval($existing['pelanggan_id']);
        $currentYear = intval($existing['period_year']);
        $currentMonth = intval($existing['period_month']);

        // Calculate next period
        $nextMonth = $currentMonth + 1;
        $nextYear = $currentYear;

        if ($nextMonth > 12) {
            $nextMonth = 1;
            $nextYear++;
        }

        // Find next month record
        $checkNext = $pdo->prepare("
            SELECT id, meter_awal, meter_akhir 
            FROM input_meters 
            WHERE pelanggan_id = :pelanggan_id 
              AND period_year = :next_year 
              AND period_month = :next_month
        ");
        $checkNext->execute([
            ':pelanggan_id' => $pelangganId,
            ':next_year' => $nextYear,
            ':next_month' => $nextMonth
        ]);
        $nextRecord = $checkNext->fetch(PDO::FETCH_ASSOC);

        // If next month exists, sync meter_awal and recalculate
        if ($nextRecord) {
            $newNextMeterAwal = $meterAkhir; // CRITICAL: next month starts where this month ends
            $newNextJumlahPakai = intval($nextRecord['meter_akhir']) - $newNextMeterAwal;
            $newNextTotalBiaya = calculateTariff($newNextJumlahPakai);

            $syncStmt = $pdo->prepare("
                UPDATE input_meters 
                SET meter_awal = :new_meter_awal,
                    jumlah_pakai = :new_jumlah_pakai,
                    total_biaya = :new_total_biaya
                WHERE id = :next_id
            ");
            $syncStmt->execute([
                ':new_meter_awal' => $newNextMeterAwal,
                ':new_jumlah_pakai' => $newNextJumlahPakai,
                ':new_total_biaya' => $newNextTotalBiaya,
                ':next_id' => $nextRecord['id']
            ]);

            // ALSO UPDATE NEXT MONTH'S TAGIHAN
            syncTagihanFromInputMeter($pdo, $nextRecord['id'], $newNextTotalBiaya);
        }

        // ============================================
        // CRITICAL: SYNC TAGIHANS TABLE
        // Update tagihans to reflect new biaya from input_meter change
        // ============================================
        syncTagihanFromInputMeter($pdo, $id, $totalBiaya);


        // Return updated record
        $stmt = $pdo->prepare("SELECT m.*, p.customer_id, p.name as pelanggan_name, p.address as pelanggan_address, p.type as pelanggan_type 
                               FROM input_meters m 
                               JOIN pelanggans p ON m.pelanggan_id = p.id 
                               WHERE m.id = :id");
        $stmt->execute([':id' => $id]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Data input meter berhasil diperbarui',
            'data' => $updated
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui data: ' . $e->getMessage()]);
    }
}

// Delete meter reading
function deleteMeterReading($pdo, $id)
{
    try {
        // Check if exists
        $stmt = $pdo->prepare("SELECT * FROM input_meters WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
            return;
        }

        // ============================================
        // SYNC: Delete corresponding records in correct order
        // ============================================

        // 1. First, get the tagihan_id before deleting
        $stmt = $pdo->prepare("SELECT id FROM tagihans WHERE input_meter_id = :id");
        $stmt->execute([':id' => $id]);
        $tagihan = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tagihan) {
            // 2. Delete related transaksi records FIRST
            $stmt = $pdo->prepare("DELETE FROM transaksis WHERE tagihan_id = :tagihan_id");
            $stmt->execute([':tagihan_id' => $tagihan['id']]);

            // 3. Delete tagihan record
            $stmt = $pdo->prepare("DELETE FROM tagihans WHERE id = :id");
            $stmt->execute([':id' => $tagihan['id']]);
        }

        // 4. Finally delete input_meter
        $stmt = $pdo->prepare("DELETE FROM input_meters WHERE id = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Data input meter dan tagihan berhasil dihapus']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus data']);
    }
}

/**
 * Sync tagihans table when input_meter is updated
 * Updates amount and recalculates total_tagihan (amount + tunggakan)
 */
function syncTagihanFromInputMeter($pdo, $inputMeterId, $newBiaya)
{
    try {
        // Check if tagihan exists for this input_meter
        $stmt = $pdo->prepare("SELECT id, tunggakan, status FROM tagihans WHERE input_meter_id = :id");
        $stmt->execute([':id' => $inputMeterId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Recalculate total_tagihan = biaya_pemakaian + tunggakan
            $tunggakan = floatval($existing['tunggakan']);
            $newTotalTagihan = $newBiaya + $tunggakan;

            // Update existing tagihan
            $updateStmt = $pdo->prepare("
                UPDATE tagihans 
                SET amount = :amount,
                    total_tagihan = :total,
                    updated_at = NOW()
                WHERE input_meter_id = :id
            ");
            $updateStmt->execute([
                ':amount' => $newBiaya,
                ':total' => $newTotalTagihan,
                ':id' => $inputMeterId
            ]);
        }
        else {
            // Get input_meter data to create new tagihan
            $meterStmt = $pdo->prepare("
                SELECT m.*, p.id as pelanggan_id 
                FROM input_meters m 
                JOIN pelanggans p ON p.id = m.pelanggan_id 
                WHERE m.id = :id
            ");
            $meterStmt->execute([':id' => $inputMeterId]);
            $meter = $meterStmt->fetch(PDO::FETCH_ASSOC);

            if ($meter) {
                // Create new tagihan record
                $insertStmt = $pdo->prepare("
                    INSERT INTO tagihans (
                        pelanggan_id, input_meter_id, month, initial_meter, final_meter, 
                        usage_amount, amount, tunggakan, total_tagihan, status, created_at, updated_at
                    ) VALUES (
                        :pelanggan_id, :input_meter_id, :month, :initial, :final, 
                        :usage, :amount, 0, :total, 'unpaid', NOW(), NOW()
                    )
                ");
                $insertStmt->execute([
                    ':pelanggan_id' => $meter['pelanggan_id'],
                    ':input_meter_id' => $inputMeterId,
                    ':month' => $meter['period_year'] . '-' . str_pad($meter['period_month'], 2, '0', STR_PAD_LEFT),
                    ':initial' => $meter['meter_awal'],
                    ':final' => $meter['meter_akhir'],
                    ':usage' => $meter['jumlah_pakai'],
                    ':amount' => $newBiaya,
                    ':total' => $newBiaya // No tunggakan for new record
                ]);
            }
        }
    }
    catch (PDOException $e) {
        // Log error but don't fail the main operation
        error_log("syncTagihanFromInputMeter error: " . $e->getMessage());
    }
}
?>
