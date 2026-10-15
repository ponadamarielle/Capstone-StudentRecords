<?php
require_once __DIR__ . '/course_catalog.php';
require_once __DIR__ . '/../activity_log.php';

if (!defined('DOCUMENTS_DIR')) {
    define('DOCUMENTS_DIR', '../uploads/documents/');
}
if (!defined('ARCHIVE_DIR')) {
    define('ARCHIVE_DIR', '../uploads/archive/');
}

const ARCHIVED_STATUSES     = ['graduated', 'inactive', 'onleave'];
const SCHOOL_YEAR_END_DAY   = '07-31';
const PROGRAM_LENGTH_YEARS  = 4;

function studentColumns($conn)
{
    static $names = null;
    if ($names === null) {
        $names = [];
        $result = mysqli_query($conn, "SHOW COLUMNS FROM students");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $names[] = $row['Field'];
            }
        }
    }
    return $names;
}

function studentHasArchivedAt($conn)
{
    return in_array('archived_at', studentColumns($conn), true);
}

function safeName($value, $fallback)
{
    $value = preg_replace('/[<>:"\/\\\\|?*#%\x00-\x1F]/', ' ', (string) $value);
    $value = trim(preg_replace('/\s+/', ' ', $value), " .");
    if ($value === '') {
        $value = $fallback;
    }
    return substr($value, 0, 80);
}

function isPathInside($path, $dir)
{
    $real = realpath($path);
    $base = realpath($dir);
    return $real !== false && $base !== false && strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
}

function uniqueTarget($dir, $fileName, array &$reserved)
{
    $info = pathinfo((string) $fileName);
    $base = safeName($info['filename'] ?? '', 'document');
    $ext  = strtolower($info['extension'] ?? '') ?: 'pdf';

    $target = $dir . $base . '.' . $ext;
    $i = 2;
    while (file_exists($target) || isset($reserved[strtolower($target)])) {
        $target = $dir . $base . ' (' . $i++ . ').' . $ext;
    }
    $reserved[strtolower($target)] = true;
    return $target;
}

function moveFiles(array $moves)
{
    $done = [];
    foreach ($moves as $move) {
        if (!@rename($move['from'], $move['to'])) {
            undoMoves($done);
            throw new Exception('Could not move "' . basename($move['from']) . '". Check folder permissions.');
        }
        $done[] = $move;
    }
    return $done;
}

function undoMoves(array $done)
{
    foreach (array_reverse($done) as $move) {
        @rename($move['to'], $move['from']);
    }
}

function removeEmptyArchiveDirs($dir)
{
    $base = realpath(ARCHIVE_DIR);
    $real = realpath($dir);
    while ($real !== false && $base !== false && $real !== $base
        && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
        $entries = @scandir($real);
        if ($entries === false || count($entries) > 2 || !@rmdir($real)) {
            break;
        }
        $real = dirname($real);
    }
}

function courseFolderName($course)
{
    $entry = resolveCourse($course);
    return $entry ? safeName($entry['code'], 'No Course') : safeName($course, 'No Course');
}

