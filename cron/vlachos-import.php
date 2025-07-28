<?php
/**
 * Vlachos Tools Automated Import Cron Job
 * 
 * This script runs automatically to import products from Vlachos Tools XML feed
 * 
 * Set up the cron job to run this script daily:
 * 0 3 * * * php /path/to/your/app/cron/vlachos-import.php
 */

// Define CLI mode
define('IS_CLI', php_sapi_name() === 'cli');

// Set unlimited execution time for large imports
set_time_limit(0);

// Enhanced error reporting
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/vlachos-import.log');

// Create logs directory if it doesn't exist
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Log function
function log_message($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Log to file
    file_put_contents(__DIR__ . '/../logs/vlachos-import.log', $log_message, FILE_APPEND);
    
    // Also output to console if running in CLI
    if (IS_CLI) {
        echo $log_message;
    }
}

// Start logging
log_message("=== Starting Vlachos Tools Automated Import ===");

try {
    // Include required files
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/Product.php';
    require_once __DIR__ . '/../includes/VlachosXMLImport.php';
    require_once __DIR__ . '/../includes/functions.php';

    // Initialize the import class
    $vlachos_import = new VlachosXMLImport();
    
    log_message("Vlachos import class initialized successfully");

    // Vlachos Tools XML URL
    $vlachos_xml_url = 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';
    
    // Test connectivity first
    log_message("Testing connectivity to Vlachos XML feed...");
    $connectivity_test = $vlachos_import->testXMLURL($vlachos_xml_url);
    
    if (!$connectivity_test['success']) {
        throw new Exception("Cannot access Vlachos XML feed: " . $connectivity_test['message']);
    }
    
    log_message("Vlachos XML feed is accessible");

    // Get initial stats
    $product = new Product();
    
    // Count current VLT products
    $db = Database::getInstance();
    $sql = "SELECT COUNT(*) as count FROM physical_inventory WHERE sku LIKE 'VLT-%'";
    $result = $db->query($sql);
    $initial_vlt_count = 0;
    
    if ($result && $result->num_rows > 0) {
        $initial_vlt_count = $result->fetch_object()->count;
    }
    
    log_message("Initial VLT product count: $initial_vlt_count");

    $start_time = microtime(true);
    
    // Perform import (update existing products in automated mode)
    log_message("Starting Vlachos XML import with update mode enabled...");
    
    $import_options = [
        'update_existing' => true  // Always update in automated mode
    ];
    
    $result = $vlachos_import->importFromURL($vlachos_xml_url, $import_options);

    $end_time = microtime(true);
    $duration = $end_time - $start_time;

    // Get final stats
    $sql = "SELECT COUNT(*) as count FROM physical_inventory WHERE sku LIKE 'VLT-%'";
    $result_count = $db->query($sql);
    $final_vlt_count = 0;
    
    if ($result_count && $result_count->num_rows > 0) {
        $final_vlt_count = $result_count->fetch_object()->count;
    }

    $net_added = $final_vlt_count - $initial_vlt_count;

    // Output results
    log_message("Vlachos import completed in " . number_format($duration, 2) . " seconds");
    log_message("Products processed: " . $result['products_processed']);
    log_message("Products imported: " . $result['products_imported']);
    log_message("Products updated: " . $result['products_updated']);
    log_message("Products skipped: " . $result['products_skipped']);
    log_message("Total VLT products before import: $initial_vlt_count");
    log_message("Total VLT products after import: $final_vlt_count");
    log_message("Net products added: $net_added");
    log_message("Status: " . $result['status']);

    // Check for errors
    if ($result['status'] === 'error') {
        $error_details = implode("\n", $result['errors']);
        log_message("Import completed with errors: $error_details", 'ERROR');
        
        // Send error notification email
        send_error_notification("Vlachos import completed with errors", $error_details, $result);
        
        exit(1);
    } else {
        log_message("=== Vlachos import completed successfully ===");
        
        // Send success notification if significant changes occurred
        if ($result['products_imported'] > 0 || $result['products_updated'] > 10) {
            send_success_notification($result, $duration);
        }
    }

    exit(0);

} catch (Exception $e) {
    $error_message = "Fatal error during Vlachos import: " . $e->getMessage();
    log_message($error_message, 'FATAL');
    
    // Send error notification
    send_error_notification("Vlachos import failed", $error_message, [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    exit(1);
}

/**
 * Send error notification email
 */
function send_error_notification($subject, $message, $details = []) {
    if (!defined('ADMIN_EMAIL')) {
        log_message("Cannot send error notification - ADMIN_EMAIL not defined", 'WARNING');
        return;
    }
    
    $to = ADMIN_EMAIL;
    $subject = "[" . (defined('SITE_NAME') ? SITE_NAME : 'Inventory System') . "] $subject";
    
    $body = "An error occurred during the Vlachos Tools automated import:\n\n";
    $body .= "Error: $message\n\n";
    
    if (!empty($details)) {
        $body .= "Details:\n";
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $body .= "- $key: $value\n";
        }
        $body .= "\n";
    }
    
    $body .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $body .= "Server: " . ($_SERVER['SERVER_NAME'] ?? 'CLI') . "\n";
    $body .= "\nPlease check the system logs for more information: logs/vlachos-import.log";
    
    $headers = "From: noreply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n";
    $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    if (function_exists('mail')) {
        if (mail($to, $subject, $body, $headers)) {
            log_message("Error notification sent to $to");
        } else {
            log_message("Failed to send error notification to $to", 'WARNING');
        }
    } else {
        log_message("Could not send error notification - mail function not available", 'WARNING');
    }
}

/**
 * Send success notification email for significant changes
 */
function send_success_notification($result, $duration) {
    if (!defined('ADMIN_EMAIL')) {
        return;
    }
    
    $to = ADMIN_EMAIL;
    $subject = "[" . (defined('SITE_NAME') ? SITE_NAME : 'Inventory System') . "] Vlachos import completed - " . $result['products_imported'] . " products imported";
    
    $body = "Vlachos Tools automated import completed successfully!\n\n";
    $body .= "Summary:\n";
    $body .= "- Products processed: " . $result['products_processed'] . "\n";
    $body .= "- Products imported: " . $result['products_imported'] . "\n";
    $body .= "- Products updated: " . $result['products_updated'] . "\n";
    $body .= "- Products skipped: " . $result['products_skipped'] . "\n";
    $body .= "- Duration: " . number_format($duration, 2) . " seconds\n";
    $body .= "- Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    if (!empty($result['errors'])) {
        $body .= "Errors encountered:\n";
        foreach ($result['errors'] as $error) {
            $body .= "- $error\n";
        }
        $body .= "\n";
    }
    
    $body .= "No action required - this is an informational message.\n";
    
    if (defined('SITE_URL')) {
        $body .= "You can view the updated inventory at: " . SITE_URL . "/xml-import.php";
    }
    
    $headers = "From: noreply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n";
    $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    if (function_exists('mail')) {
        if (mail($to, $subject, $body, $headers)) {
            log_message("Success notification sent to $to");
        }
    }
}
?>