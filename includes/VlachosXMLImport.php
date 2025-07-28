<?php
/**
 * Fixed Vlachos Tools XML Import Class
 * 
 * This version prevents importing products that already exist with VLT- prefix
 */

class VlachosXMLImport {
    private $db;
    private $product;
    private $source_identifier = 'vlachos';
    private $import_log = [];
    private $next_available_product_id = 1;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->product = new Product();
        
        // Get the next available product_id to avoid conflicts
        $this->initializeProductIdCounter();
    }
    
    /**
     * Initialize the product ID counter to avoid duplicates
     */
    private function initializeProductIdCounter() {
        try {
            // Find the highest product_id in use
            $sql = "SELECT MAX(product_id) as max_id FROM physical_inventory";
            $result = $this->db->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_object();
                $this->next_available_product_id = max(($row->max_id ?? 0) + 1, 100000); // Start from 100000 for Vlachos products
            } else {
                $this->next_available_product_id = 100000;
            }
            
            error_log("VlachosXMLImport: Starting product_id counter at " . $this->next_available_product_id);
        } catch (Exception $e) {
            error_log("VlachosXMLImport: Error initializing product ID counter: " . $e->getMessage());
            $this->next_available_product_id = 100000; // Safe fallback
        }
    }
    
    /**
     * Get the next available product ID
     */
    private function getNextProductId() {
        return $this->next_available_product_id++;
    }
    
    /**
     * Import products from Vlachos Tools XML URL
     */
    public function importFromURL($xml_url, $options = []) {
        $results = [
            'status' => 'success',
            'products_processed' => 0,
            'products_imported' => 0,
            'products_skipped' => 0,
            'products_updated' => 0,
            'errors' => [],
            'skipped_products' => [],
            'imported_products' => [],
            'updated_products' => [],
            'start_time' => microtime(true),
            'end_time' => null,
            'xml_url' => $xml_url
        ];
        
        try {
            error_log("VlachosXMLImport: Starting import from URL: $xml_url");
            
            // Download XML from URL
            $xml_content = $this->downloadXMLFromURL($xml_url);
            if (!$xml_content) {
                throw new Exception("Failed to download XML from URL: $xml_url");
            }
            
            error_log("VlachosXMLImport: Downloaded XML, size: " . strlen($xml_content) . " bytes");
            
            // Parse XML
            $xml = $this->parseXML($xml_content);
            if (!$xml) {
                throw new Exception("Could not parse XML content");
            }
            
            error_log("VlachosXMLImport: XML parsed successfully");
            
            // Extract products from XML
            $xml_products = $this->extractVlachosProducts($xml, $options);
            $results['products_processed'] = count($xml_products);
            
            error_log("VlachosXMLImport: Extracted " . count($xml_products) . " products from XML");
            
            // Process each product
            $processed_count = 0;
            foreach ($xml_products as $xml_product) {
                $processed_count++;
                
                try {
                    $import_result = $this->processVlachosProduct($xml_product, $options);
                    
                    switch ($import_result['action']) {
                        case 'imported':
                            $results['products_imported']++;
                            $results['imported_products'][] = $import_result['product'];
                            error_log("VlachosXMLImport: Imported product: " . $import_result['product']['sku']);
                            break;
                        case 'updated':
                            $results['products_updated']++;
                            $results['updated_products'][] = $import_result['product'];
                            error_log("VlachosXMLImport: Updated product: " . $import_result['product']['sku']);
                            break;
                        case 'skipped':
                            $results['products_skipped']++;
                            $results['skipped_products'][] = $import_result['product'];
                            error_log("VlachosXMLImport: Skipped product: " . $import_result['product']['sku'] . " - " . $import_result['reason']);
                            break;
                    }
                    
                    // Log progress every 100 products
                    if ($processed_count % 100 === 0) {
                        error_log("VlachosXMLImport: Processed $processed_count/" . count($xml_products) . " products");
                    }
                    
                } catch (Exception $e) {
                    $error_msg = "Error processing product {$xml_product['sku']}: " . $e->getMessage();
                    $results['errors'][] = $error_msg;
                    error_log("VlachosXMLImport: $error_msg");
                }
            }
            
            // Log the import
            $this->logVlachosImport($results);
            
        } catch (Exception $e) {
            $results['status'] = 'error';
            $error_msg = $e->getMessage();
            $results['errors'][] = $error_msg;
            error_log("VlachosXMLImport: Fatal Error: $error_msg");
        }
        
        $results['end_time'] = microtime(true);
        $results['duration'] = $results['end_time'] - $results['start_time'];
        
        error_log("VlachosXMLImport: Import completed. Status: {$results['status']}, Imported: {$results['products_imported']}, Updated: {$results['products_updated']}, Skipped: {$results['products_skipped']}");
        
        return $results;
    }
    
    /**
     * Download XML content from URL - Enhanced version
     */
    private function downloadXMLFromURL($url) {
        error_log("VlachosXMLImport: Downloading XML from $url");
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/xml, text/xml, application/rss+xml, */*',
            'Accept-Language: en-US,en;q=0.9,el;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'DNT: 1'
        ]);
        
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, '');
        curl_setopt($ch, CURLOPT_COOKIEFILE, '');
        
        $xml_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        
        curl_close($ch);
        
        if ($errno !== 0) {
            throw new Exception("cURL Error ($errno): $error");
        }
        
        if ($http_code !== 200) {
            throw new Exception("HTTP Error $http_code when accessing XML URL. Content-Type: $content_type");
        }
        
        if (empty($xml_content)) {
            throw new Exception("Empty XML content received from URL");
        }
        
        $is_xml_content = strpos($xml_content, '<?xml') !== false || strpos($xml_content, '<') === 0;
        
        if (!$is_xml_content) {
            throw new Exception("Response doesn't appear to be XML. Content-Type: $content_type. First 200 chars: " . substr($xml_content, 0, 200));
        }
        
        error_log("VlachosXMLImport: XML downloaded successfully. Size: " . strlen($xml_content) . " bytes, Content-Type: $content_type");
        
        return $xml_content;
    }
    
    /**
     * Parse XML content with enhanced error handling
     */
    private function parseXML($xml_content) {
        libxml_use_internal_errors(true);
        
        try {
            $xml_content = trim($xml_content);
            if (substr($xml_content, 0, 3) === "\xEF\xBB\xBF") {
                $xml_content = substr($xml_content, 3);
            }
            
            $xml = simplexml_load_string($xml_content, 'SimpleXMLElement', LIBXML_NOCDATA);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $error_messages = [];
                foreach ($errors as $error) {
                    $error_messages[] = trim($error->message);
                }
                throw new Exception("XML Parse Error: " . implode(", ", $error_messages));
            }
            
            return $xml;
            
        } catch (Exception $e) {
            throw new Exception("Failed to parse XML: " . $e->getMessage());
        } finally {
            libxml_clear_errors();
        }
    }
    
    /**
     * Extract products from Vlachos Tools XML structure - Enhanced
     */
    private function extractVlachosProducts($xml, $options = []) {
        $products = [];
        
        error_log("VlachosXMLImport: XML Root Element: " . $xml->getName());
        
        // Try different possible structures for Vlachos XML
        $product_nodes = null;
        
        // Check for various possible XML structures
        $possible_structures = [
            'products->product',
            'product',
            'items->item',
            'item',
            'store->products->product',
            'catalog->products->product'
        ];
        
        foreach ($possible_structures as $structure) {
            error_log("VlachosXMLImport: Trying structure: $structure");
            
            $parts = explode('->', $structure);
            $current = $xml;
            
            foreach ($parts as $part) {
                if (isset($current->$part)) {
                    $current = $current->$part;
                } else {
                    $current = null;
                    break;
                }
            }
            
            if ($current && count($current) > 0) {
                $product_nodes = $current;
                error_log("VlachosXMLImport: Found products using structure: $structure with " . count($product_nodes) . " items");
                break;
            }
        }
        
        if (!$product_nodes) {
            // Try to find any repeating element that might be products
            $children = $xml->children();
            error_log("VlachosXMLImport: XML children found: " . implode(', ', array_keys(iterator_to_array($children))));
            
            foreach ($children as $child_name => $child_elements) {
                if (count($child_elements) > 1) {
                    $product_nodes = $child_elements;
                    error_log("VlachosXMLImport: Using repeating elements: $child_name with " . count($product_nodes) . " items");
                    break;
                }
            }
        }
        
        if (!$product_nodes) {
            throw new Exception("Could not identify product nodes in Vlachos XML structure. Root element: " . $xml->getName());
        }
        
        $product_count = count($product_nodes);
        error_log("VlachosXMLImport: Found $product_count product nodes in Vlachos XML");
        
        if ($product_count === 0) {
            throw new Exception("No products found in XML feed");
        }
        
        $extracted_count = 0;
        foreach ($product_nodes as $product_node) {
            $product_data = $this->extractVlachosProductData($product_node);
            
            if ($product_data && !empty($product_data['sku'])) {
                $products[] = $product_data;
                $extracted_count++;
            } else {
                error_log("VlachosXMLImport: Skipped product node - missing SKU or invalid data");
            }
        }
        
        error_log("VlachosXMLImport: Successfully extracted $extracted_count products from $product_count nodes");
        return $products;
    }
    
    /**
     * Extract individual product data from Vlachos XML node - Enhanced
     */
    private function extractVlachosProductData($product_node) {
        $product = [];
        
        // Enhanced field mappings for Vlachos XML
        $field_mappings = [
            // Basic product info
            'sku' => ['sku', 'code', 'productId', 'id', 'mpn', 'model', 'itemcode', 'item_code', 'product_code'],
            'title' => ['title', 'name', 'productName', 'description', 'product_name', 'product_title', 'item_name'],
            'description' => ['description', 'desc', 'longDescription', 'details', 'long_description', 'summary'],
            
            // Pricing
            'price' => ['price', 'priceWithVat', 'retailPrice', 'sellingPrice', 'price_with_vat', 'retail_price', 'sale_price'],
            'price_without_vat' => ['priceWithoutVat', 'netPrice', 'basePrice', 'price_without_vat', 'net_price', 'wholesale_price'],
            'wholesale_price' => ['wholesalePrice', 'costPrice', 'buyPrice', 'dealerPrice', 'wholesale_price', 'cost_price', 'dealer_price'],
            
            // Stock and availability
            'availability' => ['availability', 'inStock', 'available', 'stock', 'in_stock', 'qty', 'quantity'],
            'quantity' => ['quantity', 'qty', 'stock', 'stock_quantity', 'amount'],
            
            // Categorization
            'category' => ['category', 'categoryPath', 'categoryName', 'group', 'category_name', 'cat', 'categories'],
            'brand' => ['brand', 'manufacturer', 'supplier', 'brand_name', 'make'],
            
            // Technical data
            'weight' => ['weight', 'weight_kg', 'weight_gr', 'mass'],
            'ean' => ['ean', 'barcode', 'gtin', 'ean13', 'upc'],
            
            // Images
            'image' => ['image', 'imageUrl', 'img', 'picture', 'image_url', 'main_image', 'photo'],
            'images' => ['images', 'imagesUrl', 'image_urls', 'additional_images', 'photos'],
            
            // Additional info
            'url' => ['url', 'productUrl', 'link', 'product_url', 'permalink'],
            'shipping' => ['shipping', 'shippingCost', 'shipping_cost', 'delivery_cost']
        ];
        
        foreach ($field_mappings as $our_field => $xml_fields) {
            foreach ($xml_fields as $xml_field) {
                if (isset($product_node->$xml_field)) {
                    $value = (string)$product_node->$xml_field;
                    if (!empty(trim($value))) {
                        $product[$our_field] = trim($value);
                        break;
                    }
                }
            }
        }
        
        // Handle special cases and nested structures
        if (isset($product_node->images)) {
            $images = [];
            foreach ($product_node->images->children() as $img) {
                $img_url = (string)$img;
                if (!empty($img_url)) {
                    $images[] = $img_url;
                }
            }
            if (!empty($images)) {
                $product['image'] = $images[0];
                if (count($images) > 1) {
                    $product['additional_images'] = array_slice($images, 1);
                }
            }
        }
        
        // Category path handling
        if (isset($product['category'])) {
            $category_separators = ['->', '>', '/', '|', ',', ' > '];
            foreach ($category_separators as $separator) {
                if (strpos($product['category'], $separator) !== false) {
                    $categories = array_map('trim', explode($separator, $product['category']));
                    $product['category'] = end($categories);
                    $product['category_path'] = implode($separator, $categories);
                    break;
                }
            }
        }
        
        // Ensure we have minimum required fields
        if (empty($product['sku']) || empty($product['title'])) {
            return null;
        }
        
        // Clean and validate data
        $product['sku'] = trim($product['sku']);
        $product['title'] = trim($product['title']);
        $product['price'] = isset($product['price']) ? $this->parsePrice($product['price']) : 0;
        $product['wholesale_price'] = isset($product['wholesale_price']) ? $this->parsePrice($product['wholesale_price']) : 0;
        
        // If no wholesale price but we have price without VAT, use that
        if ($product['wholesale_price'] == 0 && isset($product['price_without_vat'])) {
            $product['wholesale_price'] = $this->parsePrice($product['price_without_vat']);
        }
        
        // Set default category if missing
        if (empty($product['category'])) {
            $product['category'] = 'Vlachos Tools';
        }
        
        return $product;
    }
    
    /**
     * Parse price string to float
     */
    private function parsePrice($price_string) {
        $price_string = str_replace(['€', '$', '£', '₽', ' '], '', $price_string);
        $price_string = str_replace(',', '.', $price_string);
        preg_match('/[\d.]+/', $price_string, $matches);
        return isset($matches[0]) ? floatval($matches[0]) : 0;
    }
    
    /**
     * Process a single product from Vlachos XML - FIXED VERSION
     */
    private function processVlachosProduct($xml_product, $options = []) {
        $original_sku = $xml_product['sku']; // Original SKU from XML (e.g., "04841")
        $vlt_sku = 'VLT-' . $original_sku;   // VLT version to check (e.g., "VLT-04841")
        
        // Check if a product with VLT- prefix already exists (imported from WooCommerce)
        $existing_vlt = $this->findExistingProduct($vlt_sku);
        
        // If a product with VLT- prefix exists, skip this import (it's already in WooCommerce)
        if ($existing_vlt) {
            return [
                'action' => 'skipped',
                'reason' => 'Product already exists in WooCommerce (found VLT- version)',
                'product' => [
                    'sku' => $original_sku,
                    'vlt_sku' => $vlt_sku,
                    'title' => $xml_product['title'],
                    'existing_id' => $existing_vlt->id,
                    'existing_sku' => $existing_vlt->sku
                ]
            ];
        }
        
        // Check if product with original SKU exists (previous XML import)
        $existing_original = $this->findExistingProduct($original_sku);
        
        if ($existing_original) {
            // Product exists from previous XML import
            if (isset($options['update_existing']) && $options['update_existing']) {
                return $this->updateExistingVlachosProduct($existing_original, $xml_product, $options);
            } else {
                return [
                    'action' => 'skipped',
                    'reason' => 'Product already imported from XML',
                    'product' => [
                        'sku' => $original_sku,
                        'title' => $xml_product['title'],
                        'existing_id' => $existing_original->id
                    ]
                ];
            }
        } else {
            // Product doesn't exist in any form - import it with original SKU (no VLT- prefix)
            return $this->importNewVlachosProduct($xml_product, $options);
        }
    }
    
    /**
     * Find existing product by SKU
     */
    private function findExistingProduct($sku) {
        $escaped_sku = $this->db->escapeString($sku);
        $sql = "SELECT * FROM physical_inventory WHERE sku = '$escaped_sku' LIMIT 1";
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_object();
        }
        
        return null;
    }
    
    /**
     * Check if product is from a different source
     */
    private function isFromDifferentSource($product) {
        // Check if the product has notes indicating it's from WooCommerce sync
        if (strpos($product->notes, 'WooCommerce') !== false || 
            strpos($product->notes, 'Variable product') !== false ||
            !empty($product->product_id)) {
            return true;
        }
        
        // Check if it's from a different XML import source
        if (strpos($product->notes, 'XML Import') !== false && 
            strpos($product->notes, 'Vlachos') === false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get product source identifier
     */
    private function getProductSource($product) {
        if (!empty($product->product_id)) {
            return 'WooCommerce';
        }
        
        if (strpos($product->notes, 'XML Import') !== false) {
            if (strpos($product->notes, 'Vlachos') !== false) {
                return 'Vlachos XML';
            }
            return 'Other XML';
        }
        
        return 'Unknown';
    }
    
    /**
     * Import new product from Vlachos data - FIXED VERSION WITHOUT VLT- PREFIX
     */
    private function importNewVlachosProduct($xml_product, $options = []) {
        $sku = $xml_product['sku']; // Use original SKU without prefix
        
        // Use a unique product_id that won't conflict with WooCommerce
        $unique_product_id = $this->getNextProductId();
        
        $product_data = [
            'product_id' => $unique_product_id,
            'title' => $xml_product['title'],
            'sku' => $sku, // Original SKU, no VLT- prefix
            'category' => $xml_product['category'] ?? 'Vlachos Tools',
            'price' => 0, // Set retail price to 0
            'wholesale_price' => $xml_product['price'] ?? 0, // XML price goes to wholesale
            'stock' => 0, // Default stock
            'image_url' => $xml_product['image'] ?? '',
            'low_stock_threshold' => DEFAULT_LOW_STOCK_THRESHOLD,
            'notes' => $this->buildProductNotes($xml_product),
            'product_type' => 'simple'
        ];
        
        // Import the product
        $product_id = $this->product->add($product_data);
        
        if ($product_id) {
            return [
                'action' => 'imported',
                'product' => [
                    'id' => $product_id,
                    'sku' => $sku,
                    'original_sku' => $xml_product['sku'],
                    'title' => $xml_product['title'],
                    'price' => 0,
                    'wholesale_price' => $product_data['wholesale_price'],
                    'category' => $product_data['category'],
                    'product_id' => $unique_product_id
                ]
            ];
        } else {
            throw new Exception("Failed to import product with SKU: $sku");
        }
    }
    
    /**
     * Update existing Vlachos product
     */
    private function updateExistingVlachosProduct($existing_product, $xml_product, $options = []) {
        $update_data = [];
        
        // Update title if different
        if ($existing_product->title !== $xml_product['title']) {
            $update_data['title'] = $xml_product['title'];
        }
        
        // Update prices if they have changed
        $new_price = $xml_product['price'] ?? 0;
        $new_wholesale_price = $xml_product['wholesale_price'] ?? 0;
        
        if ($existing_product->price != $new_price) {
            $update_data['price'] = $new_price;
        }
        
        if ($existing_product->wholesale_price != $new_wholesale_price) {
            $update_data['wholesale_price'] = $new_wholesale_price;
        }
        
        // Update category if different
        $new_category = $xml_product['category'] ?? 'Vlachos Tools';
        if ($existing_product->category !== $new_category) {
            $update_data['category'] = $new_category;
        }
        
        // Update image if provided and different
        $new_image = $xml_product['image'] ?? '';
        if (!empty($new_image) && $existing_product->image_url !== $new_image) {
            $update_data['image_url'] = $new_image;
        }
        
        // Update notes with import info
        $import_note = "\n\nUpdated from Vlachos XML on " . date('Y-m-d H:i:s');
        $update_data['notes'] = $existing_product->notes . $import_note;
        
        // Only update if there are changes
        if (!empty($update_data)) {
            $success = $this->product->update($existing_product->id, $update_data);
            
            if ($success) {
                return [
                    'action' => 'updated',
                    'product' => [
                        'id' => $existing_product->id,
                        'sku' => $existing_product->sku,
                        'original_sku' => $xml_product['sku'],
                        'title' => $xml_product['title'],
                        'changes' => array_keys($update_data)
                    ]
                ];
            } else {
                throw new Exception("Failed to update product with SKU: " . $existing_product->sku);
            }
        } else {
            return [
                'action' => 'skipped',
                'reason' => 'No changes detected',
                'product' => [
                    'id' => $existing_product->id,
                    'sku' => $existing_product->sku,
                    'title' => $existing_product->title
                ]
            ];
        }
    }
    
    /**
     * Build product notes from XML data
     */
    private function buildProductNotes($xml_product) {
        $notes = "Imported from Vlachos Tools XML on " . date('Y-m-d H:i:s');
        $notes .= "\nOriginal SKU: " . $xml_product['sku'];
        
        if (isset($xml_product['description'])) {
            $notes .= "\n\nDescription: " . $xml_product['description'];
        }
        
        if (isset($xml_product['brand'])) {
            $notes .= "\nBrand: " . $xml_product['brand'];
        }
        
        if (isset($xml_product['ean'])) {
            $notes .= "\nEAN: " . $xml_product['ean'];
        }
        
        if (isset($xml_product['weight'])) {
            $notes .= "\nWeight: " . $xml_product['weight'];
        }
        
        if (isset($xml_product['availability'])) {
            $notes .= "\nAvailability: " . $xml_product['availability'];
        }
        
        return $notes;
    }
    
    /**
     * Log the Vlachos import results
     */
    private function logVlachosImport($results) {
        $now = date('Y-m-d H:i:s');
        $status = $results['status'];
        $details = "Vlachos XML Import from URL: {$results['products_imported']} imported, {$results['products_updated']} updated, {$results['products_skipped']} skipped";
        
        if (!empty($results['errors'])) {
            $details .= " | Errors: " . count($results['errors']);
            $status = 'partial_success';
        }
        
        $escaped_details = $this->db->escapeString($details);
        
        $sql = "INSERT INTO sync_log 
                (sync_date, products_added, products_updated, status, details) 
                VALUES 
                ('$now', {$results['products_imported']}, {$results['products_updated']}, '$status', '$escaped_details')";
        
        $this->db->query($sql);
    }
    
    /**
     * Test XML URL accessibility
     */
    public function testXMLURL($url) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                return ['success' => false, 'message' => "Connection error: $error"];
            }
            
            if ($http_code !== 200) {
                return ['success' => false, 'message' => "HTTP error: $http_code"];
            }
            
            return ['success' => true, 'message' => 'XML URL is accessible'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => "Test failed: " . $e->getMessage()];
        }
    }
}