function archiveStudentRecord($conn, $studentId, $status)
{
    if (!in_array($status, ARCHIVED_STATUSES, true)) {
        return ['success' => false, 'message' => 'Only GRADUATE, IN-ACTIVE or ON-LEAVE students can be archived.'];
    }
    $studentId = (int) $studentId;

    $stmt = mysqli_prepare($conn,
        "SELECT student_number, name, course, year_section FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$student) {
        return ['success' => false, 'message' => 'Student not found.'];
    }

    $studentLabel = trim(($student['student_number'] ?? '') . ' - ' . ($student['name'] ?? ''), " -");
    $folder = ARCHIVE_DIR
        . date('Y') . '/'
        . courseFolderName($student['course']) . '/'
        . safeName($student['year_section'], 'No Year & Section') . '/'
        . safeName($studentLabel, 'Student ' . $studentId) . '/';

    if (!is_dir($folder) && !@mkdir($folder, 0755, true)) {
        return ['success' => false, 'message' => 'Could not create the archive folder. Check folder permissions.'];
    }

    $docsStmt = mysqli_prepare($conn, "SELECT document_id, file_name, file_path FROM documents WHERE student_id = ?");
    mysqli_stmt_bind_param($docsStmt, "i", $studentId);
    mysqli_stmt_execute($docsStmt);
    $documents = mysqli_fetch_all(mysqli_stmt_get_result($docsStmt), MYSQLI_ASSOC);

    $moves    = [];
    $missing  = [];
    $reserved = [];
    foreach ($documents as $doc) {
        $path = $doc['file_path'] ?? '';
        $name = ($doc['file_name'] ?? '') !== '' ? $doc['file_name'] : basename($path);

        if ($path === '' || !is_file($path)) {
            $missing[] = $name;
            continue;
        }
        if (!isPathInside($path, DOCUMENTS_DIR)) {
            continue; 
        }
        $moves[] = [
            'document_id' => (int) $doc['document_id'],
            'from'        => $path,
            'to'          => uniqueTarget($folder, $name, $reserved),
        ];
    }

    try {
        $done = moveFiles($moves);
    } catch (Exception $e) {
        removeEmptyArchiveDirs($folder);
        return ['success' => false, 'message' => 'The student was not archived. ' . $e->getMessage()];
    }

    mysqli_begin_transaction($conn);
    try {
        $studentStmt = mysqli_prepare($conn, studentHasArchivedAt($conn)
            ? "UPDATE students SET status = ?, archived_at = NOW() WHERE student_id = ?"
            : "UPDATE students SET status = ? WHERE student_id = ?");
        mysqli_stmt_bind_param($studentStmt, "si", $status, $studentId);
        if (!mysqli_stmt_execute($studentStmt)) {
            throw new Exception(mysqli_error($conn));
        }

        $pathStmt = mysqli_prepare($conn, "UPDATE documents SET file_path = ? WHERE document_id = ?");
        foreach ($done as $move) {
            mysqli_stmt_bind_param($pathStmt, "si", $move['to'], $move['document_id']);
            if (!mysqli_stmt_execute($pathStmt)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        $markStmt = mysqli_prepare($conn,
            "UPDATE documents SET is_archived = 1, archived_at = NOW() WHERE student_id = ?");
        mysqli_stmt_bind_param($markStmt, "i", $studentId);
        if (!mysqli_stmt_execute($markStmt)) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        undoMoves($done);
        removeEmptyArchiveDirs($folder);
        return ['success' => false, 'message' => 'Could not archive the student: ' . $e->getMessage()];
    }

    removeEmptyArchiveDirs($folder); 

    return [
        'success'   => true,
        'archived'  => true,
        'status'    => $status,
        'message'   => 'Student moved to Archive. ' . count($done) . ' document(s) moved to the archive folder.',
        'documents' => count($done),
        'skipped'   => $missing,
    ];
}

function entryYearFromStudentNumber($studentNumber)
{
    return preg_match('/^\s*((?:19|20)\d{2})/', (string) $studentNumber, $m) ? (int) $m[1] : null;
}

function expectedGraduationYear(array $student)
{
    if (!empty($student['graduation_year'])) {
        return (int) $student['graduation_year'];
    }
    $entryYear = entryYearFromStudentNumber($student['student_number'] ?? '');
    return $entryYear !== null ? $entryYear + PROGRAM_LENGTH_YEARS : null;
}

function schoolYearHasEnded($endYear)
{
    return date('Y-m-d') > sprintf('%04d-%s', (int) $endYear, SCHOOL_YEAR_END_DAY);
}

function currentSchoolYearEndYear()
{
    $year = (int) date('Y');
    return date('m-d') > SCHOOL_YEAR_END_DAY ? $year + 1 : $year;
}

function schoolYearLabel($endYear)
{
    return 'SY ' . ((int) $endYear - 1) . '-' . (int) $endYear;
}

function migrateEnrolleeStatus($conn)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'status'");
        $column = $result ? mysqli_fetch_assoc($result) : null;
        if ($column) {
            $type = strtolower((string) $column['Type']);
            $needsAlter = (strpos($type, 'enum(') === 0 && strpos($type, "'enrollee'") === false)
                || (string) $column['Default'] !== 'enrollee';
            if ($needsAlter) {
                mysqli_query($conn, "ALTER TABLE students MODIFY status VARCHAR(20) NULL DEFAULT 'enrollee'");
            }
        }
        mysqli_query($conn, "UPDATE students SET status = 'enrollee' WHERE status = 'active' OR status IS NULL OR status = ''");
    } catch (Throwable $e) {
        error_log('Enrollee status migration failed: ' . $e->getMessage());
    }
}

