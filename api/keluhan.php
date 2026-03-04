<?php
/**
 * API Keluhan - Smart Air Desa
 * Handles complaint submissions and management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once 'util/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// WhatsApp Notification Config (Fonnte)
$FONNTE_TOKEN = '9GR9kHDnzxfqzcV9vdjDU54a193u55Tpm9Pxy'; // TODO: Isi dengan token Fonnte Anda
$ADMIN_WHATSAPP = '085784466697';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':
                getKeluhans($pdo);
                break;
            case 'detail':
                $id = isset($_GET['id']) ? intval($_GET['id']) : null;
                if ($id) {
                    getKeluhanDetail($pdo, $id);
                }
                else {
                    echo json_encode(['success' => false, 'message' => 'ID diperlukan']);
                }
                break;
            case 'count_new':
                getNewKeluhanCount($pdo);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
        }
        break;

    case 'POST':
        if ($action === 'submit') {
            submitKeluhan($pdo);
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
        }
        break;

    case 'PUT':
        if ($action === 'update_status') {
            updateKeluhanStatus($pdo);
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

// Get all keluhans with optional filtering & Pagination
function getKeluhans($pdo)
{
    try {
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';
        $search = isset($_GET['search']) ? $_GET['search'] : '';

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;

        $whereSql = " WHERE 1=1";
        $params = [];

        if ($status) {
            $whereSql .= " AND k.status = ?";
            $params[] = $status;
        }

        if ($kategori) {
            $whereSql .= " AND k.kategori = ?";
            $params[] = $kategori;
        }

        if ($search) {
            $whereSql .= " AND (k.nama_lengkap LIKE ? OR k.no_hpm LIKE ? OR k.detail_laporan LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // 1. Get Count
        $countSql = "SELECT COUNT(*) FROM keluhans k LEFT JOIN pelanggans p ON k.no_hpm = p.customer_id" . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = intval($countStmt->fetchColumn());

        // 2. Get Paginated Data
        $sql = "SELECT k.*, p.name as nama_pelanggan, p.address as alamat 
                FROM keluhans k 
                LEFT JOIN pelanggans p ON k.no_hpm = p.customer_id" .
            $whereSql .
            " ORDER BY k.created_at DESC LIMIT ? OFFSET ?";

        $stmt = $pdo->prepare($sql);

        // Bind other parameters
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val);
        }

        // Bind limit/offset as integers
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);

        $stmt->execute();
        $keluhans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $keluhans,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => ceil($totalItems / $limit),
                'current_page' => $page,
                'limit' => $limit
            ]
        ]);
    }
    catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data: ' . $e->getMessage()]);
    }
}

/**
 * Get single keluhan detail
 */
function getKeluhanDetail($pdo, $id)
{
    try {
        $stmt = $pdo->prepare("
            SELECT k.*, p.name as nama_pelanggan, p.address as alamat, p.phone as telepon_pelanggan 
            FROM keluhans k 
            LEFT JOIN pelanggans p ON k.no_hpm = p.customer_id 
            WHERE k.id = ?
        ");
        $stmt->execute([$id]);
        $keluhan = $stmt->fetch();

        if ($keluhan) {
            echo json_encode(['success' => true, 'data' => $keluhan]);
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Keluhan tidak ditemukan']);
        }
    }
    catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data: ' . $e->getMessage()]);
    }
}

/**
 * Get count of new (pending) keluhans for badge
 */
function getNewKeluhanCount($pdo)
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM keluhans WHERE status = 'Menunggu'");
        $result = $stmt->fetch();
        echo json_encode(['success' => true, 'count' => intval($result['count'])]);
    }
    catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data']);
    }
}

/**
 * Submit new keluhan (public endpoint)
 */
