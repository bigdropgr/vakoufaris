<?php
/**
 * Vlachos Tools XML Import API
 * 
 * Handles AJAX requests for Vlachos Tools XML import operations
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/Database.php';
require_once '../includes/Auth.php';
require_once '../includes/Product.php';
require_once '../includes/VlachosXMLImport.php';
require_once '../includes/functions.php';

$auth = new Auth();
$vlachos_import = new VlachosXMLImport();

if (!$auth->isLoggedIn()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

switch ($action) {
    case 'test_url':
        $xml_url = isset($_POST['xml_url']) ? $_POST['xml_url'] : 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';
        
        $test_result = $vlachos_import->testXMLURL($xml_url);
        
        json_response([
            'success' => $test_result['success'],
            'message' => $test_result['message'],
            'url' => $xml_url
        ]);
        break;
        
    case 'import':
        $xml_url = isset($_POST['xml_url']) ? $_POST['xml_url'] : 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';
        $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] == '1';
        
        $import_options = [
            'update_existing' => $update_existing
        ];
        
        try {
            $import_result = $vlachos_import->importFromURL($xml_url, $import_options);
            
            json_response([
                'success' => true,
                'result' => $import_result
            ]);
        } catch (Exception $e) {
            json_response([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500);
        }
        break;
        
    case 'get_recent_imports':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        
        if ($limit < 1 || $limit > 50) {
            $limit = 10;
        }
        
        // Get recent Vlachos imports from sync log
        $db = Database::getInstance();
        $sql = "SELECT * FROM sync_log WHERE details LIKE '%Vlachos%' ORDER BY sync_date DESC LIMIT $limit";
        $result = $db->query($sql);
        $imports = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $imports[] = $row;
            }
        }
        
        json_response([
            'success' => true,
            'imports' => $imports,
            'count' => count($imports)
        ]);
        break;
        
    case 'get_vlachos_products':
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        
        if ($page < 1) {
            $page = 1;
        }
        
        if ($per_page < 1 || $per_page > 100) {
            $per_page = 20;
        }
        
        $offset = ($page - 1) * $per_page;
        
        $db = Database::getInstance();
        
        // Build query for VLT products
        $where_conditions = ["sku LIKE 'VLT-%'"];
        $params = [];
        
        if (!empty($search)) {
            $search_escaped = $db->escapeString($search);
            $where_conditions[] = "(title LIKE '%$search_escaped%' OR sku LIKE '%$search_escaped%' OR notes LIKE '%$search_escaped%')";
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Get products
        $sql = "SELECT * FROM physical_inventory WHERE $where_clause ORDER BY title ASC LIMIT $per_page OFFSET $offset";
        $result = $db->query($sql);
        $products = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $products[] = $row;
            }
        }
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM physical_inventory WHERE $where_clause";
        $count_result = $db->query($count_sql);
        $total = 0;
        
        if ($count_result && $count_result->num_rows > 0) {
            $total = $count_result->fetch_object()->total;
        }
        
        json_response([
            'success' => true,
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ]);
        break;
        
    case 'get_vlachos_stats':
        $db = Database::getInstance();
        
        // Count VLT products
        $sql = "SELECT COUNT(*) as count FROM physical_inventory WHERE sku LIKE 'VLT-%'";
        $result = $db->query($sql);
        $total_products = 0;
        
        if ($result && $result->num_rows > 0) {
            $total_products = $result->fetch_object()->count;
        }
        
        // Calculate total value
        $sql = "SELECT SUM(price * stock) as retail_value, SUM(wholesale_price * stock) as wholesale_value FROM physical_inventory WHERE sku LIKE 'VLT-%'";
        $result = $db->query($sql);
        $retail_value = 0;
        $wholesale_value = 0;
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_object();
            $retail_value = $row->retail_value ?? 0;
            $wholesale_value = $row->wholesale_value ?? 0;
        }
        
        // Count low stock VLT products
        $sql = "SELECT COUNT(*) as count FROM physical_inventory WHERE sku LIKE 'VLT-%' AND is_low_stock = 1";
        $result = $db->query($sql);
        $low_stock_count = 0;
        
        if ($result && $result->num_rows > 0) {
            $low_stock_count = $result->fetch_object()->count;
        }
        
        // Get last import date
        $sql = "SELECT sync_date FROM sync_log WHERE details LIKE '%Vlachos%' ORDER BY sync_date DESC LIMIT 1";
        $result = $db->query($sql);
        $last_import = null;
        
        if ($result && $result->num_rows > 0) {
            $last_import = $result->fetch_object()->sync_date;
        }
        
        json_response([
            'success' => true,
            'stats' => [
                'total_products' => $total_products,
                'retail_value' => $retail_value,
                'wholesale_value' => $wholesale_value,
                'low_stock_count' => $low_stock_count,
                'last_import' => $last_import
            ]
        ]);
        break;
        
    case 'download_sample_mapping':
        // Provide information about Vlachos XML structure
        $sample_structure = [
            'vlachos_fields' => [
                'sku' => 'Product SKU/Code',
                'title' => 'Product Name/Title',
                'price' => 'Retail Price (with VAT)',
                'priceWithoutVat' => 'Price without VAT',
                'wholesale_price' => 'Wholesale/Dealer Price',
                'category' => 'Product Category',
                'brand' => 'Brand/Manufacturer',
                'description' => 'Product Description',
                'image' => 'Main Image URL',
                'images' => 'Additional Images',
                'ean' => 'EAN/Barcode',
                'weight' => 'Product Weight',
                'availability' => 'Stock Availability',
                'url' => 'Product URL'
            ],
            'mapping_info' => [
                'prefix' => 'VLT-',
                'source' => 'Vlachos Tools',
                'url' => 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982',
                'notes' => 'Products are automatically mapped from Vlachos XML structure'
            ]
        ];
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="vlachos-field-mapping.json"');
        echo json_encode($sample_structure, JSON_PRETTY_PRINT);
        exit;
        break;
        
    default:
        json_response(['success' => false, 'message' => 'Invalid action'], 400);
        break;
}
?>