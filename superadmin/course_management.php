<!DOCTYPE html>
<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../admin/course_catalog.php';

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
courseCatalogDb();

$views = ['all', 'offered', 'not_offered', 'unrecognized'];
$view = in_array($_GET['view'] ?? 'all', $views, true) ? ($_GET['view'] ?? 'all') : 'all';

function cmCleanText($value)
{
    return trim(preg_replace('/\s+/u', ' ', (string) $value));
}

function cmCourseById($id)
{
    foreach (getCourseCatalog() as $entry) {
        if (($entry['id'] ?? 0) === (int) $id) {
            return $entry;
        }
    }
    return null;
}

function cmValidateCourse($code, $name, array $aliases, $excludeId = 0)
{
    $errors = [];
    if (!preg_match('/^[A-Z][A-Z\-]{1,29}$/', $code)) {
        $errors['code'] = $code === '' ? 'This field is required.' : 'Enter a valid code.';
    }
    if (!preg_match("/^\p{L}[\p{L}\p{M} .,'&()\-]{4,254}$/u", $name)) {
        $errors['name'] = $name === '' ? 'This field is required.' : 'Enter a valid program name.';
    }
    if (count($aliases) > 10) {
        $errors['aliases'] = 'Up to 10 other names only.';
    } else {
        foreach ($aliases as $alias) {
            if (!preg_match('/^[A-Za-z][A-Za-z .\-]{0,39}$/', $alias)) {
                $errors['aliases'] = 'Enter valid other names.';
                break;
            }
        }
    }
    if ($errors) {
        return $errors;
    }
    $index = courseIndexes()['full'];
    $checks = ['code' => [$code], 'name' => [$name], 'aliases' => $aliases];
    foreach ($checks as $field => $values) {
        foreach ($values as $value) {
            $owner = $index[courseKey($value)] ?? null;
            if ($owner !== null && (int) ($owner['id'] ?? 0) !== (int) $excludeId) {
                $errors[$field] = courseKey($value) === courseKey($owner['code'])
                    ? $owner['code'] . ' is already in the course list.'
                    : '"' . $value . '" is already used by ' . $owner['code'] . '.';
                break;
            }
        }
    }
    return $errors;
}

function cmRedirect($view, $type, $message)
{
    $_SESSION['cm_flash'] = ['type' => $type, 'message' => $message];
    header('Location: course_management.php' . ($view !== 'all' ? '?view=' . urlencode($view) : ''));
    exit();
}

