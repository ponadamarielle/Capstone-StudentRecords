<?php
require_once __DIR__ . '/../admin/archive.php';
require_once __DIR__ . '/../year_end_notice.php';

function yearEndGateConnection()
{
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        return $GLOBALS['conn'];
    }
    try {
        $conn = @mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
    } catch (Throwable $e) {
        $conn = false;
    }
    if ($conn) {
        $GLOBALS['conn'] = $conn;
    }
    return $conn ?: null;
}

$yearEndConn = yearEndGateConnection();
$yearEndPending = $yearEndConn ? pendingYearEnd($yearEndConn) : null;

if ($yearEndPending !== null):
    yearEndNotifyIfDue($yearEndConn);
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $yearEndPreview = yearEndPreview($yearEndConn, $yearEndPending);
    $yearEndDate = date('M j, Y', strtotime(schoolYearEndDate($yearEndPending)));
    $yearEndToday = date('Y-m-d') === schoolYearEndDate($yearEndPending);
?>
<link href="../css/year-end-gate.css?v=<?= @filemtime(__DIR__ . '/../css/year-end-gate.css') ?>" rel="stylesheet">
<div class="modal fade" id="yearEndGate" tabindex="-1" aria-labelledby="yearEndGateTitle" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false" data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ye-content">
            <div class="ye-head">
                <span class="ye-icon"><i class="bi bi-mortarboard"></i></span>
                <div>
                    <h5 class="ye-title" id="yearEndGateTitle"><?= htmlspecialchars(schoolYearLabel($yearEndPending)) ?> <?= $yearEndToday ? 'ends today' : 'has ended' ?></h5>
                    <p class="ye-subtitle"><?= $yearEndToday ? 'Today, ' . htmlspecialchars($yearEndDate) . ', is the last day of the school year.' : 'Ended ' . htmlspecialchars($yearEndDate) . '.' ?> Confirm the year-end update to continue.</p>
                </div>
            </div>
            <div class="ye-body">
                <div class="ye-stats">
                    <div class="ye-stat">
                        <span class="ye-stat-label">Will graduate</span>
                        <span class="ye-stat-value"><?= (int) $yearEndPreview['graduate'] ?></span>
                    </div>
                    <div class="ye-stat">
                        <span class="ye-stat-label">Move up a year</span>
                        <span class="ye-stat-value"><?= (int) $yearEndPreview['move_up'] ?></span>
                    </div>
                </div>
                <div id="yearEndError" class="ye-error" role="alert" hidden></div>
            </div>
            <div class="ye-foot">
                <button type="button" class="ye-confirm" id="yearEndConfirm">Confirm year-end update</button>
            </div>
        </div>
    </div>
</div>
<script src="../js/year-end-gate.js?v=<?= @filemtime(__DIR__ . '/../js/year-end-gate.js') ?>"></script>
<?php endif; ?>
