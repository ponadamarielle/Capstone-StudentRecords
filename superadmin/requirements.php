<!DOCTYPE html>
<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../admin/document_classifier.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Super Admin') {
    header("Location: ../signin.php");
    exit();
}

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');

$trackGroups = requirementTrackGroups();
$typeLabels = ['regular' => 'Regular', 'shiftee' => 'Shiftee', 'transferee' => 'Transferee'];
$typeGroups = ['regular' => 'credential', 'shiftee' => 'shiftee', 'transferee' => 'transferee'];
$trackShort = ['shs_current' => 'SHS current', 'shs_past' => 'SHS past', 'als' => 'ALS'];
$trackNames = ['shs_current' => 'SHS graduates (current school year)', 'shs_past' => 'SHS graduates (past school years)', 'als' => 'ALS graduates'];
$type = array_key_exists($_GET['type'] ?? 'regular', $trackGroups) ? ($_GET['type'] ?? 'regular') : 'regular';
$sub = array_key_exists($_GET['track'] ?? 'shs_current', $trackShort) ? ($_GET['track'] ?? 'shs_current') : 'shs_current';

function rqLoadEnv($path)
{
    if (!file_exists($path)) {
        error_log('.env file not found at ' . $path);
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
rqLoadEnv(__DIR__ . '/../.env');

function rqCleanText($value)
{
    return trim(preg_replace('/\s+/u', ' ', (string) $value));
}

function rqKey($value)
{
    return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
}

function rqUrl($type, $sub)
{
    if ($type !== 'regular') {
        return 'requirements.php?type=' . urlencode($type);
    }
    return 'requirements.php' . ($sub !== 'shs_current' ? '?track=' . urlencode($sub) : '');
}

function rqRedirect($type, $sub, $kind, $message)
{
    $_SESSION['rq_flash'] = ['type' => $kind, 'message' => $message];
    header('Location: ' . rqUrl($type, $sub));
    exit();
}

$catalog = getDocumentRequirements();
$formErrors = [];
$formOld = ['label' => ''];
$openForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF validation failed.");
    }
    $action = $_POST['rq_action'] ?? '';
    $postType = array_key_exists($_POST['student_type'] ?? '', $trackGroups) ? $_POST['student_type'] : $type;
    $type = $postType;
    $sub = array_key_exists($_POST['track'] ?? '', $trackShort) ? $_POST['track'] : $sub;
    $tracks = $type === 'regular' ? [$sub] : $trackGroups[$type];
    $typeName = $type === 'regular' ? 'Regular - ' . $trackShort[$sub] : $typeLabels[$type];

    if ($action === 'add') {
        $label = rqCleanText($_POST['label'] ?? '');
        $hint = '';
        $formOld = ['label' => $label];
        $openForm = true;

        if (!preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\p{M} .,'&()\/\-]{2,119}$/u", $label)) {
            $formErrors['label'] = $label === '' ? 'This field is required.' : 'Enter a valid document name.';
        }

        $match = null;
        if (!$formErrors) {
            foreach ($catalog as $code => $req) {
                if (rqKey($req['label']) === rqKey($label)) {
                    $match = $code;
                    break;
                }
            }
            if ($match !== null && empty($catalog[$match]['store'])) {
                $formErrors['label'] = '"' . $catalog[$match]['label'] . '" is only used to read student information and cannot be a requirement.';
            } elseif ($match !== null && !array_diff($tracks, $catalog[$match]['required_for'] ?? [])) {
                $formErrors['label'] = '"' . $catalog[$match]['label'] . '" is already required for ' . ($type === 'regular' ? $trackNames[$sub] : $typeName . ' students') . '.';
            }
        }

        if (!$formErrors) {
            if ($match !== null) {
                $code = $match;
                $label = $catalog[$code]['label'];
                if (!empty($catalog[$code]['custom']) && trim((string) ($catalog[$code]['ai_hint'] ?? '')) === '') {
                    $hint = aiDescribeRequirement($label)['description'];
                    if ($hint !== '') {
                        $stmt = mysqli_prepare($conn, "UPDATE requirement_documents SET ai_hint = ? WHERE code = ?");
                        mysqli_stmt_bind_param($stmt, 'ss', $hint, $code);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            } else {
                $hint = aiDescribeRequirement($label)['description'];
                $base = 'custom_' . substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_'), 0, 48);
                $code = $base;
                $n = 2;
                while (isset($catalog[$code])) {
                    $code = $base . '_' . $n++;
                }
                $group = $typeGroups[$type];
                $stmt = mysqli_prepare($conn, "INSERT INTO requirement_documents (code, label, ai_hint, doc_group, is_custom) VALUES (?, ?, ?, ?, 1)");
                mysqli_stmt_bind_param($stmt, 'ssss', $code, $label, $hint, $group);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            setRequirementTracks($conn, $code, $tracks, true);
            logActivity('requirement_added', 'Added requirement ' . $label . ' for ' . $typeName . ' students', [
                'target_type' => 'requirement',
                'details'     => ['code' => $code, 'document' => $label, 'student_type' => $typeName, 'existing_document' => $match !== null ? 'Yes' : 'No'],
            ]);
            rqRedirect($type, $sub, 'success', $label . ' is now required for ' . ($type === 'regular' ? $trackNames[$sub] : $typeName . ' students') . '.');
        }
    }

    if ($action === 'remove') {
        $code = (string) ($_POST['code'] ?? '');
        if (!isset($catalog[$code]) || empty($catalog[$code]['store'])) {
            rqRedirect($type, $sub, 'error', 'The requirement could not be found. Please try again.');
        }
        $label = $catalog[$code]['label'];
        setRequirementTracks($conn, $code, $tracks, false);
        logActivity('requirement_removed', 'Removed requirement ' . $label . ' for ' . $typeName . ' students', [
            'target_type' => 'requirement',
            'details'     => ['code' => $code, 'document' => $label, 'student_type' => $typeName, 'effect' => 'Documents already uploaded are kept.'],
        ]);
        rqRedirect($type, $sub, 'success', $label . ' is no longer required for ' . ($type === 'regular' ? $trackNames[$sub] : $typeName . ' students') . '.');
    }
}

$flash = $_SESSION['rq_flash'] ?? null;
unset($_SESSION['rq_flash']);

$lists = [];
$subLists = [];
foreach (array_merge($trackGroups, array_map(fn($t) => [$t], array_combine(array_keys($trackShort), array_keys($trackShort)))) as $key => $groupTracks) {
    $items = [];
    foreach ($catalog as $code => $req) {
        if (empty($req['store'])) {
            continue;
        }
        if (array_intersect($groupTracks, $req['required_for'] ?? [])) {
            $items[] = ['code' => $code, 'label' => $req['label'], 'custom' => !empty($req['custom'])];
        }
    }
    if (isset($trackShort[$key])) {
        $subLists[$key] = $items;
    } else {
        $lists[$key] = $items;
    }
}
$shown = $type === 'regular' ? $subLists[$sub] : $lists[$type];
$typeName = $type === 'regular' ? 'Regular - ' . $trackShort[$sub] : $typeLabels[$type];
$studentsName = $type === 'regular' ? $trackNames[$sub] : $typeLabels[$type] . ' students';
$formAction = htmlspecialchars(rqUrl($type, $sub));
?>

<html>
<head>
    <title>Requirements</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-superadmin.css" rel="stylesheet">
    <link href="../css/course_management.css" rel="stylesheet">
    <link href="../css/requirements.css?v=4" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="cm-container">

<?php if ($flash): ?>
    <div class="cm-flash cm-flash-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status">
        <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
        <span><?= htmlspecialchars($flash['message']) ?></span>
    </div>
<?php endif; ?>

<div class="cm-toolbar">
    <div class="cm-tabs" role="tablist">
        <?php foreach ($typeLabels as $key => $label): ?>
            <a class="cm-tab<?= $type === $key ? ' active' : '' ?>" href="requirements.php<?= $key !== 'regular' ? '?type=' . $key : '' ?>">
                <?= $label ?> <span class="cm-tab-count"><?= count($lists[$key]) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <button type="button" class="cm-btn-add" data-bs-toggle="modal" data-bs-target="#requirementFormModal">
        <i class="bi bi-plus-lg"></i>ADD REQUIREMENT
    </button>
</div>

<?php if ($type === 'regular'): ?>
<div class="rq-subtabs" role="tablist">
    <span class="rq-subtabs-label">Admission track</span>
    <?php foreach ($trackShort as $key => $label): ?>
        <a class="rq-subtab<?= $sub === $key ? ' active' : '' ?>" href="<?= htmlspecialchars(rqUrl('regular', $key)) ?>">
            <?= $label ?> <span class="rq-subtab-count"><?= count($subLists[$key]) ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="cm-table-wrap">
<table class="cm-table rq-table">
    <tr>
        <th>Document</th>
        <th>Actions</th>
    </tr>
    <?php foreach ($shown as $row): ?>
    <tr>
        <td class="rq-doc">
            <?= htmlspecialchars($row['label']) ?>
            <?php if ($row['custom']): ?>
                <span class="rq-badge">Added</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="cm-actions">
                <button type="button" class="cm-deactivate-btn" data-bs-toggle="modal" data-bs-target="#removeModal"
                        data-code="<?= htmlspecialchars($row['code'], ENT_QUOTES) ?>"
                        data-label="<?= htmlspecialchars($row['label'], ENT_QUOTES) ?>">
                    <i class="bi bi-trash3"></i> Remove
                </button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$shown): ?>
    <tr><td colspan="2" class="cm-empty">No requirements yet for <?= htmlspecialchars($studentsName) ?>.</td></tr>
    <?php endif; ?>
</table>
<div class="cm-table-footer">Showing <?= count($shown) ?> requirement<?= count($shown) === 1 ? '' : 's' ?></div>
</div>

</div>

<div class="modal fade cm-modal" id="requirementFormModal" tabindex="-1" aria-labelledby="requirementFormTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= $formAction ?>" id="requirementForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="rq_action" value="add">
        <input type="hidden" name="student_type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="track" value="<?= htmlspecialchars($sub) ?>">
        <div class="modal-header cm-modal-header">
          <span class="cm-modal-icon"><i class="bi bi-file-earmark-plus"></i></span>
          <div>
            <h5 class="modal-title" id="requirementFormTitle">Add new requirement</h5>
            <p class="cm-modal-subtitle">Required for <?= htmlspecialchars($studentsName) ?></p>
          </div>
        </div>
        <div class="modal-body">
          <div class="cm-field mb-0">
            <label for="requirementLabel" class="cm-label">Document <span class="req-mark">*</span></label>
            <input type="text" class="form-control<?= isset($formErrors['label']) ? ' is-invalid' : '' ?>" id="requirementLabel" name="label" maxlength="120" autocomplete="off"
                   placeholder="e.g. Certificate of Enrollment" value="<?= htmlspecialchars($formOld['label']) ?>">
            <div class="invalid-feedback" id="requirementLabelFeedback"><?= htmlspecialchars($formErrors['label'] ?? '') ?></div>
          </div>
        </div>
        <div class="modal-footer cm-modal-footer">
          <button type="button" class="btn cm-cancel-btn" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn cm-save-btn"><i class="bi bi-plus-lg"></i> <span>Add requirement</span></button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade cm-confirm-modal" id="removeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      <div class="modal-body mb-3">
        <span class="cm-confirm-icon"><i class="bi bi-file-earmark-x"></i></span>
        <div class="cm-confirm-title" id="removeTitle"></div>
        <p class="cm-confirm-text">It will no longer be required for <?= htmlspecialchars($studentsName) ?>. Documents already uploaded are kept.</p>
      </div>
      <form method="POST" action="<?= $formAction ?>">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="rq_action" value="remove">
        <input type="hidden" name="student_type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="track" value="<?= htmlspecialchars($sub) ?>">
        <input type="hidden" name="code" id="removeCode">
        <div class="d-flex justify-content-center gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Remove</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="rqFlags" data-open-form="<?= $openForm && $formErrors ? 'true' : 'false' ?>"></div>
<script src="../js/requirements.js?v=5"></script>
</body>
</html>