function archiveGraduatedStudents($conn, $limit = 30)
{
    migrateEnrolleeStatus($conn);
    $hasColumn = in_array('graduation_year', studentColumns($conn), true);
    $result = mysqli_query($conn, "SELECT student_id, student_number"
        . ($hasColumn ? ", graduation_year" : "")
        . " FROM students WHERE (status IN ('enrollee', 'active') OR status IS NULL OR status = '')");
    if (!$result) {
        return 0;
    }

    $archived = 0;
    while (($row = mysqli_fetch_assoc($result)) && $archived < $limit) {
        $endYear = expectedGraduationYear($row);
        if ($endYear === null || !schoolYearHasEnded($endYear)) {
            continue;
        }
        $outcome = archiveStudentRecord($conn, (int) $row['student_id'], 'graduated');
        if (!empty($outcome['success'])) {
            $archived++;
            $studentOpts = activityStudentOpts($conn, (int) $row['student_id']);
            logActivity('student_auto_archived',
                'Automatically archived ' . activityStudentLabel($studentOpts) . ' as GRADUATE',
                $studentOpts + [
                    'system'      => true,
                    'target_type' => 'student',
                    'target_id'   => $row['student_id'],
                    'details'     => [
                        'new_status'        => 'graduated',
                        'school_year_ended' => schoolYearLabel($endYear),
                        'documents_moved'   => $outcome['documents'] ?? 0,
                        'reason'            => 'Expected graduation school year has ended.',
                    ],
                ]);
        } else {
            error_log('Auto-archive of graduate ' . $row['student_number'] . ' failed: ' . ($outcome['message'] ?? ''));
        }
    }
    return $archived;
}

function yearLevelForGraduation($endYear)
{
    $level = PROGRAM_LENGTH_YEARS - ((int) $endYear - currentSchoolYearEndYear());
    return max(1, min(PROGRAM_LENGTH_YEARS, $level));
}

function graduationYearForLevel($level)
{
    $level = max(1, min(PROGRAM_LENGTH_YEARS, (int) $level));
    return currentSchoolYearEndYear() + (PROGRAM_LENGTH_YEARS - $level);
}

function yearLevelOf($yearSection)
{
    return preg_match('/^\s*(\d)\s*-\s*\d\s*$/', (string) $yearSection, $m) ? (int) $m[1] : null;
}

function applyYearLevel($conn, $studentId)
{
    $columns = studentColumns($conn);
    $stmt = mysqli_prepare($conn, "SELECT student_number, year_section"
        . (in_array('graduation_year', $columns, true) ? ", graduation_year" : "")
        . " FROM students WHERE student_id = ?");
    $studentId = (int) $studentId;
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$student || !preg_match('/^\s*\d\s*-\s*(\d)\s*$/', (string) $student['year_section'], $m)) {
        return null;
    }

    $endYear = expectedGraduationYear($student);
    if ($endYear === null) {
        return null;
    }

    $newYearSection = yearLevelForGraduation($endYear) . '-' . $m[1];
    if ($newYearSection === $student['year_section']) {
        return null;
    }

    $update = mysqli_prepare($conn, "UPDATE students SET year_section = ? WHERE student_id = ?");
    mysqli_stmt_bind_param($update, "si", $newYearSection, $studentId);
    mysqli_stmt_execute($update);

    return ['from' => $student['year_section'], 'to' => $newYearSection];
}

function updateYearLevels($conn)
{
    $result = mysqli_query($conn, "SELECT student_id FROM students WHERE (status IN ('enrollee', 'active') OR status IS NULL OR status = '')");
    if (!$result) {
        return 0;
    }

    $updated = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $change = applyYearLevel($conn, (int) $row['student_id']);
        if ($change === null) {
            continue;
        }
        $updated++;
        $studentOpts = activityStudentOpts($conn, (int) $row['student_id']);
        logActivity('student_updated',
            'Updated year level of ' . activityStudentLabel($studentOpts) . ' to ' . $change['to'] . ' for ' . schoolYearLabel(currentSchoolYearEndYear()),
            $studentOpts + [
                'system'      => true,
                'target_type' => 'student',
                'target_id'   => $row['student_id'],
                'details'     => [
                    'changes' => ['Year & Section' => $change],
                    'reason'  => 'Year level follows the Graduation SY and the current school year.',
                ],
            ]);
    }
    return $updated;
}

