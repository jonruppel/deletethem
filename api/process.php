<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Method not allowed'], 405);
}

require_once __DIR__ . '/config.php';

// Environment notes
// cURL is required only for Gemini calls; fallback will still work
if (GEMINI_API_KEY && !extension_loaded('curl')) {
    header('X-Processing-Mode: fallback-no-curl');
    // Continue; we will skip Gemini and use fallback
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    send_json(['error' => 'Image upload failed'], 400);
}

$markersRaw = $_POST['markers'] ?? '[]';
$markers = json_decode($markersRaw, true);
if (!is_array($markers)) {
    send_json(['error' => 'Invalid markers payload'], 400);
}

// Optional user prompt coming from the selection prompt UI
$userPromptRaw = isset($_POST['prompt']) ? (string)$_POST['prompt'] : '';
$userPrompt = trim($userPromptRaw);
$userPrompt = mb_substr($userPrompt, 0, 500); // safety cap
$normalized = strtolower($userPrompt);
if ($normalized === 'delete') {
    $userPrompt = '';
}

// In production, avoid emitting HTML errors which confuse the frontend
error_reporting(E_ALL);
ini_set('display_errors', '0');

$upload = $_FILES['image'];
if (!isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
    send_json(['error' => 'No valid image uploaded'], 400);
}

$tmpName = $upload['tmp_name'];
$imgInfo = @getimagesize($tmpName) ?: null;
$mime = is_array($imgInfo) && isset($imgInfo['mime']) ? (string)$imgInfo['mime'] : '';
if (!preg_match('/^image\/(jpeg|png)$/i', $mime)) {
    send_json(['error' => 'Only JPEG or PNG images are supported'], 400);
}

// Read image data into memory (no temp files)
$imageData = file_get_contents($tmpName);

// Require Gemini; if unavailable, return error (no custom fallback)
if (!(GEMINI_API_KEY && function_exists('curl_init'))) {
    header('X-Processing-Mode: error-no-gemini');
    send_json(['error' => 'Service temporarily unavailable. Please try again.'], 503);
}

// Use Gemini 2.5 Flash Image (Nano Banana) for image editing
$result = call_gemini_with_face_crop($imageData, $markers, GEMINI_API_KEY, GEMINI_MODEL, $userPrompt);
    if ($result && isset($result['resultB64'])) {
        header('X-Processing-Mode: gemini-nano-banana');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'resultImage' => $result['resultB64'],
        'mimeType' => $result['mimeType'] ?? 'image/png',
        'effectivePrompt' => $result['effectivePrompt'] ?? null
        ]);
        exit;
}

// Gemini failed → return error with details
header('X-Processing-Mode: error-gemini');
$errorDetails = '';
if (isset($data['error']['message'])) {
    $errorDetails = $data['error']['message'];
} else if (isset($data['error'])) {
    $errorDetails = json_encode($data['error']);
} else {
    $errorDetails = 'Unknown error (check server logs)';
}
send_json([
    'error' => 'Something went wrong processing the image. Please try again.',
    'details' => $errorDetails
], 502);

