<?php
/**
 * API Pelanggan - Smart Air Desa
 * Handles CRUD operations for customers
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
$path = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {
    case 'GET':
        if ($id) {
            // Get single customer
            getCustomer($pdo, $id);
        }
        else {
            // Get all customers
            getCustomers($pdo);
        }
        break;

    case 'POST':
        createCustomer($pdo);
        break;

    case 'PUT':
        if ($id) {
            updateCustomer($pdo, $id);
        }
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID pelanggan diperlukan']);
        }
        break;

    case 'DELETE':
        if ($id) {
            deleteCustomer($pdo, $id);
        }
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID pelanggan diperlukan']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

// Get all customers with Pagination & Stats
function getCustomers($pdo)
{
    try {
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        $type = isset($_GET['type']) ? $_GET['type'] : '';

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;

        $whereSql = " WHERE 1=1";
        $params = [];

        if ($search) {
            $whereSql .= " AND (name LIKE :search OR customer_id LIKE :search2)";
            $params[':search'] = "%$search%";
            $params[':search2'] = "%$search%";
        }

        if ($type) {
            $whereSql .= " AND type = :type";
            $params[':type'] = $type;
        }

        // 1. Get Stats & Count
        $countSql = "SELECT COUNT(*) FROM pelanggans" . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = intval($countStmt->fetchColumn());

        // Get breakdown by type
        $typeStatsSql = "SELECT type, COUNT(*) as count FROM pelanggans GROUP BY type";
        $typeStatsStmt = $pdo->query($typeStatsSql);
        $typeStats = $typeStatsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 2. Get Paginated Data
        $sql = "SELECT * FROM pelanggans" . $whereSql . " ORDER BY address ASC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $customers,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => ceil($totalItems / $limit),
                'current_page' => $page,
                'limit' => $limit
            ],
            'stats' => [
                'total_pelanggan' => $totalItems,
                'breakdown' => [
                    'R1' => intval($typeStats['R1'] ?? 0),
                    'R2' => intval($typeStats['R2'] ?? 0),
                    'N1' => intval($typeStats['N1'] ?? 0),
                    'S1' => intval($typeStats['S1'] ?? 0)
                ]
            ]
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data pelanggan']);
    }
}

// Get single customer
function getCustomer($pdo, $id)
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customer) {
            echo json_encode(['success' => true, 'data' => $customer]);
        }
        else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan']);
        }
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data pelanggan']);
    }
}

// Create new customer
function createCustomer($pdo)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validation
        $errors = [];
        if (empty($input['name']))
            $errors['name'] = 'Nama pelanggan wajib diisi';
        if (empty($input['phone'])) {
            $errors['phone'] = 'No. Handphone wajib diisi';
        }
        elseif (!preg_match('/^\d{10,15}$/', $input['phone'])) {
            $errors['phone'] = 'No. Handphone harus 10-15 digit angka';
        }
        if (empty($input['address']))
            $errors['address'] = 'Alamat wajib diisi';
        if (empty($input['type']) || !in_array($input['type'], ['R1', 'R2', 'N1', 'S1'])) {
            $errors['type'] = 'Golongan tarif tidak valid';
        }

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Validasi gagal', 'errors' => $errors]);
            return;
        }

        // Generate customer_id (format: HPM00001)
        $stmt = $pdo->prepare("SELECT customer_id FROM pelanggans WHERE customer_id LIKE 'HPM%' ORDER BY customer_id DESC LIMIT 1");
        $stmt->execute();
        $lastCustomer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lastCustomer) {
            $lastNumber = (int)substr($lastCustomer['customer_id'], 3); // Extract number after 'HPM'
            $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
        }
        else {
            $newNumber = '00001';
        }
        $customerId = 'HPM' . $newNumber;

        // Insert
        $stmt = $pdo->prepare("INSERT INTO pelanggans (customer_id, name, phone, address, type, created_at, updated_at) VALUES (:customer_id, :name, :phone, :address, :type, NOW(), NOW())");
        $stmt->execute([
            ':customer_id' => $customerId,
            ':name' => $input['name'],
            ':phone' => $input['phone'],
            ':address' => $input['address'],
            ':type' => $input['type']
        ]);

        $newId = $pdo->lastInsertId();

        // Return the created customer
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $newId]);
        $newCustomer = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Pelanggan berhasil ditambahkan',
            'data' => $newCustomer
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan pelanggan: ' . $e->getMessage()]);
    }
}

// Update customer
function updateCustomer($pdo, $id)
{
    try {
        // Check if customer exists
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        // Validation
        $errors = [];
        if (empty($input['name']))
            $errors['name'] = 'Nama pelanggan wajib diisi';
        if (empty($input['phone'])) {
            $errors['phone'] = 'No. Handphone wajib diisi';
        }
        elseif (!preg_match('/^\d{10,15}$/', $input['phone'])) {
            $errors['phone'] = 'No. Handphone harus 10-15 digit angka';
        }
        if (empty($input['address']))
            $errors['address'] = 'Alamat wajib diisi';
        if (empty($input['type']) || !in_array($input['type'], ['R1', 'R2', 'N1', 'S1'])) {
            $errors['type'] = 'Golongan tarif tidak valid';
        }

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Validasi gagal', 'errors' => $errors]);
            return;
        }

        // Update
        $stmt = $pdo->prepare("UPDATE pelanggans SET name = :name, phone = :phone, address = :address, type = :type, updated_at = NOW() WHERE id = :id");
        $stmt->execute([
            ':name' => $input['name'],
            ':phone' => $input['phone'],
            ':address' => $input['address'],
            ':type' => $input['type'],
            ':id' => $id
        ]);

        // Return updated customer
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $updatedCustomer = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Pelanggan berhasil diperbarui',
            'data' => $updatedCustomer
        ]);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui pelanggan']);
    }
}

// Delete customer
function deleteCustomer($pdo, $id)
{
    try {
        // Check if customer exists
        $stmt = $pdo->prepare("SELECT * FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan']);
            return;
        }

        // Check if customer has bills
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tagihans WHERE pelanggan_id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['count'] > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Pelanggan tidak dapat dihapus karena masih memiliki data tagihan']);
            return;
        }

        // Delete
        $stmt = $pdo->prepare("DELETE FROM pelanggans WHERE id = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Pelanggan berhasil dihapus']);
    }
    catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus pelanggan']);
    }
}
?>
