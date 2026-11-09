<?php
require_once __DIR__ . '/course_catalog.php';

const GROQ_API_URL       = 'https://api.groq.com/openai/v1/chat/completions';
const GROQ_DEFAULT_MODEL = 'qwen/qwen3.8-27b';
const AI_MAX_PARALLEL    = 5;
const AI_PAGE_IMAGE_SIZE = 1280;
const AI_ZOOM_WIDTH      = 1440;
const AI_ZOOM_HEIGHT     = 1152;
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
    $perRequest = max($last, (int) (getenv('GROQ_TOKENS_PER_PAGE') ?: 3400));
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
    $needed = (int) (getenv('GROQ_TOKENS_PER_PAGE') ?: 3400);
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

function aiSkipReason($reason = null)
{
    static $current = null;
    if ($reason !== null) {
        $current = $current ?? $reason;
    }
    return $current;
}

function aiDeadline($at = null)
{
    static $deadline = null;
    if ($at !== null) {
        $deadline = $at;
    }
    return $deadline;
}

function aiTimeLeft()
{
    $deadline = aiDeadline();
    return $deadline === null ? INF : $deadline - microtime(true);
}

function aiInternetReachable()
{
    $file = groqCooldownFile() . '.net.txt';
    $cached = @file_get_contents($file);
    if ($cached !== false && preg_match('/^(\d+) ([01])$/', trim($cached), $m)) {
        $age = time() - (int) $m[1];
        if ($age >= 0 && $age < ($m[2] === '1' ? 30 : 10)) {
            return $m[2] === '1';
        }
    }

    $limitMs = (int) round(max(1, (float) (getenv('AI_NET_CHECK_SECONDS') ?: 5)) * 1000);
    $mh = curl_multi_init();
    $handles = [];
    foreach (['https://api.groq.com/', 'https://generativelanguage.googleapis.com/', 'https://api.cloudflare.com/'] as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CONNECT_ONLY      => true,
            CURLOPT_CONNECTTIMEOUT_MS => $limitMs,
            CURLOPT_TIMEOUT_MS        => $limitMs,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $reachable = false;
    do {
        $status = curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            if ($info['result'] === CURLE_OK) {
                $reachable = true;
            }
        }
        if ($running && !$reachable) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running && !$reachable && $status === CURLM_OK);
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    if (!$reachable) {
        error_log('No internet or the connection is too slow (no AI service answered within ' . ($limitMs / 1000) . 's). Upload continues without AI.');
    }
    @file_put_contents($file, time() . ' ' . ($reachable ? '1' : '0'), LOCK_EX);
    return $reachable;
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
    $timeout = max(4, (int) (getenv('GEMINI_TIMEOUT') ?: 12));
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
    $curlCodes = [];
    do {
        $status = curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $curlCodes[is_object($info['handle']) ? spl_object_id($info['handle']) : (int) $info['handle']] = $info['result'];
        }
        if ($running) {
            aiRunIdleTasks($handles);
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    while ($info = curl_multi_info_read($mh)) {
        $curlCodes[is_object($info['handle']) ? spl_object_id($info['handle']) : (int) $info['handle']] = $info['result'];
    }

    $results = [];
    $failures = [];
    foreach ($handles as $key => $ch) {
        $response = (string) curl_multi_getcontent($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $curlCode = $curlCodes[is_object($ch) ? spl_object_id($ch) : (int) $ch] ?? 0;
        if ($curlErr === '' && $curlCode !== 0) {
            $curlErr = 'curl error ' . $curlCode . ': ' . curl_strerror($curlCode);
        }
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
                if ($code === 400 && preg_match('/reasoning|thinking/i', (string) $response)) {
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
            } elseif ($httpCode === 0) {
                $pause = max($pause, 15);
            } elseif (in_array($httpCode, [400, 401, 403, 404], true)) {
                $pause = max($pause, 600);
            } else {
                $pause = max($pause, 60);
            }
        }
        if ($pause > 0) {
            groqCooldowns(['gemini:' . $model => time() + $pause]);
        }
        if ($failures && !array_filter($failures, fn($f) => $f[0] !== 0)) {
            break;
        }
    }
    if ($results) {
        error_log('Groq is rate-limited, read ' . count($results) . ' request(s) with ' . aiUsedSource() . '.');
    }
    return $results;
}

function cloudflareModel()
{
    return getenv('CLOUDFLARE_MODEL') ?: '@cf/qwen/qwen3.8-27b';
}

function cloudflareAvailable()
{
    if ((string) getenv('CLOUDFLARE_ACCOUNT_ID') === '' || (string) getenv('CLOUDFLARE_API_TOKEN') === '') {
        return false;
    }
    return !isset(groqCooldowns()['cloudflare']);
}

function cloudflareBodyFromGroq($groqBody, $noThinkingFlag)
{
    $payload = json_decode($groqBody, true) ?: [];
    $body = [
        'model'                 => cloudflareModel(),
        'messages'              => $payload['messages'] ?? [],
        'temperature'           => 0,
        'max_completion_tokens' => max(2048, ((int) ($payload['max_completion_tokens'] ?? 1024)) * 3),
        'reasoning_effort'      => 'low',
        'options'               => ['rejectIfBusy' => true],
    ];
    if (isset($payload['response_format'])) {
        $body['response_format'] = $payload['response_format'];
    }
    if ($noThinkingFlag) {
        $body['chat_template_kwargs'] = ['enable_thinking' => false];
    }
    return json_encode($body);
}

function cloudflareSend(array $pending, $noThinkingFlag)
{
    $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode((string) getenv('CLOUDFLARE_ACCOUNT_ID')) . '/ai/v1/chat/completions';
    $timeout = max(4, (int) (getenv('CLOUDFLARE_TIMEOUT') ?: 10));
    $mh = curl_multi_init();
    $handles = [];
    foreach ($pending as $key => $body) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . getenv('CLOUDFLARE_API_TOKEN'),
            ],
            CURLOPT_POSTFIELDS     => cloudflareBodyFromGroq($body, $noThinkingFlag),
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $curlCodes = [];
    do {
        $status = curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $curlCodes[is_object($info['handle']) ? spl_object_id($info['handle']) : (int) $info['handle']] = $info['result'];
        }
        if ($running) {
            aiRunIdleTasks($handles);
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    while ($info = curl_multi_info_read($mh)) {
        $curlCodes[is_object($info['handle']) ? spl_object_id($info['handle']) : (int) $info['handle']] = $info['result'];
    }

    $results = [];
    $failures = [];
    foreach ($handles as $key => $ch) {
        $response = (string) curl_multi_getcontent($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $curlCode = $curlCodes[is_object($ch) ? spl_object_id($ch) : (int) $ch] ?? 0;
        if ($curlErr === '' && $curlCode !== 0) {
            $curlErr = 'curl error ' . $curlCode . ': ' . curl_strerror($curlCode);
        }
        $took     = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $content = $data['choices'][0]['message']['content'] ?? null;
            error_log(sprintf('Cloudflare speed: %.2fs, %d input tokens, %d output tokens, reasoning %s.',
                $took,
                (int) ($data['usage']['prompt_tokens'] ?? 0),
                (int) ($data['usage']['completion_tokens'] ?? 0),
                trim((string) ($data['choices'][0]['message']['reasoning_content'] ?? $data['choices'][0]['message']['reasoning'] ?? '')) !== '' ? 'ON' : 'off'));
            if (is_string($content) && trim($content) !== '') {
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

function cloudflareChatMany(array $groqBodies)
{
    if (!cloudflareAvailable() || !$groqBodies) {
        return [];
    }
    $noThinkingFlag = !isset(groqCooldowns()['cloudflare-no-kwargs']);
    [$results, $failures] = cloudflareSend($groqBodies, $noThinkingFlag);

    if ($noThinkingFlag) {
        $retry = [];
        foreach ($failures as $key => [$code, $response]) {
            if ($code === 400 && preg_match('/chat_template_kwargs|enable_thinking/i', (string) $response)) {
                $retry[$key] = $groqBodies[$key];
            }
        }
        if ($retry) {
            groqCooldowns(['cloudflare-no-kwargs' => time() + 86400]);
            [$doneRetry, $failRetry] = cloudflareSend($retry, false);
            $results += $doneRetry;
            $failures = array_diff_key($failures, $retry) + $failRetry;
        }
    }

    $pause = 0;
    foreach ($failures as [$httpCode, $response, $curlErr]) {
        $error = 'Cloudflare fallback failed (HTTP ' . $httpCode . ', model ' . cloudflareModel() . ')' . ($curlErr !== '' ? ' ' . $curlErr : '') . ': ' . substr($response, 0, 300);
        error_log($error);
        @file_put_contents(groqCooldownFile() . '.cloudflare.txt', date('Y-m-d H:i:s') . ' ' . $error);
        if ($httpCode === 429) {
            $pause = max($pause, 300);
        } elseif ($httpCode === 0) {
            $pause = max($pause, 15);
        } elseif (in_array($httpCode, [400, 401, 403, 404], true)) {
            $pause = max($pause, 600);
        } else {
            $pause = max($pause, 60);
        }
    }
    if ($pause > 0) {
        groqCooldowns(['cloudflare' => time() + $pause]);
    }
    if ($results) {
        aiUsedSource('Cloudflare (' . cloudflareModel() . ')');
        error_log('Groq is rate-limited, read ' . count($results) . ' request(s) with Cloudflare (' . cloudflareModel() . ').');
    }
    return $results;
}

function aiFallbackAvailable()
{
    return cloudflareAvailable() || geminiAvailable();
}

function aiFallbackFailurePause($provider, $httpCode)
{
    if ($httpCode === 429) {
        return $provider === 'cloudflare' ? 300 : 30;
    }
    if ($httpCode === 0) {
        return 15;
    }
    return in_array($httpCode, [400, 401, 403, 404], true) ? 600 : 60;
}

function aiHedgedFallback(array $groqBodies, &$sawTimeout)
{
    $sawTimeout = false;
    $model = geminiReadyModels()[0] ?? null;
    if ($model === null || !$groqBodies) {
        return [];
    }
    $effort = geminiEffort($model);
    $hedgeAfter = max(1.0, (float) (getenv('AI_HEDGE_SECONDS') ?: 4));
    $geminiUrl = rtrim(getenv('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta/openai', '/') . '/chat/completions';
    $cloudflareUrl = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode((string) getenv('CLOUDFLARE_ACCOUNT_ID')) . '/ai/v1/chat/completions';
    $noThinkingFlag = !isset(groqCooldowns()['cloudflare-no-kwargs']);

    $mh = curl_multi_init();
    $jobs = [];
    $addJob = function ($key, $provider) use ($mh, &$jobs, $groqBodies, $model, $effort, $geminiUrl, $cloudflareUrl, $noThinkingFlag) {
        $isGemini = $provider === 'gemini';
        $ch = curl_init($isGemini ? $geminiUrl : $cloudflareUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($isGemini ? getenv('GEMINI_API_KEY') : getenv('CLOUDFLARE_API_TOKEN')),
            ],
            CURLOPT_POSTFIELDS     => $isGemini
                ? geminiBodyFromGroq($groqBodies[$key], $model, $effort)
                : cloudflareBodyFromGroq($groqBodies[$key], $noThinkingFlag),
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $isGemini
                ? max(4, (int) (getenv('GEMINI_TIMEOUT') ?: 12))
                : max(4, (int) (getenv('CLOUDFLARE_TIMEOUT') ?: 10)),
        ]);
        curl_multi_add_handle($mh, $ch);
        $jobs[spl_object_id($ch)] = ['key' => $key, 'provider' => $provider, 'ch' => $ch];
    };

    foreach (array_keys($groqBodies) as $key) {
        $addJob($key, 'gemini');
    }
    $started = microtime(true);
    $cloudflareSent = [];
    $results = [];
    $sources = [];
    $pause = ['gemini' => 0, 'cloudflare' => 0];

    $finish = function ($ch) use ($mh, &$jobs) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($jobs[spl_object_id($ch)]);
    };

    do {
        $status = curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $job = $jobs[spl_object_id($ch)] ?? null;
            if ($job === null) {
                continue;
            }
            $key = $job['key'];
            $provider = $job['provider'];
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $response = (string) curl_multi_getcontent($ch);
            $curlErr = curl_error($ch);
            if ($curlErr === '' && $info['result'] !== 0) {
                $curlErr = 'curl error ' . $info['result'] . ': ' . curl_strerror($info['result']);
            }
            $took = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $finish($ch);

            $content = null;
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $content = $data['choices'][0]['message']['content'] ?? null;
                if (is_string($content) && trim($content) !== '') {
                    $content = trim(preg_replace('/<think>.*?<\/think>/s', '', $content));
                    $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content));
                } else {
                    $content = null;
                }
            }
            if ($content !== null) {
                if (!isset($results[$key])) {
                    $results[$key] = $content;
                    $sources[$provider] = true;
                    error_log(sprintf('AI fallback: %s answered in %.2fs.', $provider === 'gemini' ? 'Gemini (' . $model . ')' : 'Cloudflare', $took));
                    foreach ($jobs as $other) {
                        if ($other['key'] === $key) {
                            $finish($other['ch']);
                        }
                    }
                }
                continue;
            }

            $label = $provider === 'gemini' ? 'Gemini fallback failed (HTTP ' . $httpCode . ', model ' . $model . ')' : 'Cloudflare fallback failed (HTTP ' . $httpCode . ', model ' . cloudflareModel() . ')';
            $error = $label . ($curlErr !== '' ? ' ' . $curlErr : '') . ': ' . substr($response, 0, 300);
            error_log($error);
            @file_put_contents(groqCooldownFile() . '.' . $provider . '.txt', date('Y-m-d H:i:s') . ' ' . $error);
            if ($httpCode === 0) {
                $sawTimeout = true;
            }
            if ($provider === 'gemini' && $httpCode === 400 && preg_match('/reasoning|thinking/i', $response)) {
                groqCooldowns(['gemini-low-only:' . $model => time() + 86400]);
            } elseif ($provider === 'cloudflare' && $httpCode === 400 && preg_match('/chat_template_kwargs|enable_thinking/i', $response)) {
                groqCooldowns(['cloudflare-no-kwargs' => time() + 86400]);
            } else {
                $pause[$provider] = max($pause[$provider], aiFallbackFailurePause($provider, $httpCode));
            }
            if ($provider === 'gemini' && !isset($results[$key]) && !isset($cloudflareSent[$key]) && cloudflareAvailable()) {
                $cloudflareSent[$key] = true;
                $addJob($key, 'cloudflare');
            }
        }

        if (microtime(true) - $started >= $hedgeAfter && cloudflareAvailable()) {
            foreach (array_keys($groqBodies) as $key) {
                if (!isset($results[$key]) && !isset($cloudflareSent[$key])) {
                    $cloudflareSent[$key] = true;
                    $addJob($key, 'cloudflare');
                    error_log('AI fallback: Gemini is slow, also asking Cloudflare.');
                }
            }
        }

        if (!$jobs) {
            break;
        }
        aiRunIdleTasks(array_column($jobs, 'ch'));
        curl_multi_select($mh, 0.25);
    } while ($status === CURLM_OK);

    foreach ($jobs as $job) {
        $finish($job['ch']);
    }
    curl_multi_close($mh);

    if ($pause['gemini'] > 0) {
        groqCooldowns(['gemini:' . $model => time() + $pause['gemini']]);
    }
    if ($pause['cloudflare'] > 0) {
        groqCooldowns(['cloudflare' => time() + $pause['cloudflare']]);
    }
    if ($results) {
        $used = [];
        if (!empty($sources['gemini'])) {
            $used[] = 'Gemini (' . $model . ')';
        }
        if (!empty($sources['cloudflare'])) {
            $used[] = 'Cloudflare (' . cloudflareModel() . ')';
        }
        aiUsedSource(implode(' + ', $used));
        error_log('Groq is rate-limited, read ' . count($results) . ' request(s) with ' . implode(' + ', $used) . '.');
    }
    return $results;
}

