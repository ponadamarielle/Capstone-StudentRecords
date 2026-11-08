<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../report_template.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isAjax = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!isset($_SESSION['name'], $_SESSION['role']) || $_SESSION['role'] !== 'Super Admin') {
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please sign in as the Super Admin.']);
        exit();
    }
    header('Location: ../signin.php');
    exit();
}

if (!is_dir(REPORT_TEMPLATE_DIR)) {
    @mkdir(REPORT_TEMPLATE_DIR, 0755, true);
}

function templateReply($success, $message, array $extra = [])
{
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit();
}

function templateUrl($name)
{
    $files = reportTemplateFiles();
    $images = reportTemplateImages();
    $path = $name === 'draft' ? $files['preview'] : $images[$name];
    $relative = '../' . ltrim(str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)))), '/');
    return $relative . '?v=' . (@filemtime($path) ?: time());
}

if ($isAjax) {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        templateReply(false, 'Your session expired. Reload the page and try again.');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload') {
        $file = $_FILES['template'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            templateReply(false, 'Choose a template file to upload.');
        }
        if ($file['size'] > 20 * 1024 * 1024) {
            templateReply(false, 'The file is too large. The limit is 20 MB.');
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['docx', 'pdf'], true)) {
            templateReply(false, 'Upload a Word (.docx) or PDF file.');
        }
        $error = '';
        $page = reportTemplatePrepareDraft($file['tmp_name'], $extension, $error);
        if ($page === null) {
            templateReply(false, $error);
        }
        $width = $page['width'];
        $height = $page['height'];
        if ($width < 600 || $height < 600) {
            templateReply(false, 'The page is too small. Upload a full page of the letterhead.');
        }
        $cuts = reportTemplateSuggestCuts($page);
        unset($page);
        templateReply(true, 'Template uploaded.', [
            'draft'        => templateUrl('draft'),
            'width'        => $width,
            'height'       => $height,
            'header_end'   => $cuts['header_end'],
            'footer_start' => $cuts['footer_start'],
            'file_name'    => (string) $file['name'],
        ]);
    }

    if ($action === 'save') {
        $error = '';
        if (!reportTemplateSaveCuts((int) ($_POST['header_end'] ?? 0), (int) ($_POST['footer_start'] ?? 0),
            (int) ($_POST['width'] ?? 0), (int) ($_POST['height'] ?? 0), $error)) {
            templateReply(false, $error);
        }
        $fileName = trim((string) ($_POST['file_name'] ?? ''));
        logActivity('report_template_changed', 'Changed the report template' . ($fileName !== '' ? ' to ' . $fileName : ''), [
            'category'    => 'audit',
            'target_type' => 'report',
        ]);
        templateReply(true, 'The new template is now used for all reports.', [
            'header' => templateUrl('header'),
            'footer' => templateUrl('footer'),
        ]);
    }

    if ($action === 'cancel') {
        @unlink(reportTemplateFiles()['draft']);
        @unlink(reportTemplateFiles()['preview']);
        templateReply(true, '');
    }

    templateReply(false, 'Unknown action.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Template</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-superadmin.css" rel="stylesheet">
    <link href="../css/report-template.css?v=<?= @filemtime(__DIR__ . '/../css/report-template.css') ?>" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="rt-container" id="reportTemplatePage" data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div id="rtFlash" class="rt-flash" role="status" hidden></div>

    <section class="rt-card" id="rtCurrent">
        <div class="rt-card-head">
            <h2 class="rt-title">Current template</h2>
            <div class="rt-actions">
                <label class="rt-btn rt-btn-primary" for="rtFile">
                    <i class="bi bi-upload"></i> Upload template
                </label>
                <input type="file" id="rtFile" accept=".docx,.pdf" hidden>
            </div>
        </div>
        <p class="rt-help">Upload the letterhead as a Word (.docx) or PDF file. The header and footer are used on every page of the student and students record reports.</p>
        <div class="rt-paper">
            <img id="rtHeaderImg" src="<?= htmlspecialchars(templateUrl('header')) ?>" alt="Report header">
            <div class="rt-paper-body">
                <span></span><span></span><span></span><span class="rt-short"></span>
                <span></span><span></span><span class="rt-short"></span>
            </div>
            <img id="rtFooterImg" src="<?= htmlspecialchars(templateUrl('footer')) ?>" alt="Report footer">
        </div>
    </section>

    <section class="rt-card" id="rtEditor" hidden>
        <div class="rt-card-head">
            <div>
                <h2 class="rt-title">Set the header and footer</h2>
                <p class="rt-subtitle">Drag the lines. Everything above the top line is the header, and everything below the bottom line is the footer.</p>
            </div>
            <div class="rt-actions">
                <button type="button" class="rt-btn rt-btn-outline" id="rtCancelBtn">Cancel</button>
                <button type="button" class="rt-btn rt-btn-primary" id="rtSaveBtn"><i class="bi bi-check2"></i> Save template</button>
            </div>
        </div>
        <div class="rt-stage" id="rtStage">
            <img id="rtDraftImg" alt="Uploaded template">
            <div class="rt-shade rt-shade-top" id="rtShadeTop"></div>
            <div class="rt-shade rt-shade-bottom" id="rtShadeBottom"></div>
            <div class="rt-line" id="rtHeaderLine" data-line="header" tabindex="0" role="slider" aria-label="End of header">
                <span class="rt-line-label">Header ends here</span>
            </div>
            <div class="rt-line" id="rtFooterLine" data-line="footer" tabindex="0" role="slider" aria-label="Start of footer">
                <span class="rt-line-label">Footer starts here</span>
            </div>
        </div>
    </section>
</div>

<script src="../js/report-template.js?v=<?= @filemtime(__DIR__ . '/../js/report-template.js') ?>"></script>
</body>
</html>