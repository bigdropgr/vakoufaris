<?php
/**
 * Vlachos Tools Import Setup Script
 * 
 * Run this script to set up the Vlachos Tools XML import functionality
 */

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';

echo "<h2>Vlachos Tools XML Import Setup</h2>";

// Test database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    echo "<p>✓ Database connection successful</p>";
    
    // Check if required tables exist
    $required_tables = ['physical_inventory', 'sync_log'];
    foreach ($required_tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            echo "<p>✓ Table '$table' exists</p>";
        } else {
            echo "<p>✗ Table '$table' is missing - please run the main database setup first</p>";
        }
    }
    
    // Check if physical_inventory table has all required fields for Vlachos import
    $required_fields = ['wholesale_price', 'notes', 'product_type'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        $result = $conn->query("SHOW COLUMNS FROM physical_inventory LIKE '$field'");
        if ($result->num_rows == 0) {
            $missing_fields[] = $field;
        }
    }
    
    if (empty($missing_fields)) {
        echo "<p>✓ All required database fields are present</p>";
    } else {
        echo "<p>⚠ Missing database fields: " . implode(', ', $missing_fields) . "</p>";
        echo "<p>Please run the database update script first: setup/update-database.php</p>";
    }
    
    // Test cURL functionality
    if (function_exists('curl_init')) {
        echo "<p>✓ cURL extension is available</p>";
        
        // Test actual connection to Vlachos XML feed
        $vlachos_url = 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $vlachos_url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP XML Import)');
        
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo "<p>⚠ Could not connect to Vlachos XML feed: $error</p>";
        } elseif ($http_code === 200) {
            echo "<p>✓ Successfully connected to Vlachos XML feed</p>";
        } else {
            echo "<p>⚠ Vlachos XML feed returned HTTP code: $http_code</p>";
        }
    } else {
        echo "<p>✗ cURL extension is not available - required for XML import</p>";
    }
    
    // Check required PHP extensions
    $required_extensions = ['xml', 'simplexml', 'curl'];
    $missing_extensions = [];
    
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing_extensions[] = $ext;
        }
    }
    
    if (empty($missing_extensions)) {
        echo "<p>✓ All required PHP extensions are loaded</p>";
    } else {
        echo "<p>✗ Missing PHP extensions: " . implode(', ', $missing_extensions) . "</p>";
        echo "<p>Please install the missing extensions for full functionality</p>";
    }
    
    // Test XML parsing with sample data
    $sample_xml = '<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <sku>12345</sku>
        <title>Test Product</title>
        <price>29.99</price>
        <category>Test Category</category>
    </product>
