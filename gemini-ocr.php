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

if (!isset($input['image']) || empty($input['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No image provided']);
    exit;
}

$base64Image = $input['image'];
// Remove data URI scheme prefix if present
if (strpos($base64Image, 'data:image/') === 0) {
    $parts = explode(',', $base64Image);
    $base64Image = $parts[1];
}

$prompt = 'You are an expert OCR system specializing in tabular data and bilingual text (English and Gujarati handwritten and printed text). 
I will provide you with an image of a physical asset register.
Your task is to extract the table rows and return them as a JSON array of objects.

CRITICAL INSTRUCTIONS:
1. Some entries span MULTIPLE lines in the image (e.g., a long asset name might wrap to the next line within the same row cell). You MUST intelligently merge multi-line text into a single entry for that cell. Do NOT create separate rows for wrapped text.
2. FIRST, look at the title/header of the register at the top of the image (e.g., "Departmental Stores Consumable Register", "Dead Stock Register", "Furniture Register", "Expandable Register"). Based on this title, determine the register category.
3. ALSO look at the very top-right corner of the image. There may be a small handwritten or printed page/folio number there (e.g., "3", "12", "46"). Extract this as the page_no. If there is no such number visible in the top-right corner, return empty string.
4. The columns in the register usually include: Date of Receipt, Pg No of G.P.R. entry, Opening Balance, Indent No & Date, Quantity received, Total Qty, Initial of HOD, Name of section, Qty issued, Date of issue, Closing Balance, Bill No, Item No / I No, Remarks, etc.
5. Map the extracted data to the following JSON keys for EACH ROW:
   - "category": ONE of these exact values based on the register title: "Consumables" (for consumable register), "Expandable" (for expandable register), "Deadstock" (for dead stock/deadstock register), "Furniture" (for furniture register). If unclear, return empty string.
   - "page_no": Look closely at the very top-right corner of the page, inside or near the round stamp/seal. There is often a handwritten page number written in ink (for example, "48" written in purple ink inside the circle). Extract ONLY this handwritten digit/number. Same value for all rows. Empty string if not found.
   - "item_no": Value from the "Item No" or "I No" or "I-No" column in the row. This is the serial item number. Empty string if not present.
   - "date_of_issue": Date of Receipt or Date of issue (YYYY-MM-DD format if possible, otherwise the string as written).
   - "pr_page_no": Pg No of G.P.R entry (the GPR page reference number).
   - "gem_order_no": Leave this field as an EMPTY STRING.
   - "gem_invoice_no": Value from the "Bill No" or "Bill Number" column. Only extract the number itself (e.g., if it says "Bill No 14832 Dt. 24/10/17" or "Bill No. 880", extract "14832" or "880"). Exclude any date part (like "Dt. 24/10/17" or "date...").
   - "quantity": Quantity Received or Total Qty (numeric).
   - "unit": The unit of quantity (e.g. "pcs", "mtr", "liter", "box", "kg"). Look for units near the quantity number or in the column header. By default, use "pcs".
   - "opening_balance": The value from the "Opening Balance" column (numeric, can be decimal). Empty string if not present.
   - "location": Name of section.
   - "asset_name": Name of Material. If the material name spans the Indent column too, merge them intelligently.
   - "remarks": Any remarks or extra info.
6. ONLY return a valid JSON array of objects. Do not include markdown formatting like ```json or any other text.';

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

$data = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
                [
                    'inlineData' => [
                        'mimeType' => 'image/jpeg',
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
