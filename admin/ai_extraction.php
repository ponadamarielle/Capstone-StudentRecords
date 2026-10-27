<?php
require_once __DIR__ . '/course_catalog.php';

const GROQ_API_URL       = 'https://api.groq.com/openai/v1/chat/completions';
const GROQ_DEFAULT_MODEL = 'qwen/qwen3.8-27b';
const AI_MAX_PARALLEL    = 5;
const AI_PAGE_IMAGE_SIZE = 1280;
const AI_ZOOM_WIDTH      = 1600;
const AI_ZOOM_HEIGHT     = 1280;
const AI_MAX_WAIT        = 45;

const AI_STUDENT_FIELDS = [
    'student_number', 'last_name', 'first_name', 'middle_name', 'course', 'year_section', 'sex',
    'date_of_birth', 'place_of_birth', 'address', 'mobile_number', 'email',
];

function groqModel()
{
    return getenv('GROQ_VISION_MODEL') ?: GROQ_DEFAULT_MODEL;
}

function groqModels()
{
    $models = [groqModel()];
    foreach (explode(',', (string) getenv('GROQ_FALLBACK_MODELS')) as $model) {
        $model = trim($model);
        if ($model !== '') {
            $models[] = $model;
        }
    }
    return array_values(array_unique($models));
}

function groqCooldownFile()
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'pup_erecords_groq_cooldown.json';
}

