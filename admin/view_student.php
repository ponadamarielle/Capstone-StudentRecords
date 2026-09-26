<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/course_catalog.php';

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../signin.php");
    exit();
}

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

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

define('PDFTOPPM_BIN', 'C:\\xampp\\htdocs\\CAPSTONE\\poppler-26.02.0\\Library\\bin\\pdftoppm.exe');

loadEnv(__DIR__ . '/../.env');

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json');

    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    ob_start();

    switch ($action) {
        case 'ocr_extract':
            handleOcrExtract();
            break;
        case 'delete_document_record':
            handleDeleteDocumentRecord($conn);
            break;
        case 'delete_staged_document':
            handleDeleteStagedDocument();
            break;
        case 'replace_document':
            handleReplaceDocument($conn);
            break;
        case 'add_document':
            handleAddDocument($conn);
            break;
        case 'update_student_info':
            handleUpdateStudentInfo($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }

    $buffered = ob_get_clean();
    $jsonStart = strpos($buffered, '{');
    $arrStart  = strpos($buffered, '[');
    if ($arrStart !== false && ($jsonStart === false || $arrStart < $jsonStart)) {
        $jsonStart = $arrStart;
    }
    if ($jsonStart === false) {
        error_log("view_student.php action '$action' produced no JSON output: " . $buffered);
        echo json_encode(['success' => false, 'message' => 'Unexpected server error. Please try again.']);
    } elseif ($jsonStart > 0) {
        error_log("view_student.php action '$action' had unexpected output before JSON: " . substr($buffered, 0, $jsonStart));
        echo substr($buffered, $jsonStart);
    } else {
        echo $buffered;
    }
    exit();
}

