<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/course_catalog.php';

function loadEnv($path)
{
    if (!file_exists($path)) {
        error_log('.env file not found at ' . $path . ' — falling back to defaults.');
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

$action = $_GET['action'] ?? '';
$isAjaxAction = $action !== '';

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    if ($isAjaxAction) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit();
    }
    header("Location: ../signin.php");
    exit();
}

if (!$isAjaxAction && isset($_SESSION['is_first_login']) && $_SESSION['is_first_login'] == 1) {
    header("Location: ../change_password.php");
    exit();
}

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

define('PDFTOPPM_BIN', 'C:\\xampp\\htdocs\\CAPSTONE\\poppler-26.02.0\\Library\\bin\\pdftoppm.exe');

loadEnv(__DIR__ . '/../.env');

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);

if(!$conn) {
    if ($isAjaxAction) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . mysqli_connect_error()]);
        exit();
    }
    die("Connection failed: " .mysqli_connect_error());
}

if ($isAjaxAction) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');

    switch ($action) {
        case 'ocr_extract':
            handleOcrExtract();
            break;
        case 'check_student_number':
            handleCheckStudentNumber($conn);
            break;
        case 'add_student':
            handleAddStudent($conn);
            break;
        case 'update_status':
            handleUpdateStatus($conn);
            break;
        case 'delete_document':
            handleDeleteDocument();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }
    exit();
}

function detectDocumentTypeFromText($text)
{
    if (preg_match('/health\s*information\s*form/i', $text)) {
        return 'health_information_form';
    }
    if (preg_match('/sar[\s\-]*form|confirmation\s*slip/i', $text)) {
        return 'sar_form';
    }
    return null;
}

function extractIdPhoto($pdfPath, $docType)
{
    static $idPhotoBoxes = [
        'sar_form' => ['x' => 1890, 'y' => 390, 'width' => 600, 'height' => 600],
        'health_information_form' => ['x' => 1845, 'y' => 50, 'width' => 540, 'height' => 580],
    ];

    if (!isset($idPhotoBoxes[$docType])) {
        return null;
    }
    $box = $idPhotoBoxes[$docType];

    $photoDir = '../uploads/photos/';
    if (!is_dir($photoDir)) {
        mkdir($photoDir, 0755, true);
    }
    $photoBase = $photoDir . uniqid('photo_', true);

    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -jpeg -r 300 -f 1 -l 1'
        . ' -x ' . escapeshellarg($box['x'])
        . ' -y ' . escapeshellarg($box['y'])
        . ' -W ' . escapeshellarg($box['width'])
        . ' -H ' . escapeshellarg($box['height'])
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($photoBase) . ' 2>&1';
    exec($cmd, $cmdOut, $cmdCode);

    $generated = $photoBase . '-1.jpg';
    if (!file_exists($generated)) {
        $generated = $photoBase . '.jpg';
    }

    if (!file_exists($generated)) {
        return null;
    }

    if (!cropLooksLikePersonPhoto($pdfPath, $box)) {
        @unlink($generated);
        return null;
    }

    return $generated;
}

function readPpmPixels($path)
{
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 2 || substr($data, 0, 2) !== 'P6') {
        return null;
    }

    $pos = 2;
    $len = strlen($data);
    $tokens = [];

    while (count($tokens) < 3 && $pos < $len) {
        while ($pos < $len && ctype_space($data[$pos])) {
            $pos++;
        }
        if ($pos < $len && $data[$pos] === '#') {
            while ($pos < $len && $data[$pos] !== "\n") {
                $pos++;
            }
            continue;
        }
        $start = $pos;
        while ($pos < $len && !ctype_space($data[$pos])) {
            $pos++;
        }
        if ($pos > $start) {
            $tokens[] = substr($data, $start, $pos - $start);
        }
    }

    if (count($tokens) < 3) {
        return null;
    }

    $pos++;

    $width = (int) $tokens[0];
    $height = (int) $tokens[1];
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    return ['width' => $width, 'height' => $height, 'data' => substr($data, $pos)];
}

function cropLooksLikePersonPhoto($pdfPath, $box, $minSkinRatio = 0.04)
{
    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('ppmcheck_', true);

    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -r 300 -f 1 -l 1'
        . ' -x ' . escapeshellarg($box['x'])
        . ' -y ' . escapeshellarg($box['y'])
        . ' -W ' . escapeshellarg($box['width'])
        . ' -H ' . escapeshellarg($box['height'])
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
    exec($cmd, $cmdOut, $cmdCode);

    $generated = $tmpBase . '-1.ppm';
    if (!file_exists($generated)) {
        $generated = $tmpBase . '.ppm';
    }
    if (!file_exists($generated)) {
        return true;
    }

    $ppm = readPpmPixels($generated);
    @unlink($generated);

    if ($ppm === null) {
        return true;
    }

    $totalPixels = $ppm['width'] * $ppm['height'];
    $bytes = $ppm['data'];
    if ($totalPixels <= 0 || strlen($bytes) < $totalPixels * 3) {
        return true;
    }

    $step = max(1, (int) floor($totalPixels / 6000));
    $skinCount = 0;
    $sampleCount = 0;

    for ($i = 0; $i < $totalPixels; $i += $step) {
        $offset = $i * 3;
        $r = ord($bytes[$offset]);
        $g = ord($bytes[$offset + 1]);
        $b = ord($bytes[$offset + 2]);

        $sampleCount++;

        $maxC = max($r, $g, $b);
        $minC = min($r, $g, $b);
        $isSkin = $r > 95 && $g > 40 && $b > 20
            && ($maxC - $minC) > 15
            && abs($r - $g) > 15
            && $r > $g && $r > $b;

        if ($isSkin) {
            $skinCount++;
        }
    }

    if ($sampleCount === 0) {
        return false;
    }

    return ($skinCount / $sampleCount) >= $minSkinRatio;
}

