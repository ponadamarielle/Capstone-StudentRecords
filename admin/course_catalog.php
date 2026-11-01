<?php
function coreCourseCatalog()
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

function courseCatalogDb()
{
    static $ready = false;
    $conn = $GLOBALS['conn'] ?? null;
    if (!($conn instanceof mysqli)) {
        return null;
    }
    if ($ready) {
        return $conn;
    }
    $ready = true;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS courses (
            course_id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            aliases VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            source VARCHAR(10) NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $existing = [];
        $usedKeys = [];
        $result = mysqli_query($conn, "SELECT course_id, code, name, aliases FROM courses");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $existing[courseKey($row['code'])] = $row;
            foreach (array_merge([$row['code'], $row['name']], courseAliasList($row['aliases'])) as $value) {
                $usedKeys[courseKey($value)] = courseKey($row['code']);
            }
        }
        $wasEmpty = !$existing;

        $insert = mysqli_prepare($conn, "INSERT IGNORE INTO courses (code, name, aliases, status, source) VALUES (?, ?, ?, 'active', ?)");
        $update = mysqli_prepare($conn, "UPDATE courses SET aliases = ? WHERE course_id = ?");
        foreach (coreCourseCatalog() as $entry) {
            $codeKey = courseKey($entry['code']);
            $row = $existing[$codeKey] ?? null;
            if ($row === null) {
                $owner = $usedKeys[$codeKey] ?? ($usedKeys[courseKey($entry['name'])] ?? null);
                $row = $owner !== null ? ($existing[$owner] ?? null) : null;
            }
            if ($row === null) {
                $aliases = implode(', ', array_filter($entry['aliases'], fn($alias) => !isset($usedKeys[courseKey($alias)])));
                $source = 'default';
                mysqli_stmt_bind_param($insert, 'ssss', $entry['code'], $entry['name'], $aliases, $source);
                mysqli_stmt_execute($insert);
                continue;
            }
            $current = courseAliasList($row['aliases']);
            $added = false;
            foreach ($entry['aliases'] as $alias) {
                $owner = $usedKeys[courseKey($alias)] ?? null;
                if ($owner === null) {
                    $current[] = $alias;
                    $usedKeys[courseKey($alias)] = courseKey($row['code']);
                    $added = true;
                }
            }
            if ($added && count($current) <= 10) {
                $aliasText = implode(', ', $current);
                $courseId = (int) $row['course_id'];
                mysqli_stmt_bind_param($update, 'si', $aliasText, $courseId);
                mysqli_stmt_execute($update);
            }
        }
        mysqli_stmt_close($insert);
        mysqli_stmt_close($update);

        if ($wasEmpty) {
            try {
                $learned = mysqli_query($conn, "SELECT code, name FROM course_catalog_learned");
                $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO courses (code, name, aliases, status, source) VALUES (?, ?, '', 'active', 'auto')");
                while ($learned && ($row = mysqli_fetch_assoc($learned))) {
                    mysqli_stmt_bind_param($stmt, 'ss', $row['code'], $row['name']);
                    mysqli_stmt_execute($stmt);
                }
                mysqli_stmt_close($stmt);
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
        error_log('Course catalog: ' . $e->getMessage());
        return null;
    }
    return $conn;
}

function courseAliasList($aliases)
{
    $list = [];
    foreach (explode(',', (string) $aliases) as $alias) {
        $alias = trim($alias);
        if ($alias !== '') {
            $list[] = $alias;
        }
    }
    return array_values(array_unique($list));
}

function getCourseCatalog($reset = false)
{
    static $catalog = null;
    if ($reset) {
        $catalog = null;
        courseIndexes(true);
        return [];
    }
    if ($catalog !== null) {
        return $catalog;
    }
    $conn = courseCatalogDb();
    if ($conn === null) {
        $catalog = coreCourseCatalog();
        return $catalog;
    }
    $catalog = [];
    try {
        $result = mysqli_query($conn, "SELECT course_id, code, name, aliases, status, source FROM courses ORDER BY code ASC");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $catalog[] = [
                'id'      => (int) $row['course_id'],
                'code'    => $row['code'],
                'label'   => $row['code'],
                'name'    => $row['name'],
                'aliases' => courseAliasList($row['aliases']),
                'status'  => $row['status'],
                'source'  => $row['source'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Course catalog: ' . $e->getMessage());
    }
    if (!$catalog) {
        $catalog = coreCourseCatalog();
    }
    return $catalog;
}

function officialCourseCatalog()
{
    return array_values(array_filter(getCourseCatalog(), function ($entry) {
        return ($entry['status'] ?? 'active') === 'active';
    }));
}

function courseKey($value)
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
}

function courseDisplayName(array $entry)
{
    return $entry['code'] . ' - ' . $entry['name'];
}

function courseIndexes($reset = false)
{
    static $indexes = null;
    if ($reset) {
        $indexes = null;
        return null;
    }
    if ($indexes === null) {
        $indexes = ['full' => [], 'acronym' => []];
        foreach (getCourseCatalog() as $entry) {
            $acronyms = array_merge([$entry['code']], $entry['aliases']);
            foreach ($acronyms as $a) {
                $indexes['acronym'][courseKey($a)] = $entry;
                $indexes['full'][courseKey($a)]    = $entry;
            }
            $indexes['full'][courseKey($entry['name'])] = $entry;
            $indexes['full'][courseKey(courseDisplayName($entry))] = $entry;
        }
    }
    return $indexes;
}

function resolveCourse($raw, $acronymOnly = false)
{
    $indexes      = courseIndexes();
    $fullIndex    = $indexes['full'];
    $acronymIndex = $indexes['acronym'];

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

function normalizeCourseName($raw, $learn = false)
{
    $entry = resolveCourse($raw);
    if (!$entry && $learn) {
        $entry = learnCourse($raw);
    }
    return $entry ? courseDisplayName($entry) : trim((string) $raw);
}

function courseAcronym($name)
{
    $skip = ['OF', 'IN', 'AND', 'THE', 'MAJOR', 'WITH', 'ON', 'FOR', 'A', 'AN'];
    $letters = '';
    foreach (preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY) as $word) {
        if (!in_array(strtoupper($word), $skip, true)) {
            $letters .= strtoupper($word[0]);
        }
    }
    return $letters;
}

function parseNewCourse($raw)
{
    $raw = trim(preg_replace('/\s+/', ' ', (string) $raw));
    $degree = '(?:Bachelor|Diploma|Associate|Master|Doctor|Certificate)\b';
    $explicitCode = true;
    if (preg_match('/^([A-Za-z0-9][A-Za-z0-9 .\-]{0,19}?)\s*[-\x{2013}\x{2014}:]\s*(' . $degree . '.+)$/iu', $raw, $m)) {
        [$code, $name] = [$m[1], $m[2]];
    } elseif (preg_match('/^(' . $degree . '.+?)\s*\(([A-Za-z0-9 .\-]{2,20})\)$/iu', $raw, $m)) {
        [$code, $name] = [$m[2], $m[1]];
    } elseif (preg_match('/^' . $degree . '/iu', $raw)) {
        [$code, $name] = [courseAcronym($raw), $raw];
        $explicitCode = false;
    } else {
        return null;
    }
    $code = trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($code))), '-');
    $name = trim($name);
    if (strlen($code) < 2 || strlen($code) > 30 || mb_strlen($name) < 10 || mb_strlen($name) > 255) {
        return null;
    }
    return [$code, $name, $explicitCode];
}

