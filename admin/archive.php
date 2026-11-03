<?php
require_once __DIR__ . '/course_catalog.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../school_year.php';

if (!defined('DOCUMENTS_DIR')) {
    define('DOCUMENTS_DIR', '../uploads/documents/');
}
if (!defined('ARCHIVE_DIR')) {
    define('ARCHIVE_DIR', '../uploads/archive/');
}

const ARCHIVED_STATUSES     = ['graduated', 'withdrawn', 'onleave', 'transferred', 'inactive'];
const LEFT_STATUSES         = ['withdrawn', 'onleave', 'transferred'];
const RESTORABLE_STATUSES   = ['withdrawn', 'onleave', 'inactive'];
const PROGRAM_LENGTH_YEARS  = 4;
const GRADUATE_ARCHIVE_YEARS = 4;

function studentColumns($conn, $refresh = false)
{
    static $names = null;
    if ($names === null || $refresh) {
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

function ensureStudentArchivedAt($conn)
{
    static $checked = false;
    if ($checked) {
        return studentHasArchivedAt($conn);
    }
    $checked = true;
    if (studentHasArchivedAt($conn)) {
        return true;
    }
    try {
        mysqli_query($conn, "ALTER TABLE students ADD COLUMN archived_at DATETIME NULL DEFAULT NULL");
        studentColumns($conn, true);
        mysqli_query($conn, "UPDATE students s SET archived_at = (SELECT MAX(d.archived_at) FROM documents d WHERE d.student_id = s.student_id AND d.is_archived = 1) WHERE s.status IN ('graduated', 'withdrawn', 'onleave', 'transferred', 'inactive') AND s.archived_at IS NULL");
    } catch (Throwable $e) {
        error_log('Adding students.archived_at failed: ' . $e->getMessage());
    }
    return studentHasArchivedAt($conn);
}

function archivedStudentCondition($conn, $alias = 's')
{
    $a = $alias !== '' ? $alias . '.' : '';
    if (!ensureStudentArchivedAt($conn)) {
        return "{$a}status IN ('graduated', 'withdrawn', 'onleave', 'transferred', 'inactive')";
    }
    return "({$a}status IN ('withdrawn', 'onleave', 'transferred', 'inactive') OR ({$a}status = 'graduated' AND {$a}archived_at IS NOT NULL))";
}

function directoryStudentCondition($conn, $alias = '')
{
    $a = $alias !== '' ? $alias . '.' : '';
    $base = "{$a}status IN ('enrollee', 'active') OR {$a}status IS NULL OR {$a}status = ''";
    if (!ensureStudentArchivedAt($conn)) {
        return "($base)";
    }
    return "($base OR ({$a}status = 'graduated' AND {$a}archived_at IS NULL))";
}

function isStudentArchived(array $student)
{
    $status = $student['status'] ?? '';
    if (in_array($status, ['withdrawn', 'onleave', 'transferred', 'inactive'], true)) {
        return true;
    }
    if ($status !== 'graduated') {
        return false;
    }
    return !array_key_exists('archived_at', $student) || !empty($student['archived_at']);
}

function syncGraduateStatus($conn, $studentId)
{
    $studentId = (int) $studentId;
    $columns = studentColumns($conn);
    $stmt = mysqli_prepare($conn, "SELECT student_id, student_number, status"
        . (in_array('graduation_year', $columns, true) ? ", graduation_year" : "")
        . (ensureStudentArchivedAt($conn) ? ", archived_at" : "")
        . " FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$student || isStudentArchived($student)) {
        return null;
    }

    $status  = (string) ($student['status'] ?? '');
    $endYear = expectedGraduationYear($student);
    $ended   = $endYear !== null && schoolYearHasEnded($endYear);
    if ($ended && in_array($status, ['enrollee', 'active', ''], true)) {
        $newStatus = 'graduated';
    } elseif (!$ended && $status === 'graduated') {
        $newStatus = 'enrollee';
    } else {
        return null;
    }

    $update = mysqli_prepare($conn, "UPDATE students SET status = ? WHERE student_id = ?");
    mysqli_stmt_bind_param($update, "si", $newStatus, $studentId);
    if (!mysqli_stmt_execute($update)) {
        return null;
    }

    $studentOpts = activityStudentOpts($conn, $studentId);
    logActivity('student_status_changed',
        'Changed status of ' . activityStudentLabel($studentOpts) . ' to ' . ($newStatus === 'graduated' ? 'GRADUATE' : 'ENROLLEE'),
        $studentOpts + [
            'system'      => true,
            'target_type' => 'student',
            'target_id'   => $studentId,
            'details'     => [
                'changes' => ['Status' => ['from' => $status !== '' ? $status : 'enrollee', 'to' => $newStatus]],
                'reason'  => $newStatus === 'graduated'
                    ? 'Year of Graduation school year has ended.'
                    : 'Year of Graduation school year has not ended yet.',
            ],
        ]);
    return $newStatus;
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

function studentMissingRequirements($conn, $studentId)
{
    if (function_exists('requirementsForClient')) {
        $requirements = requirementsForClient();
    } elseif (function_exists('getDocumentRequirementsForClient')) {
        $requirements = array_map(function ($r) {
            if (preg_match('/chest[\s_-]*x[\s_-]*-?ray|xray/i', ($r['label'] ?? '') . ' ' . ($r['code'] ?? ''))) {
                $r['required_for'] = [];
            }
            return $r;
        }, getDocumentRequirementsForClient());
    } else {
        return [];
    }

    $studentId = (int) $studentId;
    $stmt = mysqli_prepare($conn, "SELECT admission_track FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$student) {
        return [];
    }
    $track = function_exists('normalizeAdmissionTrack')
        ? normalizeAdmissionTrack($student['admission_track'] ?? '')
        : ($student['admission_track'] ?? '');

    $codeStmt = mysqli_prepare($conn, "SELECT requirement_code FROM documents WHERE student_id = ?");
    mysqli_stmt_bind_param($codeStmt, "i", $studentId);
    mysqli_stmt_execute($codeStmt);
    $onFile = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($codeStmt), MYSQLI_ASSOC) as $doc) {
        if (($doc['requirement_code'] ?? '') !== '') {
            $onFile[$doc['requirement_code']] = true;
        }
    }

    $missing = [];
    foreach ($requirements as $r) {
        $requiredFor = $r['required_for'] ?? [];
        if (is_array($requiredFor) && in_array($track, $requiredFor, true) && !isset($onFile[$r['code']])) {
            $missing[] = $r['label'] ?? $r['code'];
        }
    }
    return $missing;
}

function archiveFolderFor(array $student, $studentId)
{
    $studentLabel = trim(($student['student_number'] ?? '') . ' - ' . ($student['name'] ?? ''), " -");
    return ARCHIVE_DIR
        . courseFolderName($student['course'] ?? '') . '/'
        . safeName($studentLabel, 'Student ' . (int) $studentId) . '/';
}

function archiveFileName(array $doc)
{
    $code = $doc['requirement_code'] ?? '';
    if ($code !== '' && $code !== null && function_exists('isStorableRequirement') && isStorableRequirement($code)) {
        return requirementLabel($code) . '.pdf';
    }
    return ($doc['file_name'] ?? '') !== '' ? $doc['file_name'] : basename((string) ($doc['file_path'] ?? ''));
}

function relocateArchivedFiles($conn)
{
    static $done = false;
    if ($done) {
        return 0;
    }
    $done = true;
    $result = mysqli_query($conn,
        "SELECT d.document_id, d.file_name, d.file_path, d.requirement_code, s.student_id, s.student_number, s.name, s.course
         FROM documents d JOIN students s ON s.student_id = d.student_id
         WHERE d.is_archived = 1 AND d.file_path LIKE '" . mysqli_real_escape_string($conn, ARCHIVE_DIR) . "%'
         ORDER BY s.student_id, d.document_id");
    if (!$result) {
        return 0;
    }
    $moved    = 0;
    $reserved = [];
    $oldDirs  = [];
    $update   = mysqli_prepare($conn, "UPDATE documents SET file_path = ? WHERE document_id = ?");
    while ($doc = mysqli_fetch_assoc($result)) {
        $path   = (string) $doc['file_path'];
        $folder = archiveFolderFor($doc, (int) $doc['student_id']);
        if (dirname($path) . '/' === $folder || !is_file($path) || !isPathInside($path, ARCHIVE_DIR)) {
            continue;
        }
        if (!is_dir($folder) && !@mkdir($folder, 0755, true)) {
            continue;
        }
        $target = uniqueTarget($folder, archiveFileName($doc), $reserved);
        if (!@rename($path, $target)) {
            continue;
        }
        $documentId = (int) $doc['document_id'];
        mysqli_stmt_bind_param($update, "si", $target, $documentId);
        if (!mysqli_stmt_execute($update)) {
            @rename($target, $path);
            continue;
        }
        $oldDirs[dirname($path)] = true;
        $moved++;
    }
    foreach (array_keys($oldDirs) as $dir) {
        removeEmptyArchiveDirs($dir);
    }
    return $moved;
}

function archiveStudentRecord($conn, $studentId, $status, $force = false)
{
    if (!in_array($status, ARCHIVED_STATUSES, true)) {
        return ['success' => false, 'message' => 'Only GRADUATE, WITHDRAWN, ON-LEAVE or TRANSFERRED students can be archived.'];
    }
    $studentId = (int) $studentId;
    ensureStudentArchivedAt($conn);

    if ($status === 'graduated' && !$force) {
        $missingRequirements = studentMissingRequirements($conn, $studentId);
        if (!empty($missingRequirements)) {
            return [
                'success'    => false,
                'incomplete' => true,
                'missing'    => $missingRequirements,
                'message'    => 'This student cannot be archived as GRADUATE because the requirements are incomplete. Missing: ' . implode(', ', $missingRequirements) . '.',
            ];
        }
    }

    $stmt = mysqli_prepare($conn,
        "SELECT student_number, name, course, year_section FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$student) {
        return ['success' => false, 'message' => 'Student not found.'];
    }

    $folder = archiveFolderFor($student, $studentId);

    if (!is_dir($folder) && !@mkdir($folder, 0755, true)) {
        return ['success' => false, 'message' => 'Could not create the archive folder. Check folder permissions.'];
    }

    $docsStmt = mysqli_prepare($conn, "SELECT document_id, file_name, file_path, requirement_code FROM documents WHERE student_id = ?");
    mysqli_stmt_bind_param($docsStmt, "i", $studentId);
    mysqli_stmt_execute($docsStmt);
    $documents = mysqli_fetch_all(mysqli_stmt_get_result($docsStmt), MYSQLI_ASSOC);

    $moves    = [];
    $missing  = [];
    $reserved = [];
    foreach ($documents as $doc) {
        $path = $doc['file_path'] ?? '';
        $name = archiveFileName($doc);

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
    return date('Y-m-d') > schoolYearEndDate($endYear);
}

function currentSchoolYearEndYear()
{
    $year = (int) date('Y');
    return date('Y-m-d') > schoolYearEndDate($year) ? $year + 1 : $year;
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
            $needsAlter = (strpos($type, 'enum(') === 0 && (strpos($type, "'enrollee'") === false || strpos($type, "'withdrawn'") === false || strpos($type, "'transferred'") === false))
                || (string) $column['Default'] !== 'enrollee';
            if ($needsAlter) {
                mysqli_query($conn, "ALTER TABLE students MODIFY status VARCHAR(20) NULL DEFAULT 'enrollee'");
            }
        }
        mysqli_query($conn, "UPDATE students SET status = 'enrollee' WHERE status = 'active' OR status IS NULL OR status = ''");
        mysqli_query($conn, "UPDATE students SET status = 'withdrawn' WHERE status = 'inactive'");
    } catch (Throwable $e) {
        error_log('Enrollee status migration failed: ' . $e->getMessage());
    }
}

function markGraduatedStudents($conn, $limit = 200)
{
    migrateEnrolleeStatus($conn);
    $hasColumn = in_array('graduation_year', studentColumns($conn), true);
    $result = mysqli_query($conn, "SELECT student_id, student_number"
        . ($hasColumn ? ", graduation_year" : "")
        . " FROM students WHERE (status IN ('enrollee', 'active') OR status IS NULL OR status = '')");
    if (!$result) {
        return 0;
    }

    $marked = 0;
    $update = mysqli_prepare($conn, "UPDATE students SET status = 'graduated'"
        . (studentHasArchivedAt($conn) ? ", archived_at = NULL" : "")
        . " WHERE student_id = ?");
    while (($row = mysqli_fetch_assoc($result)) && $marked < $limit) {
        $endYear = expectedGraduationYear($row);
        if ($endYear === null || !schoolYearHasEnded($endYear)) {
            continue;
        }
        $studentId = (int) $row['student_id'];
        mysqli_stmt_bind_param($update, "i", $studentId);
        if (!mysqli_stmt_execute($update)) {
            error_log('Marking graduate ' . $row['student_number'] . ' failed: ' . mysqli_error($conn));
            continue;
        }
        $marked++;
        $studentOpts = activityStudentOpts($conn, $studentId);
        logActivity('student_status_changed',
            'Changed status of ' . activityStudentLabel($studentOpts) . ' to GRADUATE',
            $studentOpts + [
                'system'      => true,
                'target_type' => 'student',
                'target_id'   => $studentId,
                'details'     => [
                    'changes'           => ['Status' => ['from' => 'enrollee', 'to' => 'graduated']],
                    'school_year_ended' => schoolYearLabel($endYear),
                    'reason'            => 'Year of Graduation school year has ended.',
                ],
            ]);
    }
    return $marked;
}

function returnGraduateToDirectory($conn, $studentId)
{
    $studentId = (int) $studentId;
    if (!is_dir(DOCUMENTS_DIR) && !@mkdir(DOCUMENTS_DIR, 0755, true)) {
        return ['success' => false, 'message' => 'Could not create the documents folder.'];
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
        return ['success' => false, 'message' => $e->getMessage()];
    }

    mysqli_begin_transaction($conn);
    try {
        if (studentHasArchivedAt($conn)) {
            $studentStmt = mysqli_prepare($conn, "UPDATE students SET archived_at = NULL WHERE student_id = ?");
            mysqli_stmt_bind_param($studentStmt, "i", $studentId);
            if (!mysqli_stmt_execute($studentStmt)) {
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

        $markStmt = mysqli_prepare($conn,
            "UPDATE documents SET is_archived = 0, archived_at = NULL WHERE student_id = ?");
        mysqli_stmt_bind_param($markStmt, "i", $studentId);
        if (!mysqli_stmt_execute($markStmt)) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        undoMoves($done);
        return ['success' => false, 'message' => $e->getMessage()];
    }

    foreach ($done as $move) {
        removeEmptyArchiveDirs(dirname($move['from']));
    }
    return ['success' => true, 'documents' => count($done)];
}

function archiveGraduatedStudents($conn, $limit = 30)
{
    markGraduatedStudents($conn);
    if (!ensureStudentArchivedAt($conn)) {
        return 0;
    }

    $hasColumn = in_array('graduation_year', studentColumns($conn), true);
    $result = mysqli_query($conn, "SELECT s.student_id, s.student_number, s.archived_at"
        . ($hasColumn ? ", s.graduation_year" : "")
        . ", (SELECT COUNT(*) FROM documents d WHERE d.student_id = s.student_id AND d.is_archived = 1) AS archived_docs"
        . " FROM students s WHERE s.status = 'graduated'");
    if (!$result) {
        return 0;
    }

    $archived = 0;
    $returned = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $endYear = expectedGraduationYear($row);
        if ($endYear === null) {
            continue;
        }
        $studentId  = (int) $row['student_id'];
        $isArchived = !empty($row['archived_at']);
        $longAgo    = schoolYearHasEnded($endYear + GRADUATE_ARCHIVE_YEARS);

        if ($longAgo && !$isArchived && $archived < $limit) {
            $outcome = archiveStudentRecord($conn, $studentId, 'graduated', true);
            if (empty($outcome['success'])) {
                error_log('Auto-archive of graduate ' . $row['student_number'] . ' failed: ' . ($outcome['message'] ?? ''));
                continue;
            }
            $archived++;
            $studentOpts = activityStudentOpts($conn, $studentId);
            logActivity('student_auto_archived',
                'Automatically archived ' . activityStudentLabel($studentOpts) . ' as GRADUATE',
                $studentOpts + [
                    'system'      => true,
                    'target_type' => 'student',
                    'target_id'   => $studentId,
                    'details'     => [
                        'new_status'      => 'graduated',
                        'year_graduated'  => $endYear,
                        'documents_moved' => $outcome['documents'] ?? 0,
                        'reason'          => 'Graduated ' . GRADUATE_ARCHIVE_YEARS . ' or more years ago.',
                    ],
                ]);
        } elseif (!$longAgo && ($isArchived || (int) $row['archived_docs'] > 0) && $returned < $limit) {
            $outcome = returnGraduateToDirectory($conn, $studentId);
            if (empty($outcome['success'])) {
                error_log('Returning graduate ' . $row['student_number'] . ' to the directory failed: ' . ($outcome['message'] ?? ''));
                continue;
            }
            $returned++;
            $studentOpts = activityStudentOpts($conn, $studentId);
            logActivity('student_restored',
                'Moved ' . activityStudentLabel($studentOpts) . ' back to the Student Directory as GRADUATE',
                $studentOpts + [
                    'system'      => true,
                    'target_type' => 'student',
                    'target_id'   => $studentId,
                    'details'     => [
                        'year_graduated'  => $endYear,
                        'documents_moved' => $outcome['documents'] ?? 0,
                        'reason'          => 'Graduates stay in the Student Directory until ' . GRADUATE_ARCHIVE_YEARS . ' years after graduation.',
                    ],
                ]);
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
    if (!in_array($student['status'], RESTORABLE_STATUSES, true)) {
        echo json_encode(['success' => false, 'message' => 'Transferred records cannot be restored. The record stays in the Archive.']);
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

        if (in_array('graduation_year', studentColumns($conn), true)) {
            $levelStmt = mysqli_prepare($conn, "SELECT year_section FROM students WHERE student_id = ?");
            mysqli_stmt_bind_param($levelStmt, "i", $studentId);
            mysqli_stmt_execute($levelStmt);
            $restoredLevel = yearLevelOf(mysqli_fetch_assoc(mysqli_stmt_get_result($levelStmt))['year_section'] ?? '');
            $newEndYear = $restoredLevel !== null ? graduationYearForLevel($restoredLevel) : null;
            if ($newEndYear !== null) {
                $gradStmt = mysqli_prepare($conn, "UPDATE students SET graduation_year = ? WHERE student_id = ?");
                mysqli_stmt_bind_param($gradStmt, "ii", $newEndYear, $studentId);
                if (!mysqli_stmt_execute($gradStmt)) {
                    throw new Exception(mysqli_error($conn));
                }
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

$search            = isset($_GET['search']) ? trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\\p{L}\\p{M}0-9\\s.,'\\-]/u", '', $_GET['search']))) : '';
$courseFilter      = isset($_GET['course']) ? trim($_GET['course']) : '';
$hasGradColumn     = in_array('graduation_year', studentColumns($conn), true);

archiveGraduatedStudents($conn);
require_once __DIR__ . '/document_classifier.php';
relocateArchivedFiles($conn);

$conditions = [archivedStudentCondition($conn)];
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

$sql = "SELECT s.student_id, s.student_number, s.name, s.course, s.year_section, s.status," . ($hasGradColumn ? ' s.graduation_year,' : '') . "
               COUNT(d.document_id) AS doc_count,
               " . (studentHasArchivedAt($conn) ? "COALESCE(s.archived_at, MAX(d.archived_at))" : "MAX(d.archived_at)") . " AS archived_at
        FROM students s
        LEFT JOIN documents d ON d.student_id = s.student_id
        WHERE " . implode(' AND ', $conditions) . "
        GROUP BY s.student_id
        ORDER BY " . (studentHasArchivedAt($conn) ? "COALESCE(s.archived_at, MAX(d.archived_at))" : "MAX(d.archived_at)") . " IS NULL, " . (studentHasArchivedAt($conn) ? "COALESCE(s.archived_at, MAX(d.archived_at))" : "MAX(d.archived_at)") . " DESC, s.name ASC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}
$students = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
foreach ($students as &$studentRow) {
    $studentRow['grad_year'] = $studentRow['status'] === 'graduated' ? expectedGraduationYear($studentRow) : null;
}
unset($studentRow);

$studentFiles = [];
$studentIds = array_map('intval', array_column($students, 'student_id'));
if (!empty($studentIds)) {
    require_once __DIR__ . '/document_classifier.php';
    $idList = implode(',', $studentIds);
    $filesResult = mysqli_query($conn,
        "SELECT student_id, file_name, file_path, requirement_code FROM documents
         WHERE student_id IN ($idList)
         ORDER BY document_id ASC");
    if ($filesResult) {
        while ($file = mysqli_fetch_assoc($filesResult)) {
            $studentFiles[(int) $file['student_id']][] = $file;
        }
    }
}

$courseOptions = [];
foreach (officialCourseCatalog() as $catalogEntry) {
    $courseOptions[$catalogEntry['code']] = courseDisplayName($catalogEntry);
}
$extraCourseResult = mysqli_query($conn, "SELECT DISTINCT course FROM students WHERE " . archivedStudentCondition($conn, '') . " AND course IS NOT NULL AND course <> '' ORDER BY course ASC");
if ($extraCourseResult) {
    while ($row = mysqli_fetch_assoc($extraCourseResult)) {
        $usedCourse = resolveCourse($row['course']);
        if ($usedCourse === null && !isset($courseOptions[$row['course']])) {
            $courseOptions[$row['course']] = $row['course'];
        } elseif ($usedCourse !== null) {
            $courseOptions[$usedCourse['code']] = courseDisplayName($usedCourse);
        }
    }
}


$statusLabels = ['enrollee' => 'ENROLLEE', 'active' => 'ENROLLEE', 'graduated' => 'GRADUATE', 'withdrawn' => 'WITHDRAWN', 'inactive' => 'WITHDRAWN', 'onleave' => 'ON-LEAVE', 'transferred' => 'TRANSFERRED'];

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
    <link href="../css/course-filter.css" rel="stylesheet">
</head>

<body>
    <div class="main-container">

    <div class="textNButton-row">

      <form method="GET" action="archive.php" class="search-group" id="archiveSearchForm">
        <div class="search-input-wrapper">
            <input type="text" name="search" id="archiveSearchInput" class="searchbar" placeholder="STUDENT NUMBER / NAME" value="<?= htmlspecialchars($search) ?>" required
                   maxlength="100" autocomplete="off" pattern="[A-Za-zÑñ0-9\s.,'\-]+">
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

          <div class="filter-wrap">
          <select class="filter<?= $courseFilter !== '' ? ' has-value' : '' ?>" id="archiveCourseFilter" name="course">
              <option value="">Course</option>
              <?php foreach ($courseOptions as $courseValue => $courseLabel): ?>
                  <option value="<?= htmlspecialchars($courseValue) ?>" <?= $courseFilter === $courseValue ? 'selected' : '' ?>>
                      <?= htmlspecialchars($courseLabel) ?>
                  </option>
              <?php endforeach; ?>
          </select>
          <?php if ($courseFilter !== ''): ?>
              <a href="archive.php?<?= htmlspecialchars(http_build_query(array_filter(['search' => $search], 'strlen'))) ?>" class="clear-icon filter-clear-icon" title="Clear course"><i class="bi bi-x-circle"></i></a>
          <?php endif; ?>
          </div>

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
        <th>Last Year Level</th>
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
            <td><?= (($lastLevel = $row['grad_year'] !== null ? PROGRAM_LENGTH_YEARS : yearLevelOf($row['year_section'] ?? '')) !== null ? $lastLevel . ([1 => 'st', 2 => 'nd', 3 => 'rd'][$lastLevel] ?? 'th') . ' Year' : '-') ?></td>
            <td>
                <span class="archive-status archive-status-<?= htmlspecialchars($row['status'] === 'inactive' ? 'withdrawn' : $row['status']) ?>">
                    <?= htmlspecialchars($statusLabels[$row['status']] ?? strtoupper((string) $row['status'])) ?>
                </span>
            </td>
            <td>
                <?php if (!empty($row['archived_at'])): ?>
                    <?php $archivedTs = strtotime($row['archived_at']); ?>
                    <span class="archive-date"><?= htmlspecialchars(date('M j, Y', $archivedTs)) ?></span>
                    <span class="archive-time"><?= htmlspecialchars(date('g:i A', $archivedTs)) ?></span>
                <?php else: ?>
                    -
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
                                    <?php
                                        $exists = ($file['file_path'] ?? '') !== '' && is_file($file['file_path']);
                                        $fileLabel = ($file['requirement_code'] ?? '') !== '' && function_exists('requirementLabel')
                                            ? requirementLabel($file['requirement_code'])
                                            : $file['file_name'];
                                    ?>
                                    <li>
                                        <?php if ($exists): ?>
                                            <a class="dropdown-item archive-file-link" href="<?= htmlspecialchars(fileUrl($file['file_path'])) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($file['file_name']) ?>" data-file-label="<?= htmlspecialchars($fileLabel) ?>" data-file-name="<?= htmlspecialchars($file['file_name']) ?>">
                                                <i class="bi bi-file-earmark-pdf"></i> <?= htmlspecialchars($fileLabel) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="dropdown-item disabled text-muted" title="File is missing on the server">
                                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($fileLabel) ?> (missing)
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (in_array($row['status'], RESTORABLE_STATUSES, true)): ?>
                        <button type="button" class="btn btn-sm archive-restore-btn" data-id="<?= (int) $row['student_id'] ?>" data-name="<?= htmlspecialchars($row['name']) ?>">
                            <i class="bi bi-arrow-counterclockwise"></i> Restore
                        </button>
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
            <div class="modal-header confirm-header">
                <div class="add-heading">
                    <span class="add-heading-icon"><i class="bi bi-arrow-counterclockwise"></i></span>
                    <div class="add-heading-text">
                        <h5 class="modal-title confirm-title">Restore record</h5>
                        <p class="add-subtitle">The record will be moved back to the Student Directory.</p>
                    </div>
                </div>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Are you sure you want to restore <strong id="archiveRestoreName"></strong>?
                </p>
                <div id="archiveRestoreError" class="alert alert-danger small mt-3 mb-0 d-none"></div>
            </div>
            <div class="modal-footer confirm-footer">
                <button type="button" class="btn btn-cancel" id="archiveRestoreCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save archive-nowrap-btn" id="archiveRestoreConfirmBtn">Restore</button>
            </div>
        </div>
    </div>
</div>

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

<script src="../js/archive.js"></script>
<script src="../js/course-filter.js"></script>
</body>
</html>