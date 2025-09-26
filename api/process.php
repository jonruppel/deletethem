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

// Gemini failed → return error; UI will prompt user to retry
header('X-Processing-Mode: error-gemini');
send_json(['error' => 'Something went wrong processing the image. Please try again.'], 502);

function call_gemini_inpaint(string $imagePath, array $markers, string $apiKey, string $model): ?string {
    // Build a binary mask from markers (white = keep, black = remove)
    $src = open_image($imagePath);
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $mask = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($mask, 255, 255, 255);
    $black = imagecolorallocate($mask, 0, 0, 0);
    imagefilledrectangle($mask, 0, 0, $w, $h, $white);
    imagealphablending($mask, true);
    $dilate = 1.35; // expand mask radius to ensure coverage
    foreach ($markers as $m) {
        $x = isset($m['x']) ? intval($m['x']) : 0;
        $y = isset($m['y']) ? intval($m['y']) : 0;
        $r = isset($m['radius']) ? intval($m['radius']) : 80;
        $rr = max(1, (int)round($r * $dilate));
        imagefilledellipse($mask, $x, $y, $rr * 2, $rr * 2, $black);
    }

    // Save mask to temp PNG
    $maskPath = dirname($imagePath) . '/' . uniqid('mask_') . '.png';
    imagepng($mask, $maskPath);
    imagedestroy($mask);
    imagedestroy($src);

    // JSON request to Gemini generateContent with inline image + mask
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

    // Build bounding boxes from markers (x,y,r in natural pixels)
    $boxes = [];
    foreach ($markers as $m) {
        $x = intval($m['x'] ?? 0);
        $y = intval($m['y'] ?? 0);
        $r = intval($m['radius'] ?? 0);
        $boxes[] = [ 'x1' => max(0, $x - $r), 'y1' => max(0, $y - $r), 'x2' => $x + $r, 'y2' => $y + $r ];
    }
    $prompt = 'You are an image editor. Task: Remove person(s) inside the provided black mask regions and fill the background naturally. Also consider these bounding boxes (pixels) as targets: ' . json_encode($boxes) . '. Output only the edited image as inlineData with mimeType image/png. Do not include any text.';
    $imageData = file_get_contents($imagePath);
    $maskData = file_get_contents($maskPath);
    $origSha = hash('sha256', $imageData);
    $maskSha = hash('sha256', $maskData);

    // Put mask first, then original image, to emphasize regions to remove
    $payload = [
        'contents' => [[
            'parts' => [
                [ 'text' => $prompt ],
                [ 'inlineData' => [ 'mimeType' => 'image/png', 'data' => base64_encode($maskData) ] ],
                [ 'inlineData' => [ 'mimeType' => mime_content_type($imagePath), 'data' => base64_encode($imageData) ] ]
            ]
        ]]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json; charset=UTF-8', 'x-goog-api-key: ' . $apiKey ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $resp = curl_exec($ch);
    if ($resp === false) {
        @unlink($maskPath);
        curl_close($ch);
        return null;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Parse response; expect an image inline_data
    $data = json_decode($resp, true);
    if ($status >= 200 && $status < 300 && isset($data['candidates'][0]['content']['parts'])) {
        $parts = $data['candidates'][0]['content']['parts'];

        $candidates = [];
        foreach ($parts as $part) {
            // camelCase inlineData
            if (isset($part['inlineData']['data']) && isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                $binB64 = $part['inlineData']['data'];
                $mime = $part['inlineData']['mimeType'];
                $candidates[] = [ 'mime' => $mime, 'data_b64' => $binB64 ];
            }
            // snake_case inline_data
            if (isset($part['inline_data']['data']) && isset($part['inline_data']['mime_type']) && str_starts_with($part['inline_data']['mime_type'], 'image/')) {
                $binB64 = $part['inline_data']['data'];
                $mime = $part['inline_data']['mime_type'];
                $candidates[] = [ 'mime' => $mime, 'data_b64' => $binB64 ];
            }
            // file uri fallback
            if (isset($part['file_data']['file_uri'])) {
                $fileUri = $part['file_data']['file_uri'];
                $dl = download_gemini_file_uri($fileUri, $apiKey);
                if ($dl) {
                    $mime = mime_content_type_from_bytes($dl) ?: 'image/jpeg';
                    $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
                    $outPath = dirname($imagePath) . '/' . uniqid('gem_') . '.' . $ext;
                    file_put_contents($outPath, $dl);
                    @unlink($maskPath);
                    return $outPath;
                }
            }
            // some models return a data URL inside text (code-fenced or raw)
            if (isset($part['text']) && is_string($part['text'])) {
                $maybe = extract_image_from_text_data_url($part['text']);
                if ($maybe) {
                    $candidates[] = [ 'mime' => $maybe['mime'], 'data_b64' => $maybe['b64'] ];
                }
            }
        }

        // Filter out images that exactly match the original or mask
        $filtered = [];
        foreach ($candidates as $c) {
            $bin = base64_decode($c['data_b64']);
            $sha = hash('sha256', $bin);
            if ($sha === $origSha || $sha === $maskSha) {
                continue;
            }
            $filtered[] = [ 'mime' => $c['mime'], 'bin' => $bin ];
        }

        // Choose the largest remaining image as the edited output
        usort($filtered, function($a, $b) { return strlen($b['bin']) <=> strlen($a['bin']); });
        if (!empty($filtered)) {
            $chosen = $filtered[0];
            $mime = $chosen['mime'];
            $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
            $outPath = dirname($imagePath) . '/' . uniqid('gem_') . '.' . $ext;
            file_put_contents($outPath, $chosen['bin']);
            @unlink($maskPath);
            return $outPath;
        }
    }

    // If no usable image, try inverted mask convention (white=remove)
    $invPath = dirname($imagePath) . '/' . uniqid('mask_inv_') . '.png';
    $origMask = imagecreatefrompng($maskPath);
    if ($origMask) {
        imagefilter($origMask, IMG_FILTER_NEGATE);
        imagepng($origMask, $invPath);
        imagedestroy($origMask);
        @unlink($maskPath);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $imageData = file_get_contents($imagePath);
        $maskData = file_get_contents($invPath);
        $payload = [
            'contents' => [[
                'parts' => [
                    [ 'text' => 'Invert mask interpretation: here, white pixels mark regions to remove. Remove those person regions and output only the edited image as inlineData (image/png). No text.' ],
                    [ 'inlineData' => [ 'mimeType' => 'image/png', 'data' => base64_encode($maskData) ] ],
                    [ 'inlineData' => [ 'mimeType' => mime_content_type($imagePath), 'data' => base64_encode($imageData) ] ]
                ]
            ]]
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json; charset=UTF-8', 'x-goog-api-key: ' . $apiKey ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $resp2 = curl_exec($ch);
        $status2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data2 = json_decode($resp2, true);

        if ($status2 >= 200 && $status2 < 300 && isset($data2['candidates'][0]['content']['parts'])) {
            foreach ($data2['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inlineData']['data']) && isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                    $bin = base64_decode($part['inlineData']['data']);
                    $mime = $part['inlineData']['mimeType'];
                    $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
                    $outPath = dirname($imagePath) . '/' . uniqid('gem_') . '.' . $ext;
                    file_put_contents($outPath, $bin);
                    @unlink($invPath);
                    return $outPath;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $maybe = extract_image_from_text_data_url($part['text']);
                    if ($maybe) {
                        $bin = base64_decode($maybe['b64']);
                        $ext = ($maybe['mime'] === 'image/png') ? 'png' : (($maybe['mime'] === 'image/webp') ? 'webp' : 'jpg');
                        $outPath = dirname($imagePath) . '/' . uniqid('gem_') . '.' . $ext;
                        file_put_contents($outPath, $bin);
                        @unlink($invPath);
                        return $outPath;
                    }
                }
            }
        }
        @unlink($invPath);
    }

    return null;
}

function output_image_file(string $path): void {
    $mime = mime_content_type($path) ?: 'image/jpeg';
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store');
    readfile($path);
}


function call_gemini_with_face_crop(string $imageData, array $markers, string $apiKey, string $model, string $userPrompt = ''): ?array {
    // Use Gemini 2.5 Flash Image (Nano Banana) for image editing
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

    // The client already drew the red dashed box onto the image it sends us.
    // Avoid any server-side image work (no GD): pass the received image as-is.
    $boxOverlayB64 = base64_encode($imageData);

    // Get original image dimensions to include in prompt
    $imageInfo = getimagesizefromstring($imageData);
    $originalWidth = $imageInfo[0];
    $originalHeight = $imageInfo[1];

    // Calculate aspect ratio for explicit instruction
    $aspectRatio = round($originalWidth / $originalHeight, 3);
    $isLandscape = $originalWidth > $originalHeight;
    $isPortrait = $originalHeight > $originalWidth;
    $orientationText = $isLandscape ? "landscape" : ($isPortrait ? "portrait" : "square");

    // Build prompt. Default action is remove (delete). If user provided custom text, prefer it.
    $baseInstruction = "Edit this image. The output MUST be exactly {$originalWidth}x{$originalHeight} pixels ({$orientationText} orientation, aspect ratio {$aspectRatio}:1). Do NOT change the image dimensions or aspect ratio. Keep the exact {$originalWidth}x{$originalHeight} size. Remove the red box from the final result. Return only the edited image (inline data).";
    if ($userPrompt !== '') {
        // Use user intent with strong constraints
        $action = trim($userPrompt);
        $prompt = "CRITICAL: {$action}. Focus any edits within the region enclosed by the red dashed box; outside areas should remain natural. {$baseInstruction}";
    } else {
        $prompt = "CRITICAL: Look at the red dashed box and identify which single person fills more of that box area. Remove only that one person entirely from the photo, leaving all other people untouched. Do NOT add, replace with, or generate any new people. Fill in only with background elements like scenery, objects, or empty space - never with people. {$baseInstruction}";
    }

    $payload = [
        'contents' => [[
            'parts' => [
                [ 'text' => $prompt ],
                [ 'inlineData' => [ 'mimeType' => 'image/png', 'data' => $boxOverlayB64 ] ]
            ]
        ]],
        'generationConfig' => [
            'temperature' => 0.1,
            'candidateCount' => 1,
            'maxOutputTokens' => 8192
        ]
    ];

    $payloadJson = json_encode($payload);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json; charset=UTF-8', 'x-goog-api-key: ' . $apiKey ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    $resp = curl_exec($ch);

    if ($resp === false) {
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);

    if ($status >= 200 && $status < 300 && isset($data['candidates'][0]['content']['parts'])) {
        $bestB64 = null;
        $bestLen = -1;
        $bestMime = 'image/png';
        foreach ($data['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['inlineData']['data']) && isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                $b64 = $part['inlineData']['data'];
                $len = strlen($b64);
                if ($len > $bestLen) { $bestLen = $len; $bestB64 = $b64; $bestMime = $part['inlineData']['mimeType']; }
            }
            if (isset($part['text'])) {
                $maybe = extract_image_from_text_data_url($part['text']);
                if ($maybe) {
                    $b64 = $maybe['b64'];
                    $len = strlen($b64);
                    if ($len > $bestLen) { $bestLen = $len; $bestB64 = $b64; $bestMime = $maybe['mime']; }
                }
            }
        }
        if ($bestB64) {
            return ['resultB64' => $bestB64, 'mimeType' => $bestMime, 'effectivePrompt' => $prompt];
        }
    }
    return null;
}

function download_gemini_file_uri(string $fileUri, string $apiKey): ?string {
    // Expect URIs like gs:// or https://storage.googleapis.com/...; attempt direct download
    if (str_starts_with($fileUri, 'http')) {
        $ch = curl_init($fileUri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $bin = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($bin !== false && $status >= 200 && $status < 300) return $bin;
        return null;
    }
    // gs:// URIs generally require authenticated download; not implemented here
    return null;
}

function mime_content_type_from_bytes(string $bytes): ?string {
    $sig = substr($bytes, 0, 12);
    if (strncmp($sig, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) return 'image/png';
    if (strncmp($sig, "\xFF\xD8", 2) === 0) return 'image/jpeg';
    if (strncmp($sig, "RIFF", 4) === 0 && substr($sig, 8, 4) === 'WEBP') return 'image/webp';
    return null;
}

function call_imagen_inpaint(string $imagePath, array $markers, string $apiKey, string $model): ?string {
    // Create a proper mask for Imagen inpainting (white = keep, black = remove)
    $maskPath = create_inpaint_mask($imagePath, $markers);
    if (!$maskPath) {
        return null;
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $imageData = file_get_contents($imagePath);
    $maskData = file_get_contents($maskPath);
    
    $payload = [
        'contents' => [[
            'parts' => [
                [ 'text' => 'Inpaint this image by removing the person in the masked area and filling with natural background. Do not add new people.' ],
                [ 'inlineData' => [ 'mimeType' => mime_content_type($imagePath), 'data' => base64_encode($imageData) ] ],
                [ 'inlineData' => [ 'mimeType' => 'image/png', 'data' => base64_encode($maskData) ] ]
            ]
        ]]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json; charset=UTF-8', 'x-goog-api-key: ' . $apiKey ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    
    @unlink($maskPath);
    
    if ($status >= 200 && $status < 300 && isset($data['candidates'][0]['content']['parts'])) {
        foreach ($data['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['inlineData']['data']) && isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                $bin = base64_decode($part['inlineData']['data']);
                $mime = $part['inlineData']['mimeType'];
                $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
                $outPath = dirname($imagePath) . '/' . uniqid('imagen_') . '.' . $ext;
                file_put_contents($outPath, $bin);
                return $outPath;
            }
        }
    }
    return null;
}

function create_inpaint_mask(string $imagePath, array $markers): ?string {
    $src = open_image($imagePath);
    if (!$src) return null;
    
    $w = imagesx($src);
    $h = imagesy($src);
    imagedestroy($src);
    
    // Create mask: white background (keep), black circles (remove)
    $mask = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($mask, 255, 255, 255);
    $black = imagecolorallocate($mask, 0, 0, 0);
    imagefilledrectangle($mask, 0, 0, $w, $h, $white);
    
    foreach ($markers as $m) {
        $x = intval($m['x'] ?? 0);
        $y = intval($m['y'] ?? 0);
        $r = intval($m['radius'] ?? 80);
        imagefilledellipse($mask, $x, $y, $r * 2, $r * 2, $black);
    }
    
    $maskPath = dirname($imagePath) . '/' . uniqid('mask_') . '.png';
    imagepng($mask, $maskPath);
    imagedestroy($mask);
    
    return $maskPath;
}

function create_box_overlay_image(string $imageData, array $markers): ?string {
    $src = open_image_from_data($imageData);
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    if (empty($markers)) { imagedestroy($src); return null; }
    $box = $markers[0];
    $boxX = intval($box['x'] ?? 0);
    $boxY = intval($box['y'] ?? 0);
    $boxWidth = intval($box['width'] ?? 100);
    $boxHeight = intval($box['height'] ?? 100);

    $canvas = imagecreatetruecolor($w, $h);
    imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
    imagedestroy($src);

    $red = imagecolorallocate($canvas, 255, 0, 0);
    $x1 = max(0, $boxX);
    $y1 = max(0, $boxY);
    $x2 = min($w - 1, $boxX + $boxWidth);
    $y2 = min($h - 1, $boxY + $boxHeight);

    imagesetthickness($canvas, 3);
    $dashLength = 10;
    $gapLength = 5;
    for ($x = $x1; $x < $x2; $x += $dashLength + $gapLength) {
        imageline($canvas, $x, $y1, min($x + $dashLength, $x2), $y1, $red);
    }
    for ($x = $x1; $x < $x2; $x += $dashLength + $gapLength) {
        imageline($canvas, $x, $y2, min($x + $dashLength, $x2), $y2, $red);
    }
    for ($y = $y1; $y < $y2; $y += $dashLength + $gapLength) {
        imageline($canvas, $x1, $y, $x1, min($y + $dashLength, $y2), $red);
    }
    for ($y = $y1; $y < $y2; $y += $dashLength + $gapLength) {
        imageline($canvas, $x2, $y, $x2, min($y + $dashLength, $y2), $red);
    }

    // Capture PNG bytes and return base64
    ob_start();
    imagepng($canvas);
    $pngData = ob_get_clean();
    imagedestroy($canvas);
    return base64_encode($pngData);
}

function create_face_crop_reference(string $imagePath, array $markers): ?string {
    $src = open_image($imagePath);
    if (!$src) return null;
    
    $w = imagesx($src);
    $h = imagesy($src);
    
    // Use the first marker to create a face crop
    if (empty($markers)) {
        imagedestroy($src);
        return null;
    }
    
    $marker = $markers[0]; // Take first marker only
    $centerX = intval($marker['x'] ?? 0);
    $centerY = intval($marker['y'] ?? 0);
    $radius = intval($marker['radius'] ?? 80);
    
    // Create a square crop around the face (2x radius for good context)
    $cropSize = $radius * 2;
    $cropX = max(0, $centerX - $cropSize / 2);
    $cropY = max(0, $centerY - $cropSize / 2);
    
    // Ensure crop doesn't go outside image bounds
    $cropX = min($cropX, $w - $cropSize);
    $cropY = min($cropY, $h - $cropSize);
    $cropSize = min($cropSize, $w - $cropX);
    $cropSize = min($cropSize, $h - $cropY);
    
    // Create the cropped image
    $crop = imagecreatetruecolor($cropSize, $cropSize);
    imagecopy($crop, $src, 0, 0, $cropX, $cropY, $cropSize, $cropSize);
    imagedestroy($src);
    
    // Save as PNG
    $cropPath = dirname($imagePath) . '/' . uniqid('face_crop_') . '.png';
    imagepng($crop, $cropPath);
    imagedestroy($crop);
    
    return $cropPath;
}

function extract_image_from_text_data_url(string $text): ?array {
    // find data:image/...;base64,.... optionally within backticks or code fences
    if (preg_match('/data:(image\/(png|jpeg|webp));base64,([A-Za-z0-9+\/=]+)/', $text, $m)) {
        return [ 'mime' => $m[1], 'b64' => $m[3] ];
    }
    return null;
}

function process_with_fallback(string $imagePath, array $markers) {
    $src = open_image($imagePath);
    if (!$src) return null;
    foreach ($markers as $m) {
        // Markers are boxes; approximate with circle for quick fallback
        $cx = intval(($m['x'] ?? 0) + ($m['width'] ?? 0) / 2);
        $cy = intval(($m['y'] ?? 0) + ($m['height'] ?? 0) / 2);
        $r = intval(max(($m['width'] ?? 0), ($m['height'] ?? 0)) / 2);
        pixelate_region($src, $cx, $cy, max(10, $r), 12);
    }
    return $src;
}

function process_with_fallback_from_data(string $imageData, array $markers) {
    $src = open_image_from_data($imageData);
    if (!$src) return null;
    foreach ($markers as $m) {
        $cx = intval(($m['x'] ?? 0) + ($m['width'] ?? 0) / 2);
        $cy = intval(($m['y'] ?? 0) + ($m['height'] ?? 0) / 2);
        $r = intval(max(($m['width'] ?? 0), ($m['height'] ?? 0)) / 2);
        pixelate_region($src, $cx, $cy, max(10, $r), 12);
    }
    return $src;
}

function open_image(string $path) {
    $mime = mime_content_type($path);
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        return imagecreatefromjpeg($path);
    }
    if ($mime === 'image/png') {
        $img = imagecreatefrompng($path);
        $true = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagealphablending($true, true);
        imagesavealpha($true, true);
        imagecopy($true, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        imagedestroy($img);
        return $true;
    }
    return null;
}

function open_image_from_data(string $data) {
    $img = @imagecreatefromstring($data);
    if ($img === false) return null;
    // Normalize to truecolor with alpha for PNGs
    $true = imagecreatetruecolor(imagesx($img), imagesy($img));
    imagealphablending($true, true);
    imagesavealpha($true, true);
    imagecopy($true, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
    imagedestroy($img);
    return $true;
}

function pixelate_region($image, int $centerX, int $centerY, int $radius, int $pixelSize = 12): void {
    $width = imagesx($image);
    $height = imagesy($image);
    $x0 = max(0, $centerX - $radius);
    $y0 = max(0, $centerY - $radius);
    $boxW = min($radius * 2, $width - $x0);
    $boxH = min($radius * 2, $height - $y0);
    if ($boxW <= 0 || $boxH <= 0) return;

    $region = imagecreatetruecolor($boxW, $boxH);
    imagecopy($region, $image, 0, 0, $x0, $y0, $boxW, $boxH);

    $smallW = max(1, intdiv($boxW, $pixelSize));
    $smallH = max(1, intdiv($boxH, $pixelSize));
    $small = imagecreatetruecolor($smallW, $smallH);

    imagecopyresampled($small, $region, 0, 0, 0, 0, $smallW, $smallH, $boxW, $boxH);
    imagecopyresampled($region, $small, 0, 0, 0, 0, $boxW, $boxH, $smallW, $smallH);
    imagecopy($image, $region, $x0, $y0, 0, 0, $boxW, $boxH);

    imagedestroy($small);
    imagedestroy($region);
}

function send_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}