function isPathInsideUploads($path)
{
    $uploadDir = realpath('../uploads/documents/');
    $target    = realpath($path);
    return $uploadDir !== false && $target !== false && strpos($target, $uploadDir) === 0;
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
            foreach ($result['analyzeResult']['readResults'] ?? [] as $page) {
                foreach ($page['lines'] ?? [] as $line) {
                    $lines[] = $line['text'];
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

    $docType   = detectDocumentTypeFromText($ocrOutput);
    $photoPath = extractIdPhoto($destPath, $docType);

    echo json_encode([
        'success'    => true,
        'file_path'  => $destPath,
        'image_path' => $imagePath,
        'file_name'  => $originalName,
        'ocr_text'   => $ocrOutput,
        'ocr_source' => $ocrSource,
        'extracted'  => parseOcrText($ocrOutput),
        'photo_path' => $photoPath,
        'doc_type'   => $docType,
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
            '/program[ \t]*[:\-][ \t]*\r?\n?[ \t]*([A-Za-z0-9.\-]+)/i',
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
        $fields['year_section'] = normalizeYearSection($fields['year_section']);
        if (!preg_match('/^\d{1,2}-[A-Za-z0-9]{1,3}$/', $fields['year_section'])) {
            $fields['year_section'] = '';
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

function handleUpdateStudentInfo($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }

    $name        = trim($_POST['name'] ?? '');
    $studentNum  = trim($_POST['student_number'] ?? '');
    $course      = normalizeCourseName($_POST['course'] ?? '');
    $yearSection = trim($_POST['year_section'] ?? '');
    $sex         = trim($_POST['sex'] ?? '');
    $dob         = dateOfBirthForDb(trim($_POST['date_of_birth'] ?? ''));
    $pob         = trim($_POST['place_of_birth'] ?? '');
    $mobile      = trim($_POST['mobile_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $address     = trim($_POST['address'] ?? '');

    if ($studentNum !== '') {
        $dupSql  = "SELECT student_id FROM students WHERE student_number = ? AND student_id != ? LIMIT 1";
        $dupStmt = mysqli_prepare($conn, $dupSql);
        mysqli_stmt_bind_param($dupStmt, "si", $studentNum, $studentId);
        mysqli_stmt_execute($dupStmt);
        mysqli_stmt_store_result($dupStmt);

        if (mysqli_stmt_num_rows($dupStmt) > 0) {
            echo json_encode(['success' => false, 'message' => 'That student number is already used by another record.']);
            return;
        }
    }

    $newPhotoPath = null;
    $oldPhotoPath = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            echo json_encode(['success' => false, 'message' => 'Unsupported photo type. Use JPG or PNG.']);
            return;
        }

        $lookupSql  = "SELECT photo_path FROM students WHERE student_id = ?";
        $lookupStmt = mysqli_prepare($conn, $lookupSql);
        mysqli_stmt_bind_param($lookupStmt, "i", $studentId);
        mysqli_stmt_execute($lookupStmt);
        $lookupRow    = mysqli_fetch_assoc(mysqli_stmt_get_result($lookupStmt));
        $oldPhotoPath = $lookupRow['photo_path'] ?? null;

        $photoDir = '../uploads/photos/';
        if (!is_dir($photoDir)) {
            mkdir($photoDir, 0755, true);
        }

        $safeName = uniqid('photo_', true) . '.' . $ext;
        $destPath = $photoDir . $safeName;

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destPath)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded photo.']);
            return;
        }

        $newPhotoPath = $destPath;
    }

    $photoIsStaged = false;
    if ($newPhotoPath === null) {
        $stagedPhoto = trim($_POST['photo_path'] ?? '');
        if ($stagedPhoto !== '') {
            $realPhotoDir = realpath('../uploads/photos/');
            $realStaged   = realpath($stagedPhoto);

            if ($realPhotoDir === false || $realStaged === false || !is_file($realStaged)
                || strpos($realStaged, $realPhotoDir . DIRECTORY_SEPARATOR) !== 0) {
                echo json_encode(['success' => false, 'message' => 'The scanned photo could not be found. Please scan the document again.']);
                return;
            }

            $lookupSql  = "SELECT photo_path FROM students WHERE student_id = ?";
            $lookupStmt = mysqli_prepare($conn, $lookupSql);
            mysqli_stmt_bind_param($lookupStmt, "i", $studentId);
            mysqli_stmt_execute($lookupStmt);
            $lookupRow    = mysqli_fetch_assoc(mysqli_stmt_get_result($lookupStmt));
            $oldPhotoPath = $lookupRow['photo_path'] ?? null;

            $newPhotoPath  = '../uploads/photos/' . basename($realStaged);
            $photoIsStaged = true;
        }
    }

    if ($newPhotoPath !== null) {
        $sql  = "UPDATE students
                 SET name = ?, student_number = ?, course = ?, year_section = ?, sex = ?,
                     date_of_birth = ?, place_of_birth = ?, mobile_number = ?, email = ?, address = ?,
                     photo_path = ?
                 WHERE student_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "sssssssssssi",
            $name, $studentNum, $course, $yearSection, $sex, $dob, $pob, $mobile, $email, $address, $newPhotoPath, $studentId
        );
    } else {
        $sql  = "UPDATE students
                 SET name = ?, student_number = ?, course = ?, year_section = ?, sex = ?,
                     date_of_birth = ?, place_of_birth = ?, mobile_number = ?, email = ?, address = ?
                 WHERE student_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "ssssssssssi",
            $name, $studentNum, $course, $yearSection, $sex, $dob, $pob, $mobile, $email, $address, $studentId
        );
    }

    if (mysqli_stmt_execute($stmt)) {
        if ($newPhotoPath !== null && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath
            && strpos($oldPhotoPath, '../uploads/photos/') === 0 && is_file($oldPhotoPath)) {
            @unlink($oldPhotoPath);
        }
        echo json_encode([
            'success'    => true,
            'message'    => 'Student information updated.',
            'photo_path' => $newPhotoPath,
        ]);
    } else {
        if ($newPhotoPath !== null && !$photoIsStaged) {
            @unlink($newPhotoPath);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)]);
    }
}