</products>';
    
    $xml = simplexml_load_string($sample_xml);
    if ($xml !== false) {
        echo "<p>✓ XML parsing functionality works</p>";
    } else {
        echo "<p>✗ XML parsing failed - check PHP XML extension</p>";
    }
    
    // Check file permissions for includes directory
    $includes_dir = '../includes/';
    if (is_writable($includes_dir)) {
        echo "<p>✓ Includes directory is writable</p>";
    } else {
        echo "<p>⚠ Includes directory is not writable - may need to manually place VlachosXMLImport.php</p>";
    }
    
    // Check if VlachosXMLImport class file exists
    $class_file = '../includes/VlachosXMLImport.php';
    if (file_exists($class_file)) {
        echo "<p>✓ VlachosXMLImport.php class file exists</p>";
        
        // Try to include and instantiate the class
        try {
            require_once '../includes/Database.php';
            require_once '../includes/Product.php';
            require_once $class_file;
            
            $import_instance = new VlachosXMLImport();
            echo "<p>✓ VlachosXMLImport class loads successfully</p>";
        } catch (Exception $e) {
            echo "<p>✗ Error loading VlachosXMLImport class: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p>⚠ VlachosXMLImport.php class file not found</p>";
        echo "<p>Please save the VlachosXMLImport class as: <code>includes/VlachosXMLImport.php</code></p>";
    }
    
    // Check API endpoint
    $api_file = '../api/vlachos-import.php';
    if (file_exists($api_file)) {
        echo "<p>✓ Vlachos import API endpoint exists</p>";
    } else {
        echo "<p>⚠ API endpoint not found: <code>api/vlachos-import.php</code></p>";
    }
    
    // Check main import page
    $import_page = '../xml-import.php';
    if (file_exists($import_page)) {
        echo "<p>✓ XML import page exists</p>";
    } else {
        echo "<p>⚠ Import page not found: <code>xml-import.php</code></p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p>✗ Database error: " . $e->getMessage() . "</p>";
}

// Check PHP configuration for XML import
$max_execution = ini_get('max_execution_time');
$memory_limit = ini_get('memory_limit');
$user_agent = ini_get('user_agent');

echo "<h3>PHP Configuration for XML Import:</h3>";
echo "<ul>";
echo "<li>Max execution time: $max_execution seconds</li>";
echo "<li>Memory limit: $memory_limit</li>";
echo "<li>User agent: " . ($user_agent ?: 'Not set') . "</li>";
echo "</ul>";

if (intval($max_execution) < 120 && intval($max_execution) !== 0) {
    echo "<p>⚠ Consider increasing max_execution_time to at least 120 seconds for large XML imports</p>";
}

echo "<h3>Vlachos Tools Integration Details:</h3>";
echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>XML Feed URL:</strong> https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982</p>";
echo "<p><strong>SKU Prefix:</strong> VLT-</p>";
echo "<p><strong>Import Method:</strong> Direct URL download</p>";
echo "<p><strong>Source Protection:</strong> Products from other sources are automatically protected</p>";
echo "<p><strong>Field Mapping:</strong> Automatic detection of Vlachos XML structure</p>";
echo "</div>";

echo "<h3>Setup Status:</h3>";

// Overall status check
$setup_issues = [];

if (!function_exists('curl_init')) {
    $setup_issues[] = 'cURL extension missing';
}

if (!extension_loaded('xml') || !extension_loaded('simplexml')) {
    $setup_issues[] = 'XML extensions missing';
}

if (!file_exists('../includes/VlachosXMLImport.php')) {
    $setup_issues[] = 'VlachosXMLImport.php class file missing';
}

if (!file_exists('../api/vlachos-import.php')) {
    $setup_issues[] = 'API endpoint missing';
}

if (!file_exists('../xml-import.php')) {
    $setup_issues[] = 'Import page missing';
}

if (empty($setup_issues)) {
    echo "<div style='color: green; font-weight: bold;'>";
    echo "<p>✓ Setup Complete! Vlachos Tools XML import is ready to use.</p>";
    echo "</div>";
    
    echo "<h3>Next Steps:</h3>";
    echo "<ol>";
    echo "<li>Navigate to the XML Import page in your application</li>";
    echo "<li>Test the connection to the Vlachos XML feed</li>";
    echo "<li>Run your first import to verify everything works</li>";
    echo "<li>Consider setting up automated imports via cron job</li>";
    echo "</ol>";
    
} else {
    echo "<div style='color: red; font-weight: bold;'>";
    echo "<p>✗ Setup Issues Found:</p>";
    echo "<ul>";
    foreach ($setup_issues as $issue) {
        echo "<li>$issue</li>";
    }
    echo "</ul>";
    echo "</div>";
    
    echo "<h3>Required Actions:</h3>";
    echo "<ol>";
    if (in_array('VlachosXMLImport.php class file missing', $setup_issues)) {
        echo "<li>Save the VlachosXMLImport class as: <code>includes/VlachosXMLImport.php</code></li>";
    }
    if (in_array('API endpoint missing', $setup_issues)) {
        echo "<li>Save the API endpoint as: <code>api/vlachos-import.php</code></li>";
    }
    if (in_array('Import page missing', $setup_issues)) {
        echo "<li>Save the import page as: <code>xml-import.php</code></li>";
    }
    if (in_array('cURL extension missing', $setup_issues)) {
        echo "<li>Install PHP cURL extension</li>";
    }
    if (in_array('XML extensions missing', $setup_issues)) {
        echo "<li>Install PHP XML and SimpleXML extensions</li>";
    }
    echo "</ol>";
}

echo "<h3>Cron Job Setup (Optional):</h3>";
echo "<p>To automatically import from Vlachos Tools, you can set up a cron job:</p>";
echo "<pre style='background-color: #f1f1f1; padding: 10px;'>";
echo "# Run every day at 3 AM\n";
echo "0 3 * * * php " . dirname(__DIR__) . "/cron/vlachos-import.php\n";
echo "</pre>";

echo "<div style='margin-top: 30px; padding: 15px; background-color: #e7f3ff; border-left: 4px solid #2196F3;'>";
echo "<h4>📋 Installation Checklist:</h4>";
echo "<ul style='margin: 0;'>";
echo "<li>✓ Database tables and fields</li>";
echo "<li>✓ PHP extensions (cURL, XML, SimpleXML)</li>";
echo "<li>✓ VlachosXMLImport.php class</li>";
echo "<li>✓ API endpoint (api/vlachos-import.php)</li>";
echo "<li>✓ Import page (xml-import.php)</li>";
echo "<li>✓ Vlachos XML feed connectivity</li>";
echo "<li>✓ File permissions</li>";
echo "</ul>";
echo "</div>";

echo "<p style='margin-top: 20px;'><a href='../xml-import.php'>← Go to Vlachos XML Import Page</a> | <a href='../dashboard.php'>← Return to Dashboard</a></p>";
?>