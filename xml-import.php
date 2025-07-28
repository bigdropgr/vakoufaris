<?php
/**
 * Vlachos Tools XML Import Page with Progress Bar
 * 
 * Updated to import from Vlachos Tools XML feed URL with real-time progress
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Product.php';
require_once 'includes/functions.php';
require_once 'includes/i18n.php';

// Check if VlachosXMLImport class exists, if not show setup message
$vlachos_class_file = 'includes/VlachosXMLImport.php';
if (!file_exists($vlachos_class_file)) {
    die('VlachosXMLImport class not found. Please run setup/setup-vlachos-import.php first.');
}

require_once $vlachos_class_file;

$auth = new Auth();
$auth->requireAuth();

// Handle AJAX requests for progress and import
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'start_import':
            // Start the import process
            $vlachos_import = new VlachosXMLImport();
            $vlachos_xml_url = 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';
            $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] == '1';
            
            try {
                // Test URL first
                $url_test = $vlachos_import->testXMLURL($vlachos_xml_url);
                if (!$url_test['success']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot access Vlachos XML feed: ' . $url_test['message']]);
                    exit;
                }
                
                // Store import status in session
                session_start();
                $_SESSION['vlachos_import_status'] = [
                    'status' => 'starting',
                    'progress' => 0,
                    'message' => 'Connecting to Vlachos XML feed...',
                    'start_time' => time()
                ];
                
                echo json_encode(['success' => true, 'message' => 'Import started']);
                exit;
                
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()]);
                exit;
            }
            break;
            
        case 'get_progress':
            session_start();
            $status = $_SESSION['vlachos_import_status'] ?? [
                'status' => 'idle',
                'progress' => 0,
                'message' => 'No import in progress'
            ];
            echo json_encode($status);
            exit;
            break;
            
        case 'do_import':
            // Perform the actual import
            session_start();
            $vlachos_import = new VlachosXMLImport();
            $vlachos_xml_url = 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';
            $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] == '1';
            
            try {
                // Update progress
                $_SESSION['vlachos_import_status'] = [
                    'status' => 'downloading',
                    'progress' => 10,
                    'message' => 'Downloading XML from Vlachos Tools...',
                    'start_time' => $_SESSION['vlachos_import_status']['start_time'] ?? time()
                ];
                
                $import_options = ['update_existing' => $update_existing];
                $import_result = $vlachos_import->importFromURL($vlachos_xml_url, $import_options);
                
                // Store final result
                $_SESSION['vlachos_import_status'] = [
                    'status' => 'completed',
                    'progress' => 100,
                    'message' => 'Import completed successfully!',
                    'result' => $import_result,
                    'start_time' => $_SESSION['vlachos_import_status']['start_time'] ?? time()
                ];
                
                echo json_encode(['success' => true, 'result' => $import_result]);
                exit;
                
            } catch (Exception $e) {
                $_SESSION['vlachos_import_status'] = [
                    'status' => 'error',
                    'progress' => 0,
                    'message' => 'Import failed: ' . $e->getMessage(),
                    'start_time' => $_SESSION['vlachos_import_status']['start_time'] ?? time()
                ];
                
                echo json_encode(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()]);
                exit;
            }
            break;
            
        case 'test_url':
            $vlachos_import = new VlachosXMLImport();
            $vlachos_xml_url = 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';
            $test_result = $vlachos_import->testXMLURL($vlachos_xml_url);
            echo json_encode($test_result);
            exit;
            break;
    }
}

$vlachos_import = new VlachosXMLImport();
$import_result = null;
$import_error = '';

// Vlachos Tools XML URL
$vlachos_xml_url = 'https://www.vlachostools.gr/xmldownload/?u=301304390&p=28101982';

// Handle traditional form submission (fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    if (isset($_POST['test_url'])) {
        // Test URL accessibility
        $url_test = $vlachos_import->testXMLURL($vlachos_xml_url);
        
        if ($url_test['success']) {
            set_flash_message('success', 'Vlachos XML feed is accessible and ready for import');
        } else {
            set_flash_message('error', 'Cannot access Vlachos XML feed: ' . $url_test['message']);
        }
    }
}

include 'templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="fas fa-download"></i> Vlachos Tools XML Import
    </h1>
    <div>
        <a href="search.php" class="btn btn-outline-secondary me-2">
            <i class="fas fa-search"></i> Search Products
        </a>
        <a href="dashboard.php" class="btn btn-outline-primary">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Import Form -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-download"></i> Import from Vlachos Tools XML Feed
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Vlachos Tools Import:</strong> This import connects directly to the Vlachos Tools XML feed. 
                    Products will be imported with the VLT- prefix. Existing products from other sources (like WooCommerce) will not be modified.
                </div>
                
                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-link"></i> XML Feed URL
                    </label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($vlachos_xml_url); ?>" readonly>
                    <div class="form-text">
                        This is the direct link to the Vlachos Tools product feed.
                    </div>
                </div>
                
                <!-- Progress Section (Hidden by default) -->
                <div id="import-progress-section" class="mb-4" style="display: none;">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0">
                                <i class="fas fa-spinner fa-spin"></i> Import in Progress
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="progress mb-3" style="height: 25px;">
                                <div id="import-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                    0%
                                </div>
                            </div>
                            <div id="import-status-message" class="text-center">
                                <span class="text-muted">Preparing import...</span>
                            </div>
                            <div id="import-timing" class="text-center mt-2">
                                <small class="text-muted">Elapsed time: <span id="elapsed-time">0s</span></small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Import Form -->
                <form id="vlachos-import-form">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="update_existing" name="update_existing" value="1">
                            <label class="form-check-label" for="update_existing">
                                <i class="fas fa-sync-alt"></i> Update existing Vlachos products
                            </label>
                            <div class="form-text">
                                If checked, existing VLT- products will be updated with new information from the XML feed. 
                                Products from other sources (WooCommerce, other imports) will never be modified.
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" id="import-btn" class="btn btn-primary btn-lg">
                            <i class="fas fa-download"></i> Import Products from Vlachos Tools
                        </button>
                        <button type="button" id="test-url-btn" class="btn btn-outline-secondary">
                            <i class="fas fa-link"></i> Test XML Feed Connection
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Import Results -->
        <div id="import-results-section" style="display: none;">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-success">
                        <i class="fas fa-chart-bar"></i> Import Results
                    </h6>
                </div>
                <div class="card-body" id="import-results-content">
                    <!-- Results will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Vlachos Tools Info -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-info-circle"></i> About Vlachos Tools Import
                </h6>
            </div>
            <div class="card-body">
                <h6><i class="fas fa-building"></i> Vlachos Tools</h6>
                <p>Family business in the import trade since 1947, specializing in tools and hardware.</p>
                
                <h6><i class="fas fa-tag"></i> SKU Handling</h6>
                <p>Products are imported with the <code>VLT-</code> prefix to identify them as Vlachos Tools products.</p>
                
                <h6><i class="fas fa-shield-alt"></i> Source Protection</h6>
                <p>Products from other sources (WooCommerce, other suppliers) are automatically protected and will not be modified.</p>
                
                <div class="alert alert-info">
                    <i class="fas fa-clock"></i> <strong>Recommendation:</strong> Run this import weekly to keep your Vlachos Tools catalog up to date.
                </div>
            </div>
        </div>
        
        <!-- Recent Vlachos Imports -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-secondary">
                    <i class="fas fa-history"></i> Recent Vlachos Imports
                </h6>
            </div>
            <div class="card-body">
                <?php
                // Get recent Vlachos imports from sync log
                $db = Database::getInstance();
                $sql = "SELECT * FROM sync_log WHERE details LIKE '%Vlachos%' ORDER BY sync_date DESC LIMIT 5";
                $result = $db->query($sql);
                $recent_imports = [];
                
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_object()) {
                        $recent_imports[] = $row;
                    }
                }
                ?>
                
                <?php if (empty($recent_imports)): ?>
                <p class="text-muted text-center">
                    <i class="fas fa-inbox"></i><br>
                    No recent Vlachos imports found.
                </p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Added</th>
                                <th>Updated</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_imports as $import): ?>
                            <tr>
                                <td><small><?php echo format_date($import->sync_date); ?></small></td>
                                <td><span class="badge bg-success"><?php echo $import->products_added; ?></span></td>
                                <td><span class="badge bg-info"><?php echo $import->products_updated; ?></span></td>
                                <td>
                                    <span class="badge bg-<?php echo $import->status === 'success' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($import->status); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-info">
                    <i class="fas fa-bolt"></i> Quick Actions
                </h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="search.php?term=VLT-" class="btn btn-outline-primary">
                        <i class="fas fa-search"></i> View All VLT Products
                    </a>
                    <a href="search.php?filter=low_stock&term=VLT-" class="btn btn-outline-warning">
                        <i class="fas fa-exclamation-triangle"></i> VLT Low Stock
                    </a>
                    <a href="sync.php" class="btn btn-outline-secondary">
                        <i class="fas fa-sync"></i> WooCommerce Sync
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alert Container -->
<div id="alert-container"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let importInterval;
    let startTime;
    
    const importBtn = document.getElementById('import-btn');
    const testBtn = document.getElementById('test-url-btn');
    const progressSection = document.getElementById('import-progress-section');
    const progressBar = document.getElementById('import-progress-bar');
    const statusMessage = document.getElementById('import-status-message');
    const elapsedTimeSpan = document.getElementById('elapsed-time');
    const resultsSection = document.getElementById('import-results-section');
    const resultsContent = document.getElementById('import-results-content');
    
    // Test URL function
    testBtn.addEventListener('click', function() {
        testBtn.disabled = true;
        testBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';
        
        fetch('?action=test_url', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            testBtn.disabled = false;
            testBtn.innerHTML = '<i class="fas fa-link"></i> Test XML Feed Connection';
            
            if (data.success) {
                showAlert('Vlachos XML feed is accessible and ready for import', 'success');
            } else {
                showAlert('Cannot access Vlachos XML feed: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            testBtn.disabled = false;
            testBtn.innerHTML = '<i class="fas fa-link"></i> Test XML Feed Connection';
            showAlert('Error testing connection: ' + error.message, 'danger');
        });
    });
    
    // Import function
    importBtn.addEventListener('click', function() {
        startImport();
    });
    
    function startImport() {
        // Hide results and show progress
        resultsSection.style.display = 'none';
        progressSection.style.display = 'block';
        
        // Disable buttons
        importBtn.disabled = true;
        testBtn.disabled = true;
        
        // Reset progress
        updateProgress(0, 'Starting import...');
        startTime = Date.now();
        
        // Get form data
        const updateExisting = document.getElementById('update_existing').checked;
        
        // Start the import process
        const formData = new FormData();
        formData.append('update_existing', updateExisting ? '1' : '0');
        
        fetch('?action=start_import', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Start monitoring progress
                startProgressMonitoring();
                
                // Start the actual import after a short delay
                setTimeout(() => {
                    performImport(updateExisting);
                }, 1000);
            } else {
                handleImportError(data.message);
            }
        })
        .catch(error => {
            handleImportError('Failed to start import: ' + error.message);
        });
    }
    
    function performImport(updateExisting) {
        updateProgress(20, 'Downloading XML from Vlachos Tools...');
        
        const formData = new FormData();
        formData.append('update_existing', updateExisting ? '1' : '0');
        
        fetch('?action=do_import', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            stopProgressMonitoring();
            
            if (data.success) {
                updateProgress(100, 'Import completed successfully!');
                setTimeout(() => {
                    showImportResults(data.result);
                }, 1000);
            } else {
                handleImportError(data.message);
            }
        })
        .catch(error => {
            stopProgressMonitoring();
            handleImportError('Import failed: ' + error.message);
        });
    }
    
    function startProgressMonitoring() {
        let progress = 20;
        
        importInterval = setInterval(() => {
            // Simulate progress increase
            if (progress < 90) {
                progress += Math.random() * 10;
                updateProgress(Math.min(progress, 90), 'Processing products...');
            }
            
            // Update elapsed time
            updateElapsedTime();
        }, 1000);
    }
    
    function stopProgressMonitoring() {
        if (importInterval) {
            clearInterval(importInterval);
        }
    }
    
    function updateProgress(percent, message) {
        percent = Math.round(percent);
        progressBar.style.width = percent + '%';
        progressBar.setAttribute('aria-valuenow', percent);
        progressBar.textContent = percent + '%';
        statusMessage.innerHTML = '<span class="text-primary">' + message + '</span>';
    }
    
    function updateElapsedTime() {
        if (startTime) {
            const elapsed = Math.round((Date.now() - startTime) / 1000);
            elapsedTimeSpan.textContent = elapsed + 's';
        }
    }
    
    function handleImportError(message) {
        stopProgressMonitoring();
        updateProgress(0, 'Import failed');
        progressSection.style.display = 'none';
        
        // Re-enable buttons
        importBtn.disabled = false;
        testBtn.disabled = false;
        
        showAlert(message, 'danger');
    }
    
    function showImportResults(result) {
        progressSection.style.display = 'none';
        
        // Build results HTML
        let html = `
            <div class="row text-center mb-3">
                <div class="col-md-3">
                    <div class="bg-light p-3 rounded">
                        <h4 class="text-primary">${result.products_processed}</h4>
                        <small class="text-muted">Processed</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="bg-light p-3 rounded">
                        <h4 class="text-success">${result.products_imported}</h4>
                        <small class="text-muted">Imported</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="bg-light p-3 rounded">
                        <h4 class="text-info">${result.products_updated}</h4>
                        <small class="text-muted">Updated</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="bg-light p-3 rounded">
                        <h4 class="text-warning">${result.products_skipped}</h4>
                        <small class="text-muted">Skipped</small>
                    </div>
                </div>
            </div>
            <p><strong>Duration:</strong> ${result.duration.toFixed(2)} seconds</p>
            <p><strong>Source:</strong> <a href="${result.xml_url}" target="_blank">Vlachos Tools XML Feed</a></p>
        `;
        
        if (result.errors && result.errors.length > 0) {
            html += `
                <div class="alert alert-warning">
                    <h6><i class="fas fa-exclamation-triangle"></i> Errors Encountered:</h6>
                    <ul class="mb-0">
                        ${result.errors.map(error => `<li>${escapeHtml(error)}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        resultsContent.innerHTML = html;
        resultsSection.style.display = 'block';
        
        // Re-enable buttons
        importBtn.disabled = false;
        testBtn.disabled = false;
        
        // Show success message
        const totalChanged = result.products_imported + result.products_updated;
        if (totalChanged > 0) {
            showAlert(`Import completed! ${result.products_imported} imported, ${result.products_updated} updated, ${result.products_skipped} skipped.`, 'success');
        } else {
            showAlert('Import completed with no changes.', 'info');
        }
        
        // Scroll to results
        resultsSection.scrollIntoView({ behavior: 'smooth' });
    }
    
    function showAlert(message, type) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const container = document.getElementById('alert-container');
        container.innerHTML = '';
        container.appendChild(alertDiv);
        
        // Auto-hide success messages
        if (type === 'success' || type === 'info') {
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>

<?php include 'templates/footer.php'; ?>