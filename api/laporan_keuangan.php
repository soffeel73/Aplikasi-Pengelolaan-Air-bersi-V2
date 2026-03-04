<?php
/**
 * API Laporan Keuangan - Smart Air Desa
 * Enhanced: Yearly report, Daily transactions, Pemasukan & Pengeluaran
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
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : null;

// Tariff constants
define('TARIF_BLOK_1', 1500);
define('TARIF_BLOK_2', 2000);
define('TARIF_BLOK_3', 2500);
define('BIAYA_ADMIN', 2000);

// Month labels
$MONTH_NAMES = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'summary':
                getSummary($pdo, $year);
                break;
            case 'yearly':
                getYearlyReport($pdo, $year);
                break;
            case 'daily':
                if ($month) {
                    getDailyReport($pdo, $year, $month);
                }
                else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Parameter month diperlukan']);
                }
                break;
            case 'detail':
                if ($month) {
                    getDetailBreakdown($pdo, $year, $month);
                }
                else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Parameter month diperlukan']);
                }
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
        }
        break;

    case 'POST':
        createTransaction($pdo);
        break;

    case 'PUT':
        if ($id) {
            updateTransaction($pdo, $id);
        }
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID diperlukan']);
        }
        break;

    case 'DELETE':
        if ($id) {
            deleteTransaction($pdo, $id);
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
 * Get Summary Stats for Stat Cards
 * All pemasukan data from transaksis table
 */
function getSummary($pdo, $year)
{
    try {
        // Total Pendapatan Air (from transaksis - kategori 'Tagihan Air Bulanan')
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) as total
            FROM transaksis
            WHERE period_year = :year AND tipe = 'pemasukan' AND kategori = 'Tagihan Air Bulanan'
        ");
        $stmt->execute([':year' => $year]);
        $pendapatanAir = floatval($stmt->fetchColumn());

        // Total Pemasukan Lainnya (all other pemasukan EXCEPT Tagihan Air Bulanan)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) as total
            FROM transaksis
            WHERE period_year = :year AND tipe = 'pemasukan' AND kategori != 'Tagihan Air Bulanan'
        ");
        $stmt->execute([':year' => $year]);
        $pemasukanLainnya = floatval($stmt->fetchColumn());

        $totalPendapatan = $pendapatanAir + $pemasukanLainnya;

        // Total Piutang (from tagihans - unpaid bills)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(m.total_biaya + COALESCE(t.tunggakan, 0)), 0) as total
            FROM input_meters m
            LEFT JOIN tagihans t ON t.input_meter_id = m.id
            WHERE m.period_year = :year AND (t.status IS NULL OR t.status = 'unpaid')
        ");
        $stmt->execute([':year' => $year]);
        $totalPiutang = floatval($stmt->fetchColumn());

        // Total Pengeluaran
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) as total
            FROM transaksis
            WHERE period_year = :year AND tipe = 'pengeluaran'
        ");
        $stmt->execute([':year' => $year]);
        $totalPengeluaran = floatval($stmt->fetchColumn());

        // Saldo Bersih
        $saldoBersih = $totalPendapatan - $totalPengeluaran;

        echo json_encode([
            'success' => true,
            'year' => $year,
            'data' => [
                'pendapatan_air' => $pendapatanAir,
                'pemasukan_lainnya' => $pemasukanLainnya,
                'total_pendapatan' => $totalPendapatan,
                'total_piutang' => $totalPiutang,
                'total_pengeluaran' => $totalPengeluaran,
                'saldo_bersih' => $saldoBersih
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil ringkasan: ' . $e->getMessage()]);
    }
}

/**
 * Get Yearly Report - 12 month summary
 * All data from transaksis table (auto-recorded + manual entries)
 * Piutang shown but excluded from Saldo Bersih (Cash Basis Accounting)
 */
function getYearlyReport($pdo, $year)
{
    global $MONTH_NAMES;

    try {
        $yearlyData = [];

        for ($month = 1; $month <= 12; $month++) {
            // Pendapatan Air (from transaksis - kategori 'Tagihan Air Bulanan')
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(nominal), 0) as total
                FROM transaksis
                WHERE period_year = :year AND period_month = :month 
                  AND tipe = 'pemasukan' AND kategori = 'Tagihan Air Bulanan'
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $pendapatanAir = floatval($stmt->fetchColumn());

            // Pemasukan Lainnya (all other pemasukan EXCEPT Tagihan Air Bulanan)
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(nominal), 0) as total
                FROM transaksis
                WHERE period_year = :year AND period_month = :month 
                  AND tipe = 'pemasukan' AND kategori != 'Tagihan Air Bulanan'
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $pemasukanLainnya = floatval($stmt->fetchColumn());

            // Piutang (unpaid bills for this month - NOT included in cash balance)
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(m.total_biaya + COALESCE(t.tunggakan, 0)), 0) as total
                FROM input_meters m
                LEFT JOIN tagihans t ON t.input_meter_id = m.id
                WHERE m.period_year = :year AND m.period_month = :month 
                  AND (t.status IS NULL OR t.status = 'unpaid')
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $piutang = floatval($stmt->fetchColumn());

            // Pengeluaran
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(nominal), 0) as total
                FROM transaksis
                WHERE period_year = :year AND period_month = :month AND tipe = 'pengeluaran'
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $pengeluaran = floatval($stmt->fetchColumn());

            // Total Pemasukan (CASH ONLY - excludes piutang)
            $totalPemasukan = $pendapatanAir + $pemasukanLainnya;
            // Saldo Bersih = Cash Pemasukan - Pengeluaran (Cash Basis)
            $saldoBersih = $totalPemasukan - $pengeluaran;

            $yearlyData[] = [
                'bulan' => $MONTH_NAMES[$month],
                'period_month' => $month,
                'period_year' => $year,
                'pendapatan_air' => $pendapatanAir,
                'pemasukan_lainnya' => $pemasukanLainnya,
                'total_pemasukan' => $totalPemasukan,
                'piutang' => $piutang,
                'pengeluaran' => $pengeluaran,
                'saldo_bersih' => $saldoBersih
            ];
        }

        // Calculate totals
        $totals = [
            'pendapatan_air' => array_sum(array_column($yearlyData, 'pendapatan_air')),
            'pemasukan_lainnya' => array_sum(array_column($yearlyData, 'pemasukan_lainnya')),
            'total_pemasukan' => array_sum(array_column($yearlyData, 'total_pemasukan')),
            'piutang' => array_sum(array_column($yearlyData, 'piutang')),
            'pengeluaran' => array_sum(array_column($yearlyData, 'pengeluaran')),
            'saldo_bersih' => array_sum(array_column($yearlyData, 'saldo_bersih'))
        ];

        echo json_encode([
            'success' => true,
            'year' => $year,
            'data' => $yearlyData,
            'totals' => $totals
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil laporan tahunan: ' . $e->getMessage()]);
    }
}

