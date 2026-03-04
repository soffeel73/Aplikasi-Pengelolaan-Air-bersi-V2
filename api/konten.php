<?php
/**
 * API Konten - Smart Air Desa
 * CRUD for Berita (News) and Pengumuman (Announcements)
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

// Helper: Normalize image path for cross-environment compatibility
function normalizeImagePath($path)
{
    if (empty($path))
        return $path;
    // Remove /aplikasi-air prefix if exists (for localhost DB data used on production)
    $path = preg_replace('#^/aplikasi-air/#', '/uploads/', $path);
    // Ensure path starts with /uploads/ if it's a local upload
    if (strpos($path, 'http') !== 0 && strpos($path, '/uploads/') !== 0) {
        // Path mungkin sudah relative, cek apakah perlu prefix
        if (strpos($path, 'uploads/') === 0) {
            $path = '/' . $path;
        }
    }
    return $path;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    // ============ BERITA ============
    case 'berita':
        handleBerita($pdo, $method);
        break;
    case 'berita_latest':
        getLatestBerita($pdo);
        break;

    // ============ PENGUMUMAN ============
    case 'pengumuman':
        handlePengumuman($pdo, $method);
        break;
    case 'pengumuman_aktif':
        getActivePengumuman($pdo);
        break;

    // ============ GALLERY ============
    case 'galeri':
        handleGaleri($pdo, $method);
        break;

    // ============ PENGURUS ============
    case 'pengurus':
        handlePengurus($pdo, $method);
        break;

    // ============ UPLOAD ============
    case 'upload':
        // Existing upload handler for News/Generic
        handleUpload();
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ============ PENGURUS FUNCTIONS ============

function handlePengurus($pdo, $method)
{
    switch ($method) {
        case 'GET':
            getPengurus($pdo);
            break;
        case 'POST':
            createPengurus($pdo);
            break;
        case 'PUT':
            updatePengurus($pdo);
            break;
        case 'DELETE':
            deletePengurus($pdo);
            break;
    }
}

function getPengurus($pdo)
{
    try {
        $stmt = $pdo->query("SELECT * FROM pengurus WHERE is_active = TRUE ORDER BY urutan ASC, id ASC");
        $pengurus = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize image paths
        foreach ($pengurus as &$item) {
            if (isset($item['foto_url'])) {
                $item['foto_url'] = normalizeImagePath($item['foto_url']);
            }
        }

        echo json_encode(['success' => true, 'data' => $pengurus]);
    }
    catch (PDOException $e) {
        // Table might not exist yet, return empty
        echo json_encode(['success' => true, 'data' => []]);
    }
}

function createPengurus($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['nama']) || empty($data['jabatan'])) {
        echo json_encode(['success' => false, 'message' => 'Nama dan jabatan harus diisi']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO pengurus (nama, jabatan, foto_url, urutan, is_active)
            VALUES (:nama, :jabatan, :foto_url, :urutan, TRUE)
        ");
        $stmt->execute([
            ':nama' => $data['nama'],
            ':jabatan' => $data['jabatan'],
            ':foto_url' => $data['foto_url'] ?? null,
            ':urutan' => $data['urutan'] ?? 0
        ]);

        echo json_encode(['success' => true, 'message' => 'Pengurus berhasil ditambahkan', 'id' => $pdo->lastInsertId()]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan pengurus: ' . $e->getMessage()]);
    }
}

function updatePengurus($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE pengurus SET 
                nama = :nama,
                jabatan = :jabatan,
                foto_url = :foto_url,
                urutan = :urutan,
                is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':nama' => $data['nama'],
            ':jabatan' => $data['jabatan'],
            ':foto_url' => $data['foto_url'] ?? null,
            ':urutan' => $data['urutan'] ?? 0,
            ':is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true
        ]);

        echo json_encode(['success' => true, 'message' => 'Pengurus berhasil diperbarui']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui pengurus']);
    }
}

function deletePengurus($pdo)
{
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM pengurus WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Pengurus berhasil dihapus']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus pengurus']);
    }
}

// ============ GALLERY FUNCTIONS ============

function handleGaleri($pdo, $method)
{
    switch ($method) {
        case 'GET':
            getGaleri($pdo);
            break;
        case 'POST':
            createGaleri($pdo); // Also handles upload
            break;
        case 'PUT':
            updateGaleri($pdo);
            break;
        case 'DELETE':
            deleteGaleri($pdo);
            break;
    }
}

function getGaleri($pdo)
{
    try {
        $stmt = $pdo->query("SELECT * FROM galeris ORDER BY created_at DESC");
        $galeri = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize image paths for cross-environment compatibility
        foreach ($galeri as &$item) {
            if (isset($item['image_path'])) {
                $item['image_path'] = normalizeImagePath($item['image_path']);
            }
        }

        echo json_encode(['success' => true, 'data' => $galeri]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data galeri']);
    }
}

function createGaleri($pdo)
{
    // Check if it's a file upload request or JSON data
    // Since we want drag-and-drop upload + metadata, we likely use FormData

    if (isset($_FILES['image'])) {
        handleUploadGaleri($pdo);
    }
    else {
        // Fallback for JSON only (if image path is already known/handled separately)
        $data = json_decode(file_get_contents('php://input'), true);
    // ... (implementation if needed, but primary is upload)
    }
}

function handleUploadGaleri($pdo)
{
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload gagal']);
        return;
    }

    $file = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Tipe file tidak didukung (JPG, PNG, GIF, WebP only)']);
        return;
    }

    // Generate unique filename
    $uploadDir = __DIR__ . '/../uploads/gallery/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'gallery_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    // Resize/Optimize (Native PHP GD as fallback since intervention isn't installed)
    // For simplicity efficiently move file first, can implement resize later if needed
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Use relative path without /aplikasi-air/ for production compatibility
        $url = '/uploads/gallery/' . $filename;

        // Save to DB
        try {
            $judul = $_POST['judul'] ?? null;
            $caption = $_POST['caption'] ?? null;
            $kategori = $_POST['kategori'] ?? 'Kegiatan';

            $stmt = $pdo->prepare("
                INSERT INTO galeris (image_path, judul, caption, kategori, is_active)
                VALUES (:image_path, :judul, :caption, :kategori, TRUE)
            ");
            $stmt->execute([
                ':image_path' => $url,
                ':judul' => $judul,
                ':caption' => $caption,
                ':kategori' => $kategori
            ]);

            echo json_encode(['success' => true, 'message' => 'Foto berhasil diupload', 'data' => [
                    'id' => $pdo->lastInsertId(),
                    'image_path' => $url,
                    'judul' => $judul
                ]]);
        }
        catch (PDOException $e) {
            // Delete file if DB insert fails
            unlink($filepath);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file ke server']);
    }
}

function updateGaleri($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE galeris SET 
                judul = :judul,
                caption = :caption,
                kategori = :kategori,
                is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':judul' => $data['judul'] ?? null,
            ':caption' => $data['caption'] ?? null,
            ':kategori' => $data['kategori'] ?? 'Kegiatan',
            ':is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true
        ]);

        echo json_encode(['success' => true, 'message' => 'Data galeri diperbarui']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal update database']);
    }
}

function deleteGaleri($pdo)
{
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }

    // Get file path first to delete file
    try {
        $stmt = $pdo->prepare("SELECT image_path FROM galeris WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            // Delete from DB
            $delStmt = $pdo->prepare("DELETE FROM galeris WHERE id = :id");
            $delStmt->execute([':id' => $id]);

            // Delete file if exists
            // Handle both old format (/aplikasi-air/uploads/...) and new format (/uploads/...)
            $imagePath = $item['image_path'];
            $relativePath = preg_replace('#^/aplikasi-air/#', '', $imagePath);
            $relativePath = preg_replace('#^/#', '', $relativePath); // Remove leading slash
            $absolutePath = __DIR__ . '/../' . $relativePath;

            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }

            echo json_encode(['success' => true, 'message' => 'Foto berhasil dihapus']);
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
        }
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus data']);
    }
}

// ============ BERITA FUNCTIONS ============

function handleBerita($pdo, $method)
{
    switch ($method) {
        case 'GET':
            getBerita($pdo);
            break;
        case 'POST':
            createBerita($pdo);
            break;
        case 'PUT':
            updateBerita($pdo);
            break;
        case 'DELETE':
            deleteBerita($pdo);
            break;
    }
}

function getBerita($pdo)
{
    try {
        $stmt = $pdo->query("SELECT * FROM beritas ORDER BY tanggal_publish DESC, id DESC");
        $berita = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize image paths
        foreach ($berita as &$item) {
            if (isset($item['foto_url'])) {
                $item['foto_url'] = normalizeImagePath($item['foto_url']);
            }
        }

        echo json_encode(['success' => true, 'data' => $berita]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data berita']);
    }
}

function getLatestBerita($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT * FROM beritas 
            WHERE status = 'published' 
            ORDER BY tanggal_publish DESC, id DESC 
            LIMIT 3
        ");
        $berita = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize image paths
        foreach ($berita as &$item) {
            if (isset($item['foto_url'])) {
                $item['foto_url'] = normalizeImagePath($item['foto_url']);
            }
        }

        echo json_encode(['success' => true, 'data' => $berita]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil berita terbaru']);
    }
}

function createBerita($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['judul'])) {
        echo json_encode(['success' => false, 'message' => 'Judul berita harus diisi']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO beritas (judul, foto_url, ringkasan, isi_berita, tanggal_publish, status)
            VALUES (:judul, :foto_url, :ringkasan, :isi_berita, :tanggal_publish, :status)
        ");
        $stmt->execute([
            ':judul' => $data['judul'],
            ':foto_url' => $data['foto_url'] ?? null,
            ':ringkasan' => $data['ringkasan'] ?? null,
            ':isi_berita' => $data['isi_berita'] ?? null,
            ':tanggal_publish' => $data['tanggal_publish'] ?? date('Y-m-d'),
            ':status' => $data['status'] ?? 'published'
        ]);

        echo json_encode(['success' => true, 'message' => 'Berita berhasil ditambahkan', 'id' => $pdo->lastInsertId()]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan berita: ' . $e->getMessage()]);
    }
}

function updateBerita($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID berita tidak valid']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE beritas SET 
                judul = :judul,
                foto_url = :foto_url,
                ringkasan = :ringkasan,
                isi_berita = :isi_berita,
                tanggal_publish = :tanggal_publish,
                status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':judul' => $data['judul'],
            ':foto_url' => $data['foto_url'] ?? null,
            ':ringkasan' => $data['ringkasan'] ?? null,
            ':isi_berita' => $data['isi_berita'] ?? null,
            ':tanggal_publish' => $data['tanggal_publish'] ?? date('Y-m-d'),
            ':status' => $data['status'] ?? 'published'
        ]);

        echo json_encode(['success' => true, 'message' => 'Berita berhasil diperbarui']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui berita']);
    }
}

function deleteBerita($pdo)
{
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID berita tidak valid']);
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM beritas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Berita berhasil dihapus']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus berita']);
    }
}

// ============ PENGUMUMAN FUNCTIONS ============

function handlePengumuman($pdo, $method)
{
    switch ($method) {
        case 'GET':
            getPengumuman($pdo);
            break;
        case 'POST':
            createPengumuman($pdo);
            break;
        case 'PUT':
            updatePengumuman($pdo);
            break;
        case 'DELETE':
            deletePengumuman($pdo);
            break;
    }
}

function getPengumuman($pdo)
{
    try {
        $stmt = $pdo->query("SELECT * FROM pengumumans ORDER BY created_at DESC");
        $pengumuman = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $pengumuman]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data pengumuman']);
    }
}

function getActivePengumuman($pdo)
{
    try {
        $stmt = $pdo->query("SELECT * FROM pengumumans WHERE status = 'aktif' ORDER BY created_at DESC");
        $pengumuman = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $pengumuman]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil pengumuman aktif']);
    }
}

function createPengumuman($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['teks'])) {
        echo json_encode(['success' => false, 'message' => 'Teks pengumuman harus diisi']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO pengumumans (teks, status)
            VALUES (:teks, :status)
        ");
        $stmt->execute([
            ':teks' => $data['teks'],
            ':status' => $data['status'] ?? 'aktif'
        ]);

        echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil ditambahkan', 'id' => $pdo->lastInsertId()]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan pengumuman']);
    }
}

function updatePengumuman($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID pengumuman tidak valid']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE pengumumans SET teks = :teks, status = :status WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':teks' => $data['teks'],
            ':status' => $data['status'] ?? 'aktif'
        ]);

        echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil diperbarui']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui pengumuman']);
    }
}

function deletePengumuman($pdo)
{
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID pengumuman tidak valid']);
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM pengumumans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil dihapus']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus pengumuman']);
    }
}

// ============ UPLOAD FUNCTION ============

function handleUpload()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload gagal']);
        return;
    }

    $file = $_FILES['foto'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($file['type'], $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Tipe file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP']);
        return;
    }

    $uploadDir = __DIR__ . '/../uploads/berita/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'berita_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Use relative path without /aplikasi-air/ for production compatibility
        $url = '/uploads/berita/' . $filename;
        echo json_encode(['success' => true, 'url' => $url, 'filename' => $filename]);
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file']);
    }
}
?>