$formErrors = [];
$formMode = null;
$formOld = ['id' => '', 'code' => '', 'name' => '', 'aliases' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF validation failed.");
    }
    $action = $_POST['cm_action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $code = strtoupper(cmCleanText($_POST['code'] ?? ''));
        $name = cmCleanText($_POST['name'] ?? '');
        $aliases = courseAliasList(cmCleanText($_POST['aliases'] ?? ''));
        $existing = $action === 'edit' ? cmCourseById($courseId) : null;
        $formMode = $action;
        $formOld = ['id' => $courseId, 'code' => $code, 'name' => $name, 'aliases' => implode(', ', $aliases)];

        if ($action === 'edit' && $existing === null) {
            cmRedirect($view, 'error', 'The course could not be found. Please try again.');
        }
        if ($existing !== null && $existing['code'] !== $code && !in_array($existing['code'], $aliases, true)) {
            $aliases[] = $existing['code'];
        }
        $formErrors = cmValidateCourse($code, $name, $aliases, $courseId);

        if (!$formErrors) {
            $aliasText = implode(', ', $aliases);
            if ($action === 'add') {
                $stmt = mysqli_prepare($conn, "INSERT INTO courses (code, name, aliases, status, source) VALUES (?, ?, ?, 'active', 'manual')");
                mysqli_stmt_bind_param($stmt, 'sss', $code, $name, $aliasText);
                mysqli_stmt_execute($stmt);
                $newId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                logActivity('course_created', 'Added course ' . $code . ' - ' . $name, [
                    'target_type' => 'course',
                    'target_id'   => $newId,
                    'details'     => ['code' => $code, 'name' => $name, 'other_names' => $aliasText],
                ]);
                cmRedirect($view, 'success', $code . ' - ' . $name . ' was added.');
            }

            $stmt = mysqli_prepare($conn, "UPDATE courses SET code = ?, name = ?, aliases = ? WHERE course_id = ?");
            mysqli_stmt_bind_param($stmt, 'sssi', $code, $name, $aliasText, $courseId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $moved = 0;
            $newDisplay = $code . ' - ' . $name;
            $oldDisplay = courseDisplayName($existing);
            if ($newDisplay !== $oldDisplay) {
                $stmt = mysqli_prepare($conn, "UPDATE students SET course = ? WHERE course IN (?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'ssss', $newDisplay, $oldDisplay, $existing['code'], $existing['name']);
                mysqli_stmt_execute($stmt);
                $moved = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
            }

            $changes = [];
            if ($existing['code'] !== $code) $changes['Code'] = ['from' => $existing['code'], 'to' => $code];
            if ($existing['name'] !== $name) $changes['Program name'] = ['from' => $existing['name'], 'to' => $name];
            if (implode(', ', $existing['aliases']) !== $aliasText) $changes['Other names'] = ['from' => implode(', ', $existing['aliases']), 'to' => $aliasText];
            if ($changes) {
                logActivity('course_updated', 'Updated course ' . $newDisplay, [
                    'target_type' => 'course',
                    'target_id'   => $courseId,
                    'details'     => ['changes' => $changes, 'students_updated' => $moved],
                ]);
            }
            cmRedirect($view, 'success', $newDisplay . ' was updated.' . ($moved ? ' ' . $moved . ' student record' . ($moved === 1 ? ' was' : 's were') . ' updated to the new name.' : ''));
        }
    }

    if ($action === 'toggle') {
        $course = cmCourseById((int) ($_POST['course_id'] ?? 0));
        if ($course === null) {
            cmRedirect($view, 'error', 'The course could not be found. Please try again.');
        }
        $newStatus = $course['status'] === 'active' ? 'inactive' : 'active';
        $stmt = mysqli_prepare($conn, "UPDATE courses SET status = ? WHERE course_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $newStatus, $course['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        logActivity('course_status_changed', ($newStatus === 'active' ? 'Marked as offered: ' : 'Marked as not offered: ') . courseDisplayName($course), [
            'target_type' => 'course',
            'target_id'   => $course['id'],
            'details'     => [
                'changes' => ['Status' => ['from' => $course['status'] === 'active' ? 'Offered' : 'Not offered', 'to' => $newStatus === 'active' ? 'Offered' : 'Not offered']],
                'effect'  => $newStatus === 'active'
                    ? 'The course appears again in the course filters.'
                    : 'Students already in this course keep it. It is only shown in the filters while students still use it.',
            ],
        ]);
        cmRedirect($view, 'success', courseDisplayName($course) . ' is now ' . ($newStatus === 'active' ? 'offered' : 'not offered') . '.');
    }

    if ($action === 'assign') {
        $raw = (string) ($_POST['raw_course'] ?? '');
        $course = cmCourseById((int) ($_POST['course_id'] ?? 0));
        if ($raw === '' || $course === null) {
            cmRedirect($view, 'error', 'Choose the correct course first.');
        }
        $display = courseDisplayName($course);
        $stmt = mysqli_prepare($conn, "UPDATE students SET course = ? WHERE course = ?");
        mysqli_stmt_bind_param($stmt, 'ss', $display, $raw);
        mysqli_stmt_execute($stmt);
        $moved = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        $aliasAdded = false;
        $alias = cmCleanText($raw);
        if (!empty($_POST['remember_alias']) && preg_match('/^[A-Za-z][A-Za-z .\-]{0,39}$/', $alias)
            && !isset(courseIndexes()['full'][courseKey($alias)]) && count($course['aliases']) < 10) {
            $aliasText = implode(', ', array_merge($course['aliases'], [$alias]));
            $stmt = mysqli_prepare($conn, "UPDATE courses SET aliases = ? WHERE course_id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $aliasText, $course['id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $aliasAdded = true;
        }

        logActivity('course_assigned', 'Moved ' . $moved . ' student record' . ($moved === 1 ? '' : 's') . ' from "' . $raw . '" to ' . $display, [
            'target_type' => 'course',
            'target_id'   => $course['id'],
            'details'     => ['from' => $raw, 'to' => $display, 'students_updated' => $moved, 'remembered_as_other_name' => $aliasAdded ? 'Yes' : 'No'],
        ]);
        cmRedirect($view, 'success', $moved . ' student record' . ($moved === 1 ? ' was' : 's were') . ' moved to ' . $display . '.'
            . ($aliasAdded ? ' "' . $alias . '" will be recognized as this course from now on.' : ''));
    }
}

$flash = $_SESSION['cm_flash'] ?? null;
unset($_SESSION['cm_flash']);

getCourseCatalog(true);
$catalog = getCourseCatalog();

$studentCounts = [];
$unrecognized = [];
$countResult = mysqli_query($conn, "SELECT course, COUNT(*) AS total FROM students WHERE course IS NOT NULL AND course <> '' GROUP BY course");
while ($countResult && ($row = mysqli_fetch_assoc($countResult))) {
    $entry = resolveCourse($row['course']);
    if ($entry !== null) {
        $studentCounts[$entry['code']] = ($studentCounts[$entry['code']] ?? 0) + (int) $row['total'];
    } else {
        $unrecognized[$row['course']] = (int) $row['total'];
    }
}
ksort($unrecognized, SORT_NATURAL | SORT_FLAG_CASE);

$counts = ['all' => count($catalog), 'offered' => 0, 'not_offered' => 0, 'unrecognized' => count($unrecognized)];
foreach ($catalog as $entry) {
    $counts[$entry['status'] === 'active' ? 'offered' : 'not_offered']++;
}

$shown = array_values(array_filter($catalog, function ($entry) use ($view) {
    if ($view === 'offered') return $entry['status'] === 'active';
    if ($view === 'not_offered') return $entry['status'] !== 'active';
    return true;
}));

$assignOptions = $catalog;
usort($assignOptions, fn($a, $b) => strcmp($a['code'], $b['code']));

$tabLabels = [
    'all'          => 'All',
    'offered'      => 'Offered',
    'not_offered'  => 'Not offered',
    'unrecognized' => 'Unrecognized',
];
$formAction = htmlspecialchars('course_management.php' . ($view !== 'all' ? '?view=' . $view : ''));
?>

<html>
<head>
    <title>Course Management</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-superadmin.css" rel="stylesheet">
    <link href="../css/course_management.css" rel="stylesheet">
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

<?php if ($counts['unrecognized'] > 0): ?>
    <div class="cm-notice">
        <i class="bi bi-info-circle-fill"></i>
        <span><?= $counts['unrecognized'] ?> course name<?= $counts['unrecognized'] === 1 ? ' is' : 's are' ?> not recognized.</span>
    </div>
<?php endif; ?>

<div class="cm-toolbar">
    <div class="cm-tabs" role="tablist">
        <?php foreach ($tabLabels as $key => $label): ?>
            <a class="cm-tab<?= $view === $key ? ' active' : '' ?>" href="course_management.php<?= $key !== 'all' ? '?view=' . $key : '' ?>">
                <?= $label ?> <span class="cm-tab-count"><?= $counts[$key] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <button type="button" class="cm-btn-add" data-bs-toggle="modal" data-bs-target="#courseFormModal" data-mode="add">
        <i class="bi bi-plus-lg"></i>ADD COURSE
    </button>
</div>

<?php if ($view === 'unrecognized'): ?>
<div class="cm-table-wrap">
<table class="cm-table">
    <tr>
        <th>Course as saved</th>
        <th>Students</th>
        <th>Actions</th>
    </tr>
    <?php foreach ($unrecognized as $raw => $total):
        $parsed = parseNewCourse($raw);
        $suggestCode = $parsed ? $parsed[0] : (preg_match('/^[A-Za-z\-]{2,30}$/', $raw) ? strtoupper($raw) : '');
        $suggestName = $parsed ? $parsed[1] : '';
    ?>
    <tr>
        <td><span class="cm-raw"><?= htmlspecialchars($raw) ?></span></td>
        <td><?= $total ?></td>
        <td>
            <div class="cm-actions">
                <button type="button" class="cm-action-btn" data-bs-toggle="modal" data-bs-target="#assignModal"
                        data-raw="<?= htmlspecialchars($raw, ENT_QUOTES) ?>" data-total="<?= $total ?>">
                    <i class="bi bi-arrow-left-right"></i> Assign to course
                </button>
                <button type="button" class="cm-action-btn" data-bs-toggle="modal" data-bs-target="#courseFormModal" data-mode="add"
                        data-code="<?= htmlspecialchars($suggestCode, ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($suggestName, ENT_QUOTES) ?>">
                    <i class="bi bi-plus-lg"></i> Add as new course
                </button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$unrecognized): ?>
    <tr><td colspan="3" class="cm-empty">Every saved course matches a course in the list.</td></tr>
    <?php endif; ?>
