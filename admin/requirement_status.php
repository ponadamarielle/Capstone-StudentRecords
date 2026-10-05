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
        : 'Missing: ' . implode(', ', $status['missing']);
    $class = $status['complete'] ? 'req-completion-complete' : 'req-completion-incomplete';
    $icon  = $status['complete'] ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';

    return '<span class="req-completion ' . $class . '" title="' . htmlspecialchars($title) . '">'
        . '<i class="bi ' . $icon . '"></i> ' . htmlspecialchars($status['label']) . '</span>';
}
