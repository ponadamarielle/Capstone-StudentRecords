<?php
session_start();
require_once __DIR__ . '/course_catalog.php';
require_once __DIR__ . '/document_classifier.php';
require_once __DIR__ . '/ai_extraction.php';
require_once __DIR__ . '/archive.php';
require_once __DIR__ . '/requirement_status.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../lib/fpdf/fpdf.php';

if (!isset($_SESSION['name'], $_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header('Location: ../signin.php');
    exit();
}

require_once __DIR__ . '/../account_guard.php';
requireActiveAccount(false);
session_write_close();

date_default_timezone_set(defined('ACTIVITY_LOG_TIMEZONE') ? ACTIVITY_LOG_TIMEZONE : 'Asia/Manila');

function reportEnv($key, $default)
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = __DIR__ . '/../.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    return isset($file[$key]) && $file[$key] !== '' ? $file[$key] : $default;
}

function reportFail($message, $status = 400)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Report</title></head><body>'
        . '<p>' . htmlspecialchars($message) . '</p></body></html>';
    exit();
}

function reportText($value)
{
    $value = (string) $value;
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $value);
    return $converted !== false ? $converted : $value;
}

function reportRequirements()
{
    if (!function_exists('getDocumentRequirementsForClient')) {
        return [];
    }
    return array_values(array_map(function ($r) {
        $text = ($r['label'] ?? '') . ' ' . ($r['code'] ?? '');
        if (preg_match('/chest[\s_-]*x[\s_-]*-?ray|xray/i', $text)) {
            $r['required_for'] = [];
        }
        return $r;
    }, getDocumentRequirementsForClient()));
}

function reportDocumentLabel(array $doc, array $labels)
{
    $code = $doc['requirement_code'] ?? null;
    if ($code !== null && $code !== '' && isset($labels[$code])) {
        return $labels[$code];
    }
    return ($doc['document_type'] ?? '') !== '' ? $doc['document_type'] : 'Other Document';
}

function reportDate($value, $format = 'F j, Y')
{
    if (!$value) {
        return '';
    }
    $time = strtotime((string) $value);
    return $time ? date($format, $time) : (string) $value;
}

class StudentReportPdf extends FPDF
{
    public $headerImage = '';
    public $footerImage = '';
    public $headerHeight = 0;
    public $footerHeight = 0;

    function Header()
    {
        if ($this->headerImage !== '' && is_file($this->headerImage)) {
            $this->Image($this->headerImage, 0, 0, $this->GetPageWidth(), $this->headerHeight);
        }
        $this->SetY($this->headerHeight + 6);
    }

    function Footer()
    {
        $footerTop = $this->GetPageHeight() - $this->footerHeight;
        $this->SetY($footerTop - 8);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        if ($this->footerImage !== '' && is_file($this->footerImage)) {
            $this->Image($this->footerImage, 0, $footerTop, $this->GetPageWidth(), $this->footerHeight);
        }
        $this->SetTextColor(0, 0, 0);
    }

    function SectionTitle($title)
    {
        $this->EnsureSpace(16);
        $this->Ln(3);
        $this->SetFillColor(128, 0, 0);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 10);
        $this->Cell(0, 7, reportText('  ' . strtoupper($title)), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    function EnsureSpace($height)
    {
        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage();
        }
    }

    function LineCount($width, $text)
    {
        $text = str_replace("\r", '', (string) $text);
        $max = $width - 2 * $this->cMargin;
        $lines = 0;
        foreach (explode("\n", $text) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph));
            $line = '';
            $count = 1;
            foreach ($words as $word) {
                $test = $line === '' ? $word : $line . ' ' . $word;
                if ($this->GetStringWidth($test) <= $max) {
                    $line = $test;
                    continue;
                }
                if ($line !== '') {
                    $count++;
                }
                $line = $word;
                while ($this->GetStringWidth($line) > $max && strlen($line) > 1) {
                    $cut = strlen($line) - 1;
                    while ($cut > 1 && $this->GetStringWidth(substr($line, 0, $cut)) > $max) {
                        $cut--;
                    }
                    $line = substr($line, $cut);
                    $count++;
                }
            }
            $lines += $count;
        }
        return max(1, $lines);
    }

    function TableRow(array $widths, array $cells, $lineHeight = 5.2, array $styles = [], $fill = false)
    {
        $height = 0;
        foreach ($cells as $i => $text) {
            $this->SetFont('Helvetica', $styles[$i]['font'] ?? '', 9);
            $height = max($height, $this->LineCount($widths[$i], reportText($text)) * $lineHeight);
        }
        $height += 2;
        $this->EnsureSpace($height);
        $x = $this->GetX();
        $y = $this->GetY();
        foreach ($cells as $i => $text) {
            $w = $widths[$i];
            $style = $styles[$i] ?? [];
            if ($fill) {
                $this->SetFillColor(245, 245, 245);
                $this->Rect($x, $y, $w, $height, 'F');
            }
            $this->Rect($x, $y, $w, $height);
            $this->SetFont('Helvetica', $style['font'] ?? '', 9);
            $color = $style['color'] ?? [0, 0, 0];
            $this->SetTextColor($color[0], $color[1], $color[2]);
            $this->SetXY($x, $y + 1);
            $this->MultiCell($w, $lineHeight, reportText($text), 0, $style['align'] ?? 'L');
            $this->SetTextColor(0, 0, 0);
            $x += $w;
        }
        $this->SetXY($this->lMargin, $y + $height);
    }

    function TableHeader(array $widths, array $titles)
    {
        $this->EnsureSpace(14);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetFillColor(235, 235, 235);
        foreach ($titles as $i => $title) {
            $this->Cell($widths[$i], 7, reportText($title), 1, 0, 'L', true);
        }
        $this->Ln();
    }
}