function handleDeleteStagedDocument()
{
    $filePath  = $_POST['file_path'] ?? '';
    $imagePath = $_POST['image_path'] ?? '';
    $photoPath = $_POST['photo_path'] ?? '';

    if ($filePath === '' && $imagePath === '' && $photoPath === '') {
        echo json_encode(['success' => false, 'message' => 'No file path provided.']);
        return;
    }

    $uploadDir = realpath('../uploads/documents/');
    if ($uploadDir === false) {
        echo json_encode(['success' => false, 'message' => 'Upload directory not found.']);
        return;
    }

    $paths   = array_filter([$filePath, $imagePath], fn($p) => $p !== '');
    $deleted = true;

    foreach ($paths as $path) {
        $targetPath = realpath($path);
        if ($targetPath === false || strpos($targetPath, $uploadDir) !== 0) {
            continue;
        }
        if (file_exists($targetPath) && !unlink($targetPath)) {
            $deleted = false;
        }
    }

    if ($photoPath !== '') {
        $photoDir        = realpath('../uploads/photos/');
        $targetPhotoPath = realpath($photoPath);
        if ($photoDir === false || $targetPhotoPath === false
            || strpos($targetPhotoPath, $photoDir . DIRECTORY_SEPARATOR) !== 0) {
            $deleted = false;
        } elseif (file_exists($targetPhotoPath) && !unlink($targetPhotoPath)) {
            $deleted = false;
        }
    }

    echo json_encode(['success' => $deleted]);
}

function handleDeleteDocumentRecord($conn)
{
    $documentId = $_POST['document_id'] ?? '';
    if ($documentId === '' || !ctype_digit((string) $documentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid document.']);
        return;
    }

    $sql  = "SELECT file_path FROM documents WHERE document_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $documentId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        return;
    }

    $deleteSql  = "DELETE FROM documents WHERE document_id = ?";
    $deleteStmt = mysqli_prepare($conn, $deleteSql);
    mysqli_stmt_bind_param($deleteStmt, "i", $documentId);

    if (mysqli_stmt_execute($deleteStmt)) {
        if (isPathInsideUploads($row['file_path']) && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }
        echo json_encode(['success' => true, 'message' => 'Document deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete: ' . mysqli_error($conn)]);
    }
}

function handleReplaceDocument($conn)
{
    $documentId = $_POST['document_id'] ?? '';
    if ($documentId === '' || !ctype_digit((string) $documentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid document.']);
        return;
    }

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or an upload error occurred.']);
        return;
    }

    $allowedExt   = ['pdf'];
    $originalName = $_FILES['document']['name'];
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

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

    $sql  = "SELECT file_path FROM documents WHERE document_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $documentId);
    mysqli_stmt_execute($stmt);
    $row     = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $oldPath = $row['file_path'] ?? '';

    $updateSql  = "UPDATE documents
                   SET file_name = ?, file_path = ?, drive_link = NULL
                   WHERE document_id = ?";
    $updateStmt = mysqli_prepare($conn, $updateSql);
    mysqli_stmt_bind_param($updateStmt, "ssi", $originalName, $destPath, $documentId);

    if (mysqli_stmt_execute($updateStmt)) {
        if ($oldPath !== '' && isPathInsideUploads($oldPath) && is_file($oldPath)) {
            @unlink($oldPath);
        }
        echo json_encode([
            'success'   => true,
            'message'   => 'Document replaced.',
            'file_name' => $originalName,
            'file_path' => $destPath,
        ]);
    } else {
        @unlink($destPath);
        echo json_encode(['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)]);
    }
}

function handleAddDocument($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }

    $stagedPath = $_POST['document_file_path'] ?? '';
    $stagedName = $_POST['document_file_name'] ?? '';

    if ($stagedPath !== '') {
        if (!isPathInsideUploads($stagedPath) || !is_file($stagedPath)) {
            echo json_encode(['success' => false, 'message' => 'Scanned file not found. Please upload again.']);
            return;
        }
        $destPath     = $stagedPath;
        $originalName = $stagedName !== '' ? $stagedName : basename($stagedPath);
    } else {
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or an upload error occurred.']);
            return;
        }

        $allowedExt   = ['pdf'];
        $originalName = $_FILES['document']['name'];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

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
    }

    $documentType = $_POST['document_type'] ?? 'Uploaded Document';
    $addedBy      = $_SESSION['name'] ?? '';

    $sql  = "INSERT INTO documents (student_id, document_type, file_name, file_path, added_by)
             VALUES (?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issss", $studentId, $documentType, $originalName, $destPath, $addedBy);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            'success'     => true,
            'message'     => 'Document uploaded.',
            'document_id' => mysqli_insert_id($conn),
            'file_name'   => $originalName,
            'file_path'   => $destPath,
        ]);
    } else {
        if ($stagedPath === '') {
            @unlink($destPath);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to save: ' . mysqli_error($conn)]);
    }
}