/**
 * Get Daily Report - transactions for a specific month
 * All water payments are already auto-recorded to transaksis table
 * Piutang shown but excluded from Saldo (Cash Basis Accounting)
 */
function getDailyReport($pdo, $year, $month)
{
    global $MONTH_NAMES;

    try {
        // Pagination setup
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        // 1. Get Summary (Total for the filters, not paginated)
        $stmt = $pdo->prepare("
            SELECT tipe, SUM(nominal) as total
            FROM transaksis
            WHERE period_year = :year AND period_month = :month
            GROUP BY tipe
        ");
        $stmt->execute([':year' => $year, ':month' => $month]);
        $summaryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPemasukan = 0;
        $totalPengeluaran = 0;
        foreach ($summaryRows as $row) {
            if ($row['tipe'] === 'pemasukan')
                $totalPemasukan = floatval($row['total']);
            else
                $totalPengeluaran = floatval($row['total']);
        }

        // 2. Get Count for Pagination
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM transaksis
            WHERE period_year = :year AND period_month = :month
        ");
        $stmt->execute([':year' => $year, ':month' => $month]);
        $totalItems = intval($stmt->fetchColumn());

        // 3. Get Paginated Data
        $stmt = $pdo->prepare("
            SELECT id, tagihan_id, tipe, nama, kategori, nominal, tanggal, keterangan
            FROM transaksis
            WHERE period_year = :year AND period_month = :month
            ORDER BY tanggal DESC, id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        $stmt->bindValue(':month', $month, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate piutang for this month
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(m.total_biaya + COALESCE(t.tunggakan, 0)), 0) as total
            FROM input_meters m
            LEFT JOIN tagihans t ON t.input_meter_id = m.id
            WHERE m.period_year = :year AND m.period_month = :month 
              AND (t.status IS NULL OR t.status = 'unpaid')
        ");
        $stmt->execute([':year' => $year, ':month' => $month]);
        $piutang = floatval($stmt->fetchColumn());

        echo json_encode([
            'success' => true,
            'periode' => $MONTH_NAMES[$month] . ' ' . $year,
            'year' => $year,
            'month' => $month,
            'data' => $transactions,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => ceil($totalItems / $limit),
                'current_page' => $page,
                'limit' => $limit
            ],
            'summary' => [
                'total_pemasukan' => $totalPemasukan,
                'total_pengeluaran' => $totalPengeluaran,
                'piutang' => $piutang,
                'saldo' => $totalPemasukan - $totalPengeluaran
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil laporan harian: ' . $e->getMessage()]);
    }
}

/**
 * Get Detail Breakdown for a specific month
 */
function getDetailBreakdown($pdo, $year, $month)
{
    global $MONTH_NAMES;

    try {
        // Calculate breakdown by tariff blocks
        $stmt = $pdo->prepare("
            SELECT m.jumlah_pakai, m.total_biaya, t.status
            FROM input_meters m
            LEFT JOIN tagihans t ON t.input_meter_id = m.id
            WHERE m.period_year = :year AND m.period_month = :month AND t.status = 'paid'
        ");
        $stmt->execute([':year' => $year, ':month' => $month]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $blok1 = 0;
        $blok2 = 0;
        $blok3 = 0;
        $adminFee = 0;
        $customerCount = 0;

        foreach ($readings as $reading) {
            $usage = intval($reading['jumlah_pakai']);
            $customerCount++;
            $adminFee += BIAYA_ADMIN;

            if ($usage <= 5) {
                $blok1 += $usage * TARIF_BLOK_1;
            }
            elseif ($usage <= 10) {
                $blok1 += 5 * TARIF_BLOK_1;
                $blok2 += ($usage - 5) * TARIF_BLOK_2;
            }
            else {
                $blok1 += 5 * TARIF_BLOK_1;
                $blok2 += 5 * TARIF_BLOK_2;
                $blok3 += ($usage - 10) * TARIF_BLOK_3;
            }
        }

        // Get actual cash received for water (Tagihan Air Bulanan)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0)
            FROM transaksis
            WHERE period_year = :year AND period_month = :month 
              AND tipe = 'pemasukan' AND kategori = 'Tagihan Air Bulanan'
        ");
        $stmt->execute([':year' => $year, ':month' => $month]);
        $totalPemasukanAirCash = floatval($stmt->fetchColumn());

        // Get other income (EXCLUDING Tagihan Air Bulanan)
        $stmt = $pdo->prepare("
            SELECT id, nama, kategori, nominal, tanggal
            FROM transaksis
            WHERE period_year = :year AND period_month = :month 
              AND tipe = 'pemasukan' AND kategori != 'Tagihan Air Bulanan'
            ORDER BY tanggal ASC
        ");
        $stmt->execute([':year' => $year, ':month' => $month]);
        $pemasukanLainnya = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalPemasukanLainnya = array_sum(array_column($pemasukanLainnya, 'nominal'));

        // Get pengeluaran
        $stmt = $pdo->prepare("
            SELECT id, nama, kategori, nominal, tanggal
            FROM transaksis
            WHERE period_year = :year AND period_month = :month AND tipe = 'pengeluaran'
            ORDER BY tanggal ASC
        ");
        $stmt->execute([':year' => $year, ':month' => $month]);
        $pengeluaran = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalPengeluaran = array_sum(array_column($pengeluaran, 'nominal'));

        $totalPendapatanAirBreakdown = $blok1 + $blok2 + $blok3 + $adminFee;
        // Pembayaran Tunggakan/Lain-lain is the difference between total cash and current month breakdown
        $pembayaranTunggakan = $totalPemasukanAirCash - $totalPendapatanAirBreakdown;

        $totalPemasukan = $totalPemasukanAirCash + $totalPemasukanLainnya;

        echo json_encode([
            'success' => true,
            'periode' => $MONTH_NAMES[$month] . ' ' . $year,
            'year' => $year,
            'month' => $month,
            'breakdown_air' => [
                'blok_1' => $blok1,
                'blok_2' => $blok2,
                'blok_3' => $blok3,
                'admin_fee' => $adminFee,
                'customer_count' => $customerCount,
                'pembayaran_tunggakan' => $pembayaranTunggakan,
                'total' => $totalPemasukanAirCash
            ],
            'pemasukan_lainnya' => $pemasukanLainnya,
            'total_pemasukan_lainnya' => $totalPemasukanLainnya,
            'pengeluaran' => $pengeluaran,
            'total_pengeluaran' => $totalPengeluaran,
            'total_pemasukan' => $totalPemasukan,
            'laba_rugi' => $totalPemasukan - $totalPengeluaran,
            'chart_data' => [
                'labels' => ['Pemasukan', 'Pengeluaran'],
                'values' => [$totalPemasukan, $totalPengeluaran],
                'colors' => ['#2EC4B6', '#FF9F1C']
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil detail: ' . $e->getMessage()]);
    }
}

/**
 * Create new transaction (pemasukan or pengeluaran)
 */
function createTransaction($pdo)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validation
        $errors = [];
        if (empty($input['tipe']) || !in_array($input['tipe'], ['pemasukan', 'pengeluaran'])) {
            $errors['tipe'] = 'Tipe transaksi tidak valid';
        }
        if (empty($input['nama']))
            $errors['nama'] = 'Nama transaksi wajib diisi';
        if (empty($input['kategori']))
            $errors['kategori'] = 'Kategori wajib dipilih';
        if (!isset($input['nominal']) || $input['nominal'] <= 0)
            $errors['nominal'] = 'Nominal harus lebih dari 0';
        if (empty($input['tanggal']))
            $errors['tanggal'] = 'Tanggal wajib diisi';

        // Validate kategori
        $validKategoriPemasukan = ['Pasang Baru', 'Denda', 'Penjualan Material', 'Lainnya'];
        $validKategoriPengeluaran = ['Operasional', 'Gaji', 'Maintenance', 'Lainnya'];

        if (!empty($input['tipe']) && !empty($input['kategori'])) {
            if ($input['tipe'] === 'pemasukan' && !in_array($input['kategori'], $validKategoriPemasukan)) {
                $errors['kategori'] = 'Kategori pemasukan tidak valid';
            }
            if ($input['tipe'] === 'pengeluaran' && !in_array($input['kategori'], $validKategoriPengeluaran)) {
                $errors['kategori'] = 'Kategori pengeluaran tidak valid';
            }
        }

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Validasi gagal', 'errors' => $errors]);
            return;
        }

        // Parse date
        $tanggal = new DateTime($input['tanggal']);
        $periodYear = intval($tanggal->format('Y'));
        $periodMonth = intval($tanggal->format('n'));

        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO transaksis (tipe, nama, kategori, nominal, tanggal, keterangan, period_year, period_month)
            VALUES (:tipe, :nama, :kategori, :nominal, :tanggal, :keterangan, :period_year, :period_month)
        ");
        $stmt->execute([
            ':tipe' => $input['tipe'],
            ':nama' => $input['nama'],
            ':kategori' => $input['kategori'],
            ':nominal' => floatval($input['nominal']),
            ':tanggal' => $input['tanggal'],
            ':keterangan' => isset($input['keterangan']) ? $input['keterangan'] : null,
            ':period_year' => $periodYear,
            ':period_month' => $periodMonth
        ]);

        $newId = $pdo->lastInsertId();

        // Get created record
        $stmt = $pdo->prepare("SELECT * FROM transaksis WHERE id = :id");
        $stmt->execute([':id' => $newId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        $label = $input['tipe'] === 'pemasukan' ? 'Pemasukan' : 'Pengeluaran';

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => $label . ' berhasil ditambahkan',
            'data' => $transaction
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage()]);
    }
}

/**
 * Update transaction
 */
function updateTransaction($pdo, $id)
{
    try {
        // Check if exists
        $stmt = $pdo->prepare("SELECT * FROM transaksis WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data transaksi tidak ditemukan']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        // Build update
        $nama = isset($input['nama']) ? $input['nama'] : $existing['nama'];
        $kategori = isset($input['kategori']) ? $input['kategori'] : $existing['kategori'];
        $nominal = isset($input['nominal']) ? floatval($input['nominal']) : $existing['nominal'];
        $tanggal = isset($input['tanggal']) ? $input['tanggal'] : $existing['tanggal'];
        $keterangan = isset($input['keterangan']) ? $input['keterangan'] : $existing['keterangan'];

        // Parse date
        $date = new DateTime($tanggal);
        $periodYear = intval($date->format('Y'));
        $periodMonth = intval($date->format('n'));

        // Update
        $stmt = $pdo->prepare("
            UPDATE transaksis 
            SET nama = :nama, kategori = :kategori, nominal = :nominal, 
                tanggal = :tanggal, keterangan = :keterangan,
                period_year = :period_year, period_month = :period_month
            WHERE id = :id
        ");
        $stmt->execute([
            ':nama' => $nama,
            ':kategori' => $kategori,
            ':nominal' => $nominal,
            ':tanggal' => $tanggal,
            ':keterangan' => $keterangan,
            ':period_year' => $periodYear,
            ':period_month' => $periodMonth,
            ':id' => $id
        ]);

        // Get updated record
        $stmt = $pdo->prepare("SELECT * FROM transaksis WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Transaksi berhasil diperbarui',
            'data' => $transaction
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui transaksi']);
    }
}

/**
 * Delete transaction
 */
function deleteTransaction($pdo, $id)
{
    try {
        // Check if exists
        $stmt = $pdo->prepare("SELECT * FROM transaksis WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data transaksi tidak ditemukan']);
            return;
        }

        // Delete
        $stmt = $pdo->prepare("DELETE FROM transaksis WHERE id = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Transaksi berhasil dihapus']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus transaksi']);
    }
}
?>
