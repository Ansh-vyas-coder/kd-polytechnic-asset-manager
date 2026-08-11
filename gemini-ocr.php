<?php
// gemini-ocr.php
header('Content-Type: application/json');

// API key is stored in gemini-config.php (gitignored — never committed)
// Copy gemini-config.sample.php → gemini-config.php and add your key there.
require_once __DIR__ . '/gemini-config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$base64Image = $input['image'];
$mimeType = 'image/jpeg';

if (strpos($base64Image, 'data:') === 0) {
    $parts = explode(',', $base64Image);
    $meta = $parts[0];
    $base64Image = $parts[1];
    
    // Extract mimetype (e.g. image/png, application/pdf, etc.)
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
   - "category": Infer category based on product description (e.g. electronics/boards -> "Deadstock", furniture -> "Furniture", consumables like toner/cables -> "Consumables").

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
    http_response_code(500);
    echo json_encode(['error' => 'Curl error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 400) {
    http_response_code(500);
    $errorData = json_decode($response, true);
    $errorMsg = isset($errorData['error']['message']) ? $errorData['error']['message'] : 'Gemini API returned error';
    echo json_encode(['error' => $errorMsg, 'details' => $errorData]);
    exit;
}

$responseData = json_decode($response, true);

if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    $extractedJsonString = $responseData['candidates'][0]['content']['parts'][0]['text'];
    // Sometimes Gemini wraps JSON in markdown even with responseMimeType
    $extractedJsonString = preg_replace('/```json\s*/', '', $extractedJsonString);
    $extractedJsonString = preg_replace('/```\s*/', '', $extractedJsonString);
    $extractedJsonString = trim($extractedJsonString);

    echo $extractedJsonString;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse Gemini response', 'response' => $responseData]);
}