function learnCourse($raw)
{
    $parsed = parseNewCourse($raw);
    if ($parsed === null) {
        return null;
    }
    [$code, $name, $explicitCode] = $parsed;
    $indexes = courseIndexes();
    if (isset($indexes['full'][courseKey($name)])) {
        return $indexes['full'][courseKey($name)];
    }
    if (isset($indexes['acronym'][courseKey($code)])) {
        $existing = $indexes['acronym'][courseKey($code)];
        similar_text(courseKey($existing['name']), courseKey($name), $percent);
        return ($explicitCode || $percent >= 85) ? $existing : null;
    }
    $conn = courseCatalogDb();
    if ($conn === null) {
        return null;
    }
    try {
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO courses (code, name, aliases, status, source) VALUES (?, ?, '', 'active', 'auto')");
        mysqli_stmt_bind_param($stmt, 'ss', $code, $name);
        mysqli_stmt_execute($stmt);
        $added = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Course catalog: ' . $e->getMessage());
        return null;
    }
    getCourseCatalog(true);
    if ($added && function_exists('logActivity')) {
        logActivity('course_created', 'Added course ' . $code . ' - ' . $name . ' automatically from a saved student record', [
            'target_type' => 'course',
            'details'     => ['code' => $code, 'name' => $name, 'source' => 'Detected from a student record.'],
        ]);
    }
    return resolveCourse($code, true);
}