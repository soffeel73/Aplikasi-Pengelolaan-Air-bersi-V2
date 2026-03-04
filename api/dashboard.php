<?php
/**
 * API Dashboard - Smart Air Desa
 * Aggregates data from all modules for real-time dashboard
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once 'util/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'stats';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Month labels
$MONTH_NAMES = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

switch ($action) {
    case 'stats':
        getStats($pdo);
        break;
    case 'charts':
        getCharts($pdo, $year);
        break;
    case 'recent':
        getRecentTransactions($pdo);
        break;
    default:
        getStats($pdo);
}

/**
 * Get Dashboard Statistics for Stat Cards
 */
function getStats($pdo)
{
    try {
        $currentYear = date('Y');
        $currentMonth = date('n');
        $prevMonth = $currentMonth - 1;
        $prevYear = $currentYear;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear = $currentYear - 1;
        }

        // 1. Total Pelanggan
        $stmt = $pdo->query("SELECT COUNT(*) FROM pelanggans");
        $totalPelanggan = intval($stmt->fetchColumn());

        // Pelanggan last month
        $stmt = $pdo->query("SELECT COUNT(*) FROM pelanggans WHERE created_at < DATE_FORMAT(NOW(), '%Y-%m-01')");
        $pelangganLastMonth = intval($stmt->fetchColumn());
        $pelangganChange = $pelangganLastMonth > 0 ? round((($totalPelanggan - $pelangganLastMonth) / $pelangganLastMonth) * 100, 1) : 0;

        // 2. Pendapatan Bulan Ini (Lunas - from transaksis)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) FROM transaksis 
            WHERE tipe = 'pemasukan' AND period_year = :year AND period_month = :month
        ");
        $stmt->execute([':year' => $currentYear, ':month' => $currentMonth]);
        $pendapatanBulanIni = floatval($stmt->fetchColumn());

        // Pendapatan bulan lalu
        $stmt->execute([':year' => $prevYear, ':month' => $prevMonth]);
        $pendapatanBulanLalu = floatval($stmt->fetchColumn());
        $pendapatanChange = $pendapatanBulanLalu > 0 ? round((($pendapatanBulanIni - $pendapatanBulanLalu) / $pendapatanBulanLalu) * 100, 1) : 0;

        // 3. Total Piutang (Belum Bayar)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(m.total_biaya + COALESCE(t.tunggakan, 0)), 0)
            FROM input_meters m
            LEFT JOIN tagihans t ON t.input_meter_id = m.id
            WHERE m.period_year = :year AND (t.status IS NULL OR t.status = 'unpaid')
        ");
        $stmt->execute([':year' => $currentYear]);
        $totalPiutang = floatval($stmt->fetchColumn());

        // Piutang bulan lalu
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(m.total_biaya + COALESCE(t.tunggakan, 0)), 0)
            FROM input_meters m
            LEFT JOIN tagihans t ON t.input_meter_id = m.id
            WHERE m.period_year = :year AND m.period_month < :month AND (t.status IS NULL OR t.status = 'unpaid')
        ");
        $stmt->execute([':year' => $currentYear, ':month' => $currentMonth]);
        $piutangBulanLalu = floatval($stmt->fetchColumn());
        $piutangChange = $piutangBulanLalu > 0 ? round((($totalPiutang - $piutangBulanLalu) / $piutangBulanLalu) * 100, 1) : 0;

        // 4. Saldo Kas = Total Pemasukan - Total Pengeluaran (tahun ini)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) FROM transaksis 
            WHERE tipe = 'pemasukan' AND period_year = :year
        ");
        $stmt->execute([':year' => $currentYear]);
        $totalPemasukan = floatval($stmt->fetchColumn());

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) FROM transaksis 
            WHERE tipe = 'pengeluaran' AND period_year = :year
        ");
        $stmt->execute([':year' => $currentYear]);
        $totalPengeluaran = floatval($stmt->fetchColumn());

        $saldoKas = $totalPemasukan - $totalPengeluaran;

        echo json_encode([
            'success' => true,
            'data' => [
                'total_pelanggan' => $totalPelanggan,
                'pelanggan_change' => $pelangganChange,
                'pendapatan_bulan_ini' => $pendapatanBulanIni,
                'pendapatan_change' => $pendapatanChange,
                'total_piutang' => $totalPiutang,
                'piutang_change' => $piutangChange,
                'saldo_kas' => $saldoKas,
                'total_pemasukan' => $totalPemasukan,
                'total_pengeluaran' => $totalPengeluaran
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil statistik: ' . $e->getMessage()]);
    }
}

/**
 * Get Charts Data
 */
function getCharts($pdo, $year)
{
    global $MONTH_NAMES;

    try {
        $pemakaianAir = [];
        $pemasukan = [];
        $pengeluaran = [];
        $trenTunggakan = [];

        // Monthly data for 12 months
        for ($month = 1; $month <= 12; $month++) {
            // Pemakaian Air (m³)
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(jumlah_pakai), 0) FROM input_meters 
                WHERE period_year = :year AND period_month = :month
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $pemakaianAir[] = intval($stmt->fetchColumn());

            // Pemasukan
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(nominal), 0) FROM transaksis 
                WHERE tipe = 'pemasukan' AND period_year = :year AND period_month = :month
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $pemasukan[] = floatval($stmt->fetchColumn());

            // Pengeluaran
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(nominal), 0) FROM transaksis 
                WHERE tipe = 'pengeluaran' AND period_year = :year AND period_month = :month
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $pengeluaran[] = floatval($stmt->fetchColumn());

            // Tunggakan (unpaid bills for the month)
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(m.total_biaya + COALESCE(t.tunggakan, 0)), 0)
                FROM input_meters m
                LEFT JOIN tagihans t ON t.input_meter_id = m.id
                WHERE m.period_year = :year AND m.period_month = :month 
                  AND (t.status IS NULL OR t.status = 'unpaid')
            ");
            $stmt->execute([':year' => $year, ':month' => $month]);
            $trenTunggakan[] = floatval($stmt->fetchColumn());
        }

        // Tunggakan per Wilayah (Group by address)
        $stmt = $pdo->prepare("
            SELECT 
                SUBSTRING_INDEX(p.address, ',', 1) as wilayah,
                COALESCE(SUM(m.total_biaya + COALESCE(t.tunggakan, 0)), 0) as total
            FROM input_meters m
            JOIN pelanggans p ON p.id = m.pelanggan_id
            LEFT JOIN tagihans t ON t.input_meter_id = m.id
            WHERE m.period_year = :year AND (t.status IS NULL OR t.status = 'unpaid')
            GROUP BY SUBSTRING_INDEX(p.address, ',', 1)
            ORDER BY total DESC
            LIMIT 10
        ");
        $stmt->execute([':year' => $year]);
        $tunggakanPerWilayah = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'year' => $year,
            'labels' => array_slice($MONTH_NAMES, 1),
            'data' => [
                'pemakaian_air' => $pemakaianAir,
                'pemasukan' => $pemasukan,
                'pengeluaran' => $pengeluaran,
                'tren_tunggakan' => $trenTunggakan,
                'tunggakan_per_wilayah' => $tunggakanPerWilayah,
                'pelanggan_per_wilayah' => $pdo->query("
                    SELECT SUBSTRING_INDEX(address, ',', 1) as wilayah, COUNT(*) as total 
                    FROM pelanggans 
                    GROUP BY SUBSTRING_INDEX(address, ',', 1)
                    ORDER BY total DESC
                ")->fetchAll(PDO::FETCH_ASSOC)
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data chart: ' . $e->getMessage()]);
    }
}

/**
 * Get Recent Transactions
 */
function getRecentTransactions($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT id, tipe, nama, kategori, nominal, tanggal, keterangan
            FROM transaksis
            ORDER BY tanggal DESC, id DESC
            LIMIT 5
        ");
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $transactions
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil transaksi terbaru']);
    }
}
?>