function fileUrl($path)
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function handleArchiveStudent($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    $status    = $_POST['status'] ?? '';

    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    echo json_encode(archiveStudentRecord($conn, (int) $studentId, $status));
}

function handleRestoreStudent($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    $studentId = (int) $studentId;

    $stmt = mysqli_prepare($conn, "SELECT status, student_number FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        return;
    }
    if (!in_array($student['status'], ARCHIVED_STATUSES, true)) {
        echo json_encode(['success' => false, 'message' => 'This student is not archived.']);
        return;
    }
    if ($student['status'] === 'graduated') {
        echo json_encode(['success' => false, 'message' => 'Graduated records cannot be restored. The record stays in the Archive.']);
        return;
    }

    if (!is_dir(DOCUMENTS_DIR) && !@mkdir(DOCUMENTS_DIR, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Could not create the documents folder.']);
        return;
    }

    $docsStmt = mysqli_prepare($conn, "SELECT document_id, file_path FROM documents WHERE student_id = ?");
    mysqli_stmt_bind_param($docsStmt, "i", $studentId);
    mysqli_stmt_execute($docsStmt);
    $documents = mysqli_fetch_all(mysqli_stmt_get_result($docsStmt), MYSQLI_ASSOC);

    $moves = [];
    foreach ($documents as $doc) {
        $path = $doc['file_path'] ?? '';
        if ($path === '' || !is_file($path) || !isPathInside($path, ARCHIVE_DIR)) {
            continue;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'pdf';
        $moves[] = [
            'document_id' => (int) $doc['document_id'],
            'from'        => $path,
            'to'          => DOCUMENTS_DIR . uniqid('doc_', true) . '.' . $ext,
        ];
    }

    try {
        $done = moveFiles($moves);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'The student was not restored. ' . $e->getMessage()]);
        return;
    }

    mysqli_begin_transaction($conn);
    try {
        $studentStmt = mysqli_prepare($conn, studentHasArchivedAt($conn)
            ? "UPDATE students SET status = 'enrollee', archived_at = NULL WHERE student_id = ?"
            : "UPDATE students SET status = 'enrollee' WHERE student_id = ?");
        mysqli_stmt_bind_param($studentStmt, "i", $studentId);
        if (!mysqli_stmt_execute($studentStmt)) {
            throw new Exception(mysqli_error($conn));
        }

        if ($student['status'] === 'graduated' && in_array('graduation_year', studentColumns($conn), true)) {
            $levelStmt = mysqli_prepare($conn, "SELECT year_section FROM students WHERE student_id = ?");
            mysqli_stmt_bind_param($levelStmt, "i", $studentId);
            mysqli_stmt_execute($levelStmt);
            $restoredLevel = yearLevelOf(mysqli_fetch_assoc(mysqli_stmt_get_result($levelStmt))['year_section'] ?? '');
            $newEndYear = $restoredLevel !== null ? graduationYearForLevel($restoredLevel) : currentSchoolYearEndYear();
            $gradStmt = mysqli_prepare($conn, "UPDATE students SET graduation_year = ? WHERE student_id = ?");
            mysqli_stmt_bind_param($gradStmt, "ii", $newEndYear, $studentId);
            if (!mysqli_stmt_execute($gradStmt)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        $pathStmt = mysqli_prepare($conn, "UPDATE documents SET file_path = ? WHERE document_id = ?");
        foreach ($done as $move) {
            mysqli_stmt_bind_param($pathStmt, "si", $move['to'], $move['document_id']);
            if (!mysqli_stmt_execute($pathStmt)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        $docsStmt = mysqli_prepare($conn,
            "UPDATE documents SET is_archived = 0, archived_at = NULL WHERE student_id = ?");
        mysqli_stmt_bind_param($docsStmt, "i", $studentId);
        if (!mysqli_stmt_execute($docsStmt)) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        undoMoves($done);
        echo json_encode(['success' => false, 'message' => 'Could not restore the student: ' . $e->getMessage()]);
        return;
    }

    foreach ($done as $move) {
        removeEmptyArchiveDirs(dirname($move['from']));
    }

    $studentOpts = activityStudentOpts($conn, $studentId);
    logActivity('student_restored',
        'Restored ' . activityStudentLabel($studentOpts) . ' to the Student Directory',
        $studentOpts + [
            'target_type' => 'student',
            'target_id'   => $studentId,
            'details'     => [
                'changes'         => ['Status' => ['from' => $student['status'], 'to' => 'enrollee']],
                'documents_moved' => count($done),
            ],
        ]);

    echo json_encode(['success' => true, 'message' => 'Student restored to the Student Directory.', 'status' => 'enrollee']);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) {
    return;
}

include '../security_headers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isAjax = in_array($action, ['archive_student', 'restore_student'], true);

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . mysqli_connect_error()]);
        exit();
    }
    die("Connection failed: " . mysqli_connect_error());
}