function aiFallbackChatMany(array $groqBodies)
{
    if (geminiAvailable() && cloudflareAvailable()) {
        $sawTimeout = false;
        $results = aiHedgedFallback($groqBodies, $sawTimeout);
        $pending = array_diff_key($groqBodies, $results);
        if ($pending && !$sawTimeout && geminiAvailable()) {
            $results += geminiChatMany($pending);
        }
        return $results;
    }
    $results = geminiAvailable() ? geminiChatMany($groqBodies) : [];
    $pending = array_diff_key($groqBodies, $results);
    if ($pending) {
        $results += cloudflareChatMany($pending);
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

function aiAddIdleTask(callable $task)
{
    $GLOBALS['aiIdleTasks'][] = $task;
}

function aiRunIdleTasks(array $handles)
{
    if (empty($GLOBALS['aiIdleTasks'])) {
        return;
    }
    foreach ($handles as $ch) {
        $total = (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_UPLOAD_T);
        $sent = (float) curl_getinfo($ch, CURLINFO_SIZE_UPLOAD_T);
        if ($total <= 0 || $sent < $total) {
            return;
        }
    }
    $task = array_shift($GLOBALS['aiIdleTasks']);
    try {
        $task();
    } catch (Throwable $e) {
        error_log('AI idle task failed: ' . $e->getMessage());
    }
}

function groqChatMany(array $bodies)
{
    $results = array_fill_keys(array_keys($bodies), null);
    if (!$bodies || aiSkipReason() !== null) {
        return $results;
    }
    if (aiTimeLeft() <= 0) {
        aiSkipReason('slow');
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
    if (aiFallbackAvailable() && (groqAllCooling() || groqWouldExceed(count($queue)))) {
        foreach (aiFallbackChatMany($queue) as $key => $reply) {
            $results[$key] = $reply;
            unset($queue[$key]);
        }
    }
    for ($attempt = 1; $attempt <= 4 && $queue; $attempt++) {
        if (aiTimeLeft() <= 0) {
            aiSkipReason('slow');
            break;
        }
        $retry = [];
        $retryAfter = 1;
        $hardFail = false;
        $mustWait = false;
        $noConnection = 0;
        $sentCount = 0;
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
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => max(5, (int) (getenv('GROQ_TIMEOUT') ?: 12)),
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    aiRunIdleTasks($handles);
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $key => $ch) {
                $response = curl_multi_getcontent($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr  = curl_error($ch);
                $seconds  = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
                $sentCount++;
                if ($httpCode === 0 && (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) <= 0) {
                    $noConnection++;
                }
                if ($seconds >= 6) {
                    error_log(sprintf('Groq was slow: %.2fs (HTTP %d).', $seconds, $httpCode));
                }
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
                    if ($httpCode !== 429) {
                        $hardFail = true;
                    }
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
        if ($queue && $sentCount > 0 && $noConnection === $sentCount) {
            error_log('Groq could not be reached at all (no connection). Upload continues without AI.');
            @file_put_contents(groqCooldownFile() . '.net.txt', time() . ' 0', LOCK_EX);
            aiSkipReason('offline');
            break;
        }
        if ($queue && $mustWait && !aiFallbackAvailable()) {
            error_log('AI fallback skipped. Cloudflare: '
                . ((string) getenv('CLOUDFLARE_API_TOKEN') === '' ? 'CLOUDFLARE_API_TOKEN is not set in .env' : 'paused, last error ' . trim((string) @file_get_contents(groqCooldownFile() . '.cloudflare.txt')))
                . '. Gemini: '
                . ((string) getenv('GEMINI_API_KEY') === '' ? 'GEMINI_API_KEY is not set in .env' : implode(', ', geminiModels()) . ' paused, last error ' . trim((string) @file_get_contents(groqCooldownFile() . '.gemini.txt'))));
        }
        $groqBackSoon = !$hardFail && $retryAfter <= max(1, (int) (getenv('GROQ_SHORT_WAIT') ?: 4));
        if ($queue && $mustWait && $groqBackSoon) {
            error_log('Groq is busy for only ' . $retryAfter . 's, waiting for Groq instead of the fallback.');
        }
        if ($queue && $mustWait && !$groqBackSoon && aiFallbackAvailable()) {
            foreach (aiFallbackChatMany($queue) as $key => $reply) {
                $results[$key] = $reply;
                unset($queue[$key]);
            }
            if (!$queue) {
                break;
            }
        }
        if ($queue && $attempt < 4 && $mustWait) {
            $wait = (int) min($retryAfter, 20, AI_MAX_WAIT - $waited, floor(aiTimeLeft()));
            if ($wait <= 0) {
                break;
            }
            sleep($wait);
            $waited += $wait;
        }
    }

    if ($queue && aiSkipReason() === null && aiTimeLeft() <= 0) {
        aiSkipReason('slow');
    }
    if ($queue && aiSkipReason() === null) {
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
        . "the student is a freshman, so return \"1-\" plus the section, e.g. \"1-1\". \"\" if not written. A school year like 2026-2027 is NOT a year & section. "
        . "A handwritten section is often a single short vertical stroke; a lone stroke that looks like I, l or | is the digit 1, so \"Section: |\" gives \"1-1\". "
        . "Look closely at the small Section blank beside Program on the Route and Approval Slip before deciding it is empty.\n"
        . "- sex: \"Male\" or \"Female\".\n"
        . "- date_of_birth: YYYY-MM-DD.\n"
        . "- place_of_birth: city/municipality and province.\n"
        . "- address: the student's home/permanent address.\n"
        . "- mobile_number: 11 digits starting with 09.\n"
        . "- email: the student's email address, letter for letter (a dot may look like a small dash).\n"
        . "- Ignore form codes/revision stamps like \"PUP-XXXX-XX Rev.0 Jan 1, 2020\".";
    return $rules;
}

function aiPageCache($text = null, $fields = null, $first = false)
{
    static $pages = [];
    if ($text !== null) {
        if ($first) {
            array_unshift($pages, ['text' => $text, 'fields' => $fields]);
        } else {
            $pages[] = ['text' => $text, 'fields' => $fields];
        }
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
        . "{\"doc_type\": \"\", \"doc_confidence\": \"high\", \"grade_level\": \"\", \"turn\": \"none\", \"fields\": {\"student_number\": \"\", \"last_name\": \"\", \"first_name\": \"\", \"middle_name\": \"\", \"course\": \"\", \"year_section\": \"\", \"sex\": \"\", "
        . "\"date_of_birth\": \"\", \"place_of_birth\": \"\", \"address\": \"\", \"mobile_number\": \"\", \"email\": \"\"}, \"text\": \"...\"}\n"
        . ($types !== ''
            ? "\"doc_type\": the code of what this page IS (not documents it merely mentions), or \"none\" for a blank page, "
              . "a back side or continuation page, or a document matching no type. For report cards use the card's own grade level: "
              . "a card with four quarterly grade columns and learning areas such as Araling Panlipunan, EsP, TLE and MAPEH is grade_10_card (Junior High School); "
              . "a card with 1st/2nd semester and Core, Applied or Specialized subjects is grade_11_card or grade_12_card. "
              . "The Grade or Grade Level written on the card decides: Grade 10 is grade_10_card, Grade 11 is grade_11_card, Grade 12 is grade_12_card, "
              . "even when the form code says SF9-JHS or the page only shows attendance and signatures. "
              . "Pages may be scanned sideways or upside down; read the rotated text.\n"
              . "\"doc_confidence\": high, medium or low.\n\"grade_level\": for a report card, only the number written in its Grade or Grade Level field (for example 11), otherwise empty.\nDocument types (code: name. description):\n" . $types
            : '')
        . "\"turn\": how the image must be turned so its main text reads upright: \"none\" if it is already upright, "
        . "\"clockwise\" if it must be turned 90 degrees clockwise (the text now runs from bottom to top), "
        . "\"counterclockwise\" if it must be turned 90 degrees counterclockwise (the text now runs from top to bottom), or \"upside_down\".\n"
        . "\"fields\": the student's details found on this page.\n"
        . "\"text\": only the form code and the document title printed on the page (use \\n between them), at most 200 characters. No markdown.\n"
        . aiFieldRules();

    $intro = count($dataUrls) > 1
        ? 'Image 1 is the whole page. Image 2 is a zoomed-in view of the top of the same page; read handwritten values from image 2.'
        : 'This image is the page, or the top part of it. Read it carefully, including handwriting and sideways text.';
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
        $gradeLevel = preg_replace('/\D/', '', (string) ($json['grade_level'] ?? ''));
        $read['grade_level'] = $gradeLevel;
        $turn = strtolower(str_replace([' ', '-'], '_', trim((string) ($json['turn'] ?? ''))));
        $read['turn'] = in_array($turn, ['clockwise', 'counterclockwise', 'upside_down'], true) ? $turn : 'none';
        error_log('AI page read: doc_type=' . ($read['doc_type'] ?? '') . ', grade_level=' . ($json['grade_level'] ?? '') . ', text=' . str_replace("\n", ' | ', $text));
        if (in_array($gradeLevel, ['10', '11', '12'], true) && in_array((string) $read['doc_type'], ['grade_10_card', 'grade_11_card', 'grade_12_card', 'none', ''], true)) {
            $read['doc_type'] = 'grade_' . $gradeLevel . '_card';
        }
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

function aiPageFullRenderCommand($pdfPath, $page, $outDir)
{
    $base = rtrim($outDir, '/\\') . '/' . uniqid('aifull_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN) . ' -jpeg -jpegopt quality=80 -scale-to ' . AI_ZOOM_WIDTH
        . ' -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
    return [$cmd, $base . '.jpg'];
}

function aiSidewaysPages($pdfPath, array $pages, $workDir)
{
    if (!function_exists('readPgmFile')) {
        return [];
    }
    $commands = [];
    $files = [];
    foreach ($pages as $page) {
        $base = rtrim($workDir, '/\\') . '/' . uniqid('aiorient_', true);
        $commands[$page] = escapeshellarg(PDFTOPPM_BIN) . ' -gray -scale-to 800 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
            . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
        $files[$page] = $base . '.pgm';
    }
    aiRunCommandsParallel($commands);

    $sideways = [];
    foreach ($files as $page => $file) {
        $img = readPgmFile($file);
        @unlink($file);
        if ($img === null) {
            continue;
        }
        $w = $img['width'];
        $h = $img['height'];
        $x0 = (int) ($w * 0.05);
        $x1 = $w - $x0;
        $y0 = (int) ($h * 0.05);
        $y1 = $h - $y0;
        $rows = array_fill(0, $y1 - $y0, 0);
        $cols = array_fill(0, $x1 - $x0, 0);
        for ($y = $y0; $y < $y1; $y++) {
            $offset = $y * $w;
            for ($x = $x0; $x < $x1; $x++) {
                if (ord($img['data'][$offset + $x]) < 128) {
                    $rows[$y - $y0]++;
                    $cols[$x - $x0]++;
                }
            }
        }
        $total = array_sum($rows);
        if ($total < 500) {
            continue;
        }
        $change = function (array $values) use ($total) {
            $sum = 0;
            for ($i = 1, $n = count($values); $i < $n; $i++) {
                $sum += abs($values[$i] - $values[$i - 1]);
            }
            return $sum / $total;
        };
        if ($change($cols) > $change($rows) * 1.25) {
            $sideways[$page] = true;
        }
    }
    return $sideways;
}

function aiReadPdfPages($pdfPath, array $pages, $workDir)
{
    $renderStart = microtime(true);
    $sideways = aiSidewaysPages($pdfPath, $pages, $workDir);
    if ($sideways) {
        error_log('AI: page(s) ' . implode(', ', array_keys($sideways)) . ' scanned sideways, sending the whole page.');
    }
    $commands = [];
    $files = [];
    $wideFiles = [];
    foreach ($pages as $page) {
        [$cmd, $file] = isset($sideways[$page])
            ? aiPageFullRenderCommand($pdfPath, $page, $workDir)
            : aiPageRenderCommand($pdfPath, $page, $workDir, true);
        $commands[$page] = $cmd;
        $files[$page][] = $file;
        if (!isset($sideways[$page]) && count($pages) <= 3) {
            [$commands['wide' . $page], $wideFiles[$page]] = aiPageWideRenderCommand($pdfPath, $page, $workDir);
        }
    }
    aiRunCommandsParallel($commands);
    if (microtime(true) - $renderStart >= 5) {
        error_log(sprintf('AI page render was slow: %.2fs for page(s) %s.', microtime(true) - $renderStart, implode(', ', $pages)));
    }
    foreach ($wideFiles as $page => $file) {
        $files[$page][] = $file;
    }

    $images = [];
    $results = [];
    foreach ($pages as $page) {
        $ready = array_values(array_filter([$files[$page][0]], 'file_exists'));
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

    $cardCodes = ['grade_10_card', 'grade_11_card', 'grade_12_card'];
    $fullCommands = [];
    foreach ($results as $page => $read) {
        $gradeKnown = in_array($read['grade_level'] ?? '', ['10', '11', '12'], true);
        $looksLikeCard = in_array((string) $read['doc_type'], $cardCodes, true)
            || ((string) $read['doc_type'] === 'none' && preg_match('/\bSF\s*-?\s*9\b|F\s*-?\s*138|report\s*card|progress\s*report|attendance\s*record/i', (string) $read['text']));
        if (!$gradeKnown && $looksLikeCard && !isset($sideways[$page])) {
            [$fullCommands[$page], $fullFile] = aiPageFullRenderCommand($pdfPath, $page, $workDir);
            $files[$page][] = $fullFile;
        }
    }
    if ($fullCommands) {
        aiRunCommandsParallel($fullCommands);
        $fullImages = [];
        foreach (array_keys($fullCommands) as $page) {
            $file = end($files[$page]);
            if (file_exists($file)) {
                $fullImages[$page] = [$file];
            }
        }
        error_log('AI report card re-check (full page) for page(s): ' . implode(', ', array_keys($fullImages)));
        foreach (aiReadImages($fullImages) as $page => $read) {
            if ($read !== null && in_array((string) $read['doc_type'], $cardCodes, true)) {
                $results[$page]['doc_type'] = $read['doc_type'];
                $results[$page]['doc_confidence'] = $read['doc_confidence'];
            }
        }
    }
    $cardPages = [];
    $cardCommands = [];
    foreach ($results as $page => $read) {
        $isCard = in_array((string) $read['doc_type'], $cardCodes, true)
            || preg_match('/\bSF\s*-?\s*9\b|F\s*-?\s*138|report\s*card|progress\s*report|attendance\s*record/i', (string) $read['text']);
        $gradeKnown = in_array((string) ($read['grade_level'] ?? ''), ['10', '11', '12'], true)
            && (string) $read['doc_type'] === 'grade_' . $read['grade_level'] . '_card';
        if ($isCard && isset($sideways[$page]) && !$gradeKnown) {
            $base = rtrim($workDir, '/\\') . '/' . uniqid('aicard_', true);
            $cardCommands[$page] = escapeshellarg(PDFTOPPM_BIN) . ' -jpeg -jpegopt quality=85 -scale-to 2000'
                . ' -singlefile -f ' . (int) $page . ' -l ' . (int) $page
                . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
            $files[$page][] = $base . '.jpg';
            $cardPages[$page] = ['file' => $base . '.jpg', 'turn' => $read['turn'] ?? 'none'];
        }
    }
    if ($cardPages) {
        aiRunCommandsParallel($cardCommands);
        foreach (aiReadCardGrade($cardPages) as $page => $grade) {
            $code = 'grade_' . $grade . '_card';
            if ($results[$page]['doc_type'] !== $code) {
                error_log('AI report card grade re-check: page ' . $page . ' changed from ' . $results[$page]['doc_type'] . ' to ' . $code . '.');
            }
            $results[$page]['doc_type'] = $code;
            $results[$page]['grade_level'] = $grade;
        }
    }

    $slipPages = [];
    foreach ($results as $page => $read) {
        if ((string) $read['doc_type'] === 'route_approval_slip' && !isset($sideways[$page]) && trim((string) $read['text']) !== '') {
            if (isset($wideFiles[$page]) && is_file($wideFiles[$page])) {
                $slipPages[$page] = $wideFiles[$page];
                continue;
            }
            [$slipCommands[$page], $slipFile] = aiPageWideRenderCommand($pdfPath, $page, $workDir);
            $files[$page][] = $slipFile;
            $slipPages[$page] = $slipFile;
        }
    }
    if ($slipPages) {
        if (!empty($slipCommands)) {
            aiRunCommandsParallel($slipCommands);
        }
        aiReadRouteSlipProgram($slipPages, $results, $workDir, $files);
    }

    $healthPages = [];
    $healthCommands = [];
    foreach ($results as $page => $read) {
        if ((string) $read['doc_type'] === 'health_information_form' && !isset($sideways[$page]) && trim((string) $read['text']) !== '') {
            $base = rtrim($workDir, '/\\') . '/' . uniqid('aihealth_', true);
            $healthCommands[$page] = escapeshellarg(PDFTOPPM_BIN) . ' -jpeg -jpegopt quality=92 -scale-to-x 4000 -scale-to-y -1'
                . ' -singlefile -f ' . (int) $page . ' -l ' . (int) $page
                . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
            $files[$page][] = $base . '.jpg';
            $healthPages[$page] = $base . '.jpg';
        }
    }
    if ($healthPages) {
        aiRunCommandsParallel($healthCommands);
        aiReadHealthStudentNumber($healthPages, $results, $workDir, $files);
    }
    foreach ($files as $set) {
        foreach ($set as $file) {
            @unlink($file);
        }
    }
    return $results;
}

function aiReadCardGrade(array $cardPages)
{
    $bodies = [];
    $upright = [];
    foreach ($cardPages as $page => $card) {
        $file = $card['file'];
        if (!is_file($file)) {
            continue;
        }
        $angles = ['clockwise' => 270, 'counterclockwise' => 90, 'upside_down' => 180];
        $upright[$page] = false;
        if (isset($angles[$card['turn']]) && function_exists('imagerotate') && function_exists('imagecreatefromjpeg')) {
            $img = @imagecreatefromjpeg($file);
            if ($img) {
                $rotated = imagerotate($img, $angles[$card['turn']], imagecolorallocate($img, 255, 255, 255));
                imagedestroy($img);
                if ($rotated) {
                    imagejpeg($rotated, $file, 85);
                    imagedestroy($rotated);
                    $upright[$page] = true;
                    error_log('AI report card grade re-check: page ' . $page . ' turned ' . $card['turn'] . ' to read it upright.');
                }
            }
        }
        $url = imageToDataUrlForAi($file, 2000, 1800000);
        if ($url === null) {
            continue;
        }
        $bodies[$page] = groqRequestBody([
            ['role' => 'system', 'content' => "You read a scanned Philippine DepEd report card (SF9 / Form 138) and reply with ONLY a JSON object: {\"grade\": \"\"}.\n"
                . ($upright[$page] ? "The page has been turned so the text is upright. " : "The page is rotated sideways; turn it in your mind so the text is upright. ")
                . "On the cover side, find the learner details (LRN, Name, Age, Sex, Grade, Section, School Year, Track/Strand). "
                . "\"grade\": only the number written in the Grade field, usually handwritten, for example 10, 11 or 12. Read both digits carefully: 12 and 10 look alike. "
                . "A Track/Strand such as STEM, ABM, HUMSS or GAS means Senior High School, so the grade is 11 or 12, never 10. Use \"\" if it is not written."],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'What grade is written on this report card?'],
                ['type' => 'image_url', 'image_url' => ['url' => $url]],
            ]],
        ], true, 60);
    }
    $grades = [];
    foreach ($bodies ? groqChatMany($bodies) : [] as $page => $reply) {
        $json = aiDecodeJson($reply);
        $grade = preg_replace('/\D/', '', (string) ($json['grade'] ?? ''));
        error_log('AI report card grade re-check: page ' . $page . ' grade=' . $grade);
        if (in_array($grade, ['10', '11', '12'], true)) {
            $grades[$page] = $grade;
        }
    }
    return $grades;
}

function aiPageWideRenderCommand($pdfPath, $page, $outDir)
{
    $base = rtrim($outDir, '/\\') . '/' . uniqid('aiwide_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN) . ' -jpeg -jpegopt quality=85 -scale-to-x ' . AI_ZOOM_WIDTH . ' -scale-to-y -1'
        . ' -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
    return [$cmd, $base . '.jpg'];
}

function aiReadRouteSlipProgram(array $slipPages, array $results, $workDir, array &$files)
{
    if (!function_exists('imagecreatefromjpeg')) {
        return;
    }
    $bodies = [];
    foreach ($slipPages as $page => $file) {
        $img = is_file($file) ? @imagecreatefromjpeg($file) : false;
        if (!$img) {
            continue;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $top = (int) round($h * 0.45);
        $band = imagecrop($img, ['x' => 0, 'y' => $top, 'width' => $w, 'height' => (int) round($h * 0.30)]);
        imagedestroy($img);
        if (!$band) {
            continue;
        }
        $small = imagescale($band, 1100, -1, IMG_BILINEAR_FIXED);
        if ($small) {
            imagedestroy($band);
            $band = $small;
        }
        $bandFile = rtrim($workDir, '/\\') . '/' . uniqid('aiband_', true) . '.jpg';
        imagejpeg($band, $bandFile, 85);
        imagedestroy($band);
        $files[$page][] = $bandFile;
        $url = imageToDataUrlForAi($bandFile, 1100, 500000);
        if ($url === null) {
            continue;
        }
        $bodies[$page] = groqRequestBody([
            ['role' => 'system', 'content' => "You read one part of a PUP Route and Approval Slip and reply with ONLY a JSON object: {\"program\": \"\", \"section\": \"\"}.\n"
                . "Find Step 2 (College Interview and Tagging of Program). \"program\": the handwritten course written after Program:, exactly as written, usually an abbreviation such as BSIT, BSCPE, BSPSY, BSA. "
                . "\"section\": the handwritten value after Section:, a single digit. A lone short vertical stroke that looks like I, l or | is the digit 1. "
                . "Use \"\" only when the blank is truly empty."],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Read the Program and Section in Step 2.'],
                ['type' => 'image_url', 'image_url' => ['url' => $url]],
            ]],
        ], true, 60);
    }
    if (!$bodies) {
        return;
    }
    foreach (groqChatMany($bodies) as $page => $reply) {
        $json = aiDecodeJson($reply);
        if ($json === null) {
            continue;
        }
        $section = trim((string) ($json['section'] ?? ''));
        $fields = sanitizeAiStudentFields([
            'course'       => (string) ($json['program'] ?? ''),
            'year_section' => $section !== '' ? 'Section ' . $section : '',
        ]);
        error_log('AI route slip re-check: program=' . ($json['program'] ?? '') . ', section=' . $section);
        $text = trim((string) ($results[$page]['text'] ?? ''));
        if ($text !== '' && ($fields['course'] !== '' || $fields['year_section'] !== '')) {
            aiPageCache($text, $fields, true);
        }
    }
}

function aiReadHealthStudentNumber(array $healthPages, array $results, $workDir, array &$files)
{
    if (!function_exists('imagecreatefromjpeg')) {
        return;
    }
    $bodies = [];
    foreach ($healthPages as $page => $file) {
        $img = is_file($file) ? @imagecreatefromjpeg($file) : false;
        if (!$img) {
            continue;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $x = (int) round($w * 0.60);
        $y = (int) round($w * 0.21);
        $crop = imagecrop($img, ['x' => $x, 'y' => $y, 'width' => $w - $x, 'height' => min((int) round($w * 0.12), $h - $y)]);
        imagedestroy($img);
        if (!$crop) {
            continue;
        }
        $cropFile = rtrim($workDir, '/\\') . '/' . uniqid('aisn_', true) . '.jpg';
        imagejpeg($crop, $cropFile, 92);
        imagedestroy($crop);
        $files[$page][] = $cropFile;
        $url = imageToDataUrlForAi($cropFile, 1600, 1200000);
        if ($url === null) {
            continue;
        }
        $bodies[$page] = groqRequestBody([
            ['role' => 'system', 'content' => "You read one part of a PUP Health Information Form and reply with ONLY a JSON object: {\"characters\": [], \"student_number\": \"\"}.\n"
                . "\"characters\": every character of the handwritten PUP Student No., one per item, left to right, including the dashes. Decide each one on its own before writing the next.\n"
                . "\"student_number\": the handwritten value after \"PUP Student No.:\", exactly as written. "
                . "The format is YYYY-NNNNN-XX-N, for example 2022-00213-BN-0: a 4-digit year that starts with 20, five digits, two capital letters (the campus code, usually BN) and one digit. "
                . "Read every digit one by one and look closely at each one; 1, 2 and 7 are easy to confuse in handwriting. "
                . "The same person writes a digit the same way every time, so compare each digit with the same digit elsewhere in the number "
                . "(for example a 2 in the year and a 2 later in the number) before deciding. A handwritten 2 has a curved or hooked top and a flat bottom stroke, even when it is small, faint or written quickly; "
                . "a 1 is a single straight stroke with no hook and no flat base. If a digit has any bottom stroke going sideways, it is a 2, not a 1. "
                . "Do not read the School Year or any other line. Use \"\" if the blank is empty or unreadable."],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Read the PUP Student No. character by character.'],
                ['type' => 'image_url', 'image_url' => ['url' => $url]],
            ]],
        ], true, 200);
    }
    if (!$bodies) {
        return;
    }
    $maxYear = (int) date('Y') + 1;
    foreach (groqChatMany($bodies) as $page => $reply) {
        $json = aiDecodeJson($reply);
        if ($json === null) {
            continue;
        }
        $chars = is_array($json['characters'] ?? null) ? implode('', array_map('strval', $json['characters'])) : '';
        $raw = (string) ($json['student_number'] ?? '');
        $fields = sanitizeAiStudentFields(['student_number' => $raw]);
        $fromChars = sanitizeAiStudentFields(['student_number' => $chars]);
        if ($fromChars['student_number'] !== '') {
            $fields = $fromChars;
        }
        $number = $fields['student_number'];
        $year = (int) substr($number, 0, 4);
        error_log('AI health form student number re-check: page ' . $page . ' characters=' . $chars . ' student_number=' . $raw . ' -> ' . $number);
        if ($number === '' || $year < 2000 || $year > $maxYear) {
            continue;
        }
        $text = trim((string) ($results[$page]['text'] ?? ''));
        if ($text !== '') {
            aiPageCache($text, ['student_number' => $number] + array_fill_keys(AI_STUDENT_FIELDS, ''), true);
        }
    }
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
        ? $m[1] . '-' . str_pad(str_replace('O', '0', $m[2]), 5, '0', STR_PAD_LEFT) . '-' . $m[3] . '-' . str_replace('O', '0', $m[4])
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

    $f['year_section'] = preg_replace('/(?<![A-Za-z])[Il|i](?![A-Za-z])/', '1', $f['year_section']);
    $f['year_section'] = preg_replace('/(?<![A-Za-z])[Oo](?![A-Za-z])/', '0', $f['year_section']);
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
    if ($error !== null && aiSkipReason() === null) {
        $result['warnings'] = array_values(array_unique(array_merge($result['warnings'] ?? [], [$error])));
    }
    return $result;
}