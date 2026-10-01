<?php
function getCourseCatalog()
{
    return [
        [
            'code'    => 'BS-PSYCH',
            'label'   => 'BS PSYCH',
            'name'    => 'Bachelor of Science in Psychology',
            'aliases' => ['BSPSY', 'BSPYCH', 'BSPSYCH', 'BSP'],
        ],
        [
            'code'    => 'BSED-ENGLISH',
            'label'   => 'BSED ENGLISH',
            'name'    => 'Bachelor of Secondary Education Major in English',
            'aliases' => ['BSED-ENG', 'BSEd English'],
        ],
        [
            'code'    => 'BSED-SS',
            'label'   => 'BSED SS',
            'name'    => 'Bachelor of Secondary Education Major in Social Studies',
            'aliases' => ['BSED-SOCSTUD', 'BSED-SOCIAL STUDIES', 'BSEd Social Studies'],
        ],
        [
            'code'    => 'BEED',
            'label'   => 'BEED',
            'name'    => 'Bachelor of Elementary Education',
            'aliases' => ['BEEd'],
        ],
        [
            'code'    => 'BSIT',
            'label'   => 'BSIT',
            'name'    => 'Bachelor of Science in Information Technology',
            'aliases' => [],
        ],
        [
            'code'    => 'BSCPE',
            'label'   => 'BSCPE',
            'name'    => 'Bachelor of Science in Computer Engineering',
            'aliases' => ['BSCE', 'BSCOE', 'BSCOMPE'],
        ],
        [
            'code'    => 'BSIE',
            'label'   => 'BSIE',
            'name'    => 'Bachelor of Science in Industrial Engineering',
            'aliases' => [],
        ],
        [
            'code'    => 'BSBA-HRM',
            'label'   => 'BSBA HRM',
            'name'    => 'Bachelor of Science in Business Administration Major in Human Resource Management',
            'aliases' => ['BSBA-HRDM'],
        ],
        [
            'code'    => 'DCET',
            'label'   => 'DCET',
            'name'    => 'Diploma in Computer Engineering Technology',
            'aliases' => [],
        ],
        [
            'code'    => 'DIT',
            'label'   => 'DIT',
            'name'    => 'Diploma in Information Technology',
            'aliases' => [],
        ],
    ];
}

function courseKey($value)
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
}

function courseDisplayName(array $entry)
{
    return $entry['code'] . ' - ' . $entry['name'];
}

function resolveCourse($raw, $acronymOnly = false)
{
    static $fullIndex = null, $acronymIndex = null;

    if ($fullIndex === null) {
        $fullIndex = $acronymIndex = [];
        foreach (getCourseCatalog() as $entry) {
            $acronyms = array_merge([$entry['code']], $entry['aliases']);
            foreach ($acronyms as $a) {
                $acronymIndex[courseKey($a)] = $entry;
                $fullIndex[courseKey($a)]    = $entry;
            }
            $fullIndex[courseKey($entry['name'])] = $entry;
            $fullIndex[courseKey(courseDisplayName($entry))] = $entry;
        }
    }

    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    if ($acronymOnly) {
        if (strlen($raw) > 20) {
            return null;
        }
        $key = courseKey($raw);
        return $acronymIndex[$key] ?? null;
    }

    $candidates = [$raw, preg_replace('/\([^)]*\)/', '', $raw)];
    if (preg_match_all('/\(([^)]*)\)/', $raw, $m)) {
        foreach ($m[1] as $inner) {
            $candidates[] = $inner;
        }
    }

    foreach ($candidates as $candidate) {
        $key = courseKey($candidate);
        if ($key !== '' && isset($fullIndex[$key])) {
            return $fullIndex[$key];
        }
    }
    return null;
}

function normalizeCourseName($raw)
{
    $entry = resolveCourse($raw);
    return $entry ? courseDisplayName($entry) : trim((string) $raw);
}