session_start();

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please sign in again.']);
        exit();
    }
    header("Location: ../signin.php");
    exit();
}

require_once __DIR__ . '/../account_guard.php';
requireActiveAccount($isAjax);

if (!$isAjax && isset($_SESSION['is_first_login']) && $_SESSION['is_first_login'] == 1) {
    header("Location: ../change_password.php");
    exit();
}

if ($action === 'archive_student') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    session_write_close();
    @set_time_limit(300);
    handleArchiveStudent($conn);
    exit();
}

if ($action === 'restore_student') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    session_write_close();
    @set_time_limit(300);
    handleRestoreStudent($conn);
    exit();
}

$search            = isset($_GET['search']) ? trim($_GET['search']) : '';
$courseFilter      = isset($_GET['course']) ? trim($_GET['course']) : '';
$yearSectionFilter = isset($_GET['year_section']) ? trim($_GET['year_section']) : '';

$conditions = ["s.status IN ('graduated', 'inactive', 'onleave')"];
$params     = [];
$types      = '';

if ($search !== '') {
    $conditions[] = '(s.student_number LIKE ? OR s.name LIKE ?)';
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
        $conditions[] = 's.course IN (?, ?, ?)';
        $params[] = $filterCourse['code'];
        $params[] = $filterCourse['name'];
        $params[] = courseDisplayName($filterCourse);
        $types   .= 'sss';
    } else {
        $conditions[] = 's.course = ?';
        $params[] = $courseFilter;
        $types   .= 's';
    }
}

if ($yearSectionFilter !== '') {
    $conditions[] = 's.year_section = ?';
    $params[] = $yearSectionFilter;
    $types   .= 's';
}

$sql = "SELECT s.student_id, s.student_number, s.name, s.course, s.year_section, s.status,
               COUNT(d.document_id) AS doc_count,
               " . (studentHasArchivedAt($conn) ? "COALESCE(s.archived_at, MAX(d.archived_at))" : "MAX(d.archived_at)") . " AS archived_at
        FROM students s
        LEFT JOIN documents d ON d.student_id = s.student_id
        WHERE " . implode(' AND ', $conditions) . "
        GROUP BY s.student_id
        ORDER BY archived_at IS NULL, archived_at DESC, s.name ASC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}
$students = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