function callAzureVisionOcr($imagePath)
{
    $apiKey = getenv('AZURE_VISION_KEY');
    $endpoint = rtrim(getenv('AZURE_VISION_ENDPOINT') ?: '', '/');
    if (!$apiKey || !$endpoint) {
        error_log('AZURE_VISION_KEY or AZURE_VISION_ENDPOINT not set in .env — skipping Azure OCR.');
        return null;
    }

    $imageData = @file_get_contents($imagePath);
    if ($imageData === false) {
        return null;
    }

    $submitUrl = $endpoint . '/vision/v3.2/read/analyze';
    $ch = curl_init($submitUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Ocp-Apim-Subscription-Key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $imageData,
        CURLOPT_TIMEOUT    => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 202) {
        error_log('Azure Read API submit failed, HTTP ' . $httpCode . ': ' . $response);
        return null;
    }

    if (!preg_match('/Operation-Location:\s*(\S+)/i', $response, $m)) {
        error_log('Azure Read API: no Operation-Location header returned.');
        return null;
    }
    $operationUrl = trim($m[1]);

    for ($attempt = 0; $attempt < 15; $attempt++) {
        sleep(1);

        $ch = curl_init($operationUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Ocp-Apim-Subscription-Key: ' . $apiKey],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $pollResponse = curl_exec($ch);
        curl_close($ch);

        if ($pollResponse === false) {
            continue;
        }

        $result = json_decode($pollResponse, true);
        $status = $result['status'] ?? '';

        if ($status === 'succeeded') {
            $lines = [];
            $GLOBALS['azureLineBoxes'] = [];
            foreach ($result['analyzeResult']['readResults'] ?? [] as $page) {
                foreach ($page['lines'] ?? [] as $line) {
                    $lines[] = $line['text'];
                    $GLOBALS['azureLineBoxes'][] = ['text' => $line['text'], 'box' => $line['boundingBox'] ?? []];
                }
            }
            return implode("\n", $lines);
        }

        if ($status === 'failed') {
            error_log('Azure Read API analysis failed: ' . $pollResponse);
            return null;
        }
    }

    error_log('Azure Read API: timed out waiting for result.');
    return null;
}

function sectionBoxHasStroke($pdfPath)
{
    $label = null;
    foreach ($GLOBALS['azureLineBoxes'] ?? [] as $l) {
        if (preg_match('/^\s*section\s*:?\s*$/i', $l['text']) && count($l['box']) >= 8) {
            $label = $l['box'];
        }
    }
    if ($label === null) {
        return false;
    }

    $ys = [$label[1], $label[3], $label[5], $label[7]];
    $x = (int) (max($label[0], $label[2], $label[4], $label[6]) + 15);
    $y = (int) min($ys);
    $w = 200;
    $h = max(20, (int) (max($ys) - min($ys)) - 12);

    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('sectioncheck_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -r 300 -f 1 -l 1'
        . ' -x ' . $x . ' -y ' . $y . ' -W ' . $w . ' -H ' . $h
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
    exec($cmd, $cmdOut, $cmdCode);

    $generated = $tmpBase . '-1.ppm';
    if (!file_exists($generated)) {
        $generated = $tmpBase . '.ppm';
    }
    if (!file_exists($generated)) {
        return false;
    }
    $ppm = readPpmPixels($generated);
    @unlink($generated);
    if ($ppm === null) {
        return false;
    }

    $width = $ppm['width'];
    $height = $ppm['height'];
    $bytes = $ppm['data'];
    $columnInk = array_fill(0, $width, 0);
    for ($row = 0; $row < $height; $row++) {
        for ($col = 0; $col < $width; $col++) {
            $i = ($row * $width + $col) * 3;
            if ($i + 2 >= strlen($bytes)) {
                break 2;
            }
            if ((ord($bytes[$i]) + ord($bytes[$i + 1]) + ord($bytes[$i + 2])) / 3 < 140) {
                $columnInk[$col]++;
            }
        }
    }
    return max($columnInk) >= max(12, (int) ($height * 0.4));
}

function handleOcrExtract()
{
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or an upload error occurred.']);
        return;
    }

    $allowedExt = ['pdf'];
    $originalName = $_FILES['document']['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        echo json_encode(['success' => false, 'message' => 'Unsupported file type. Only PDF files are allowed.']);
        return;
    }

    $mimeType = mime_content_type($_FILES['document']['tmp_name']);
    if ($mimeType !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'Unsupported file type. Only PDF files are allowed.']);
        return;
    }

    $uploadDir = '../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeName = uniqid('doc_', true) . '.' . $ext;
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES['document']['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file.']);
        return;
    }

    $imagePath = $destPath;
    if ($ext === 'pdf') {
        $pngBase = $uploadDir . uniqid('pdfpage_', true);
        $cmd = escapeshellarg(PDFTOPPM_BIN) . ' -png -r 300 -f 1 -l 1 ' . escapeshellarg($destPath) . ' ' . escapeshellarg($pngBase) . ' 2>&1';
        exec($cmd, $cmdOut, $cmdCode);

        $generated = $pngBase . '-1.png';
        if (!file_exists($generated)) {
            $generated = $pngBase . '.png';
        }

        if (file_exists($generated)) {
            $imagePath = $generated;
        } else {
            echo json_encode([
                'success'      => false,
                'message'      => 'Could not convert the PDF to an image for OCR. Make sure poppler-utils (pdftoppm) is installed.',
                'file_path'    => $destPath,
                'file_name'    => $originalName,
            ]);
            return;
        }
    }

    $ocrOutput = callAzureVisionOcr($imagePath);
    $ocrSource = 'azure_vision';

    if ($ocrOutput === null || trim($ocrOutput) === '') {
        echo json_encode([
            'success'      => false,
            'message'      => 'OCR returned no text from Azure Vision. Check your internet connection, the AZURE_VISION_KEY / AZURE_VISION_ENDPOINT values in .env, and that the scan is legible.',
            'file_path'    => $destPath,
            'file_name'    => $originalName,
        ]);
        return;
    }

        $docType = detectDocumentTypeFromText($ocrOutput);
        $photoPath = extractIdPhoto($destPath, $docType);

        $extracted = parseOcrText($ocrOutput);
        if ($extracted['year_section'] === '' && sectionBoxHasStroke($destPath)) {
            $extracted['year_section'] = '1-1';
        }

        echo json_encode([
            'success'         => true,
            'file_path'       => $destPath,
            'image_path'      => $imagePath,
            'file_name'       => $originalName,
            'ocr_text'        => $ocrOutput,
            'ocr_source'      => $ocrSource,
            'extracted'       => $extracted,
            'photo_path'      => $photoPath,
            'doc_type'        => $docType,
        ]);
}

