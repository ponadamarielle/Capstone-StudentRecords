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

class ClassReportPdf extends FPDF
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

    function CourseHeading($text)
    {
        $this->EnsureSpace(30);
        $this->Ln(3);
        $this->SetFillColor(245, 232, 232);
        $this->SetTextColor(128, 0, 0);
        $this->SetFont('Helvetica', 'B', 10.5);
        $this->Cell(0, 7, reportText('  ' . $text), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    function SubHeading($text)
    {
        $this->EnsureSpace(22);
        $this->Ln(1);
        $this->SetFont('Helvetica', 'B', 9.5);
        $this->Cell(0, 6, reportText($text), 0, 1, 'L');
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

    function GroupRow(array $widths, array $titles, array $left, array $leftStyles, array $lines, $fill = false)
    {
        $lineHeight = 5.2;
        $leftCount = count($left);
        $leftHeight = 0;
        foreach ($left as $i => $parts) {
            $h = 0;
            foreach ($parts as $part) {
                $this->SetFont('Helvetica', $part[1], 9);
                $h += $this->LineCount($widths[$i], reportText($part[0])) * $lineHeight;
            }
            $leftHeight = max($leftHeight, $h + 2);
        }
        $heights = [];
        foreach ($lines as $k => $line) {
            $h = 0;
            foreach ($line as $j => $cell) {
                $this->SetFont('Helvetica', $cell[1]['font'] ?? '', 9);
                $h = max($h, $this->LineCount($widths[$leftCount + $j], reportText($cell[0])) * $lineHeight);
            }
            $heights[$k] = $h + 2;
        }
        $total = max(array_sum($heights), $leftHeight);
        $heights[count($heights) - 1] += $total - array_sum($heights);
        if ($this->GetY() + $total > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->TableHeader($widths, $titles);
        }
        $y0 = $this->GetY();
        $x = $this->lMargin;
        foreach ($left as $i => $parts) {
            $w = $widths[$i];
            if ($fill) {
                $this->SetFillColor(245, 245, 245);
                $this->Rect($x, $y0, $w, $total, 'F');
            }
            $this->Rect($x, $y0, $w, $total);
            $this->SetXY($x, $y0 + 1);
            $color = $leftStyles[$i]['color'] ?? [0, 0, 0];
            $this->SetTextColor($color[0], $color[1], $color[2]);
            foreach ($parts as $part) {
                $this->SetFont('Helvetica', $part[1], 9);
                $this->SetX($x);
                $this->MultiCell($w, $lineHeight, reportText($part[0]), 0, $leftStyles[$i]['align'] ?? 'L');
            }
            $this->SetTextColor(0, 0, 0);
            $x += $w;
        }
        $y = $y0;
        foreach ($lines as $k => $line) {
            $cx = $x;
            foreach ($line as $j => $cell) {
                $w = $widths[$leftCount + $j];
                if ($fill) {
                    $this->SetFillColor(245, 245, 245);
                    $this->Rect($cx, $y, $w, $heights[$k], 'F');
                }
                $this->Rect($cx, $y, $w, $heights[$k]);
                $this->SetFont('Helvetica', $cell[1]['font'] ?? '', 9);
                $color = $cell[1]['color'] ?? [0, 0, 0];
                $this->SetTextColor($color[0], $color[1], $color[2]);
                $this->SetXY($cx, $y + 1);
                $this->MultiCell($w, $lineHeight, reportText($cell[0]), 0, 'L');
                $this->SetTextColor(0, 0, 0);
                $cx += $w;
            }
            $y += $heights[$k];
        }
        $this->SetXY($this->lMargin, $y0 + $total);
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

function xlsxColumn($index)
{
    $name = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = (int) (($index - $mod) / 26);
    }
    return $name;
}

function xlsxSheet(array $rows, array $widths, $autoFilter = null)
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
    foreach ($widths as $i => $width) {
        $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';
    foreach ($rows as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach ($row as $c => $cell) {
            if ($cell === null) {
                continue;
            }
            [$value, $style] = is_array($cell) ? $cell : [$cell, 0];
            $ref = xlsxColumn($c) . ($r + 1);
            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
            } else {
                $text = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if ($autoFilter !== null) {
        $xml .= '<autoFilter ref="' . $autoFilter . '"/>';
    }
    return $xml . '</worksheet>';
}

class XlsxZip
{
    private $files = [];

    public function addFromString($name, $data)
    {
        $this->files[$name] = $data;
    }

    public function save($path)
    {
        $out = '';
        $central = '';
        $time = getdate();
        $dosTime = ($time['hours'] << 11) | ($time['minutes'] << 5) | intdiv($time['seconds'], 2);
        $dosDate = (max(0, $time['year'] - 1980) << 9) | ($time['mon'] << 5) | $time['mday'];
        foreach ($this->files as $name => $data) {
            $crc = crc32($data);
            $packed = gzdeflate($data, 6);
            $method = 8;
            if ($packed === false) {
                $packed = $data;
                $method = 0;
            }
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dosTime, $dosDate,
                $crc, strlen($packed), strlen($data), strlen($name), 0) . $name . $packed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dosTime, $dosDate,
                $crc, strlen($packed), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        $out .= $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->files), count($this->files),
            strlen($central), strlen($out), 0);
        return file_put_contents($path, $out) !== false;
    }
}

function xlsxBuild(array $sheets, $path)
{
    $zip = new XlsxZip();
    $types = '';
    $workbookSheets = '';
    $rels = '';
    $i = 0;
    foreach ($sheets as $name => $xml) {
        $i++;
        $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $xml);
        $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $types . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $workbookSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="6">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF19783C"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFB41E28"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FF800000"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5E8E8"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5E8E8"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
        . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" xfId="0" fontId="0" fillId="0" borderId="0"/>'
        . '<xf numFmtId="0" xfId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/>'
        . '<xf numFmtId="0" xfId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" xfId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" xfId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment vertical="top"/></xf>'
        . '<xf numFmtId="0" xfId="0" fontId="4" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment vertical="top"/></xf>'
        . '<xf numFmtId="0" xfId="0" fontId="1" fillId="0" borderId="0" applyFont="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" xfId="0" fontId="5" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" xfId="0" fontId="1" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>');
    return $zip->save($path);
}

$courseFilter      = trim((string) ($_GET['course'] ?? ''));
$yearSectionFilter = trim((string) ($_GET['year_section'] ?? ''));
$statusFilter      = in_array($_GET['status'] ?? '', ['enrollee', 'graduated'], true) ? $_GET['status'] : '';
$typeFilter        = isset(STUDENT_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$download          = ($_GET['download'] ?? '') === '1';
$format            = ($_GET['format'] ?? '') === 'xlsx' ? 'xlsx' : 'pdf';

$conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
if (!$conn) {
    reportFail('Could not connect to the database.', 500);
}
mysqli_set_charset($conn, 'utf8mb4');

$conditions = [directoryStudentCondition($conn)];
$params     = [];
$types      = '';
$courseLabel = 'All courses';
if ($courseFilter !== '') {
    $filterCourse = null;
    foreach (getCourseCatalog() as $catalogEntry) {
        if ($catalogEntry['code'] === $courseFilter) {
            $filterCourse = $catalogEntry;
            break;
        }
    }
    if ($filterCourse !== null) {
        $conditions[] = 'course IN (?, ?, ?)';
        $params[] = $filterCourse['code'];
        $params[] = $filterCourse['name'];
        $params[] = courseDisplayName($filterCourse);
        $types   .= 'sss';
        $courseLabel = courseDisplayName($filterCourse);
    } else {
        $conditions[] = 'course = ?';
        $params[] = $courseFilter;
        $types   .= 's';
        $courseLabel = $courseFilter;
    }
}
if ($yearSectionFilter !== '') {
    $conditions[] = 'year_section = ?';
    $params[] = $yearSectionFilter;
    $types   .= 's';
}
if ($statusFilter === 'graduated') {
    $conditions[] = "status = 'graduated'";
} elseif ($statusFilter === 'enrollee') {
    $conditions[] = "(status IN ('enrollee', 'active') OR status IS NULL OR status = '')";
}
if ($typeFilter === 'shiftee' || $typeFilter === 'transferee') {
    $conditions[] = "admission_track = '" . $typeFilter . "'";
} elseif ($typeFilter === 'regular') {
    $conditions[] = "(admission_track IS NULL OR admission_track NOT IN ('shiftee', 'transferee'))";
}

$sql = 'SELECT student_id, student_number, name, course, year_section, status, admission_track FROM students WHERE '
    . implode(' AND ', $conditions) . ' ORDER BY course ASC, year_section ASC, name ASC';
if ($params) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}
$students = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

$requirements = reportRequirements();
$codesByStudent = requirementCodesByStudent($conn, array_column($students, 'student_id'));
$completeCount = 0;
$missingCounts = [];
foreach ($students as &$row) {
    $row['completion'] = requirementCompletion(
        $requirements,
        normalizeAdmissionTrack($row['admission_track'] ?? ''),
        $codesByStudent[(int) $row['student_id']] ?? []
    );
    if ($row['completion']['complete']) {
        $completeCount++;
    }
    foreach ($row['completion']['missing'] as $label) {
        $missingCounts[$label] = ($missingCounts[$label] ?? 0) + 1;
    }
}
unset($row);
arsort($missingCounts);

$groupCounts = [];
foreach ($students as &$row) {
    $row['group_course'] = trim((string) ($row['course'] ?? '')) !== '' ? trim((string) $row['course']) : 'No Course';
    $row['group_section'] = trim((string) ($row['year_section'] ?? '')) !== '' ? trim((string) $row['year_section']) : 'No Section';
    $groupCounts[$row['group_course']][$row['group_section']] = ($groupCounts[$row['group_course']][$row['group_section']] ?? 0) + 1;
}
unset($row);

function reportSectionText($section, $count)
{
    return 'Year & Section: ' . $section . '  (' . $count . ' student' . ($count === 1 ? '' : 's') . ')';
}

function xlsxGroupRow($text, $style, $columns)
{
    $cells = [[$text, $style]];
    for ($c = 1; $c < $columns; $c++) {
        $cells[] = ['', $style];
    }
    return $cells;
}

$requirementLabels = [];
foreach ($requirements as $r) {
    $requirementLabels[$r['code']] = $r['label'];
}
$documentsByStudent = [];
$studentIds = array_map('intval', array_column($students, 'student_id'));
if ($studentIds) {
    $docResult = mysqli_query($conn, 'SELECT * FROM documents WHERE student_id IN (' . implode(',', $studentIds)
        . ') ORDER BY uploaded_at ASC, document_id ASC');
    while ($docResult && ($doc = mysqli_fetch_assoc($docResult))) {
        $documentsByStudent[(int) $doc['student_id']][] = $doc;
    }
}

$statusLabels = ['' => 'All', 'enrollee' => 'Enrollee', 'graduated' => 'Graduate'];
$filterRows = [
    ['Course', $courseLabel],
    ['Year & Section', $yearSectionFilter !== '' ? $yearSectionFilter : 'All'],
    ['Status', $statusLabels[$statusFilter]],
    ['Student Type', $typeFilter !== '' ? STUDENT_TYPES[$typeFilter] : 'All'],
];
$generatedAt = date('F j, Y \a\t g:i A');
$preparedBy = (string) ($_SESSION['name'] ?? '');

$total = count($students);
$namePart = preg_replace('/[^A-Za-z0-9-]+/', '', ($courseFilter !== '' ? $courseFilter : 'All') . ($yearSectionFilter !== '' ? '_' . $yearSectionFilter : ''));

if ($format === 'xlsx') {
    $rows = [
        [['STUDENTS RECORD REPORT', 1]],
        ['Date and time generated: ' . $generatedAt],
        [],
    ];
    foreach ($filterRows as $filterRow) {
        $rows[] = [null, [$filterRow[0], 1], $filterRow[1]];
    }
    $rows[] = [null, ['Total Students', 1], $total];
    $rows[] = [null, ['Complete Requirements', 1], $completeCount];
    $rows[] = [null, ['Incomplete Requirements', 1], $total - $completeCount];
    $courseStats = [];
    foreach ($students as $row) {
        $stat = $courseStats[$row['group_course']] ?? ['total' => 0, 'complete' => 0];
        $stat['total']++;
        if ($row['completion']['complete']) {
            $stat['complete']++;
        }
        $courseStats[$row['group_course']] = $stat;
    }
    $rows[] = [];
    $rows[] = array_map(fn($h) => [$h, 2], ['No.', 'Course', 'Students', 'Complete', 'Incomplete']);
    $n = 0;
    foreach ($courseStats as $course => $stat) {
        $rows[] = [[++$n, 3], [$course, 3], [$stat['total'], 3], [$stat['complete'], 4], [$stat['total'] - $stat['complete'], 5]];
    }
    if (!$courseStats) {
        $rows[] = [['-', 3], ['No students match the selected filters.', 3], [0, 3], [0, 3], [0, 3]];
    }
    $preparedRow = [['Prepared by: ' . $preparedBy . ' (' . reportEnv('REPORT_PREPARED_TITLE', 'Admin') . ')', 1]];
    $rows[] = [];
    $rows[] = $preparedRow;
    $sheets = ['Summary' => xlsxSheet($rows, [6, 50, 14, 14, 14])];

    $headers = ['No.', 'Student Number', 'Name', 'Year & Section', 'Student Type', 'Status', 'Requirement Status',
        'Document', 'Document Status', 'Date Uploaded', 'Uploaded By'];
    $byCourse = [];
    foreach ($students as $row) {
        $byCourse[$row['group_course']][] = $row;
    }
    foreach ($byCourse as $course => $courseStudents) {
        $courseRows = [
            xlsxGroupRow($course, 7, 11),
            [],
            array_map(fn($h) => [$h, 2], $headers),
        ];
        foreach ($courseStudents as $i => $row) {
            $completion = $row['completion'];
            $info = [
                [$i + 1, 3],
                [(string) ($row['student_number'] ?? ''), 3],
                [(string) ($row['name'] ?? ''), 3],
                [(string) ($row['year_section'] ?? ''), 3],
                [STUDENT_TYPES[studentTypeOf($row['admission_track'] ?? '')] ?? 'Regular', 3],
                [($row['status'] ?? '') === 'graduated' ? 'Graduate' : 'Enrollee', 3],
                [$completion['complete'] ? 'Complete' : 'Incomplete (' . $completion['fulfilled'] . ' of ' . $completion['total'] . ')',
                    $completion['complete'] ? 4 : 5],
            ];
            $blank = array_fill(0, 7, ['', 3]);
            $docLines = [];
            foreach ($documentsByStudent[(int) $row['student_id']] ?? [] as $doc) {
                $docLines[] = [
                    [reportDocumentLabel($doc, $requirementLabels), 3],
                    ['Submitted', 4],
                    [reportDate($doc['uploaded_at'] ?? '', 'M j, Y g:i A'), 3],
                    [(string) ($doc['added_by'] ?? '') !== '' ? (string) $doc['added_by'] : '-', 3],
                ];
            }
            foreach ($completion['missing'] as $label) {
                $docLines[] = [[$label, 3], ['Missing', 5], ['-', 3], ['-', 3]];
            }
            if (!$docLines) {
                $docLines[] = [['No documents submitted', 3], ['-', 3], ['-', 3], ['-', 3]];
            }
            foreach ($docLines as $k => $line) {
                $courseRows[] = array_merge($k === 0 ? $info : $blank, $line);
            }
        }
        $courseRows[] = [];
        $courseRows[] = $preparedRow;
        $sheetName = trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', (string) strtok($course, ' ')));
        $sheetName = substr($sheetName !== '' ? $sheetName : 'Course', 0, 28);
        $base = $sheetName;
        $k = 2;
        while (isset($sheets[$sheetName]) || $sheetName === 'Missing Documents') {
            $sheetName = $base . ' ' . $k++;
        }
        $sheets[$sheetName] = xlsxSheet($courseRows, [6, 20, 32, 14, 13, 11, 20, 44, 16, 22, 20]);
    }

    $missingRows = [array_map(fn($h) => [$h, 2], ['No.', 'Document', 'Students Missing'])];
    $n = 0;
    foreach ($missingCounts as $label => $count) {
        $missingRows[] = [[++$n, 3], [$label, 3], [$count, 3]];
    }
    if (!$missingCounts) {
        $missingRows[] = [['-', 3], ['No missing documents', 3], [0, 3]];
    }
    $sheets['Missing Documents'] = xlsxSheet($missingRows, [6, 50, 18]);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if (!xlsxBuild($sheets, $tmp)) {
        @unlink($tmp);
        reportFail('The Excel file could not be created.', 500);
    }
    logActivity('report_generated',
        'Downloaded the students record report (Excel) of ' . $courseLabel . ($yearSectionFilter !== '' ? ' ' . $yearSectionFilter : ''),
        [
            'category'    => 'audit',
            'target_type' => 'report',
            'details'     => [
                'format'       => 'Excel',
                'course'       => $courseLabel,
                'year_section' => $yearSectionFilter !== '' ? $yearSectionFilter : 'All',
                'status'       => $statusLabels[$statusFilter],
                'student_type' => $typeFilter !== '' ? STUDENT_TYPES[$typeFilter] : 'All',
                'students'     => $total,
            ],
        ]);
    $fileName = 'Students_Record_Report_' . ($namePart !== '' ? $namePart : 'All') . '_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit();
}

$pdf = new ClassReportPdf('P', 'mm', [215.9, 355.6]);
$pdf->AliasNbPages();
$pdf->SetTitle(reportText('Students Record Report - ' . $courseLabel . ($yearSectionFilter !== '' ? ' ' . $yearSectionFilter : '')));
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
$pdf->Cell(0, 8, reportText('STUDENTS RECORD REPORT'), 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(90, 90, 90);
$pdf->Cell(0, 5, reportText('Date and time generated: ' . $generatedAt), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

$pdf->SectionTitle('Report Details');
foreach ($filterRows as $i => $filterRow) {
    $pdf->TableRow([42, $contentWidth - 42], [$filterRow[0], $filterRow[1]], 5.2, [['font' => 'B'], []], $i % 2 === 1);
}

$pdf->SectionTitle('Summary');
$summaryWidths = [$contentWidth / 3, $contentWidth / 3, $contentWidth / 3];
$pdf->TableHeader($summaryWidths, ['Total Students', 'Complete Requirements', 'Incomplete Requirements']);
$pdf->TableRow($summaryWidths, [
    (string) $total,
    (string) $completeCount,
    (string) ($total - $completeCount),
], 6, [
    ['font' => 'B', 'align' => 'C'],
    ['font' => 'B', 'align' => 'C', 'color' => [25, 120, 60]],
    ['font' => 'B', 'align' => 'C', 'color' => [180, 30, 40]],
]);

$pdf->SectionTitle('Student Records');
if (!$students) {
    $pdf->SetFont('Helvetica', 'I', 9.5);
    $pdf->Cell(0, 6, reportText('No students match the selected filters.'), 0, 1);
} else {
    $widths = [8, 40, 22, 0, 16, 22, 22];
    $widths[3] = $contentWidth - array_sum($widths);
    $titles = ['No.', 'Student', 'Completion', 'Document', 'Status', 'Date Uploaded', 'Uploaded By'];
    $byCourse = [];
    foreach ($students as $row) {
        $byCourse[$row['group_course']][] = $row;
    }
    foreach ($byCourse as $course => $courseStudents) {
        $pdf->CourseHeading($course . '  (' . count($courseStudents) . ' student' . (count($courseStudents) === 1 ? '' : 's') . ')');
        $pdf->TableHeader($widths, $titles);
        foreach ($courseStudents as $i => $row) {
            $completion = $row['completion'];
            $details = trim((string) ($row['year_section'] ?? '')) !== '' ? $row['year_section'] . ' | ' : '';
            $details .= (STUDENT_TYPES[studentTypeOf($row['admission_track'] ?? '')] ?? 'Regular') . ' | '
                . (($row['status'] ?? '') === 'graduated' ? 'Graduate' : 'Enrollee');
            $left = [
                [[(string) ($i + 1), '']],
                [[(string) ($row['name'] ?? ''), 'B'], [(string) ($row['student_number'] ?? ''), ''], [$details, '']],
                [[$completion['complete'] ? 'Complete' : "Incomplete\n(" . $completion['fulfilled'] . ' of ' . $completion['total'] . ')', 'B']],
            ];
            $leftStyles = [
                ['align' => 'C'],
                [],
                ['color' => $completion['complete'] ? [25, 120, 60] : [180, 30, 40]],
            ];
            $lines = [];
            foreach ($documentsByStudent[(int) $row['student_id']] ?? [] as $doc) {
                $lines[] = [
                    [reportDocumentLabel($doc, $requirementLabels), [], ],
                    ['Submitted', ['color' => [25, 120, 60]]],
                    [reportDate($doc['uploaded_at'] ?? '', 'M j, Y g:i A'), []],
                    [(string) ($doc['added_by'] ?? '') !== '' ? (string) $doc['added_by'] : '-', []],
                ];
            }
            foreach ($completion['missing'] as $label) {
                $lines[] = [
                    [$label, []],
                    ['Missing', ['font' => 'B', 'color' => [180, 30, 40]]],
                    ['-', []],
                    ['-', []],
                ];
            }
            if (!$lines) {
                $lines[] = [['No documents submitted', ['font' => 'I']], ['-', []], ['-', []], ['-', []]];
            }
            $pdf->GroupRow($widths, $titles, $left, $leftStyles, $lines, $i % 2 === 1);
        }
    }
}

if ($missingCounts) {
    $pdf->SectionTitle('Most Commonly Missing Documents');
    $widths = [10, $contentWidth - 50, 40];
    $pdf->TableHeader($widths, ['No.', 'Document', 'Students Missing']);
    $n = 0;
    foreach ($missingCounts as $label => $count) {
        $pdf->TableRow($widths, [(string) (++$n), $label, (string) $count], 5.2, [['align' => 'C'], [], ['align' => 'C']], $n % 2 === 0);
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

$fileName = 'Students_Record_Report_' . ($namePart !== '' ? $namePart : 'All') . '_' . date('Ymd_His') . '.pdf';

if (!$download) {
    logActivity('report_generated',
        'Generated the students record report of ' . $courseLabel . ($yearSectionFilter !== '' ? ' ' . $yearSectionFilter : ''),
        [
            'category'    => 'audit',
            'target_type' => 'report',
            'details'     => [
                'course'         => $courseLabel,
                'year_section'   => $yearSectionFilter !== '' ? $yearSectionFilter : 'All',
                'status'         => $statusLabels[$statusFilter],
                'student_type'   => $typeFilter !== '' ? STUDENT_TYPES[$typeFilter] : 'All',
                'students'       => $total,
                'complete'       => $completeCount,
                'incomplete'     => $total - $completeCount,
            ],
        ]);
}

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
$pdf->Output($download ? 'D' : 'I', $fileName);