$studentId = $_GET['id'] ?? '';
if ($studentId === '' || !ctype_digit((string) $studentId)) {
    reportFail('Invalid student.');
}
$studentId = (int) $studentId;
$download = ($_GET['download'] ?? '') === '1';

$conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
if (!$conn) {
    reportFail('Could not connect to the database.', 500);
}
mysqli_set_charset($conn, 'utf8mb4');

$stmt = mysqli_prepare($conn, 'SELECT * FROM students WHERE student_id = ?');
mysqli_stmt_bind_param($stmt, 'i', $studentId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$student) {
    reportFail('Student not found.', 404);
}

$docStmt = mysqli_prepare($conn, 'SELECT * FROM documents WHERE student_id = ? ORDER BY uploaded_at ASC, document_id ASC');
mysqli_stmt_bind_param($docStmt, 'i', $studentId);
mysqli_stmt_execute($docStmt);
$documents = mysqli_fetch_all(mysqli_stmt_get_result($docStmt), MYSQLI_ASSOC);

$requirements = reportRequirements();
$labels = [];
foreach ($requirements as $r) {
    $labels[$r['code']] = $r['label'];
}
$tracks = function_exists('getAdmissionTracks') ? getAdmissionTracks() : [];
$track = function_exists('normalizeAdmissionTrack')
    ? normalizeAdmissionTrack($student['admission_track'] ?? '')
    : ($student['admission_track'] ?? '');
$completion = requirementCompletion($requirements, $track, array_column($documents, 'requirement_code'));

$fullName = trim((string) ($student['name'] ?? ''));
if ($fullName === '') {
    $fullName = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''), ' ,');
}
$statusValue = strtolower((string) ($student['status'] ?? ''));
$statusLabel = in_array($statusValue, ['graduated'], true) ? 'Graduate' : 'Enrollee';
$gradYear = function_exists('expectedGraduationYear') ? expectedGraduationYear($student) : null;
$gradLabel = $gradYear ? schoolYearLabel($gradYear) : '';
$generatedAt = date('F j, Y \a\t g:i A');
$preparedBy = (string) ($_SESSION['name'] ?? '');

$pdf = new StudentReportPdf('P', 'mm', [215.9, 355.6]);
$pdf->AliasNbPages();
$pdf->SetTitle(reportText('Student Record Report - ' . $fullName));
$pdf->SetAuthor(reportText('PUP eRecords'));
$pdf->headerImage = __DIR__ . '/../images/letterhead-header.jpg';
$pdf->footerImage = __DIR__ . '/../images/letterhead-footer.jpg';
$pdf->headerHeight = 215.9 * 292 / 1700;
$pdf->footerHeight = 215.9 * 215 / 1700;
$pdf->SetMargins(18, $pdf->headerHeight + 6, 18);
$pdf->SetAutoPageBreak(true, $pdf->footerHeight + 10);
$pdf->AddPage();

$contentWidth = 215.9 - 36;