</table>
<div class="cm-table-footer">Student records with these courses can be moved to the correct course. Choosing to remember the name helps future records match automatically.</div>
</div>
<?php else: ?>
<div class="cm-table-wrap">
<table class="cm-table">
    <tr>
        <th>Code</th>
        <th>Program name</th>
        <th>Status</th>
        <th>Actions</th>
    </tr>
    <?php foreach ($shown as $entry):
        $isActive = $entry['status'] === 'active';
        $total = $studentCounts[$entry['code']] ?? 0;
    ?>
    <tr>
        <td class="cm-code"><?= htmlspecialchars($entry['code']) ?></td>
        <td>
            <?= htmlspecialchars($entry['name']) ?>
        </td>
        <td><span class="cm-status <?= $isActive ? 'cm-status-active' : 'cm-status-inactive' ?>"><?= $isActive ? 'Offered' : 'Not offered' ?></span></td>
        <td>
            <div class="cm-actions">
                <button type="button" class="cm-action-btn" data-bs-toggle="modal" data-bs-target="#courseFormModal" data-mode="edit"
                        data-id="<?= (int) $entry['id'] ?>"
                        data-code="<?= htmlspecialchars($entry['code'], ENT_QUOTES) ?>"
                        data-name="<?= htmlspecialchars($entry['name'], ENT_QUOTES) ?>"
                        data-aliases="<?= htmlspecialchars(implode(', ', $entry['aliases']), ENT_QUOTES) ?>">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button type="button" class="<?= $isActive ? 'cm-deactivate-btn' : 'cm-activate-btn' ?>" data-bs-toggle="modal" data-bs-target="#toggleModal"
                        data-id="<?= (int) $entry['id'] ?>"
                        data-display="<?= htmlspecialchars(courseDisplayName($entry), ENT_QUOTES) ?>"
                        data-active="<?= $isActive ? '1' : '0' ?>"
                        data-total="<?= $total ?>">
                    <?= $isActive ? 'Mark as not offered' : 'Mark as offered' ?>
                </button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$shown): ?>
    <tr><td colspan="4" class="cm-empty">No courses found.</td></tr>
    <?php endif; ?>