function submitKeluhan($pdo)
{
    global $FONNTE_TOKEN, $ADMIN_WHATSAPP;

    try {
        // Get form data
        $namaLengkap = isset($_POST['nama_lengkap']) ? trim($_POST['nama_lengkap']) : '';
        $noHpm = isset($_POST['no_hpm']) ? trim($_POST['no_hpm']) : '';
        $kategori = isset($_POST['kategori']) ? trim($_POST['kategori']) : '';
        $detailLaporan = isset($_POST['detail_laporan']) ? trim($_POST['detail_laporan']) : '';
        $noWhatsapp = isset($_POST['no_whatsapp']) ? trim($_POST['no_whatsapp']) : '';

        // Validasi required fields
        if (empty($namaLengkap) || empty($noHpm) || empty($kategori) || empty($detailLaporan) || empty($noWhatsapp)) {
            echo json_encode(['success' => false, 'message' => 'Semua field wajib diisi']);
            return;
        }

        // Validasi No. HPM terdaftar
        $stmt = $pdo->prepare("SELECT id, name FROM pelanggans WHERE customer_id = ?");
        $stmt->execute([$noHpm]);
        $pelanggan = $stmt->fetch();

        if (!$pelanggan) {
            echo json_encode(['success' => false, 'message' => 'No. HPM tidak terdaftar dalam sistem. Pastikan ID pelanggan Anda benar.']);
            return;
        }

        // Validasi kategori
        $validKategori = ['Pipa Bocor', 'Air Tidak Mengalir', 'Meteran Rusak', 'Kesalahan Tagihan', 'Kualitas Air', 'Lainnya'];
        if (!in_array($kategori, $validKategori)) {
            echo json_encode(['success' => false, 'message' => 'Kategori keluhan tidak valid']);
            return;
        }

        // Validasi panjang detail
        if (strlen($detailLaporan) < 10) {
            echo json_encode(['success' => false, 'message' => 'Detail laporan minimal 10 karakter']);
            return;
        }

        // Handle file upload (optional)
        $fotoBukti = null;
        if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/keluhan/';

            // Create directory if not exists
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $file = $_FILES['foto_bukti'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($file['type'], $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => 'Format file tidak valid. Gunakan JPG atau PNG.']);
                return;
            }

            if ($file['size'] > $maxSize) {
                echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 5MB']);
                return;
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'keluhan_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $fotoBukti = 'uploads/keluhan/' . $filename;
            }
        }

        // Insert keluhan
        $stmt = $pdo->prepare("
            INSERT INTO keluhans (nama_lengkap, no_hpm, kategori, detail_laporan, foto_bukti, no_whatsapp)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$namaLengkap, $noHpm, $kategori, $detailLaporan, $fotoBukti, $noWhatsapp]);
        $keluhanId = $pdo->lastInsertId();

        // Send WhatsApp notification to admin (if Fonnte token configured)
        if (!empty($FONNTE_TOKEN)) {
            $message = "🔔 *KELUHAN BARU* #$keluhanId\n\n";
            $message .= "Nama: $namaLengkap\n";
            $message .= "No. HPM: $noHpm\n";
            $message .= "Kategori: $kategori\n";
            $message .= "WhatsApp: $noWhatsapp\n\n";
            $message .= "Detail: $detailLaporan\n\n";
            $message .= "Silakan cek di Dashboard Admin untuk menindaklanjuti.";

            sendWhatsAppNotification($ADMIN_WHATSAPP, $message, $FONNTE_TOKEN);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Laporan Anda telah diterima. Tim HIPPAMS akan segera menghubungi Anda melalui WhatsApp.',
            'id' => $keluhanId
        ]);

    }
    catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan laporan: ' . $e->getMessage()]);
    }
}

/**
 * Update keluhan status (admin)
 */
function updateKeluhanStatus($pdo)
{
    global $FONNTE_TOKEN;

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $id = isset($input['id']) ? intval($input['id']) : 0;
        $status = isset($input['status']) ? trim($input['status']) : '';
        $catatanAdmin = isset($input['catatan_admin']) ? trim($input['catatan_admin']) : '';

        if (!$id || !$status) {
            echo json_encode(['success' => false, 'message' => 'ID dan status diperlukan']);
            return;
        }

        $validStatus = ['Menunggu', 'Diproses', 'Selesai'];
        if (!in_array($status, $validStatus)) {
            echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
            return;
        }

        // Get keluhan data before update for notification
        $stmt = $pdo->prepare("SELECT * FROM keluhans WHERE id = ?");
        $stmt->execute([$id]);
        $keluhan = $stmt->fetch();

        if (!$keluhan) {
            echo json_encode(['success' => false, 'message' => 'Keluhan tidak ditemukan']);
            return;
        }

        $oldStatus = $keluhan['status'];

        // Update status
        $stmt = $pdo->prepare("UPDATE keluhans SET status = ?, catatan_admin = ? WHERE id = ?");
        $stmt->execute([$status, $catatanAdmin, $id]);

        // Send WhatsApp notification to customer if status changed and token is configured
        if (!empty($FONNTE_TOKEN) && $oldStatus !== $status && $status !== 'Menunggu') {
            $customerPhone = formatPhoneNumber($keluhan['no_whatsapp']);
            $namaLengkap = $keluhan['nama_lengkap'];
            $kategori = $keluhan['kategori'];
            $noHpm = $keluhan['no_hpm'];

            if ($status === 'Diproses') {
                $message = "Halo *$namaLengkap*, laporan Anda mengenai *$kategori* sedang diproses oleh tim teknis *HIPPAMS TIRTO JOYO*.\n\n";
                $message .= "📋 No. Laporan: #$id\n";
                $message .= "📌 Status: *SEDANG DIPROSES*\n\n";
                $message .= "Mohon tunggu pembaruan selanjutnya. Terima kasih atas kesabaran Anda.";
            }
            else if ($status === 'Selesai') {
                $message = "✅ *Kabar baik!*\n\n";
                $message .= "Laporan keluhan Anda (HPM: $noHpm) mengenai *$kategori* telah dinyatakan *SELESAI*.\n\n";
                if (!empty($catatanAdmin)) {
                    $message .= "📝 Catatan: $catatanAdmin\n\n";
                }
                $message .= "Terima kasih telah menggunakan layanan HIPPAMS TIRTO JOYO.\n";
                $message .= "Salam, Smart-Air Desa 💧";
            }

            sendWhatsAppNotification($customerPhone, $message, $FONNTE_TOKEN);
        }

        echo json_encode(['success' => true, 'message' => 'Status berhasil diupdate']);

    }
    catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupdate status: ' . $e->getMessage()]);
    }
}

/**
 * Format phone number to international format (628xxx)
 */
function formatPhoneNumber($phone)
{
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);

    // Convert 08xxx to 628xxx
    if (substr($phone, 0, 1) === '0') {
        $phone = '62' . substr($phone, 1);
    }

    // If doesn't start with 62, add it
    if (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }

    return $phone;
}

/**
 * Send WhatsApp notification via Fonnte
 */
function sendWhatsAppNotification($phone, $message, $token)
{
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'target' => $phone,
            'message' => $message,
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $token
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
}
?>