function call_gemini_with_face_crop(string $imageData, array $markers, string $apiKey, string $model, string $userPrompt = ''): ?array {
    // Get original image dimensions to include in prompt
    $imageInfo = getimagesizefromstring($imageData);
    $originalWidth = $imageInfo[0];
    $originalHeight = $imageInfo[1];
    
    // Check if image needs resizing (max 768 in any dimension)
    $maxDimension = 768;
    if ($originalWidth > $maxDimension || $originalHeight > $maxDimension) {
        error_log("Image too large, resizing from {$originalWidth}x{$originalHeight}");
        
        // Calculate new dimensions maintaining aspect ratio
        if ($originalWidth > $originalHeight) {
            $newWidth = $maxDimension;
            $newHeight = intval($originalHeight * ($maxDimension / $originalWidth));
        } else {
            $newHeight = $maxDimension;
            $newWidth = intval($originalWidth * ($maxDimension / $originalHeight));
        }
        
        // Create new image
        $srcImg = imagecreatefromstring($imageData);
        $dstImg = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        
        // Capture new image data
        ob_start();
        imagepng($dstImg);
        $imageData = ob_get_clean();
        
        imagedestroy($srcImg);
        imagedestroy($dstImg);
        
        // Update dimensions for prompt
        $originalWidth = $newWidth;
        $originalHeight = $newHeight;
        error_log("Resized to {$newWidth}x{$newHeight}");
    }

    // Calculate aspect ratio for explicit instruction
    $aspectRatio = round($originalWidth / $originalHeight, 3);
    $isLandscape = $originalWidth > $originalHeight;
    $isPortrait = $originalHeight > $originalWidth;
    $orientationText = $isLandscape ? "landscape" : ($isPortrait ? "portrait" : "square");

    // Build prompt. Default action is remove (delete). If user provided custom text, prefer it.
    $baseInstruction = "The output image must be exactly {$originalWidth}x{$originalHeight} pixels. Return only the edited image.";
    
    if ($userPrompt !== '') {
        // Use user intent with strong constraints
        $action = trim($userPrompt);
        $prompt = "CRITICAL: {$action}. Focus any edits within the region enclosed by the red dashed box; outside areas should remain natural. {$baseInstruction}";
    } else {
        $prompt = "FOLLOW THESE STEPS IN ORDER:
1. IDENTIFY: Look at the person marked by the red box.
2. REMOVE: Delete that person completely from the image.
3. OBSERVE: Look at the background elements visible around the removed area (walls, floor, furniture, trees, sky, etc).
4. FILL: Using ONLY those existing background elements, fill the empty space naturally.

CRITICAL RULES:
- NEVER replace with another person
- NEVER add any human figures
- NEVER generate new people
- ONLY use background elements that already exist in the photo
- The space MUST be filled with non-human elements like walls, floor, or furniture
- If unsure, prefer simple background elements over complex ones";
    }

    $payload = [
        'contents' => [[
            'parts' => [
                [ 'text' => $prompt ],
                [ 'inlineData' => [ 'mimeType' => 'image/png', 'data' => base64_encode($imageData) ] ]
            ],
            'role' => 'user'
        ]],
        'generationConfig' => [
            'temperature' => 0.1
        ]
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json; charset=UTF-8', 'x-goog-api-key: ' . $apiKey ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    $resp = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        error_log("Gemini API curl error: " . $error);
        return null;
    }

    // Log request details
    error_log("Gemini API request URL: " . $url);
    error_log("Gemini API image size: " . strlen($imageData) . " bytes");
    error_log("Gemini API prompt: " . $prompt);
    
    error_log("=== GEMINI API CALL START ===");
    error_log("Request URL: " . $url);
    error_log("Request payload: " . json_encode($payload, JSON_PRETTY_PRINT));
    error_log("Response status: " . $status);
    error_log("Response headers: " . json_encode(curl_getinfo($ch)));
    
    if ($resp === false) {
        error_log("Response is false, curl error: " . $error);
    } else if (empty($resp)) {
        error_log("Response is empty");
    } else {
        error_log("Response length: " . strlen($resp));
        error_log("Full response: " . $resp);
    }
    
    $data = json_decode($resp, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
    }
    error_log("=== GEMINI API CALL END ===");
    
    if ($status !== 200) {
        error_log("Gemini API non-200 status: " . $status);
        if (isset($data['error'])) {
            error_log("Gemini API error details: " . json_encode($data['error']));
        }
        return null;
    }
    
    if (!isset($data['candidates']) || empty($data['candidates'])) {
        error_log("Gemini API no candidates in response");
        return null;
    }
    
    if (!isset($data['candidates'][0]['content']) || !isset($data['candidates'][0]['content']['parts'])) {
        error_log("Gemini API unexpected response structure");
        return null;
    }
    
    // Check for success
    if ($status >= 200 && $status < 300 && isset($data['candidates'][0]['content']['parts'])) {
        $bestB64 = null;
        $bestLen = -1;
        $bestMime = 'image/png';
        
        foreach ($data['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['inlineData']['data']) && isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                $b64 = $part['inlineData']['data'];
                $len = strlen($b64);
                // Decode and verify the image data
                $imgData = base64_decode($b64);
                if ($imgData === false) {
                    error_log("Invalid base64 data in response");
                    continue;
                }
                
                // Check if it's a valid image
                $img = @imagecreatefromstring($imgData);
                if ($img === false) {
                    error_log("Invalid image data in response");
                    continue;
                }
                
                // Get dimensions for logging
                $width = imagesx($img);
                $height = imagesy($img);
                error_log("Response image dimensions: {$width}x{$height}");
                
                // Convert back to PNG data
                ob_start();
                imagepng($img, null, 9); // Maximum compression
                $imgData = ob_get_clean();
                imagedestroy($img);
                
                $b64 = base64_encode($imgData);
                
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestB64 = $b64;
                    $bestMime = $part['inlineData']['mimeType'];
                }
            }
        }
        
        if ($bestB64) {
            return [
                'resultB64' => $bestB64,
                'mimeType' => $bestMime,
                'effectivePrompt' => $prompt
            ];
        }
    }
    
    return null;
}

function send_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}