function base64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function stripCommonOcrArtifacts($value)
{
    if ($value === '') return $value;
    $value = str_replace('|', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function stripStrayOcrTokens($value)
{
    if ($value === '') return $value;
    $value = preg_replace('/\b[A-Za-z]\b/', ' ', $value);
    $value = preg_replace('/\b\d{1,2}\b/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function parseOcrText($text)
{
    $text = preg_replace('/_{2,}/', ' ', $text);

    $stampMonths = 'JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC';
    $text = preg_replace(
        '/\b[A-Z]{2,6}(?:-[A-Z0-9]+){2,}\b[ \t]*\r?\n?[ \t]*Rev\.?\s*\d+[ \t]*\r?\n?[ \t]*(?:' . $stampMonths . ')[A-Za-z]*\.?\s+\d{1,2},?\s*(?:19|20)\d{2}/i',
        ' ',
        $text
    );

    $fields = [
        'student_number'    => '',
        'name'              => '',
        'course'            => '',
        'year_section'      => '',
        'sex'               => '',
        'date_of_birth'     => '',
        'place_of_birth'    => '',
        'address'           => '',
        'mobile_number'     => '',
        'email'             => '',
    ];

    $nextLabel = '(?=[ \t]*(?:pup[ \t]*)?(?:student[ \t]*(?:no|number|#)?[ \t]*\.?|school[ \t]*year|course(?:[ \t]*\/[ \t]*college)?|program|address|sex|gender|civil[ \t]*status|blood[ \t]*type|email(?:[ \t]*address)?|parent|guardian|spouse|landline|cellphone|mobile|age|section|year|date[ \t]*of[ \t]*birth|place[ \t]*of[ \t]*birth)[ \t]*[:\-]|[ \t]*(?:\r?\n|$))';

    $patternGroups = [
        'student_number' => [
            '/(?:pup[ \t]*)?student[ \t]*(?:no|number|#)?[ \t]*\.?[ \t]*[:\-][ \t]*\r?\n?[ \t]*([0-9][0-9A-Za-z\-]{4,20})/i',
        ],
        'name' => [
            '/name[ \t]*of[ \t]*applicant[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/i',
            '/^[ \t]*name[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/im',
        ],

        'course' => [
            '/program[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/i',
            '/course[ \t]*(?:\/[ \t]*college)?[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/i',
        ],
        'year_section' => [
            '/year[ \t]*(?:&|and)?[ \t]*section[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/i',
            '/\bsection[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/i',
        ],
        'sex' => [
            '/sex[ \t]*[:\-][ \t]*\r?\n?[ \t]*(male|female)/i',
            '/gender[ \t]*[:\-][ \t]*\r?\n?[ \t]*(male|female)/i',
        ],
        'date_of_birth' => [
            '/date[ \t]*of[ \t]*birth[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/i',
        ],
        'place_of_birth' => [
            '/place[ \t]*of[ \t]*birth[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/i',
        ],
        'mobile_number' => [
            '/(?:mobile|cellphone|cell)[ \t]*(?:no|number)?\.?[ \t]*[:\-][ \t]*\r?\n?[ \t]*([0-9+\-\s]{7,15})/i',
        ],
        'address' => [
            '/^[ \t]*(?:home[ \t]*)?address[ \t]*[:\-][ \t]*\r?\n?[ \t]*(.+?)' . $nextLabel . '/im',
        ],
    ];

    foreach ($patternGroups as $key => $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = trim(end($m));
                if ($candidate !== '' && !looksLikeAnotherLabel($candidate)) {
                    $fields[$key] = $candidate;
                    break;
                }
            }
        }
    }

    if ($fields['year_section'] !== '') {
        if (preg_match('/^[|lI!\[\]{}()\/\\\\]$/', trim($fields['year_section'], " \t_"))) {
            $fields['year_section'] = '1';
        }
        $fields['year_section'] = normalizeYearSection($fields['year_section']);
        if (!preg_match('/^\d{1,2}-[A-Za-z0-9]{1,3}$/', $fields['year_section'])) {
            $fields['year_section'] = '';
        }
    }

    if ($fields['year_section'] === '') {
        $ocrLines = preg_split('/\R/', $text);
        foreach ($ocrLines as $i => $ocrLine) {
            if (!preg_match('/\bsection[ \t]*[:\-]?[ \t_]*$/i', $ocrLine)) continue;
            foreach ([$i - 1, $i + 1, $i - 2, $i + 2] as $j) {
                if (isset($ocrLines[$j]) && preg_match('/^[ \t_]*[|lI!1\[\]{}()\/\\\\][ \t_]*$/', $ocrLines[$j])) {
                    $fields['year_section'] = '1-1';
                    break 2;
                }
            }
        }
    }

    if ($fields['course'] === '') {
        foreach (preg_split('/\R/', $text) as $line) {
            $entry = resolveCourse($line, true);
            if ($entry) {
                $fields['course'] = $entry['name'];
                break;
            }
        }
    }
    if ($fields['course'] === '') {
        if (preg_match('/^[ \t]*(BS[A-Z]{2,6}|BEED|BPED|BTLED)[ \t]*$/m', $text, $m)) {
            $fields['course'] = $m[1];
        }
    }

    if (preg_match('/[\w.+-]+@[\w-]+\.[a-zA-Z]{2,}/', $text, $m)) {
        $fields['email'] = $m[0];
    }

    if ($fields['mobile_number'] === '' && preg_match('/\b(09\d{9}|\+?639\d{9})\b/', $text, $m)) {
        $fields['mobile_number'] = $m[1];
    }

    if ($fields['student_number'] === '') {
        $dash = '[\-\x{2010}-\x{2015}]';
        if (preg_match('/\b(\d{4}\s*' . $dash . '\s*\d{4,6}\s*' . $dash . '\s*[A-Z0-9]{1,4}\s*' . $dash . '\s*[0O])\b/iu', $text, $m)) {
            $cleaned = preg_replace('/\s+/', '', $m[1]);
            $cleaned = preg_replace('/' . $dash . '/u', '-', $cleaned);
            $fields['student_number'] = $cleaned;
        }
    }

    if ($fields['date_of_birth'] !== '' && !looksLikeRealDate($fields['date_of_birth'])) {
        $fields['date_of_birth'] = '';
    }

    if ($fields['place_of_birth'] === '') {
        if (preg_match('/place[ \t]*of[ \t]*birth.*?\n(.*?)(?=type\s*of\s*birth)/is', $text, $m)) {
            $lines = array_filter(array_map('trim', explode("\n", $m[1])), function ($line) {
                if ($line === '' || $line[0] === '(') return false;

                if (!preg_match('/[A-Za-z]{3,}/', $line)) return false;
                if (preg_match('/^\d{1,2}[a-c]?[.,]?$/', $line)) return false;
                return true;
            });

            $lines = array_map('stripStrayOcrTokens', $lines);
            $lines = array_filter($lines, function ($line) { return $line !== ''; });

            $lines = array_values(array_unique($lines));
            if (!empty($lines)) {
                $fields['place_of_birth'] = implode(', ', $lines);
            }
        }
    }

    if ($fields['date_of_birth'] !== '' && !looksLikeRealDate($fields['date_of_birth'])) {
        $fields['date_of_birth'] = '';
    }

    if ($fields['course'] !== '') {
        $fields['course'] = normalizeCourseName($fields['course']);
    }

    foreach ($fields as $key => $value) {
        $fields[$key] = stripCommonOcrArtifacts($value);
    }

    return $fields;
}

function looksLikeRealDate($value)
{
    $digits = preg_replace('/\D/', '', $value);
    if ($digits === '' || preg_match('/^0+$/', $digits)) {
        return false;
    }

    if (preg_match('/^[\d\s\/\-.,]+$/', $value) && !preg_match('/\b(19|20)\d{2}\b/', $value)) {
        return false;
    }
    return true;
}

function dateOfBirthForDb($value)
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function normalizeYearSection($raw)
{
    $raw = trim($raw);

    if (preg_match('/^(\d{1,2})\s*-\s*([A-Za-z0-9]{1,3})$/', $raw, $m)) {
        return $m[1] . '-' . $m[2];
    }

    $year = null;
    $section = null;
    if (preg_match('/(\d)(?:st|nd|rd|th)?\s*year/i', $raw, $m)) {
        $year = $m[1];
    } elseif (preg_match('/year[ \t]*[:\-]?[ \t]*(\d)/i', $raw, $m)) {
        $year = $m[1];
    }
    if (preg_match('/section[ \t]*[:\-]?[ \t]*([A-Za-z0-9]{1,3})/i', $raw, $m)) {
        $section = $m[1];
    }

    if ($year === null && $section === null && preg_match('/^[A-Za-z0-9]{1,3}$/', $raw)) {
        $section = $raw;
    }

    if ($year === null && $section !== null) {
        $year = '1';
    }

    if ($year !== null && $section !== null) {
        return $year . '-' . $section;
    }

    return $raw;
}

function looksLikeAnotherLabel($value)
{
    if (substr(rtrim($value), -1) === ':') {
        return true;
    }
    if (preg_match('/^(signature|date|processed[ \t]*by|interviewed[ \t]*by|issued[ \t]*by|remarks|program|section|step[ \t]*\d)\b/i', trim($value))) {
        return true;
    }
    $labelWords = ['no.', 'number', 'address', 'email', 'sex', 'age', 'course', 'college',
                   'school year', 'civil status', 'blood type', 'name', 'section'];
    if (strlen($value) < 40) {
        $lower = strtolower($value);
        foreach ($labelWords as $word) {
            if (strpos($lower, $word) !== false) {
                return true;
            }
        }
    }
    return false;
}

function handleCheckStudentNumber($conn)
{
    $studentNumber = trim($_GET['student_number'] ?? '');
    if ($studentNumber === '') {
        echo json_encode(['success' => false, 'message' => 'No student number provided.']);
        return;
    }

    $sql = "SELECT student_id FROM students WHERE student_number = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $studentNumber);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    echo json_encode([
        'success' => true,
        'exists'  => mysqli_stmt_num_rows($stmt) > 0,
    ]);
}

