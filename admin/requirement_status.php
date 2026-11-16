<?php

const DEFAULT_ADMISSION_TRACK = 'shs_current';

/**
 * @param array  $requirements Requirement list (same shape as requirementsForClient()).
 * @param string $track        The student's admission track.
 * @param array  $storedCodes  requirement_code values of the student's documents.
 */
function requirementCompletion(array $requirements, $track, array $storedCodes)
{
    $track = ($track !== null && $track !== '') ? $track : DEFAULT_ADMISSION_TRACK;

    $onFile = [];
    foreach ($storedCodes as $code) {
        if ($code !== null && $code !== '') {
            $onFile[$code] = true;
        }
    }

    $total   = 0;
    $missing = [];
    foreach ($requirements as $r) {
        $requiredFor = $r['required_for'] ?? [];
        if (!is_array($requiredFor) || !in_array($track, $requiredFor, true)) {
            continue;
        }
        $total++;
        if (!isset($onFile[$r['code'] ?? ''])) {
            $missing[] = $r['label'] ?? $r['code'];
        }
    }

    $complete = count($missing) === 0;
    return [
        'complete'  => $complete,
        'label'     => $complete ? 'Complete' : 'Incomplete',
        'fulfilled' => $total - count($missing),
        'total'     => $total,
        'missing'   => $missing,
    ];
}

function requirementCodesByStudent($conn, array $studentIds)
{
    $ids = array_values(array_unique(array_map('intval', $studentIds)));
    $byStudent = array_fill_keys($ids, []);
    if (!$ids) {
        return $byStudent;
    }

    $sql = 'SELECT student_id, requirement_code FROM documents
            WHERE requirement_code IS NOT NULL AND requirement_code <> \'\'
              AND student_id IN (' . implode(',', $ids) . ')';
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $byStudent[(int) $row['student_id']][] = $row['requirement_code'];
        }
    }
    return $byStudent;
}

function requirementStatusBadge(array $status)
{
    $title = $status['complete']
        ? 'All ' . $status['total'] . ' required documents are on file'
        : 'Not yet submitted: ' . implode(', ', $status['missing']);
    $class = $status['complete'] ? 'req-completion-complete' : 'req-completion-incomplete';
    $icon  = $status['complete'] ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';

    return '<span class="req-completion ' . $class . '" title="' . htmlspecialchars($title) . '">'
        . '<i class="bi ' . $icon . '"></i> ' . htmlspecialchars($status['label']) . '</span>';
}

if (!defined('GRADUATION_TRACK')) {
    define('GRADUATION_TRACK', 'graduating');
}

function graduationCompletion(array $requirements, array $student, array $storedCodes)
{
    $graduated = strtolower((string) ($student['status'] ?? '')) === 'graduated';
    $candidate = function_exists('isGraduationCandidate') && isGraduationCandidate($student);
    if (!$graduated && !$candidate) {
        return null;
    }
    $status = requirementCompletion($requirements, GRADUATION_TRACK, $storedCodes);
    return $status['total'] > 0 ? $status : null;
}

function directoryRequirementBadge(array $completion, ?array $graduation)
{
    if (!$graduation) {
        return requirementStatusBadge($completion);
    }
    if (!$completion['complete']) {
        $title = 'Not yet submitted: ' . implode(', ', $completion['missing']);
        if (!$graduation['complete']) {
            $title .= ' | Not yet submitted for graduation: ' . implode(', ', $graduation['missing']);
        }
        return '<span class="req-completion req-completion-incomplete" title="' . htmlspecialchars($title) . '">'
            . '<i class="bi bi-exclamation-circle-fill"></i> Incomplete</span>';
    }
    if ($graduation['complete']) {
        return '<span class="req-completion req-completion-complete" title="All admission and graduation requirements are on file">'
            . '<i class="bi bi-check-circle-fill"></i> Complete</span>';
    }
    return '<span class="req-completion req-completion-graduation req-completion-incomplete" title="'
        . htmlspecialchars('Not yet submitted for graduation: ' . implode(', ', $graduation['missing'])) . '">'
        . '<i class="bi bi-mortarboard"></i> Incomplete</span>';
}

function graduationStatusBadge(array $status)
{
    $title = $status['complete']
        ? 'All ' . $status['total'] . ' graduation requirements are on file'
        : 'Not yet submitted for graduation: ' . implode(', ', $status['missing']);
    $class = $status['complete'] ? 'req-completion-complete' : 'req-completion-incomplete';
    $icon  = $status['complete'] ? 'bi-mortarboard-fill' : 'bi-mortarboard';

    return '<span class="req-completion req-completion-graduation ' . $class . '" title="' . htmlspecialchars($title) . '">'
        . '<i class="bi ' . $icon . '"></i> ' . htmlspecialchars($status['label']) . '</span>';
}