function groqCooldowns($set = null)
{
    $file = groqCooldownFile();
    $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : [];
    $data = is_array($data) ? $data : [];
    $now = time();
    $data = array_filter($data, fn($until) => (int) $until > $now);
    if ($set !== null) {
        foreach ($set as $model => $until) {
            $data[$model] = max((int) ($data[$model] ?? 0), (int) $until);
        }
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
    return $data;
}

function groqPickModel(array $skip = [])
{
    $cooling = groqCooldowns();
    $models = groqModels();
    foreach ($models as $model) {
        if (!in_array($model, $skip, true) && !isset($cooling[$model])) {
            return $model;
        }
    }
    $candidates = array_values(array_diff($models, $skip)) ?: $models;
    usort($candidates, fn($a, $b) => ($cooling[$a] ?? 0) <=> ($cooling[$b] ?? 0));
    return $candidates[0];
}

function groqUsage($tokens = null)
{
    $file = groqCooldownFile() . '.usage.json';
    $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : [];
    $data = is_array($data) ? $data : [];
    $since = microtime(true) - 60;
    $data = array_values(array_filter($data, fn($row) => is_array($row) && ($row[0] ?? 0) > $since));
    if ($tokens !== null && $tokens > 0) {
        $data[] = [microtime(true), (int) $tokens];
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
    return $data;
}

function groqWouldExceed($requests)
{
    $usage = groqUsage();
    $limit = (int) (getenv('GROQ_ITPM_LIMIT') ?: 7000);
    $last = $usage ? (int) end($usage)[1] : 0;
    $perRequest = max($last, (int) (getenv('GROQ_TOKENS_PER_PAGE') ?: 3800));
    $now = microtime(true);
    $used = 0.0;
    foreach ($usage as [$time, $tokens]) {
        $used += $tokens * max(0, 1 - ($now - $time) / 60);
    }
    return $used + $perRequest * max(1, $requests) > $limit;
}

function groqResetSeconds($value)
{
    $value = trim((string) $value);
    $seconds = 0.0;
    if (preg_match_all('/([\d.]+)\s*(ms|h|m|s)/i', $value, $parts, PREG_SET_ORDER)) {
        foreach ($parts as $part) {
            $unit = strtolower($part[2]);
            $seconds += (float) $part[1] * ($unit === 'h' ? 3600 : ($unit === 'm' ? 60 : ($unit === 'ms' ? 0.001 : 1)));
        }
    } elseif (is_numeric($value)) {
        $seconds = (float) $value;
    }
    return (int) ceil($seconds);
}

function groqNoteRemaining($model, $headers)
{
    if (!preg_match('/^x-ratelimit-remaining-tokens:\s*(\d+)/mi', (string) $headers, $remaining)) {
        return;
    }
    $needed = (int) (getenv('GROQ_TOKENS_PER_PAGE') ?: 3800);
    if ((int) $remaining[1] >= $needed) {
        return;
    }
    $reset = preg_match('/^x-ratelimit-reset-tokens:\s*(\S+)/mi', (string) $headers, $m) ? groqResetSeconds($m[1]) : 10;
    groqCooldowns([$model => time() + max(1, min($reset, 60))]);
    error_log('Groq: only ' . $remaining[1] . ' tokens left on ' . $model . ', using the fallback for the next ' . max(1, min($reset, 60)) . 's.');
}

function groqApplyModel(array $payload, $model)
{
    $payload['model'] = $model;
    if (stripos($model, 'qwen') !== false) {
        $payload['reasoning_effort'] = 'none';
    } else {
        unset($payload['reasoning_effort']);
    }
    return $payload;
}

function aiExtractionError($message = null)
{
    static $error = null;
    if ($message !== null) {
        $error = $message;
    }
    return $error;
}

function aiUsedSource($source = null)
{
    static $used = [];
    if ($source !== null) {
        $used[$source] = true;
    }
    return $used ? implode(' + ', array_keys($used)) : 'Groq (' . groqModel() . ')';
}

function geminiModels()
{
    $models = [];
    foreach (explode(',', (string) (getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite,gemini-3.8-flash')) as $model) {
        $model = trim($model);
        if ($model !== '') {
            $models[] = $model;
        }
    }
    return array_values(array_unique($models));
}

function geminiModel()
{
    return geminiModels()[0] ?? 'gemini-3.8-flash';
}

function geminiReadyModels()
{
    if ((string) getenv('GEMINI_API_KEY') === '') {
        return [];
    }
    $cooling = groqCooldowns();
    return array_values(array_filter(geminiModels(), fn($model) => !isset($cooling['gemini:' . $model])));
}

function geminiAvailable()
{
    return geminiReadyModels() !== [];
}

function groqAllCooling()
{
    $cooling = groqCooldowns();
    foreach (groqModels() as $model) {
        if (!isset($cooling[$model])) {
            return false;
        }
    }
    return true;
}

function geminiBodyFromGroq($groqBody, $model, $effort)
{
    $payload = json_decode($groqBody, true) ?: [];
    $body = [
        'model'            => $model,
        'messages'         => $payload['messages'] ?? [],
        'temperature'      => 0,
        'max_tokens'       => max(2048, ((int) ($payload['max_completion_tokens'] ?? 1024)) * 3),
        'reasoning_effort' => $effort,
    ];
    return json_encode($body);
}

function geminiEffort($model)
{
    if (preg_match('/gemini-2\.5-(flash|flash-lite)/i', $model)) {
        return 'none';
    }
    return isset(groqCooldowns()['gemini-low-only:' . $model]) ? 'low' : 'minimal';
}

function geminiSend(array $pending, $model, $effort)
{
    $url = rtrim(getenv('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta/openai', '/') . '/chat/completions';
    $timeout = max(4, (int) (getenv('GEMINI_TIMEOUT') ?: 8));
    $mh = curl_multi_init();
    $handles = [];
    foreach ($pending as $key => $body) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . getenv('GEMINI_API_KEY'),
            ],
            CURLOPT_POSTFIELDS     => geminiBodyFromGroq($body, $model, $effort),
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];
    $failures = [];
    foreach ($handles as $key => $ch) {
        $response = (string) curl_multi_getcontent($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $content = $data['choices'][0]['message']['content'] ?? null;
            if (is_string($content)) {
                $content = trim(preg_replace('/<think>.*?<\/think>/s', '', $content));
                $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);
                $results[$key] = trim($content);
                continue;
            }
        }
        $failures[$key] = [$httpCode, $response, $curlErr];
    }
    curl_multi_close($mh);
    return [$results, $failures];
}

function geminiChatMany(array $groqBodies)
{
    $results = [];
    $pending = $groqBodies;

    foreach (geminiReadyModels() as $model) {
        if (!$pending) {
            break;
        }
        $effort = geminiEffort($model);
        [$done, $failures] = geminiSend($pending, $model, $effort);

        if ($effort === 'minimal') {
            $retryLow = [];
            foreach ($failures as $key => [$code, $response]) {
                if ($code === 400 && stripos($response, 'reasoning') !== false) {
                    $retryLow[$key] = $pending[$key];
                }
            }
            if ($retryLow) {
                groqCooldowns(['gemini-low-only:' . $model => time() + 86400]);
                [$doneLow, $failLow] = geminiSend($retryLow, $model, 'low');
                $done += $doneLow;
                $failures = array_diff_key($failures, $retryLow) + $failLow;
            }
        }

        foreach ($done as $key => $reply) {
            $results[$key] = $reply;
            unset($pending[$key]);
            aiUsedSource('Gemini (' . $model . ')');
        }

        $pause = 0;
        foreach ($failures as [$httpCode, $response, $curlErr]) {
            $geminiError = 'Gemini fallback failed (HTTP ' . $httpCode . ', model ' . $model . ')' . ($curlErr !== '' ? ' ' . $curlErr : '') . ': ' . substr($response, 0, 300);
            error_log($geminiError);
            @file_put_contents(groqCooldownFile() . '.gemini.txt', date('Y-m-d H:i:s') . ' ' . $geminiError);
            if ($httpCode === 429) {
                $pause = max($pause, 30);
            } elseif (in_array($httpCode, [400, 401, 403, 404], true)) {
                $pause = max($pause, 600);
            } else {
                $pause = max($pause, 60);
            }
        }
        if ($pause > 0) {
            groqCooldowns(['gemini:' . $model => time() + $pause]);
        }
    }
    if ($results) {
        error_log('Groq is rate-limited, read ' . count($results) . ' request(s) with ' . aiUsedSource() . '.');
    }
    return $results;
}

function groqRequestBody(array $messages, $jsonMode, $maxTokens)
{
    $payload = [
        'model'                 => groqModel(),
        'messages'              => $messages,
        'temperature'           => 0,
        'max_completion_tokens' => $maxTokens,
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    return json_encode(groqApplyModel($payload, groqPickModel()));
}

function groqChatMany(array $bodies)
{
    $results = array_fill_keys(array_keys($bodies), null);
    if (!$bodies) {
        return $results;
    }

    $apiKey = getenv('GROQ_API_KEY');
    if (!$apiKey) {
        error_log('GROQ_API_KEY is not set in .env — AI extraction skipped.');
        aiExtractionError('AI extraction is not configured (GROQ_API_KEY is missing), so no fields were filled in.');
        return $results;
    }

    $queue = $bodies;
    $lastCode = 0;
    $waited = 0;
    $tried = [];
    if (geminiAvailable() && (groqAllCooling() || groqWouldExceed(count($queue)))) {
        foreach (geminiChatMany($queue) as $key => $reply) {
            $results[$key] = $reply;
            unset($queue[$key]);
        }
    }
    for ($attempt = 1; $attempt <= 4 && $queue; $attempt++) {
        $retry = [];
        $retryAfter = 1;
        $mustWait = false;
        foreach (array_chunk($queue, AI_MAX_PARALLEL, true) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            $headers = [];
            foreach ($chunk as $key => $body) {
                $ch = curl_init(GROQ_API_URL);
                $headers[$key] = '';
                curl_setopt_array($ch, [
                    CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers, $key) {
                        $headers[$key] .= $line;
                        return strlen($line);
                    },
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT        => 30,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $key => $ch) {
                $response = curl_multi_getcontent($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr  = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $lastCode = $httpCode;

                if ($httpCode === 200 && is_string($response)) {
                    $data    = json_decode($response, true);
                    $content = $data['choices'][0]['message']['content'] ?? null;
                    if (is_string($content)) {
                        $results[$key] = trim(preg_replace('/<think>.*?<\/think>/s', '', $content));
                        $usedModel = json_decode($chunk[$key], true)['model'] ?? groqModel();
                        groqUsage((int) ($data['usage']['prompt_tokens'] ?? 0));
                        aiUsedSource('Groq (' . $usedModel . ')');
                        groqNoteRemaining($usedModel, $headers[$key]);
                    } else {
                        error_log('Groq returned no message content: ' . substr($response, 0, 500));
                    }
                } elseif ($httpCode === 429 || $httpCode >= 500 || $httpCode === 0) {
                    error_log("Groq attempt $attempt failed (HTTP $httpCode) $curlErr " . substr((string) $response, 0, 300));
                    $payload = json_decode($chunk[$key], true);
                    $model = $payload['model'] ?? groqModel();
                    $after = 1;
                    if (preg_match('/^retry-after:\s*([\d.]+)/mi', $headers[$key], $m)) {
                        $after = max(1, (int) ceil((float) $m[1]));
                    }
                    if ($httpCode === 429) {
                        groqCooldowns([$model => time() + max($after, 5)]);
                        $tried[$key][] = $model;
                        $next = groqPickModel($tried[$key]);
                        if ($next !== $model && !isset(groqCooldowns()[$next])) {
                            error_log("Groq: $model is rate-limited, switching to $next.");
                            $retry[$key] = json_encode(groqApplyModel($payload, $next));
                            continue;
                        }
                    }
                    $retry[$key] = $chunk[$key];
                    $retryAfter = max($retryAfter, $after);
                    $mustWait = true;
                } elseif (($httpCode === 400 || $httpCode === 404) && count(groqModels()) > 1
                    && stripos((string) $response, 'model') !== false) {
                    $payload = json_decode($chunk[$key], true);
                    $model = $payload['model'] ?? groqModel();
                    error_log('Groq model ' . $model . ' cannot be used (HTTP ' . $httpCode . '): ' . substr((string) $response, 0, 300));
                    groqCooldowns([$model => time() + 3600]);
                    $tried[$key][] = $model;
                    $next = groqPickModel($tried[$key]);
                    if ($next !== $model && !in_array($next, $tried[$key], true)) {
                        $retry[$key] = json_encode(groqApplyModel($payload, $next));
                    } else {
                        aiExtractionError('AI extraction failed (HTTP ' . $httpCode . '). Please fill in the fields manually.');
                    }
                } else {
                    error_log('Groq request failed, HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 500));
                    aiExtractionError('AI extraction failed (HTTP ' . $httpCode . '). Please fill in the fields manually.');
                }
            }
            curl_multi_close($mh);
        }
        $queue = $retry;
        if ($queue && $mustWait && !geminiAvailable()) {
            error_log((string) getenv('GEMINI_API_KEY') === ''
                ? 'Gemini fallback skipped: GEMINI_API_KEY is not set in .env.'
                : 'Gemini fallback skipped: ' . implode(', ', geminiModels()) . ' paused after an earlier error. Last error: '
                    . trim((string) @file_get_contents(groqCooldownFile() . '.gemini.txt')));
        }
        if ($queue && $mustWait && geminiAvailable()) {
            foreach (geminiChatMany($queue) as $key => $reply) {
                $results[$key] = $reply;
                unset($queue[$key]);
            }
            if (!$queue) {
                break;
            }
        }
        if ($queue && $attempt < 4 && $mustWait) {
            $wait = min($retryAfter, 20, AI_MAX_WAIT - $waited);
            if ($wait <= 0) {
                break;
            }
            sleep($wait);
            $waited += $wait;
        }
    }

    if ($queue) {
        aiExtractionError($lastCode === 429
            ? 'The AI service is busy (rate limit reached). Please wait a minute and upload again, or fill in the fields manually.'
            : 'The AI service could not be reached. Please fill in the fields manually or try again.');
    }
    return $results;
}

function groqChat(array $messages, $jsonMode = false, $maxTokens = 2048)
{
    return groqChatMany(['one' => groqRequestBody($messages, $jsonMode, $maxTokens)])['one'];
}

function imageToDataUrlForAi($imagePath, $maxSide = AI_PAGE_IMAGE_SIZE, $maxBytes = 900000)
{
    $raw = @file_get_contents($imagePath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $info = @getimagesizefromstring($raw);
    $mime = $info['mime'] ?? 'image/jpeg';
    $tooBig = strlen($raw) > $maxBytes || ($info && max($info[0], $info[1]) > $maxSide);

    if ($tooBig && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        @ini_set('memory_limit', '512M');
        $img = @imagecreatefromstring($raw);
        if ($img) {
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, $maxSide / max($w, $h));
            if ($scale < 1) {
                $resized = imagescale($img, (int) round($w * $scale), (int) round($h * $scale), IMG_BILINEAR_FIXED);
                if ($resized) {
                    imagedestroy($img);
                    $img = $resized;
                }
            }
            foreach ([80, 70, 60] as $quality) {
                ob_start();
                imagejpeg($img, null, $quality);
                $jpeg = ob_get_clean();
                if (strlen($jpeg) <= $maxBytes) {
                    break;
                }
            }
            imagedestroy($img);
            $raw  = $jpeg;
            $mime = 'image/jpeg';
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

function aiFieldRules()
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }

    $rules = "Field rules:\n"
        . "- Copy every value exactly as it is written, character by character. Do not correct, complete, shorten or add anything.\n"
        . "- Handwriting is usually in CAPITAL letters; read each letter carefully, and never add digits or letters that are not written.\n"
        . "- Use \"\" for anything not clearly written in the document. Never guess or invent values.\n"
        . "- Only use the STUDENT's/applicant's own details, never a parent's, guardian's or school official's.\n"
        . "- student_number: the PUP Student No. exactly as written, e.g. 2026-0016-BN-0 or 2024-00123-MN-0. "
        . "The Reference Number (on SAR Form 1 and the Route and Approval Slip) is NOT the student number; never put it in student_number.\n"
        . "- last_name, first_name, middle_name: the student's name split into parts. Filipino first names often have two words "
        . "(e.g. ANDREA MAE, JOSHUA DANIEL, JOHN PAUL), so a second given name is part of first_name, NOT the middle name. "
        . "If the name is written as \"LAST, GIVEN NAMES\" in one blank, last_name is before the comma and ALL the words after it go in first_name; "
        . "only a final single-letter initial (e.g. \"C.\") goes in middle_name. "
        . "Fill middle_name with a full word only when the form has its own Middle Name blank. "
        . "Examples: \"VILLANUEVA, ANDREA MAE\" gives last_name VILLANUEVA, first_name ANDREA MAE, middle_name \"\"; "
        . "\"REYES, ADRIAN MIGUEL C.\" gives last_name REYES, first_name ADRIAN MIGUEL, middle_name C.\n"
        . "- course: the course/program exactly as written, usually an abbreviation such as BSIT, BSPSY, BSA, BEED. Never replace it with a different course. "
        . "The Selected NSTP (CWTS, LTS or ROTC) on SAR Form 1 is NOT a course; SAR Form 1 has no course, so leave course \"\" for it.\n"
        . "- year_section: \"year-section\" as two single digits, e.g. \"1-1\". If only a section is written (e.g. \"Section: 1\" on the Route and Approval Slip), "
        . "the student is a freshman, so return \"1-\" plus the section, e.g. \"1-1\". \"\" if not written. A school year like 2026-2027 is NOT a year & section.\n"
        . "- sex: \"Male\" or \"Female\".\n"
        . "- date_of_birth: YYYY-MM-DD.\n"
        . "- place_of_birth: city/municipality and province.\n"
        . "- address: the student's home/permanent address.\n"
        . "- mobile_number: 11 digits starting with 09.\n"
        . "- email: the student's email address, letter for letter (a dot may look like a small dash).\n"
        . "- Ignore form codes/revision stamps like \"PUP-XXXX-XX Rev.0 Jan 1, 2020\".";
    return $rules;
}

function aiPageCache($text = null, $fields = null)
{
    static $pages = [];
    if ($text !== null) {
        $pages[] = ['text' => $text, 'fields' => $fields];
    }
    return $pages;
}

function aiDecodeJson($reply)
{
    if ($reply === null) {
        return null;
    }
    $json = json_decode($reply, true);
    if (!is_array($json) && preg_match('/\{.*\}/s', $reply, $m)) {
        $json = json_decode($m[0], true);
    }
    if (!is_array($json) && preg_match('/"fields"\s*:\s*(\{[^{}]*\})/s', $reply, $m)) {
        $fields = json_decode($m[1], true);
        $json = is_array($fields) ? ['fields' => $fields, 'text' => ''] : null;
    }
    if (!is_array($json)) {
        error_log('Groq returned invalid JSON: ' . substr($reply, 0, 500));
        return null;
    }
    return $json;
}

function aiDocTypeList()
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = '';
    if (function_exists('getDocumentRequirements')) {
        foreach (getDocumentRequirements() as $code => $req) {
            $hint = (string) ($req['ai_hint'] ?? '');
            $list .= "- {$code}: {$req['label']}" . ($hint !== '' ? ' (' . mb_substr($hint, 0, 70) . ')' : '') . "\n";
        }
    }
    return $list;
}

function aiPageReadMessages(array $dataUrls)
{
    $types = aiDocTypeList();
    $system = "You read scanned Philippine university student documents and reply with ONLY a JSON object:\n"
        . "{\"doc_type\": \"\", \"doc_confidence\": \"high\", \"fields\": {\"student_number\": \"\", \"last_name\": \"\", \"first_name\": \"\", \"middle_name\": \"\", \"course\": \"\", \"year_section\": \"\", \"sex\": \"\", "
        . "\"date_of_birth\": \"\", \"place_of_birth\": \"\", \"address\": \"\", \"mobile_number\": \"\", \"email\": \"\"}, \"text\": \"...\"}\n"
        . ($types !== ''
            ? "\"doc_type\": the code of what this page IS (not documents it merely mentions), or \"none\" for a blank page, "
              . "a back side or continuation page, or a document matching no type. For report cards use the card's own grade level: "
              . "a card with four quarterly grade columns and learning areas such as Araling Panlipunan, EsP, TLE and MAPEH is grade_10_card (Junior High School); "
              . "a card with 1st/2nd semester and Core, Applied or Specialized subjects is grade_11_card or grade_12_card. "
              . "Pages may be scanned sideways or upside down; read the rotated text.\n"
              . "\"doc_confidence\": high, medium or low.\nDocument types (code: name. description):\n" . $types
            : '')
        . "\"fields\": the student's details found on this page.\n"
        . "\"text\": only the form code and the document title printed on the page (use \\n between them), at most 200 characters. No markdown.\n"
        . aiFieldRules();

    $intro = count($dataUrls) > 1
        ? 'Image 1 is the whole page. Image 2 is a zoomed-in view of the top of the same page; read handwritten values from image 2.'
        : 'This image is the top part of the page. Read it carefully, including handwriting.';
    $content = [['type' => 'text', 'text' => $intro]];
    foreach ($dataUrls as $url) {
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
    }

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $content],
    ];
}

function aiReadImages(array $imagePaths)
{
    static $byFile = [];
    $results = array_fill_keys(array_keys($imagePaths), null);
    $bodies = [];
    $hashes = [];

    foreach ($imagePaths as $key => $paths) {
        $paths = (array) $paths;
        $hash = @md5_file($paths[0]);
        if ($hash && array_key_exists($hash, $byFile)) {
            $results[$key] = $byFile[$hash];
            continue;
        }
        $dataUrls = [];
        foreach ($paths as $i => $path) {
            $url = imageToDataUrlForAi($path, AI_ZOOM_WIDTH, 1200000);
            if ($url !== null) {
                $dataUrls[] = $url;
            }
        }
        if (!$dataUrls) {
            continue;
        }
        $hashes[$key] = $hash;
        $bodies[$key] = groqRequestBody(aiPageReadMessages($dataUrls), true, 700);
    }

    foreach (groqChatMany($bodies) as $key => $reply) {
        $json = aiDecodeJson($reply);
        if ($json === null) {
            continue;
        }
        $text = is_string($json['text'] ?? null) ? trim($json['text']) : '';
        $fields = sanitizeAiStudentFields(is_array($json['fields'] ?? null) ? $json['fields'] : []);
        if ($text !== '') {
            aiPageCache($text, $fields);
        }
        $read = [
            'text'           => $text,
            'doc_type'       => is_string($json['doc_type'] ?? null) ? trim($json['doc_type']) : null,
            'doc_confidence' => in_array($json['doc_confidence'] ?? '', ['high', 'medium', 'low'], true) ? $json['doc_confidence'] : 'low',
        ];
        if ($hashes[$key]) {
            $byFile[$hashes[$key]] = $read;
        }
        $results[$key] = $read;
    }
    return $results;
}

function aiReadDocumentText($imagePath)
{
    return aiReadImages(['page' => $imagePath])['page']['text'] ?? null;
}

function aiPageRenderCommand($pdfPath, $page, $outDir, $zoom)
{
    $base = rtrim($outDir, '/\\') . '/' . uniqid($zoom ? 'aizoom_' : 'aipage_', true);
    $size = $zoom
        ? ' -jpegopt quality=80 -scale-to-x ' . AI_ZOOM_WIDTH . ' -scale-to-y -1 -x 0 -y 0 -W ' . AI_ZOOM_WIDTH . ' -H ' . AI_ZOOM_HEIGHT
        : ' -jpegopt quality=75 -scale-to ' . AI_PAGE_IMAGE_SIZE;
    $cmd = escapeshellarg(PDFTOPPM_BIN) . ' -jpeg' . $size
        . ' -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
    return [$cmd, $base . '.jpg'];
}

function aiRunCommandsParallel(array $commands, $limit = 6)
{
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    foreach (array_chunk($commands, $limit, true) as $chunk) {
        $procs = [];
        foreach ($chunk as $key => $cmd) {
            $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
            if (is_resource($proc)) {
                $procs[$key] = $proc;
            } else {
                exec($cmd . ' 2>&1');
            }
        }
        foreach ($procs as $proc) {
            proc_close($proc);
        }
    }
}

function aiReadPdfPages($pdfPath, array $pages, $workDir)
{
    $commands = [];
    $files = [];
    foreach ($pages as $page) {
        [$cmd, $file] = aiPageRenderCommand($pdfPath, $page, $workDir, true);
        $commands[$page] = $cmd;
        $files[$page][] = $file;
    }
    aiRunCommandsParallel($commands);

    $images = [];
    $results = [];
    foreach ($pages as $page) {
        $ready = array_values(array_filter($files[$page], 'file_exists'));
        $results[$page] = ['text' => null, 'doc_type' => null, 'doc_confidence' => 'low', 'render_failed' => !file_exists($files[$page][0])];
        if (file_exists($files[$page][0])) {
            $images[$page] = $ready;
        }
    }

    foreach (aiReadImages($images) as $page => $read) {
        if ($read !== null) {
            $results[$page] = $read + $results[$page];
        }
    }
    foreach ($files as $set) {
        foreach ($set as $file) {
            @unlink($file);
        }
    }
    return $results;
}

function aiFieldsFromCache($text)
{
    $merged = array_fill_keys(AI_STUDENT_FIELDS, '');
    $found = false;
    foreach (aiPageCache() as $page) {
        if (strpos($text, $page['text']) === false) {
            continue;
        }
        $found = true;
        foreach ($page['fields'] as $key => $value) {
            if ($merged[$key] === '' && $value !== '') {
                $merged[$key] = $value;
            }
        }
    }
    return $found ? $merged : null;
}

function aiExtractStudentFields($text)
{
    $empty = array_fill_keys(AI_STUDENT_FIELDS, '');
    $text = trim((string) $text);
    if ($text === '') {
        return $empty;
    }

    $cached = aiFieldsFromCache($text);
    if ($cached !== null) {
        return $cached;
    }

    if (strlen($text) > 15000) {
        $text = substr($text, 0, 15000);
    }

    $system = "You extract student information from school documents and reply with ONLY a JSON object with exactly these keys:\n"
        . "student_number, last_name, first_name, middle_name, course, year_section, sex, date_of_birth, place_of_birth, address, mobile_number, email.\n"
        . aiFieldRules();

    $json = aiDecodeJson(groqChat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "Document text:\n\"\"\"\n" . $text . "\n\"\"\""],
    ], true, 1024));

    return $json === null ? $empty : sanitizeAiStudentFields($json);
}

function aiTitleCase($text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    return preg_replace_callback('/[\p{L}\p{M}\d.]+/u', function ($m) {
        $word = $m[0];
        $upper = mb_strtoupper($word, 'UTF-8');
        if (preg_match('/^(I{1,3}|IV|V|VI{0,3}|IX|X)$/', $upper)) {
            return $upper;
        }
        if (preg_match('/^(\p{L}\.){2,}$/u', $word)) {
            return $upper;
        }
        if (preg_match('/^\d+(st|nd|rd|th)$/i', $word)) {
            return mb_strtolower($word, 'UTF-8');
        }
        if (preg_match('/^\d/', $word)) {
            return $upper;
        }
        return mb_convert_case(mb_strtolower($word, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }, $text);
}

function sanitizeAiStudentFields(array $raw)
{
    $f = [];
    foreach (AI_STUDENT_FIELDS as $key) {
        $v = $raw[$key] ?? '';
        $v = is_scalar($v) ? trim(preg_replace('/\s+/u', ' ', (string) $v)) : '';
        if (in_array(strtolower($v), ['n/a', 'na', 'none', 'null', '-', 'unknown', 'not found', 'not provided'], true)) {
            $v = '';
        }
        $f[$key] = $v;
    }

    $sn = strtoupper(preg_replace('/\s+/', '', $f['student_number']));
    $sn = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $sn);
    $f['student_number'] = preg_match('/^(\d{4})-?([\dO]{4,5})-?([A-Z]{2})-?([0-9O])$/', $sn, $m)
        ? $m[1] . '-' . str_replace('O', '0', $m[2]) . '-' . $m[3] . '-' . str_replace('O', '0', $m[4])
        : '';

    foreach (['last_name', 'first_name', 'middle_name'] as $key) {
        $part = preg_replace('/\S*\d\S*/u', ' ', $f[$key]);
        $f[$key] = aiTitleCase(trim(preg_replace('/\s+/u', ' ', preg_replace("/[^A-Za-zÑñ\\s.'\\-]/u", '', $part)), " -'"));
    }

    if (preg_match('/^\W*(nstp|cwts|ctws|cwst|lts|rotc|mts)\b/i', $f['course'])) {
        $f['course'] = '';
    }
    if ($f['course'] !== '') {
        $course = normalizeCourseName($f['course']);
        $f['course'] = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z\s\-]/', '', $course)));
    }

    if (preg_match('/^\D*(\d)\s*[-\x{2010}-\x{2015}\/]\s*(\d)\D*$/u', $f['year_section'], $m)) {
        $f['year_section'] = $m[1] . '-' . $m[2];
    } elseif (preg_match('/^\D*(\d)\D*$/', $f['year_section'], $m)) {
        $f['year_section'] = '1-' . $m[1];
    } else {
        $f['year_section'] = '';
    }

    $sex = strtolower($f['sex']);
    $f['sex'] = in_array($sex, ['m', 'male'], true) ? 'Male' : (in_array($sex, ['f', 'female'], true) ? 'Female' : '');

    $dob = '';
    if ($f['date_of_birth'] !== '') {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f['date_of_birth'], $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $dob = $f['date_of_birth'];
        } elseif (($ts = strtotime($f['date_of_birth'])) !== false) {
            $dob = date('Y-m-d', $ts);
        }
        if ($dob !== '' && ($dob > date('Y-m-d', strtotime('-17 years')) || $dob < '1900-01-01')) {
            $dob = '';
        }
    }
    $f['date_of_birth'] = $dob;

    $mobile = preg_replace('/\D/', '', $f['mobile_number']);
    if (preg_match('/^63(9\d{9})$/', $mobile, $m)) {
        $mobile = '0' . $m[1];
    } elseif (preg_match('/^9\d{9}$/', $mobile)) {
        $mobile = '0' . $mobile;
    }
    $f['mobile_number'] = preg_match('/^09\d{9}$/', $mobile) ? $mobile : '';

    $email = rtrim(strtolower(str_replace(' ', '', $f['email'])), '.,;');
    $f['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

    foreach (['place_of_birth', 'address'] as $key) {
        $f[$key] = preg_replace('/\s*;\s*/', ', ', $f[$key]);
        $f[$key] = mb_substr(aiTitleCase($f[$key]), 0, 255);
    }

    return $f;
}

function attachAiStatus(array $result)
{
    $result['extracted_by'] = 'ai';
    $error = aiExtractionError();
    if ($error !== null) {
        $result['warnings'] = array_values(array_unique(array_merge($result['warnings'] ?? [], [$error])));
    }
    return $result;
}