function handleDeleteDocument()
{
    $filePath = $_POST['file_path'] ?? '';
    $imagePath = $_POST['image_path'] ?? '';
    $photoPath = $_POST['photo_path'] ?? '';
    if ($filePath === '' && $imagePath === '' && $photoPath === '') {
        echo json_encode(['success' => false, 'message' => 'No file path provided.']);
        return;
    }

    $uploadDir = realpath('../uploads/documents/');
    $photoDir = realpath('../uploads/photos/');
    if ($uploadDir === false) {
        echo json_encode(['success' => false, 'message' => 'Upload directory not found.']);
        return;
    }

    $paths = array_filter([$filePath, $imagePath], fn($p) => $p !== '');
    $deleted = true;

    foreach ($paths as $path) {
        $targetPath = realpath($path);
        if ($targetPath === false || strpos($targetPath, $uploadDir) !== 0) {
            $deleted = false;
            continue;
        }
        if (file_exists($targetPath) && !unlink($targetPath)) {
            $deleted = false;
        }
    }

    if ($photoPath !== '') {
        $targetPhotoPath = realpath($photoPath);
        if ($photoDir === false || $targetPhotoPath === false || strpos($targetPhotoPath, $photoDir) !== 0) {
            $deleted = false;
        } elseif (file_exists($targetPhotoPath) && !unlink($targetPhotoPath)) {
            $deleted = false;
        }
    }

    if ($deleted) {
        echo json_encode(['success' => true, 'message' => 'File deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete file.']);
    }
}