$studentId = $_GET['id'] ?? '';
if ($studentId === '' || !ctype_digit((string) $studentId)) {
    header("Location: student_directory.php");
    exit();
}

$sql  = "SELECT * FROM students WHERE student_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $studentId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    header("Location: student_directory.php");
    exit();
}

$docsSql  = "SELECT document_id, document_type, file_name, file_path
             FROM documents WHERE student_id = ? ORDER BY document_id ASC";
$docsStmt = mysqli_prepare($conn, $docsSql);
mysqli_stmt_bind_param($docsStmt, "i", $studentId);
mysqli_stmt_execute($docsStmt);
$documents = mysqli_stmt_get_result($docsStmt);
$documentRows = $documents ? mysqli_fetch_all($documents, MYSQLI_ASSOC) : [];

$issuedSql  = "SELECT uploaded_at, added_by FROM documents
               WHERE student_id = ? ORDER BY uploaded_at DESC LIMIT 1";
$issuedStmt = mysqli_prepare($conn, $issuedSql);
mysqli_stmt_bind_param($issuedStmt, "i", $studentId);
mysqli_stmt_execute($issuedStmt);
$issuedInfo = mysqli_fetch_assoc(mysqli_stmt_get_result($issuedStmt));

$dateIssued = '';
if (!empty($issuedInfo['uploaded_at'])) {
    $dateIssued = date('F j, Y', strtotime($issuedInfo['uploaded_at']));
}
$issuedBy = $issuedInfo['added_by'] ?? '';

$statusLabels = ['active' => 'Active', 'inactive' => 'In-active', 'onleave' => 'On-leave'];
$statusLabel  = $statusLabels[$student['status']] ?? ucfirst($student['status'] ?? '');

$defaultAvatarSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
    . '<rect width="100" height="100" fill="#e9ecef"/>'
    . '<circle cx="50" cy="38" r="18" fill="#adb5bd"/>'
    . '<path d="M20 90c0-19 13.5-32 30-32s30 13 30 32" fill="#adb5bd"/>'
    . '</svg>';
$defaultAvatarUri = 'data:image/svg+xml;base64,' . base64_encode($defaultAvatarSvg);

$photoUrl = (!empty($student['photo_path']) && file_exists($student['photo_path']))
    ? $student['photo_path']
    : $defaultAvatarUri;

include 'navbar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Student - <?= htmlspecialchars($student['name']) ?></title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css" rel="stylesheet">
    <link href="../css/view-student.css" rel="stylesheet">