$pdf->SetFont('Helvetica', 'B', 15);
$pdf->Cell(0, 8, reportText('STUDENT RECORD REPORT'), 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(90, 90, 90);
$pdf->Cell(0, 5, reportText('Date and time generated: ' . $generatedAt), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

$pdf->SectionTitle('Student Information');

$photoWidth = 38;
$photoFile = null;
$photoPath = (string) ($student['photo_path'] ?? '');
if ($photoPath !== '' && is_file($photoPath)) {
    $info = @getimagesize($photoPath);
    if ($info && function_exists('imagecreatefromstring')) {
        $image = @imagecreatefromstring((string) file_get_contents($photoPath));
        if ($image) {
            $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $photoFile = tempnam(sys_get_temp_dir(), 'rpt') . '.jpg';
            imagejpeg($canvas, $photoFile, 90);
            imagedestroy($canvas);
            imagedestroy($image);
        }
    } elseif ($info && $info[2] === IMAGETYPE_JPEG) {
        $photoFile = $photoPath;
    }
}

$infoTop = $pdf->GetY();
$infoWidth = $photoFile ? $contentWidth - $photoWidth - 4 : $contentWidth;
$labelWidth = 42;
$rows = [
    ['Student Number', $student['student_number'] ?? ''],
    ['Full Name', $fullName],
    ['Course', $student['course'] ?? ''],
    ['Year & Section', $student['year_section'] ?? ''],
    ['Admission Type', $tracks[$track] ?? ($student['admission_track'] ?? '')],
    ['Student Status', $statusLabel],
    ['Expected Graduation', $gradLabel],
    ['Gender', $student['sex'] ?? ''],
    ['Date of Birth', reportDate($student['date_of_birth'] ?? '')],
    ['Place of Birth', $student['place_of_birth'] ?? ''],
    ['Mobile Number', $student['mobile_number'] ?? ''],
    ['Email Address', $student['email'] ?? ''],
    ['Home Address', $student['address'] ?? ''],
];
if ($photoFile) {
    $pdf->Image($photoFile, 18 + $contentWidth - $photoWidth, $infoTop, $photoWidth, $photoWidth * 1.15);
    $pdf->Rect(18 + $contentWidth - $photoWidth, $infoTop, $photoWidth, $photoWidth * 1.15);
}
foreach ($rows as $i => $row) {
    $width = ($photoFile && $pdf->GetY() < $infoTop + $photoWidth * 1.15 + 1) ? $infoWidth : $contentWidth;
    $pdf->TableRow(
        [$labelWidth, $width - $labelWidth],
        [$row[0], (string) $row[1] !== '' ? (string) $row[1] : '-'],
        5.2,
        [['font' => 'B'], []],
        $i % 2 === 1
    );
}
if ($photoFile && $photoFile !== $photoPath) {
    @unlink($photoFile);
}
if ($photoFile && $pdf->GetY() < $infoTop + $photoWidth * 1.15) {
    $pdf->SetY($infoTop + $photoWidth * 1.15 + 2);
}

$pdf->SectionTitle('Overall Document Status');
$pdf->SetFont('Helvetica', 'B', 13);
if ($completion['complete']) {
    $pdf->SetTextColor(25, 120, 60);
} else {
    $pdf->SetTextColor(180, 30, 40);
}
$pdf->Cell(0, 7, reportText(strtoupper($completion['label'])), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Helvetica', '', 9.5);
$pdf->MultiCell(0, 5, reportText(
    $completion['fulfilled'] . ' of ' . $completion['total'] . ' required documents submitted for '
    . ($tracks[$track] ?? 'the admission type') . '.'
));
if ($completion['missing']) {
    $pdf->SetFont('Helvetica', 'B', 9.5);
    $pdf->Cell(18, 5, reportText('Missing:'), 0, 0);
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->MultiCell(0, 5, reportText(implode(', ', $completion['missing'])));
}
$pdf->Ln(1);

$pdf->SectionTitle('Submitted Documents');
if (!$documents) {
    $pdf->SetFont('Helvetica', 'I', 9.5);
    $pdf->Cell(0, 6, reportText('No documents have been submitted.'), 0, 1);
} else {
    $widths = [10, 58, 62, 26, $contentWidth - 156];
    $pdf->TableHeader($widths, ['No.', 'Document Type', 'File Name', 'Date Uploaded', 'Checked By']);
    foreach ($documents as $i => $doc) {
        $pdf->TableRow(
            $widths,
            [
                (string) ($i + 1),
                reportDocumentLabel($doc, $labels),
                (string) ($doc['file_name'] ?? ''),
                reportDate($doc['uploaded_at'] ?? '', 'M j, Y'),
                (string) ($doc['added_by'] ?? '') !== '' ? (string) $doc['added_by'] : '-',
            ],
            5.2,
            [['align' => 'C'], [], [], [], []],
            $i % 2 === 1
        );
    }
}

$signatories = [
    ['Prepared by:', $preparedBy, reportEnv('REPORT_PREPARED_TITLE', 'Admin')],
];
$pdf->Ln(14);
$pdf->EnsureSpace(30);
$columnWidth = $contentWidth / 3;
$top = $pdf->GetY();
foreach ($signatories as $i => $signatory) {
    $x = 18 + $i * $columnWidth;
    $pdf->SetXY($x, $top);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell($columnWidth - 6, 5, reportText($signatory[0]), 0, 2, 'L');
    $pdf->SetXY($x, $top + 16);
    $pdf->SetFont('Helvetica', 'B', 9.5);
    $pdf->Cell($columnWidth - 6, 5, reportText(strtoupper($signatory[1])), 0, 2, 'C');
    $pdf->Line($x, $top + 21.5, $x + $columnWidth - 6, $top + 21.5);
    $pdf->SetXY($x, $top + 22.5);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->MultiCell($columnWidth - 6, 4.5, reportText($signatory[2]), 0, 'C');
}

$safeNumber = preg_replace('/[^A-Za-z0-9-]+/', '', (string) ($student['student_number'] ?? $studentId));
$fileName = 'Student_Report_' . ($safeNumber !== '' ? $safeNumber : $studentId) . '_' . date('Ymd_His') . '.pdf';

if (!$download) {
    $opts = activityStudentOpts($conn, $studentId, $student);
    logActivity('report_generated',
        'Generated the student record report of ' . activityStudentLabel($opts),
        $opts + [
            'category'    => 'audit',
            'target_type' => 'student',
            'target_id'   => $studentId,
            'details'     => [
                'document_status' => $completion['label'],
                'documents'       => count($documents),
            ],
        ]);
}

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
$pdf->Output($download ? 'D' : 'I', $fileName);