function handleUpdateStatus($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    $status    = $_POST['status'] ?? '';

    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }

    if ($status !== 'active') {
        echo json_encode(['success' => false, 'message' => 'IN-ACTIVE and ON-LEAVE are set by archiving the student.']);
        return;
    }

    $sql = "UPDATE students SET status = ? WHERE student_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'si', $status, $studentId);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Status updated.', 'status' => $status, 'archived' => false]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . mysqli_error($conn)]);
    }
}

function handleAddStudent($conn)
{
    $studentNumber = trim($_POST['student_number'] ?? '');
    $name          = trim($_POST['name'] ?? '');
    $course        = normalizeCourseName($_POST['course'] ?? '');
    $yearSection   = trim($_POST['year_section'] ?? '');

    $missing = [];
    if ($studentNumber === '') $missing[] = 'Student Number';
    if ($name === '')          $missing[] = 'Name';
    if ($course === '')        $missing[] = 'Course';
    if ($yearSection === '')   $missing[] = 'Year & Section';

    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please fill in: ' . implode(', ', $missing) . '.',
        ]);
        return;
    }

    $checkSql = "SELECT student_id FROM students WHERE student_number = ? LIMIT 1";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "s", $studentNumber);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        echo json_encode(['success' => false, 'duplicate' => true, 'message' => 'That student number already exists.']);
        return;
    }

    $sex              = trim($_POST['sex'] ?? '');
    $dob              = dateOfBirthForDb(trim($_POST['date_of_birth'] ?? ''));
    $placeOfBirth     = trim($_POST['place_of_birth'] ?? '');
    $address          = trim($_POST['address'] ?? '');
    $mobileNumber    = trim($_POST['mobile_number'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $status           = 'active';

    $photoBinary = null;
    $photoPathFromOcr = null;

    $photoData = $_POST['photo'] ?? '';
    if ($photoData !== '' && str_starts_with($photoData, 'data:image')) {
        $base64 = explode(',', $photoData, 2)[1] ?? '';
        if ($base64 !== '') {
            $photoBinary = base64_decode($base64);
        }
    }

    if ($photoBinary === null && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $photoBinary = file_get_contents($_FILES['photo']['tmp_name']);
        }
    }

    if ($photoBinary === null && $photoData !== '' && !str_starts_with($photoData, 'data:image')) {
        $realPath = realpath($photoData);
        $realPhotoDir = realpath('../uploads/photos/');
        if ($realPath !== false && $realPhotoDir !== false && str_starts_with($realPath, $realPhotoDir)) {
            $photoPathFromOcr = $photoData;
        }
    }

    $documents = json_decode($_POST['documents'] ?? '[]', true);
    if (!is_array($documents)) {
        $documents = [];
    }

    $cleanupLocalFiles = function () use ($documents) {
        foreach ($documents as $doc) {
            $path = $doc['file_path'] ?? '';
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }

            $imagePath = $doc['image_path'] ?? '';
            if ($imagePath !== '' && $imagePath !== $path && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    };

    mysqli_begin_transaction($conn);
    try {
        $sql = "INSERT INTO students
                (student_number, name, course, year_section, status, sex, date_of_birth,
                 place_of_birth, address, mobile_number, email)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "sssssssssss",
            $studentNumber, $name, $course, $yearSection, $status,
            $sex, $dob, $placeOfBirth, $address, $mobileNumber, $email
        );
        mysqli_stmt_execute($stmt);

        if (mysqli_errno($conn) === 1062) {
            mysqli_rollback($conn);
            $cleanupLocalFiles();
            echo json_encode(['success' => false, 'duplicate' => true, 'message' => 'That student number already exists.']);
            return;
        }

        $studentId = mysqli_insert_id($conn);

        if (count($documents) > 0) {
            $docSql = "INSERT INTO documents
                       (student_id, document_type, file_name, file_path, added_by)
                       VALUES (?, ?, ?, ?, ?)";
            $docStmt = mysqli_prepare($conn, $docSql);
            $addedBy = $_SESSION['name'] ?? '';

            foreach ($documents as $doc) {
                $filePath     = $doc['file_path'] ?? '';
                $fileName     = $doc['file_name'] ?? basename($filePath);
                $documentType = $doc['document_type'] ?? 'Uploaded Document';
                if ($filePath === '') {
                    continue;
                }

                mysqli_stmt_bind_param(
                    $docStmt, "issss",
                    $studentId, $documentType, $fileName, $filePath, $addedBy
                );
                mysqli_stmt_execute($docStmt);
            }
        }

        $photoPath = null;
        if ($photoBinary !== null) {
            $photoDir = '../uploads/photos/';
            if (!is_dir($photoDir)) {
                mkdir($photoDir, 0755, true);
            }
            $photoExt = 'jpg';
            if (isset($_FILES['photo']['name'])) {
                $uploadedExt = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($uploadedExt, ['jpg', 'jpeg', 'png'], true)) {
                    $photoExt = $uploadedExt;
                }
            }
            $photoPath = $photoDir . uniqid('photo_', true) . '.' . $photoExt;
            file_put_contents($photoPath, $photoBinary);
        } elseif ($photoPathFromOcr !== null) {
            $photoPath = $photoPathFromOcr;
        }

        if ($photoPath !== null) {
            $updatePhotoSql = "UPDATE students SET photo_path = ? WHERE student_id = ?";
            $updatePhotoStmt = mysqli_prepare($conn, $updatePhotoSql);
            mysqli_stmt_bind_param($updatePhotoStmt, "si", $photoPath, $studentId);
            mysqli_stmt_execute($updatePhotoStmt);
        }

        mysqli_commit($conn);

        foreach ($documents as $doc) {
            $filePath = $doc['file_path'] ?? '';
            $imagePath = $doc['image_path'] ?? '';

            if (
                $imagePath !== '' &&
                $imagePath !== $filePath &&
                is_file($imagePath)
            ) {
                @unlink($imagePath);
            }
        }

        echo json_encode([
            'success'    => true,
            'message'    => 'Student record saved successfully.',
            'student_id' => $studentId,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $cleanupLocalFiles();
        echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $e->getMessage()]);
    }
}