</table>
<div class="cm-table-footer">Showing <?= count($shown) ?> course<?= count($shown) === 1 ? '' : 's' ?></div>
</div>
<?php endif; ?>

</div>

<div class="modal fade cm-modal" id="courseFormModal" tabindex="-1" aria-labelledby="courseFormTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= $formAction ?>" id="courseForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="cm_action" id="courseFormAction" value="<?= $formMode === 'edit' ? 'edit' : 'add' ?>">
        <input type="hidden" name="course_id" id="courseFormId" value="<?= htmlspecialchars((string) $formOld['id']) ?>">
        <div class="modal-header cm-modal-header">
          <span class="cm-modal-icon"><i class="bi bi-mortarboard"></i></span>
          <div>
            <h5 class="modal-title" id="courseFormTitle"><?= $formMode === 'edit' ? 'Edit course' : 'Add course' ?></h5>
            <p class="cm-modal-subtitle" id="courseFormSubtitle"><?= $formMode === 'edit' ? 'Changes also update the students already in this course.' : 'The course appears in the course filters and is recognized in uploaded documents.' ?></p>
          </div>
        </div>
        <div class="modal-body">
          <div class="cm-field">
            <label for="courseCode" class="cm-label">Code <span class="req-mark">*</span></label>
            <input type="text" class="form-control<?= isset($formErrors['code']) ? ' is-invalid' : '' ?>" id="courseCode" name="code" maxlength="30" autocomplete="off"
                   placeholder="e.g. BSA" value="<?= htmlspecialchars($formOld['code']) ?>">
            <div class="invalid-feedback" id="courseCodeFeedback"><?= htmlspecialchars($formErrors['code'] ?? '') ?></div>
          </div>
          <div class="cm-field">
            <label for="courseName" class="cm-label">Program name <span class="req-mark">*</span></label>
            <input type="text" class="form-control<?= isset($formErrors['name']) ? ' is-invalid' : '' ?>" id="courseName" name="name" maxlength="255" autocomplete="off"
                   placeholder="e.g. Bachelor of Science in Accountancy" value="<?= htmlspecialchars($formOld['name']) ?>">
            <div class="invalid-feedback" id="courseNameFeedback"><?= htmlspecialchars($formErrors['name'] ?? '') ?></div>
          </div>
          <div class="cm-field">
            <label for="courseAliases" class="cm-label">Other names <span class="cm-optional">(optional)</span></label>
            <input type="text" class="form-control<?= isset($formErrors['aliases']) ? ' is-invalid' : '' ?>" id="courseAliases" name="aliases" maxlength="500" autocomplete="off"
                   placeholder="e.g. BSACC, BS Accountancy" value="<?= htmlspecialchars($formOld['aliases']) ?>">
            <div class="cm-hint">Short forms written on documents, separated by commas. They are matched to this course.</div>
            <div class="invalid-feedback" id="courseAliasesFeedback"><?= htmlspecialchars($formErrors['aliases'] ?? '') ?></div>
          </div>
        </div>
        <div class="modal-footer cm-modal-footer">
          <button type="button" class="btn cm-cancel-btn" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn cm-save-btn" id="courseFormSubmit"><i class="bi bi-check-lg"></i> <span><?= $formMode === 'edit' ? 'Save changes' : 'Add course' ?></span></button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade cm-modal" id="assignModal" tabindex="-1" aria-labelledby="assignTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= $formAction ?>" id="assignForm">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="cm_action" value="assign">
        <input type="hidden" name="raw_course" id="assignRaw">
        <div class="modal-header cm-modal-header">
          <span class="cm-modal-icon"><i class="bi bi-arrow-left-right"></i></span>
          <div>
            <h5 class="modal-title" id="assignTitle">Assign to course</h5>
            <p class="cm-modal-subtitle"><span id="assignTotal"></span> saved as <strong id="assignRawText"></strong></p>
          </div>
        </div>
        <div class="modal-body">
          <div class="cm-field">
            <label for="assignCourse" class="cm-label">Correct course <span class="req-mark">*</span></label>
            <select class="form-select" id="assignCourse" name="course_id" required>
              <option value="">Choose a course</option>
              <?php foreach ($assignOptions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"><?= htmlspecialchars(courseDisplayName($option)) ?><?= $option['status'] !== 'active' ? ' (not offered)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <label class="cm-check">
            <input type="checkbox" name="remember_alias" value="1" id="assignRemember" checked>
            <span>Recognize "<span id="assignRememberText"></span>" as this course from now on</span>
          </label>
        </div>
        <div class="modal-footer cm-modal-footer">
          <button type="button" class="btn cm-cancel-btn" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn cm-save-btn"><i class="bi bi-check-lg"></i> Assign</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade cm-confirm-modal" id="toggleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      <div class="modal-body mb-3">
        <span class="cm-confirm-icon"><i class="bi bi-mortarboard"></i></span>
        <div class="cm-confirm-title" id="toggleTitle"></div>
        <p class="cm-confirm-text" id="toggleText"></p>
      </div>
      <form method="POST" action="<?= $formAction ?>">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="cm_action" value="toggle">
        <input type="hidden" name="course_id" id="toggleId">
        <div class="d-flex justify-content-center gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger" id="toggleConfirm">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="cmFlags" data-open-form="<?= $formErrors ? 'true' : 'false' ?>"></div>
<script src="../js/course_management.js"></script>
</body>
</html>