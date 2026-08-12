<?php
// gemini-ocr.php

// --- START: Robust JSON Error Handling ---
// This ensures that if any error occurs, the script will output a valid JSON error response
// instead of a PHP HTML error page, which would break the frontend.
error_reporting(0); // Disable default PHP error reporting to prevent HTML errors.

function send_json_error($httpCode, $message, $details = null) {
    // If output buffering is active, clean it.
    if (ob_get_length()) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json');
    $response = ['error' => $message];
    // For debugging, you can include details. In production, you might log this instead.
    if ($details !== null) {
        $response['details'] = $details;
    }
    echo json_encode($response);
    exit;
}

// Catch fatal errors (e.g., require missing file, undefined function).
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE])) {
        send_json_error(500, 'A fatal server error occurred.', ['message' => $error['message'], 'file' => $error['file'], 'line' => $error['line']]);
    }
});

// Catch non-fatal errors (warnings, notices) and treat them as exceptions.
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // Error was suppressed with the @-operator.
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

ob_start(); // Start output buffering to catch any stray output.
// --- END: Robust JSON Error Handling ---

try {
header('Content-Type: application/json');

    // --- Pre-flight checks for common configuration issues ---
    if (!function_exists('curl_init')) {
        throw new Exception("cURL extension is not enabled on the server. Please enable it in your php.ini file.", 503);
    }
    if (!file_exists(__DIR__ . '/gemini-config.php')) {
        throw new Exception("Configuration file 'gemini-config.php' is missing. Please create it from the sample file.", 503);
    }

    require_once __DIR__ . '/gemini-config.php';

    if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
        throw new Exception("GEMINI_API_KEY is not defined or is empty in 'gemini-config.php'.", 503);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed. Only POST requests are accepted.', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON received in request body: ' . json_last_error_msg(), 400);
    }
    if (!isset($input['image']) || empty($input['image'])) {
        throw new Exception("The 'image' field is missing or empty in the request.", 400);
    }

    $base64Image = $input['image'];
    $mimeType = 'image/jpeg';

    if (strpos($base64Image, 'data:') === 0) {
        $parts = explode(',', $base64Image, 2);
        if (count($parts) !== 2) {
            throw new Exception("Malformed data URI for the image.", 400);
        }
        $meta = $parts[0];
        $base64Image = $parts[1];
        
        if (preg_match('/data:([^;]+);/', $meta, $matches)) {
            $mimeType = $matches[1];
        }
    }

    $prompt = 'You are an expert OCR system specializing in extracting asset details from BOTH physical stock register images and government GeM Invoices.
    Your task is to analyze the provided image, extract the data rows, and return them as a JSON array of objects.
    
    Identify if the document is a GeM Invoice (typically has GeM logo, Seller Details, Shipping To, Product Description table at bottom) or a physical stock register page.
    
    CRITICAL INSTRUCTIONS FOR GE M INVOICES:
    1. Locate "GeM Invoice No" (e.g., "GEM-77000376") and extract it as gem_invoice_no.
    2. Locate "Order No" (e.g., "GEMC-511687775489539") and extract it as gem_order_no.
    3. Locate "GeM Invoice Date" (e.g., "20-Jul-2026") or "Dispatch Date". Convert to YYYY-MM-DD format (e.g., 2026-07-20) and extract as date_of_issue.
    4. From the product table, each row represents an asset item:
       - "asset_name": Extracted from "Product Description" column (e.g., "RASPBERRY PI Digital Signal Processing Board").
       - "quantity": Extracted from "Supplied Qty" or "Quantity" column (digits only).
       - "unit": Measurement unit (e.g. "pieces" -> "pcs", "meters" -> "mtr", default "pcs").
       - "opening_balance": The total price inclusive of all taxes for that item (e.g., "Rs. 17052.600" -> extract "17052.60").
       - "location": Default to empty string.
       - "page_no": Default to empty string.
       - "item_no": Default to empty string.
       - "pr_page_no": Default to empty string.
       - "category": Default to empty string.
    
    CRITICAL INSTRUCTIONS FOR PHYSICAL STOCK REGISTERS:
    1. FIRST, determine register category from title at the top (e.g., "Departmental Stores Consumable Register" -> "Consumables").
    2. Look at the very top-right corner of the page for a handwritten page number (e.g. "48") and extract as page_no.
    3. Extract each row:
       - "asset_name": Name of Material/Article.
       - "quantity": Quantity received.
       - "unit": Unit of measure (default "pcs").
       - "item_no": Serial/item number from "Item No" or "I No" column.
       - "date_of_issue": Date of receipt or issue.
       - "pr_page_no": GPR page number.
       - "gem_order_no": Leave as empty string.
       - "gem_invoice_no": Invoice/Bill number from "Bill No" column (exclude date suffix).
       - "opening_balance": Value from "Opening Balance" column.
       - "category": Match register title ("Consumables", "Expandable", "Deadstock", "Furniture").
    
    Map all fields to these JSON keys: "category", "page_no", "item_no", "date_of_issue", "pr_page_no", "gem_order_no", "gem_invoice_no", "quantity", "unit", "opening_balance", "location", "asset_name", "remarks".
    ONLY return a valid JSON array of objects. Do not include markdown formatting like ```json or any other text.';

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => $base64Image
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'responseMimeType' => 'application/json'
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local/shared hosting compatibility

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $curl_error = curl_error($ch);
        curl_close($ch);
        throw new Exception('API connection error: ' . $curl_error, 500);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        $errorData = json_decode($response, true);
        $errorMsg = isset($errorData['error']['message']) ? $errorData['error']['message'] : 'The API returned an error';
        throw new Exception($errorMsg, $httpCode);
    }

    $responseData = json_decode($response, true);

    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $extractedJsonString = $responseData['candidates'][0]['content']['parts'][0]['text'];
        // Sometimes Gemini wraps JSON in markdown even with responseMimeType
        $extractedJsonString = preg_replace('/^```json\s*/', '', $extractedJsonString);
        $extractedJsonString = preg_replace('/\s*```$/', '', $extractedJsonString);
        $extractedJsonString = trim($extractedJsonString);

        // Final validation to ensure it's valid JSON before outputting
        json_decode($extractedJsonString);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('OCR service returned invalid JSON. ' . json_last_error_msg(), 502);
        }

        ob_end_clean(); // Discard any buffer contents from before.
        header('Content-Type: application/json'); // Re-set header just in case.
        echo $extractedJsonString;

    } else {
        throw new Exception('Failed to find text in the API response.', 502, $responseData);
    }

} catch (Exception $e) {
    // Use our custom error handler to send a clean JSON response.
    send_json_error($e->getCode() ?: 500, $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
}