$search            = isset($_GET['search']) ? trim($_GET['search']) : '';
$courseFilter      = isset($_GET['course']) ? trim($_GET['course']) : '';
$yearSectionFilter = isset($_GET['year_section']) ? trim($_GET['year_section']) : '';

$conditions = ["(status = 'active' OR status IS NULL OR status = '')"];
$params     = [];
$types      = '';

if ($search !== '') {
    $conditions[] = '(student_number LIKE ? OR name LIKE ?)';
    $likeTerm = "%$search%";
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $types   .= 'ss';
}

if ($courseFilter !== '') {
    $filterCourse = null;
    foreach (getCourseCatalog() as $catalogEntry) {
        if ($catalogEntry['code'] === $courseFilter) {
            $filterCourse = $catalogEntry;
            break;
        }
    }

    if ($filterCourse !== null) {
        $conditions[] = 'course IN (?, ?)';
        $params[] = $filterCourse['code'];
        $params[] = $filterCourse['name'];
        $types   .= 'ss';
    } else {
        $conditions[] = 'course = ?';
        $params[] = $courseFilter;
        $types   .= 's';
    }
}

if ($yearSectionFilter !== '') {
    $conditions[] = 'year_section = ?';
    $params[] = $yearSectionFilter;
    $types   .= 's';
}

$sql = "SELECT student_id, student_number, name, course, year_section, status FROM students";
if (!empty($conditions)) {
    $sql .= " WHERE " . implode(' AND ', $conditions);
}

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}

$courseOptions = [];
foreach (getCourseCatalog() as $catalogEntry) {
    $courseOptions[$catalogEntry['code']] = $catalogEntry['code'];
}

$yearSectionOptions = [];
$yearSectionResult = mysqli_query($conn, "SELECT DISTINCT year_section FROM students WHERE (status = 'active' OR status IS NULL OR status = '') AND year_section IS NOT NULL AND year_section <> '' ORDER BY year_section ASC");
if ($yearSectionResult) {
    while ($row = mysqli_fetch_assoc($yearSectionResult)) {
        $yearSectionOptions[] = $row['year_section'];
    }
}

include 'navbar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Directory</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css" rel="stylesheet">
</head>

