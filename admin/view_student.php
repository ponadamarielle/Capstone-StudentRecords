<?php
include '../security_headers.php';
session_start();

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json');

    switch ($action) {
        case 'delete_document_record':
            handleDeleteDocumentRecord($conn);
            break;
        case 'replace_document':
            handleReplaceDocument($conn);
            break;
        case 'add_document':
            handleAddDocument($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }
    exit();
}

function isPathInsideUploads($path)
{
    $uploadDir = realpath('../uploads/documents/');
    $target    = realpath($path);
    return $uploadDir !== false && $target !== false && strpos($target, $uploadDir) === 0;
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

    $allowedExt   = ['jpg', 'jpeg', 'png', 'pdf'];
    $originalName = $_FILES['document']['name'];
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        echo json_encode(['success' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or PDF.']);
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

    $updateSql  = "UPDATE documents SET file_name = ?, file_path = ? WHERE document_id = ?";
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

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or an upload error occurred.']);
        return;
    }

    $allowedExt   = ['jpg', 'jpeg', 'png', 'pdf'];
    $originalName = $_FILES['document']['name'];
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        echo json_encode(['success' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or PDF.']);
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
        @unlink($destPath);
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

$photoUrl = (!empty($student['photo_path']) && file_exists($student['photo_path']))
    ? $student['photo_path']
    : '../images/default-avatar.png';

include 'navbar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Student - <?= htmlspecialchars($student['name']) ?></title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css" rel="stylesheet">
    <link href="../css/view-student.css" rel="stylesheet">
</head>
<body>
    <div class="view-container">

        <a href="student_directory.php" class="back-arrow" title="Back to Student Directory">
            <i class="bi bi-arrow-left"></i>
        </a>

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
                            <?php if ($documents && mysqli_num_rows($documents) > 0): ?>
                                <?php while ($doc = mysqli_fetch_assoc($documents)): ?>
                                    <div class="document-row" data-document-id="<?= (int) $doc['document_id'] ?>">
                                        <span class="document-name"><?= htmlspecialchars($doc['file_name']) ?></span>
                                        <div class="document-actions">
                                            <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank"
                                               class="btn btn-sm btn-outline-secondary" title="View file">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($doc['file_path']) ?>" download
                                               class="btn btn-sm btn-outline-secondary" title="Download file">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-secondary document-change-btn" title="Change file">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger document-delete-btn" title="Delete file">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-muted document-empty-state">No documents uploaded yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="view-actions">
                    <input type="file" id="newDocumentInput" accept=".jpg,.jpeg,.png,.pdf" hidden>
                    <input type="file" id="replaceDocumentInput" accept=".jpg,.jpeg,.png,.pdf" hidden>
                    <button type="button" class="btn upload-file-btn" id="uploadNewRecordBtn">
                        UPLOAD NEW RECORD
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const STUDENT_ID = <?= (int) $studentId ?>;
    </script>
    <script src="../js/view-student.js"></script>
</body>
</html>