$studentFiles = [];
$studentIds = array_map('intval', array_column($students, 'student_id'));
if (!empty($studentIds)) {
    $idList = implode(',', $studentIds);
    $filesResult = mysqli_query($conn,
        "SELECT student_id, file_name, file_path FROM documents
         WHERE student_id IN ($idList)
         ORDER BY document_id ASC");
    if ($filesResult) {
        while ($file = mysqli_fetch_assoc($filesResult)) {
            $studentFiles[(int) $file['student_id']][] = $file;
        }
    }
}

$courseOptions = [];
foreach (getCourseCatalog() as $catalogEntry) {
    $courseOptions[$catalogEntry['code']] = $catalogEntry['code'];
}

$yearSectionOptions = [];
$yearSectionResult = mysqli_query($conn,
    "SELECT DISTINCT year_section FROM students
     WHERE status IN ('graduated', 'inactive', 'onleave') AND year_section IS NOT NULL AND year_section <> ''
     ORDER BY year_section ASC");
if ($yearSectionResult) {
    while ($row = mysqli_fetch_assoc($yearSectionResult)) {
        $yearSectionOptions[] = $row['year_section'];
    }
}

$statusLabels = ['enrollee' => 'ENROLLEE', 'active' => 'ENROLLEE', 'graduated' => 'GRADUATE', 'inactive' => 'IN-ACTIVE', 'onleave' => 'ON-LEAVE'];

include 'navbar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archived Records</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css" rel="stylesheet">
    <link href="../css/archive-admin.css" rel="stylesheet">
</head>

<body>
    <div class="main-container">

    <div class="textNButton-row">

      <form method="GET" action="archive.php" class="search-group" id="archiveSearchForm">
        <div class="search-input-wrapper">
            <input type="text" name="search" id="archiveSearchInput" class="searchbar" placeholder="STUDENT NUMBER / NAME" value="<?= htmlspecialchars($search) ?>" required>
            <?php if ($search !== ''): ?>
                <a href="archive.php" class="clear-icon" title="Clear search"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
        </div>
        <button type="submit" class="search-icon">
            <i class="bi bi-search"></i>
        </button>
      </form>

      <div class="add-filter">
        <form method="GET" action="archive.php" class="filter-row" id="archiveFilterForm">
          <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

          <select class="filter" id="archiveCourseFilter" name="course">
              <option value="">Course</option>
              <?php foreach ($courseOptions as $courseValue => $courseLabel): ?>
                  <option value="<?= htmlspecialchars($courseValue) ?>" <?= $courseFilter === $courseValue ? 'selected' : '' ?>>
                      <?= htmlspecialchars($courseLabel) ?>
                  </option>
              <?php endforeach; ?>
          </select>

          <select class="filter filter-year-section" id="archiveYearSectionFilter" name="year_section">
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
    <table class="student-table archive-table">
    <tr>
        <th>Student Number</th>
        <th>Name</th>
        <th>Course</th>
        <th>Year & Section</th>
        <th>Status</th>
        <th>Date Archived</th>
        <th>Records</th>
    </tr>

    <?php if (!empty($students)): ?>
        <?php foreach ($students as $row): ?>
            <?php
                $sid   = (int) $row['student_id'];
                $files = $studentFiles[$sid] ?? [];
            ?>
        <tr>
            <td><?= htmlspecialchars($row['student_number']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['course']) ?></td>
            <td><?= htmlspecialchars($row['year_section']) ?></td>
            <td>
                <span class="archive-status archive-status-<?= htmlspecialchars($row['status']) ?>">
                    <?= htmlspecialchars($statusLabels[$row['status']] ?? strtoupper((string) $row['status'])) ?>
                </span>
            </td>
            <td>
                <?php if (!empty($row['archived_at'])): ?>
                    <?php $archivedTs = strtotime($row['archived_at']); ?>
                    <span class="archive-date"><?= htmlspecialchars(date('M j, Y', $archivedTs)) ?></span>
                    <span class="archive-time"><?= htmlspecialchars(date('g:i A', $archivedTs)) ?></span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <div class="archive-records">
                    <?php if (!empty($files)): ?>
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle archive-files-btn"
                                    data-bs-toggle="dropdown" aria-expanded="false" title="View archived documents">
                                <i class="bi bi-folder2-open"></i> Files (<?= count($files) ?>)
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end archive-drive-menu">
                                <li><h6 class="dropdown-header">Archived documents</h6></li>
                                <?php foreach ($files as $file): ?>
                                    <?php $exists = ($file['file_path'] ?? '') !== '' && is_file($file['file_path']); ?>
                                    <li>
                                        <?php if ($exists): ?>
                                            <a class="dropdown-item" href="<?= htmlspecialchars(fileUrl($file['file_path'])) ?>" target="_blank" rel="noopener">
                                                <i class="bi bi-file-earmark-pdf"></i> <?= htmlspecialchars($file['file_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="dropdown-item disabled text-muted" title="File is missing on the server">
                                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($file['file_name']) ?> (missing)
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="7" class="no-students-message">No archived records found.</td>
        </tr>
    <?php endif; ?>
    </table>
    </div>
    </div>

<!-- confirm restore modal -->
<div class="modal fade" id="archiveRestoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Restore</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Are you sure you want to restore <strong id="archiveRestoreName"></strong>?
                </p>
                <div id="archiveRestoreError" class="alert alert-danger small mt-3 mb-0 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" id="archiveRestoreCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save archive-nowrap-btn" id="archiveRestoreConfirmBtn">Restore</button>
            </div>
        </div>
    </div>
</div>

<script src="../js/archive.js"></script>
</body>
</html>