<body>
    <div class="main-container">
    <div class="textNButton-row">

      <form method="GET" action="" class="search-group" id="directoryFilterForm">
        <div class="search-input-wrapper">
            <input type="text" name="search" class="searchbar" placeholder="STUDENT NUMBER / NAME" value="<?= htmlspecialchars($search) ?>" required>
            <?php if ($search !== ''): ?>
                <a href="student_directory.php" class="clear-icon" title="Clear search"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
        </div>
        <button type="submit" name="search_btn" class="search-icon">
            <i class="bi bi-search"></i>
        </button>
      </form>

      <div class="add-filter">
        <button class="btn-add" type="button" data-bs-toggle="modal" data-bs-target="#addRecordsModal">
            <i class="bi bi-plus-circle"></i>ADD
        </button>
      
      <form method="GET" action="" class="filter-row" id="directoryFilterOnlyForm">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

        <select class="filter" id="courseFilterSelect" name="course">
            <option value="">Course</option>
            <?php foreach ($courseOptions as $courseValue => $courseLabel): ?>
                <option value="<?= htmlspecialchars($courseValue) ?>" <?= $courseFilter === $courseValue ? 'selected' : '' ?>>
                    <?= htmlspecialchars($courseLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="filter filter-year-section" id="yearSectionFilterSelect" name="year_section">
            <option value="">Year & Section</option>
            <?php foreach ($yearSectionOptions as $yearSectionOption): ?>
                <option value="<?= htmlspecialchars($yearSectionOption) ?>" <?= $yearSectionFilter === $yearSectionOption ? 'selected' : '' ?>>
                    <?= htmlspecialchars($yearSectionOption) ?>
                </option>
            <?php endforeach; ?>
            </select>
      </form>
    </div>

    </div>

    <!-- table -->
    <div class="table-scroll-wrapper">
    <table class="student-table">
    <tr>
        <th>Student Number</th>
        <th>Name</th>
        <th>Course</th>
        <th>Year & Section</th>
        <th>Status</th>
        <th>Records</th>
    </tr>

    <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <?php while ($row = mysqli_fetch_assoc($result)): ?>
        <tr>
            <td><?= htmlspecialchars($row['student_number']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['course']) ?></td>
            <td><?= htmlspecialchars($row['year_section']) ?></td>
            <td>
                <select class="status-fliter" data-id="<?= htmlspecialchars($row['student_id']) ?>">
                    <option value="active" <?= $row['status'] === 'active' ? 'selected' : '' ?>>ACTIVE</option>
                    <option value="inactive" <?= $row['status'] === 'inactive' ? 'selected' : '' ?>>IN-ACTIVE</option>
                    <option value="onleave" <?= $row['status'] === 'onleave' ? 'selected' : '' ?>>ON-LEAVE</option>
                </select>
            </td>
            <td>
               <button class="view-btn" data-id="<?= htmlspecialchars($row['student_id']) ?>"><i class="bi bi-eye-fill"></i>View</button>
            </td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="6" class="no-students-message">No students found.</td>
        </tr>
    <?php endif; ?>
    </table>
    </div>


<!-- add new records modal -->
<div class="modal fade" id="addRecordsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">

            <div class="modal-header add-header">
                <h5 class="modal-title add-title">Add New Student Record</h5>

                <div class="header-btn-group">
                    <button type="button" class="btn scan-document-btn">
                        SCAN DOCUMENT
                    </button>

                    <input type="file" id="uploadFileInput" name="document" accept=".pdf,application/pdf" hidden>
                    <button type="button" class="btn upload-file-btn" id="uploadFileBtn">
                        UPLOAD FILE
                    </button>
                </div>
            </div>

            <div id="ocrStatus" class="ocr-status"></div>

            <form method="POST" action="student_directory.php?action=add_student" id="addStudentForm" enctype="multipart/form-data">
                <input type="hidden" id="documentsData" name="documents" value="[]">

                <div class="modal-body">
                
                <div class="student-contents">

                <div class="student-sidebar">
                <div class="photo-container" id="photoContainer">
                    <div class="photo-placeholder">
                        <i class="bi bi-person-circle"></i>
                        <span>Student Photo</span>
                    </div>
                </div>
                <input type="file" id="replacePhotoInput" name="photo" accept=".jpg,.jpeg,.png" hidden>
                <button type="button" class="btn btn-sm btn-outline-secondary replace-photo-btn" id="replacePhotoBtn">
                    <i class="bi bi-upload"></i> Upload Photo
                </button>

                </div>

                <!-- tabs -->
                <div class="student-details">
                <ul class="nav nav-tabs" id="studentTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active"
                            id="student-info-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#student-info"
                            type="button"
                            role="tab"
                            aria-controls="student-info"
                            aria-selected="true">
                        Student Information
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link"
                            id="documents-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#documents"
                            type="button"
                            role="tab"
                            aria-controls="documents"
                            aria-selected="false">
                        Documents
                    </button>
                </li>
            </ul>

            <div class="tab-content border border-top-0 p-3 pt-4 ps-4">
                <div class="tab-pane fade show active" id="student-info" role="tabpanel">
                    <!-- student info -->

                    <div class="row">

                    <div class="col-md-6">
                    <div class="d-flex align-items-center mb-3">
                        <label for="name" class="form-label form-label-custom">
                            Full Name:
                        </label>
                        <input type="text" id="name" name="name" class="form-control input-custom" required
                               pattern="[A-Za-z\s.,]+">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="student_number" class="form-label form-label-custom">
                            Student Number:
                        </label>
                        <input type="text" id="student_number" name="student_number" class="form-control input-custom" required
                               maxlength="15">
                    </div>
                    <small id="studentNumberFeedback" class="text-danger d-block mb-3"></small>

                    <div class="d-flex align-items-center mb-3">
                        <label for="course" class="form-label form-label-custom">
                            Course:
                        </label>
                        <input type="text" id="course" name="course" class="form-control input-custom"
                               pattern="[A-Za-z\s]+">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="year_section" class="form-label form-label-custom">
                            Year & Section:
                        </label>
                        <input type="text" id="year_section" name="year_section" class="form-control input-custom"
                               pattern="[0-9]+-[0-9]+" inputmode="numeric" maxlength="4">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="sex" class="form-label form-label-custom">
                            Gender:
                        </label>
                        <input type="text" id="sex" name="sex" class="form-control input-custom"
                               pattern="[A-Za-z\s]+">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="date_of_birth" class="form-label form-label-custom">
                            Date of Birth:
                        </label>
                        <input type="text" id="date_of_birth" name="date_of_birth" class="form-control input-custom">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="place_of_birth" class="form-label form-label-custom">
                            Place of Birth:
                        </label>
                        <input type="text" id="place_of_birth" name="place_of_birth" class="form-control input-custom">
                    </div>
                    </div>

                    <div class="col-md-6">
                    <div class="d-flex align-items-center mb-3">
                        <label for="mobile_number" class="form-label form-label-custom">
                            Mobile Number:
                        </label>
                        <input type="text" id="mobile_number" name="mobile_number" class="form-control input-custom"
                               pattern="[0-9]{11}" maxlength="11" inputmode="numeric">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="email" class="form-label form-label-custom">
                            Email Address:
                        </label>
                        <input type="email" id="email" name="email" class="form-control input-custom"
                               pattern="[^@\s]+@[^@\s]+\.[^@\s]+" title="Enter a valid email address">
                    </div>
                    <small id="emailFeedback" class="text-danger d-block mb-3"></small>

                    <div class="d-flex align-items-start mb-3">
                        <label for="address" class="form-label form-label-custom">
                            Address:
                        </label>
                        <textarea id="address" name="address" class="form-control input-custom" rows="3"></textarea>
                    </div>
                    </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="documents" role="tabpanel">

                <div id="documentList" class="d-flex flex-column gap-2"></div>

                <div id="documentEmptyState" class="text-center text-muted document-empty-state">
                    No documents uploaded yet.
                </div>

                <template id="documentRowTemplate">
                    <div class="d-flex align-items-center gap-3 border rounded p-2 document-row">
                        <i class="bi bi-file-earmark-text document-row-icon"></i>
                        <div class="flex-grow-1">
                            <div class="document-row-title"></div>
                            <small class="text-muted document-row-filename"></small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary document-view-btn" title="View file">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary document-change-btn" title="Change file">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger document-delete-btn" title="Delete file">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </template>

            </div>
            </div>
            </div>
            </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">CANCEL</button>
                    <button type="button" class="btn btn-save" id="saveStudentBtn">SAVE</button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- confirm modal -->
<div class="modal fade" id="confirmSaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm New Student Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Please confirm the details before saving:</p>
                <table class="table table-sm confirm-summary-table">
                    <tr><th>Student Number</th><td id="confirmSummaryNumber"></td></tr>
                    <tr><th>Name</th><td id="confirmSummaryName"></td></tr>
                    <tr><th>Course</th><td id="confirmSummaryCourse"></td></tr>
                    <tr><th>Year &amp; Section</th><td id="confirmSummarySection"></td></tr>
                    <tr><th>Attached Documents</th><td id="confirmSummaryDocs"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Back to Edit</button>
                <button type="button" class="btn btn-save" id="confirmSaveBtn">Confirm & Save</button>
            </div>
        </div>
    </div>
</div>

<!-- confirm status / archive modal -->
<div class="modal fade" id="confirmStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmStatusTitle">Confirm Status Change</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Change <strong id="confirmStatusStudentName"></strong>'s status
                    from <strong id="confirmStatusOldValue"></strong>
                    to <strong id="confirmStatusNewValue"></strong>?
                </p>
                <div id="confirmStatusArchiveNote" class="alert alert-warning small mb-0 d-none">
                    <i class="bi bi-archive"></i>
                    Their documents will be saved to Google Drive and the record moved to Archive.
                </div>
                <div id="confirmStatusError" class="alert alert-danger small mt-3 mb-0 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" id="confirmStatusCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save" id="confirmStatusChangeBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- document preview panel -->
<div id="documentPreviewPanel" class="document-preview-panel" aria-hidden="true">
    <div id="documentPreviewResizeHandle" class="document-preview-resize-handle" title="Drag to resize"></div>
    <div class="document-preview-header">
        <span id="documentPreviewTitle" class="document-preview-title">Document Preview</span>
        <div class="document-preview-header-actions">
            <button type="button" id="documentPreviewExpandBtn" class="btn btn-sm btn-outline-secondary" title="Open full size in a new tab">
                <i class="bi bi-arrows-angle-expand"></i>
            </button>
            <button type="button" id="documentPreviewCloseBtn" class="btn-close" aria-label="Close preview"></button>
        </div>
    </div>
    <div class="document-preview-body">
        <iframe id="documentPreviewFrame" title="Document preview" src="about:blank"></iframe>
    </div>
</div>

<script src="../js/add-student-ocr.js"></script>
<script src="../js/student-directory-filters.js"></script>
<script src="../js/student-directory.js"></script>
    </div>
</body>
</html>