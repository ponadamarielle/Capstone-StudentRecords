<?php
include '../security_headers.php';
session_start();

$isStatsRequest = ($_GET['action'] ?? '') === 'stats';

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Super Admin') {
    if ($isStatsRequest) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit();
    }
    header("Location: ../signin.php");
    exit();
}

require_once __DIR__ . '/../admin/course_catalog.php';
if (!function_exists('getDocumentRequirementsForClient')) {
    require_once __DIR__ . '/../admin/document_classifier.php';
}
require_once __DIR__ . '/../admin/requirement_status.php';
require_once __DIR__ . '/../database_backup.php';

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    if ($isStatsRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Connection failed.']);
        exit();
    }
    die("Connection failed: " . mysqli_connect_error());
}

function dashboardRequirements()
{
    return array_values(array_map(function ($r) {
        $text = ($r['label'] ?? '') . ' ' . ($r['code'] ?? '');
        if (preg_match('/chest[\s_-]*x[\s_-]*-?ray|xray/i', $text)) {
            $r['required_for'] = [];
        }
        return $r;
    }, getDocumentRequirementsForClient()));
}

function dashboardStats($conn)
{
    $count = function ($sql) use ($conn) {
        $result = mysqli_query($conn, $sql);
        return $result ? (int) (mysqli_fetch_row($result)[0] ?? 0) : 0;
    };

    $total     = $count("SELECT COUNT(*) FROM students");
    $enrollees = $count("SELECT COUNT(*) FROM students WHERE (status IN ('enrollee', 'active') OR status IS NULL OR status = '')");
    $graduates = $count("SELECT COUNT(*) FROM students WHERE status = 'graduated'");

    $enrolleeRows = [];
    $result = mysqli_query($conn, "SELECT student_id, admission_track FROM students
                                   WHERE (status IN ('enrollee', 'active') OR status IS NULL OR status = '')");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $enrolleeRows[] = $row;
    }

    $requirements   = dashboardRequirements();
    $codesByStudent = requirementCodesByStudent($conn, array_column($enrolleeRows, 'student_id'));

    $complete = 0;
    $missingCounts = [];
    foreach ($enrolleeRows as $row) {
        $track = function_exists('normalizeAdmissionTrack')
            ? normalizeAdmissionTrack($row['admission_track'] ?? '')
            : ($row['admission_track'] ?? '');
        $status = requirementCompletion($requirements, $track, $codesByStudent[(int) $row['student_id']] ?? []);
        if ($status['complete']) {
            $complete++;
        }
        foreach ($status['missing'] as $label) {
            $missingCounts[$label] = ($missingCounts[$label] ?? 0) + 1;
        }
    }
    arsort($missingCounts);

    $topMissing = [];
    foreach (array_slice($missingCounts, 0, 5, true) as $label => $students) {
        $topMissing[] = ['label' => $label, 'students' => $students];
    }

    $enrolleeCount = count($enrolleeRows);
    return [
        'total'       => $total,
        'enrollees'   => $enrollees,
        'graduates'   => $graduates,
        'complete'    => $complete,
        'incomplete'  => $enrolleeCount - $complete,
        'percent'     => $enrolleeCount > 0 ? (int) round($complete / $enrolleeCount * 100) : 0,
        'top_missing' => $topMissing,
        'updated_at'  => date('M j, Y g:i:s A'),
    ];
}

if ($isStatsRequest) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    ini_set('display_errors', '0');
    echo json_encode(['success' => true] + dashboardStats($conn));
    exit();
}

runScheduledBackupIfDue();
$backupConn   = backupConnection();
$backupAlerts = $backupConn ? backupFailureAlerts($backupConn) : [];

$stats = dashboardStats($conn);
$enrolleeTotal = $stats['complete'] + $stats['incomplete'];