</head>
<body data-student-id="<?= (int) $studentId ?>">
    <div class="view-container">

    <div class="arrow-add">
        <a href="student_directory.php" class="back-arrow" title="Back to Student Directory">
            <i class="bi bi-arrow-left"></i>
        </a>
        <button class="btn-add" type="button" data-bs-toggle="modal" data-bs-target="#addRecordsModal">
            <i class="bi bi-pencil-square"></i> UPDATE
        </button>
    </div>

        <div class="view-content">

            <div class="view-sidebar">
                <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" class="view-photo">
                <div class="view-name"><?= htmlspecialchars($student['name']) ?></div>
                <div class="view-number"><?= htmlspecialchars($student['student_number']) ?></div>
                <div class="view-course"><?= htmlspecialchars($student['course']) ?></div>
            </div>

            <div class="view-details">
                <ul class="nav nav-tabs" id="viewTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="view-info-tab" data-bs-toggle="tab"
                                data-bs-target="#view-info" type="button" role="tab" aria-selected="true">
                            Student Information
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="view-docs-tab" data-bs-toggle="tab"
                                data-bs-target="#view-docs" type="button" role="tab" aria-selected="false">
                            Documents
                        </button>
                    </li>
                </ul>

                <div class="tab-content border border-top-0 p-3 pt-4 ps-4">

                    <div class="tab-pane fade show active" id="view-info" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Year &amp; Section:</strong> <?= htmlspecialchars($student['year_section'] ?? '') ?></p>
                                <p><strong>Gender:</strong> <?= htmlspecialchars($student['sex'] ?? '') ?></p>
                                <p><strong>Date of Birth:</strong> <?= htmlspecialchars($student['date_of_birth'] ?? '') ?></p>
                                <p><strong>Place of Birth:</strong> <?= htmlspecialchars($student['place_of_birth'] ?? '') ?></p>
                                <p><strong>Mobile Number:</strong> <?= htmlspecialchars($student['mobile_number'] ?? '') ?></p>
                                <p><strong>Email Address:</strong> <?= htmlspecialchars($student['email'] ?? '') ?></p>
                                <p><strong>Address:</strong> <?= htmlspecialchars($student['address'] ?? '') ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Date Issued:</strong> <?= htmlspecialchars($dateIssued) ?></p>
                                <p><strong>Issued by:</strong> <?= htmlspecialchars($issuedBy) ?></p>
                                <p><strong>Status:</strong> <?= htmlspecialchars($statusLabel) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="view-docs" role="tabpanel">
                        <div id="documentList" class="d-flex flex-column gap-2">
                            <?php if (!empty($documentRows)): ?>
                                <?php foreach ($documentRows as $doc): ?>
                                    <div class="document-row" data-document-id="<?= (int) $doc['document_id'] ?>"
                                         data-file-path="<?= htmlspecialchars($doc['file_path']) ?>">
                                        <span class="document-name"><?= htmlspecialchars($doc['file_name']) ?></span>
                                        <div class="document-actions">
                                            <button type="button" class="btn btn-sm btn-outline-secondary document-view-btn" title="Preview file">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <a href="<?= htmlspecialchars($doc['file_path']) ?>" download
                                               class="btn btn-sm btn-outline-secondary" title="Download file">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted document-empty-state">No documents uploaded yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </div>

    <!-- update student record modal -->
    <div class="modal fade" id="addRecordsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">

                <div class="modal-header add-header">
                    <h5 class="modal-title add-title">Update Student Record</h5>

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

                <form method="POST" id="updateRecordForm">
                    <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
                    <input type="hidden" id="stagedFilePath" name="document_file_path" value="">
                    <input type="hidden" id="stagedFileName" name="document_file_name" value="">
                    <input type="hidden" id="stagedPhotoPath" name="photo_path" value="">

                    <div class="modal-body">

                        <div class="student-contents">

                            <div class="student-sidebar">
                                <div class="photo-container" id="photoContainer">
                                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" class="photo-img" id="modalStudentPhoto">
                                </div>
                                <input type="file" id="replacePhotoInput" name="photo" accept=".jpg,.jpeg,.png" hidden>
                                <button type="button" class="btn btn-sm btn-outline-secondary replace-photo-btn" id="replacePhotoBtn">
                                    <i class="bi bi-arrow-repeat"></i> Replace Photo
                                </button>
                                <small id="photoFeedback" class="text-danger d-block mt-2"></small>
                            </div>

                            <div class="student-details">
                                <ul class="nav nav-tabs" id="editTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="edit-info-tab" data-bs-toggle="tab"
                                                data-bs-target="#edit-info" type="button" role="tab" aria-selected="true">
                                            Student Information
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="edit-docs-tab" data-bs-toggle="tab"
                                                data-bs-target="#edit-docs" type="button" role="tab" aria-selected="false">
                                            Documents
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content border border-top-0 p-3 pt-4 ps-4">

                                    <div class="tab-pane fade show active" id="edit-info" role="tabpanel">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_name" class="form-label form-label-custom">Full Name:</label>
                                                    <input type="text" id="edit_name" name="name" class="form-control input-custom" pattern="[A-Za-z\s.,]+"
                                                           value="<?= htmlspecialchars($student['name'] ?? '') ?>">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_student_number" class="form-label form-label-custom">Student Number:</label>
                                                    <input type="text" id="edit_student_number" name="student_number" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['student_number'] ?? '') ?>" maxlength="15">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_course" class="form-label form-label-custom">Course:</label>
                                                    <input type="text" id="edit_course" name="course" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['course'] ?? '') ?>" pattern="[A-Za-z\s]+">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_year_section" class="form-label form-label-custom">Year &amp; Section:</label>
                                                    <input type="text" id="edit_year_section" name="year_section" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['year_section'] ?? '') ?>" pattern="[0-9]+-[0-9]+" inputmode="numeric" maxlength="3">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_sex" class="form-label form-label-custom">Gender:</label>
                                                    <input type="text" id="edit_sex" name="sex" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['sex'] ?? '') ?>" pattern="[A-Za-z\s]+">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_date_of_birth" class="form-label form-label-custom">Date of Birth:</label>
                                                    <input type="text" id="edit_date_of_birth" name="date_of_birth" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['date_of_birth'] ?? '') ?>">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_place_of_birth" class="form-label form-label-custom">Place of Birth:</label>
                                                    <input type="text" id="edit_place_of_birth" name="place_of_birth" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['place_of_birth'] ?? '') ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_mobile_number" class="form-label form-label-custom">Mobile Number:</label>
                                                    <input type="text" id="edit_mobile_number" name="mobile_number" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['mobile_number'] ?? '') ?>" pattern="[0-9]{11}" maxlength="11" inputmode="numeric">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_email" class="form-label form-label-custom">Email Address:</label>
                                                    <input type="email" id="edit_email" name="email" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['email'] ?? '') ?>" pattern="[^@\s]+@[^@\s]+\.[^@\s]+" title="Enter a valid email address">
                                                </div>
                                                <div class="d-flex align-items-start mb-3">
                                                    <label for="edit_address" class="form-label form-label-custom">Home Address:</label>
                                                    <textarea id="edit_address" name="address" class="form-control input-custom" rows="3"><?= htmlspecialchars($student['address'] ?? '') ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-pane fade" id="edit-docs" role="tabpanel">
                                        <div class="documents-tab-layout">
                                            <div class="documents-list-col">
                                                <div id="modalDocumentList" class="d-flex flex-column gap-2 mb-3">
                                                    <?php if (!empty($documentRows)): ?>
                                                        <?php foreach ($documentRows as $doc): ?>
                                                            <div class="document-row" data-document-id="<?= (int) $doc['document_id'] ?>"
                                                                 data-file-path="<?= htmlspecialchars($doc['file_path']) ?>">
                                                                <span class="document-name"><?= htmlspecialchars($doc['file_name']) ?></span>
                                                                <div class="document-actions">
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary document-view-btn" title="Preview file">
                                                                        <i class="bi bi-eye"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary document-change-btn" title="Replace file">
                                                                        <i class="bi bi-arrow-repeat"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger document-delete-btn" title="Delete file">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="text-muted document-empty-state">No documents uploaded yet.</div>
                                                    <?php endif; ?>
                                                </div>

                                                <input type="file" id="replaceDocumentInput" accept=".pdf" hidden>

                                                <div id="scannedFilePreview" class="mb-3">
                                                    <div class="text-muted document-empty-state">No new file attached yet.</div>
                                                </div>
                                                <small id="addDocumentFeedback" class="text-danger d-block mb-3"></small>
                                            </div>

                                        </div>
                                    </div>


                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" class="btn btn-save" id="saveDocumentBtn">UPDATE</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- confirm update modal -->
    <div class="modal fade" id="confirmSaveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Student Record Update</h5>
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
                    <button type="button" class="btn btn-save" id="confirmSaveBtn">Confirm &amp; Update</button>
                </div>
            </div>
        </div>
    </div>

    <!-- confirm delete document modal -->
    <div class="modal fade" id="confirmDeleteDocModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete Document</h5>
                </div>
                <div class="modal-body">
                    <p>
                        Are you sure you want to delete <strong id="confirmDeleteDocName"></strong>?
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-save" id="confirmDeleteDocBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- docked document preview panel -->
    <aside id="documentPreviewPanel" class="document-preview-panel" aria-hidden="true" aria-label="Document preview" inert>
        <div class="document-preview-resize-handle" id="documentPreviewResize" title="Drag to resize"></div>
        <div class="document-preview-header">
            <span class="document-preview-title" id="documentPreviewTitle">Document Preview</span>
            <div class="document-preview-header-actions">
                <a id="documentPreviewOpenTab" href="#" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary" title="Open full size in a new tab">
                    <i class="bi bi-arrows-angle-expand"></i>
                </a>
                <button type="button" id="documentPreviewClose" class="btn-close" aria-label="Close preview"></button>
            </div>
        </div>
        <div class="document-preview-body" id="documentPreviewBody"></div>
    </aside>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script src="../js/view-student.js"></script>
</body>
</html>