include 'navbar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/dashboard-superadmin.css?v=<?= @filemtime(__DIR__ . '/../css/dashboard-superadmin.css') ?>" rel="stylesheet">
</head>
<body>
<div class="dash-container">

    <div class="dash-header">
        <div>
            <h1 class="dash-title">Dashboard</h1>
            <p class="dash-subtitle">Overview of student records</p>
        </div>
        <div class="dash-updated">
            <i class="bi bi-arrow-repeat"></i>
            Updated <span id="dashUpdatedAt"><?= htmlspecialchars($stats['updated_at']) ?></span>
        </div>
    </div>

    <?php if ($backupAlerts): ?>
        <a class="dash-alert" href="backup_logs.php#backups" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>
                <strong><?= count($backupAlerts) === 1 ? 'A scheduled database backup failed' : count($backupAlerts) . ' scheduled database backups failed' ?></strong>
                on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($backupAlerts[0]['started_at']))) ?>. Open Backup &amp; Logs to review and back up now.
            </span>
            <i class="bi bi-chevron-right"></i>
        </a>
    <?php endif; ?>

    <section class="dash-section">
        <h2 class="dash-section-title">Student Statistics</h2>
        <div class="dash-stats">
            <div class="dash-stat">
                <div class="dash-stat-icon"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="dash-stat-label">Total Student Records</div>
                    <div class="dash-stat-value" data-stat="total"><?= number_format($stats['total']) ?></div>
                </div>
            </div>
            <div class="dash-stat">
                <div class="dash-stat-icon"><i class="bi bi-person-check-fill"></i></div>
                <div>
                    <div class="dash-stat-label">Enrollees</div>
                    <div class="dash-stat-value" data-stat="enrollees"><?= number_format($stats['enrollees']) ?></div>
                </div>
            </div>
            <div class="dash-stat">
                <div class="dash-stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
                <div>
                    <div class="dash-stat-label">Graduates</div>
                    <div class="dash-stat-value" data-stat="graduates"><?= number_format($stats['graduates']) ?></div>
                </div>
            </div>
        </div>
    </section>

    <section class="dash-section">
        <h2 class="dash-section-title">Document Requirements <span>Enrollees</span></h2>
        <div class="dash-panel">
            <div class="dash-req-summary">
                <div class="dash-req-count dash-req-complete">
                    <i class="bi bi-check-circle-fill"></i>
                    <div>
                        <div class="dash-req-number" data-stat="complete"><?= number_format($stats['complete']) ?></div>
                        <div class="dash-req-label">Complete</div>
                    </div>
                </div>
                <div class="dash-req-count dash-req-incomplete">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <div>
                        <div class="dash-req-number" data-stat="incomplete"><?= number_format($stats['incomplete']) ?></div>
                        <div class="dash-req-label">Incomplete</div>
                    </div>
                </div>
                <div class="dash-req-percent">
                    <span data-stat="percent"><?= $stats['percent'] ?></span>%
                    <small>of enrollees have complete requirements</small>
                </div>
            </div>

            <div class="dash-bar" id="dashReqBar" role="img"
                 aria-label="<?= $stats['complete'] ?> complete, <?= $stats['incomplete'] ?> incomplete">
                <div class="dash-bar-seg dash-bar-complete" id="dashBarComplete"
                     data-value="<?= $stats['complete'] ?>"
                     title="Complete: <?= $stats['complete'] ?>"></div>
                <div class="dash-bar-seg dash-bar-incomplete" id="dashBarIncomplete"
                     data-value="<?= $stats['incomplete'] ?>"
                     title="Incomplete: <?= $stats['incomplete'] ?>"></div>
                <div class="dash-bar-empty" id="dashBarEmpty" <?= $enrolleeTotal > 0 ? 'hidden' : '' ?>>No enrollees yet</div>
            </div>

            <div class="dash-missing">
                <div class="dash-missing-title">Most commonly missing documents</div>
                <ul class="dash-missing-list" id="dashMissingList">
                    <?php foreach ($stats['top_missing'] as $item): ?>
                        <li>
                            <span class="dash-missing-label"><?= htmlspecialchars($item['label']) ?></span>
                            <span class="dash-missing-track">
                                <span class="dash-missing-fill" data-percent="<?= $enrolleeTotal > 0 ? round($item['students'] / $enrolleeTotal * 100, 1) : 0 ?>"></span>
                            </span>
                            <span class="dash-missing-value"><?= $item['students'] ?> student<?= $item['students'] === 1 ? '' : 's' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="dash-missing-empty" id="dashMissingEmpty" <?= $stats['top_missing'] ? 'hidden' : '' ?>>
                    No missing documents.
                </p>
            </div>
        </div>
    </section>

</div>
<script src="../js/dashboard-superadmin.js?v=<?= @filemtime(__DIR__ . '/../js/dashboard-superadmin.js') ?>"></script>
</body>
</html>