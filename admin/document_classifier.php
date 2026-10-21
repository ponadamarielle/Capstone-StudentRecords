<?php
require_once __DIR__ . '/ai_extraction.php';

if (!defined('DOC_CLASSIFY_MAX_PAGES')) {
    define('DOC_CLASSIFY_MAX_PAGES', 20);   
}

function getDocumentRequirementGroups()
{
    return [
        'admission_form' => 'Admission Forms',
        'credential'     => 'Entrance Credentials (2.1)',
        'past_graduate'  => 'Previous SHS / HS Graduates of Past School Years (2.2)',
        'als'            => 'Graduates of Alternative Learning System (2.3)',
        'other'          => 'Other',
    ];
}

function getAdmissionTracks()
{
    return [
        'shs_current' => 'Senior High School Graduate (current school year)',
        'shs_past'    => 'Previous SHS / HS Graduate (past school years)',
        'als'         => 'Alternative Learning System (ALS) Graduate',
    ];
}

function normalizeAdmissionTrack($track)
{
    return array_key_exists($track, getAdmissionTracks()) ? $track : 'shs_current';
}

function isRequirementRequired($code, $track)
{
    $req = getDocumentRequirement($code);
    return $req !== null && in_array($track, $req['required_for'] ?? [], true);
}

function missingRequirementsForTrack($track, array $attachedCodes)
{
    $missing = [];
    foreach (getDocumentRequirements() as $code => $req) {
        if (!empty($req['store']) && in_array($track, $req['required_for'] ?? [], true)
            && !in_array($code, $attachedCodes, true)) {
            $missing[] = $code;
        }
    }
    return $missing;
}

function getDocumentRequirements()
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }


    $catalog = [
        'sar_form' => [
            'label' => 'SAR Form 1 (Confirmation Slip)',
            'group' => 'admission_form', 'store' => true, 'required_for' => ['shs_current', 'shs_past', 'als'],
            'ref' => '', 'note' => 'Printed from the admission packet.',
            'extract_fields' => true, 'field_priority' => 3, 'photo_box' => 'sar_form',
            'ai_hint' => 'PUP "SAR-FORM 1 with Confirmation Documents" / Confirmation Slip addressed to the University Admission Committee: "I am ___ graduated in ___ from ___ would like to confirm my interest to be admitted", with a 2x2 photo box. Usually two copies on one page.',
        ],
        'route_approval_slip' => [
            'label' => 'Route and Approval Slip',
            'group' => 'admission_form', 'store' => true, 'required_for' => ['shs_current', 'shs_past', 'als'],
            'ref' => '', 'note' => 'Printed from the admission packet; signed at each enrollment step.',
            'extract_fields' => true, 'field_priority' => 2,
            'ai_hint' => 'PUP "Route and Approval Slip": Name of Applicant, Reference Number, SHS Track/Strand, Admission Status checkboxes (PUPCET Qualifier, ALS, PWD...), Steps 1-5 (submission of credentials, college interview and tagging of program, medical examination, registration certificate, ID card) with signature lines.',
        ],
        'certification_undertaking' => [
            'label' => 'Waiver/Certification/Undertaking (Form 2)',
            'group' => 'admission_form', 'store' => true, 'required_for' => ['shs_current', 'shs_past', 'als'],
            'ref' => '2.1.5', 'note' => 'Duly signed Waiver/Certification/Undertaking.',
            'extract_fields' => true, 'field_priority' => 6,
            'ai_hint' => 'PUP "Certification/Undertaking" (Form 2): "I, ___, of legal age, Filipino ... hereby certify that" the enrollee will submit the listed documents, vouches their authenticity, signed by the enrollee and attested by parent/guardian.',
        ],

        'grade_12_card' => [
            'label' => 'Grade 12 Report Card (F138)',
            'group' => 'credential', 'store' => true, 'required_for' => ['shs_current', 'shs_past'],
            'ref' => '2.1.1', 'note' => 'Original High School Card (F138 / Grade 12 card).',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'SHS Grade 12 card: 1st/2nd semester, Core/Applied/Specialized subjects. Senior High School Grade 12 report card (Form 138 / SF9 / Learner\'s Progress Report Card) showing Grade 12 subjects, semester grades, general average.',
        ],
        'grade_11_card' => [
            'label' => 'Grade 11 Report Card',
            'group' => 'credential', 'store' => true, 'required_for' => ['shs_current', 'shs_past'],
            'ref' => '2.1.2', 'note' => 'Original or certified true copy.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'SHS Grade 11 card: 1st/2nd semester, Core/Applied/Specialized subjects. Senior High School Grade 11 report card (SF9 / Learner\'s Progress Report Card) showing Grade 11 subjects and grades.',
        ],
        'grade_10_card' => [
            'label' => 'Grade 10 Report Card',
            'group' => 'credential', 'store' => true, 'required_for' => ['shs_current', 'shs_past'],
            'ref' => '2.1.2', 'note' => 'Original or certified true copy.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'JHS Grade 10 card: quarters 1-4, Filipino, AP, EsP, TLE, MAPEH. Junior High School Grade 10 report card (Form 138 / SF9) showing Grade 10 learning areas and quarterly grades.',
        ],
        'psa_birth_certificate' => [
            'label' => 'PSA Birth Certificate',
            'group' => 'credential', 'store' => true, 'required_for' => ['shs_current', 'shs_past', 'als'],
            'ref' => '2.1.3 / 2.3.5', 'note' => 'Original PSA Birth Certificate.',
            'extract_fields' => true, 'field_priority' => 4,
            'ai_hint' => 'PSA (Philippine Statistics Authority) / NSO copy of a Certificate of Live Birth (Municipal Form No. 102): child\'s name, sex, date and place of birth, parents, registry number.',
        ],
        'good_moral_certificate' => [
            'label' => 'Certificate of Good Moral Character',
            'group' => 'credential', 'store' => true, 'required_for' => ['shs_current', 'shs_past', 'als'],
            'ref' => '2.1.4 / 2.3.4', 'note' => 'Issued by the Principal / Guidance Counselor (or school last attended for ALS), with school dry seal.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'Certificate/Certification of Good Moral Character issued by a school principal or guidance counselor stating the student is of good moral character.',
        ],
        'chest_xray' => [
            'label' => 'Chest X-ray Result',
            'group' => 'credential', 'store' => true, 'required_for' => ['shs_current', 'shs_past', 'als'],
            'ref' => '2.1.6', 'note' => 'Result must not be older than 6 months from the scheduled enrollment.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'Chest X-ray / radiology result: examination (chest PA), findings about lungs, heart, diaphragm, impression, radiologist\'s name.',
        ],
        'pwd_id' => [
            'label' => 'PWD ID / Certification (if applicable)',
            'group' => 'credential', 'store' => true, 'required_for' => [],
            'ref' => 'PWD', 'note' => 'For persons with disability only: PWD ID or certification issued by the LGU.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'Person with Disability (PWD) ID card or PWD certification issued by the LGU / PDAO / NCDA, showing type of disability.',
        ],
        'sf10_form137' => [
            'label' => 'SF10 / Form 137-A (copy for PUP)',
            'group' => 'credential', 'store' => true, 'required_for' => [],
            'ref' => 'Form 2 item 3', 'note' => 'Copy for PUP; due before the start of the Second Semester.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'SF10 / Form 137-A Learner\'s Permanent Academic Record (full scholastic record across grade levels).',
        ],

        'f137_not_sent_certification' => [
            'label' => 'Certification: F137-A Not Sent to Other School',
            'group' => 'past_graduate', 'store' => true, 'required_for' => ['shs_past'],
            'ref' => '2.2.1', 'note' => 'From the SHS registrar with school dry seal, noted by the Principal.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'Certification from the senior high school registrar that no copy of the student\'s Form 137-A has been sent to any other college or university.',
        ],
        'non_enrollment_certification' => [
            'label' => 'Certification of Non-Enrollment',
            'group' => 'past_graduate', 'store' => true, 'required_for' => ['shs_past'],
            'ref' => '2.2.2', 'note' => 'Notarized certification by the SHS Principal.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'Notarized certification from the SHS principal that the graduate has NOT enrolled in any technical, diploma or degree program after graduation.',
        ],
        'notarized_waiver' => [
            'label' => 'Notarized Waiver (Concealment of Enrollment)',
            'group' => 'past_graduate', 'store' => true, 'required_for' => ['shs_past'],
            'ref' => '2.2.3', 'note' => 'Notarized; enrollment is void if previous enrollment was concealed.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'Notarized waiver/affidavit by the student that if previous enrollment is concealed, the PUP enrollment becomes null and void.',
        ],

        'als_certificate_completion' => [
            'label' => 'ALS Certificate of Completion',
            'group' => 'als', 'store' => true, 'required_for' => ['als'],
            'ref' => '2.3.1', 'note' => '',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'Alternative Learning System (ALS) Certificate of Completion.',
        ],
        'als_certificate_rating' => [
            'label' => 'ALS Certificate of Rating',
            'group' => 'als', 'store' => true, 'required_for' => ['als'],
            'ref' => '2.3.2', 'note' => '',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'ALS Accreditation and Equivalency (A&E) Test Certificate of Rating.',
        ],
        'als_passers_list' => [
            'label' => 'ALS National List of Passers',
            'group' => 'als', 'store' => true, 'required_for' => ['als'],
            'ref' => '2.3.3', 'note' => 'Verified/certified true copy showing the name of the student.',
            'extract_fields' => false, 'field_priority' => 9,
            'ai_hint' => 'DepEd National List of Passers of the ALS A&E test (a list of names).',
        ],

        'info_enrollment_procedure' => [
            'label' => 'Admission & Enrollment Procedure (info page)',
            'group' => 'info', 'store' => false, 'required_for' => [],
            'extract_fields' => false, 'field_priority' => 99,
            'ai_hint' => 'Instruction page "New Student Admission and Enrollment Procedure" listing requirements (not a student document).',
        ],
        'info_welcome' => [
            'label' => 'Welcome to the PUP Community (info page)',
            'group' => 'info', 'store' => false, 'required_for' => [],
            'extract_fields' => false, 'field_priority' => 99,
            'ai_hint' => 'Informational page "Welcome to the PUP Community" about PUPSIS and the first day of classes.',
        ],
        'info_admission_criteria' => [
            'label' => 'Admission Criteria QR (info page)',
            'group' => 'info', 'store' => false, 'required_for' => [],
            'extract_fields' => false, 'field_priority' => 99,
            'ai_hint' => 'Page "PUP Academic Program Admission Criteria" with a QR code / bit.ly link.',
        ],
    ];

    return $catalog;
}

function getDocumentRequirement($code)
{
    $catalog = getDocumentRequirements();
    return ($code !== null && isset($catalog[$code])) ? $catalog[$code] : null;
}

function isStorableRequirement($code)
{
    $req = getDocumentRequirement($code);
    return $req !== null && !empty($req['store']);
}

function requirementLabel($code)
{
    $req = getDocumentRequirement($code);
    return $req ? $req['label'] : 'Other Document';
}

function getDocumentRequirementsForClient()
{
    $out = [];
    foreach (getDocumentRequirements() as $code => $req) {
        if (empty($req['store'])) {
            continue;
        }
        $out[] = [
            'code'     => $code,
            'label'    => $req['label'],
            'group'    => $req['group'],
            'required_for' => $req['required_for'] ?? [],
            'ref'      => $req['ref'] ?? '',
            'note'     => $req['note'] ?? '',
        ];
    }
    return $out;
}

if (!defined('AI_PAGE_TEXT_LIMIT')) {
    define('AI_PAGE_TEXT_LIMIT', 2500);  
}

function aiClassifierConfig()
{
    $provider = strtolower(trim((string) getenv('AI_PROVIDER')));
    $hasGroq = (string) getenv('GROQ_API_KEY') !== '';
    $hasAnthropic = (string) getenv('ANTHROPIC_API_KEY') !== '';
    $hasAzure = (string) getenv('AZURE_OPENAI_KEY') !== '' && (string) getenv('AZURE_OPENAI_ENDPOINT') !== ''
        && (string) getenv('AZURE_OPENAI_DEPLOYMENT') !== '';

    if ($provider === '') {
        $provider = $hasGroq ? 'groq' : ($hasAnthropic ? 'anthropic' : ($hasAzure ? 'azure_openai' : ''));
    }
    if ($provider === 'groq' && $hasGroq) {
        return ['provider' => 'groq', 'model' => getenv('GROQ_MODEL') ?: 'openai/gpt-oss-20b'];
    }
    if ($provider === 'anthropic' && $hasAnthropic) {
        return ['provider' => 'anthropic', 'model' => getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001'];
    }
    if ($provider === 'azure_openai' && $hasAzure) {
        return ['provider' => 'azure_openai', 'model' => getenv('AZURE_OPENAI_DEPLOYMENT')];
    }
    return null;
}

function aiClassifierReasoning($model)
{
    if (stripos($model, 'gpt-oss') !== false) {
        return ['reasoning_effort' => 'low'];
    }
    if (stripos($model, 'qwen') !== false) {
        return ['reasoning_effort' => 'none'];
    }
    return [];
}

function redactTextForAi($text)
{
    $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/', '[email]', (string) $text);
    $text = preg_replace('/\d[\d\s\-\/]{2,}\d/', '[number]', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return mb_substr(trim($text), 0, AI_PAGE_TEXT_LIMIT);
}

function aiHttpPostJson($url, array $headers, array $body, $timeout = 90)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);
    return [$status, $raw === false ? null : json_decode($raw, true), $raw === false ? $error : $raw];
}

function aiClassifyPages(array $pageTexts)
{
    $config = aiClassifierConfig();
    if ($config === null) {
        return ['pages' => [], 'error' => 'No AI provider is configured (set GROQ_API_KEY in .env).', 'source' => null];
    }

    $codes = array_keys(getDocumentRequirements());
    $typeList = '';
    foreach (getDocumentRequirements() as $code => $req) {
        $typeList .= "- {$code}: {$req['label']}. {$req['ai_hint']}\n";
    }

    $pagesBlock = '';
    foreach ($pageTexts as $page => $text) {
        $clean = redactTextForAi($text);
        $pagesBlock .= "<page number=\"{$page}\">\n" . ($clean !== '' ? $clean : '(no readable text)') . "\n</page>\n";
    }

    $instructions = "You classify pages of documents uploaded to the Polytechnic University of the Philippines (PUP) "
        . "Biñan campus student records system. Pages come from PDF text extraction or OCR of scans, so text may be "
        . "out of order, broken or partly unreadable; personal numbers have been replaced with [number].\n\n"
        . "Document types (code: name. description):\n{$typeList}\n"
        . "Rules:\n"
        . "- Decide from what the page IS, not from documents it merely mentions. Instruction pages and Form 2 "
        . "list many other documents by name; that does not make them those documents.\n"
        . "- A page that continues the previous page's document (back side of a card, second page of a certificate) "
        . "gets the same code as that previous page.\n"
        . "- For report cards, choose the grade level printed as the card's own level, not a 'promoted to Grade X' line.\n"
        . "- Use \"none\" for blank pages or documents that match no type.\n"
        . "- confidence: high = unmistakable; medium = probably right; low = a guess the admin should check.\n"
        . "- reason: at most 15 words, naming the evidence you used.\n"
        . "Classify every page listed below. If the record_classifications tool is available, call it exactly once.";

    $itemSchema = [
        'type' => 'object',
        'properties' => [
            'page'       => ['type' => 'integer'],
            'code'       => ['type' => 'string', 'enum' => array_merge($codes, ['none'])],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'reason'     => ['type' => 'string'],
        ],
        'required' => ['page', 'code', 'confidence', 'reason'],
        'additionalProperties' => false,
    ];
    $schema = [
        'type' => 'object',
        'properties' => ['classifications' => ['type' => 'array', 'items' => $itemSchema]],
        'required' => ['classifications'],
        'additionalProperties' => false,
    ];

    $items = null;
    if ($config['provider'] === 'groq') {
        $url = rtrim(getenv('AI_GROQ_BASE_URL') ?: 'https://api.groq.com/openai/v1', '/') . '/chat/completions';
        [$status, $json, $raw] = aiHttpPostJson($url, ['Authorization: Bearer ' . getenv('GROQ_API_KEY')], [
            'model'                 => $config['model'],
            'temperature'           => 0,
            'max_completion_tokens' => 8000,
            'response_format'       => [
                'type'        => 'json_schema',
                'json_schema' => ['name' => 'record_classifications', 'strict' => true, 'schema' => $schema],
            ],
            'messages'              => [
                ['role' => 'system', 'content' => $instructions . "\nReply with the JSON object only."],
                ['role' => 'user', 'content' => $pagesBlock],
            ],
        ] + aiClassifierReasoning($config['model']));
        $content = $json['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode($content, true);
        $items = $decoded['classifications'] ?? null;
        $source = 'Groq (' . $config['model'] . ')';
    } elseif ($config['provider'] === 'anthropic') {
        [$status, $json, $raw] = aiHttpPostJson(rtrim(getenv('AI_ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com', '/') . '/v1/messages', [
            'x-api-key: ' . getenv('ANTHROPIC_API_KEY'),
            'anthropic-version: 2023-06-01',
        ], [
            'model'       => $config['model'],
            'max_tokens'  => 4000,
            'system'      => $instructions,
            'tools'       => [[
                'name'         => 'record_classifications',
                'description'  => 'Record the document type of every page.',
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'record_classifications'],
            'messages'    => [['role' => 'user', 'content' => $pagesBlock]],
        ]);
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $items = $block['input']['classifications'] ?? null;
            }
        }
        $source = 'Claude (' . $config['model'] . ')';
    } else {
        $url = rtrim(getenv('AZURE_OPENAI_ENDPOINT'), '/') . '/openai/deployments/' . rawurlencode($config['model'])
            . '/chat/completions?api-version=' . (getenv('AZURE_OPENAI_API_VERSION') ?: '2024-10-21');
        [$status, $json, $raw] = aiHttpPostJson($url, ['api-key: ' . getenv('AZURE_OPENAI_KEY')], [
            'temperature'     => 0,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $instructions
                    . "\nReply with JSON only, in this shape: {\"classifications\": [{\"page\": 1, \"code\": \"...\", \"confidence\": \"high\", \"reason\": \"...\"}]}"],
                ['role' => 'user', 'content' => $pagesBlock],
            ],
        ]);
        $content = $json['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode($content, true);
        $items = $decoded['classifications'] ?? null;
        $source = 'Azure OpenAI (' . $config['model'] . ')';
    }

    if (!is_array($items)) {
        error_log('AI classification failed, HTTP ' . $status . ': ' . substr((string) $raw, 0, 500));
        $message = $status === 401 || $status === 403 ? 'the AI key was rejected'
            : ($status === 429 ? 'the AI service is rate-limited, try again shortly'
            : ($status === 0 ? 'the AI service could not be reached' : 'the AI service returned an error (HTTP ' . $status . ')'));
        return ['pages' => [], 'error' => 'AI classification failed: ' . $message . '.', 'source' => $source];
    }

    $result = [];
    foreach ($items as $item) {
        $page = (int) ($item['page'] ?? 0);
        if (!isset($pageTexts[$page])) {
            continue;
        }
        $code = $item['code'] ?? 'none';
        $code = ($code !== 'none' && in_array($code, $codes, true)) ? $code : null;
        $confidence = in_array($item['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $item['confidence'] : 'low';
        $result[$page] = [
            'code'       => $code,
            'confidence' => $code === null ? 'none' : $confidence,
            'reason'     => mb_substr(trim((string) ($item['reason'] ?? '')), 0, 200),
        ];
    }
    return ['pages' => $result, 'error' => null, 'source' => $source];
}

function groupClassifiedPages(array $pages)
{
    $groups  = [];
    $skipped = [];
    $current = null;

    foreach ($pages as $p) {
        $code = $p['code'];

        if ($code !== null && !isStorableRequirement($code)) {
            $skipped[] = ['page' => $p['page'], 'code' => $code, 'label' => requirementLabel($code)];
            $current = null;
            continue;
        }

        if ($current !== null && ($code === null || $code === $groups[$current]['code'])) {
            $groups[$current]['pages'][] = $p['page'];
            continue;
        }

        $groups[] = [
            'code'       => $code,
            'confidence' => $code === null ? 'none' : $p['confidence'],
            'reason'     => $p['reason'] ?? '',
            'pages'      => [$p['page']],
        ];
        $current = count($groups) - 1;
    }

    return ['groups' => $groups, 'skipped' => $skipped];
}

function popplerBin($tool)
{
    $dir = dirname(PDFTOPPM_BIN);
    $isExe = strtolower(substr(PDFTOPPM_BIN, -4)) === '.exe';
    return $dir . DIRECTORY_SEPARATOR . $tool . ($isExe ? '.exe' : '');
}

function pdfPageCount($pdfPath)
{
    $cmd = escapeshellarg(popplerBin('pdfinfo')) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
    exec($cmd, $out, $code);
    foreach ($out as $line) {
        if (preg_match('/^Pages:\s*(\d+)/i', $line, $m)) {
            return max(1, (int) $m[1]);
        }
    }
    return 1;
}

function pdfPageText($pdfPath, $page)
{
    $cmd = escapeshellarg(popplerBin('pdftotext'))
        . ' -layout -enc UTF-8 -f ' . (int) $page . ' -l ' . (int) $page
        . ' ' . escapeshellarg($pdfPath) . ' - 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        return '';
    }
    return trim(implode("\n", $out));
}

function renderPdfPagePng($pdfPath, $page, $outDir)
{
    $base = rtrim($outDir, '/\\') . '/' . uniqid('pdfpage_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -png -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base) . ' 2>&1';
    exec($cmd, $out, $code);
    return file_exists($base . '.png') ? $base . '.png' : null;
}

function extractPdfPages($srcPdf, array $pages, $destPdf)
{
    $tmpFiles = [];
    foreach ($pages as $page) {
        $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('pdfsplit_', true) . '.pdf';
        $cmd = escapeshellarg(popplerBin('pdfseparate'))
            . ' -f ' . (int) $page . ' -l ' . (int) $page
            . ' ' . escapeshellarg($srcPdf) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        exec($cmd, $out, $code);
        if (!file_exists($tmp)) {
            foreach ($tmpFiles as $f) @unlink($f);
            return false;
        }
        $tmpFiles[] = $tmp;
    }

    if (count($tmpFiles) === 1) {
        $ok = @rename($tmpFiles[0], $destPdf) || (@copy($tmpFiles[0], $destPdf) && @unlink($tmpFiles[0]));
        return $ok && file_exists($destPdf);
    }

    $cmd = escapeshellarg(popplerBin('pdfunite'));
    foreach ($tmpFiles as $f) {
        $cmd .= ' ' . escapeshellarg($f);
    }
    $cmd .= ' ' . escapeshellarg($destPdf) . ' 2>&1';
    exec($cmd, $out, $code);
    foreach ($tmpFiles as $f) @unlink($f);
    return file_exists($destPdf);
}

function processDocumentUpload($destPath, $originalName, $uploadDir, array $options = [])
{
    $startedAt  = microtime(true);
    $timings    = [];
    $sourceHash = hash_file('sha256', $destPath);
    $pageCount  = pdfPageCount($destPath);
    $warnings   = [];

    $aiCache      = [];
    $aiFailed     = false;
    $renderFailed = false;
    $readPages = function (array $pages) use (&$aiCache, &$aiFailed, &$renderFailed, $destPath, $uploadDir) {
        $pages = array_values(array_unique(array_diff($pages, array_keys($aiCache))));
        if (!$pages) {
            return;
        }
        foreach (aiReadPdfPages($destPath, $pages, $uploadDir) as $page => $read) {
            $hasText = $read['text'] !== null && trim($read['text']) !== '';
            if ($read['render_failed']) {
                $renderFailed = true;
            } elseif (!$hasText) {
                $aiFailed = true;
            }
            $aiCache[$page] = $hasText ? $read : null;
        }
    };

    $pageTexts = [];
    $scannedPages = [];
    $classifyUpTo = min($pageCount, DOC_CLASSIFY_MAX_PAGES);
    $singleFile = !empty($options['single_file']);
    for ($p = 1; $p <= $classifyUpTo; $p++) {
        $pageTexts[$p] = pdfPageText($destPath, $p);
        if (preg_match_all('/[A-Za-z]/', $pageTexts[$p]) < 40) {
            $scannedPages[] = $p;
            if ($singleFile) {
                $readPages([$p]);
                $found = $aiCache[$p]['doc_type'] ?? null;
                if ($found !== null && $found !== 'none' && getDocumentRequirement($found) !== null) {
                    break;
                }
            }
        } elseif ($singleFile) {
            break;
        }
    }
    $timings['pdf_text'] = microtime(true) - $startedAt;
    $mark = microtime(true);
    $readPages($scannedPages);
    foreach ($scannedPages as $p) {
        $pageTexts[$p] = $aiCache[$p]['text'] ?? '';
    }
    $timings['ai_read'] = microtime(true) - $mark;
    $mark = microtime(true);

    $codes = array_keys(getDocumentRequirements());
    $visionPages = [];
    foreach ($pageTexts as $p => $text) {
        $type = $aiCache[$p]['doc_type'] ?? null;
        if ($type === null || ($type !== 'none' && !in_array($type, $codes, true))) {
            $visionPages = null;
            break;
        }
        $visionPages[$p] = [
            'code'       => $type === 'none' ? null : $type,
            'confidence' => $type === 'none' ? 'none' : $aiCache[$p]['doc_confidence'],
            'reason'     => '',
        ];
    }

    $ai = $visionPages
        ? ['pages' => $visionPages, 'error' => null, 'source' => 'Groq (' . groqModel() . ')']
        : aiClassifyPages($pageTexts);
    $timings['classify'] = microtime(true) - $mark;
    if ($ai['error'] !== null) {
        $warnings[] = $ai['error'] . ' The file was kept as one unclassified document; choose its type manually.';
    }

    $pages = [];
    for ($p = 1; $p <= $pageCount; $p++) {
        $c = $ai['pages'][$p] ?? ['code' => null, 'confidence' => 'none', 'reason' => ''];
        $pages[] = ['page' => $p, 'code' => $c['code'], 'confidence' => $c['confidence'], 'reason' => $c['reason']];
    }

    $grouped = groupClassifiedPages($pages);
    $groups  = $grouped['groups'];
    $skipped = $grouped['skipped'];

    if (empty($groups)) {
        @unlink($destPath);
        return [
            'success' => false,
            'message' => 'This PDF only contains information pages ('
                . implode(', ', array_unique(array_column($skipped, 'label')))
                . '). There is nothing to store.',
        ];
    }

    $fieldPages = [];
    foreach ($groups as $g) {
        $req = getDocumentRequirement($g['code']);
        if ($req && !empty($req['extract_fields'])) {
            $fieldPages[] = $g['pages'][0];
        }
    }
    $mark = microtime(true);
    $readPages($fieldPages);
    $timings['ai_fields'] = microtime(true) - $mark;
    $mark = microtime(true);

    $fieldSets = [];
    $photoPath = null;
    $photoTime = 0.0;
    $photoGroupIndex = null;
    foreach ($groups as $gi => $g) {
        $req       = getDocumentRequirement($g['code']);
        $firstPage = $g['pages'][0];
        $extract   = $req && !empty($req['extract_fields']);

        $read = $aiCache[$firstPage] ?? null;
        $text = $read['text'] ?? ($pageTexts[$firstPage] ?? '');
        if (trim($text) === '') {
            continue;
        }

        $fields = $extract ? aiExtractStudentFields($text) : null;
        if ($fields !== null) {
            $fieldSets[] = ['priority' => $req['field_priority'] ?? 50, 'fields' => $fields];
        }

        if ($photoPath === null && $req && !empty($req['photo_box'])) {
            $photoStart = microtime(true);
            $photoPath = extractIdPhoto($destPath, $req['photo_box'], $firstPage);
            $photoTime += microtime(true) - $photoStart;
            if ($photoPath !== null) {
                $photoGroupIndex = $gi;
            }
        }
    }

    $timings['fields'] = microtime(true) - $mark - $photoTime;
    $timings['photo'] = $photoTime;
    $mark = microtime(true);

    usort($fieldSets, fn($a, $b) => $a['priority'] <=> $b['priority']);
    $extracted = [];
    foreach ($fieldSets as $set) {
        foreach ($set['fields'] as $key => $value) {
            if (($extracted[$key] ?? '') === '' && $value !== '') {
                $extracted[$key] = $value;
            }
        }
    }

    if (($extracted['mobile_number'] ?? '') !== '') {
        $digits = preg_replace('/\D/', '', $extracted['mobile_number']);
        if (preg_match('/^639\d{9}$/', $digits)) {
            $digits = '0' . substr($digits, 2);
        }
        $extracted['mobile_number'] = preg_match('/^09\d{9}$/', $digits) ? $digits : '';
    }

    if (!empty($options['single_file']) && (count($groups) > 1 || !empty($skipped))) {
        $main = null;
        foreach ($groups as $gi => $g) {
            if ($g['code'] !== null) {
                $main = $gi;
                break;
            }
        }
        $main = $main ?? 0;
        $groups = [array_merge($groups[$main], ['pages' => range(1, $pageCount)])];
        $skipped = [];
        $photoGroupIndex = $photoPath !== null ? 0 : null;
    }

    $needSplit = count($groups) > 1 || !empty($skipped);
    $origBase  = pathinfo($originalName, PATHINFO_FILENAME);
    $filePaths = [];

    if ($needSplit) {
        foreach ($groups as $gi => $g) {
            $splitPath = $uploadDir . uniqid('doc_', true) . '.pdf';
            if (!extractPdfPages($destPath, $g['pages'], $splitPath)) {
                foreach ($filePaths as $f) @unlink($f);
                $filePaths = [];
                break;
            }
            $filePaths[$gi] = $splitPath;
        }

        if (empty($filePaths)) {
            $warnings[] = 'Could not split the PDF into separate documents (pdfseparate/pdfunite not found next to pdftoppm). It was stored as one file.';
            $groups    = [$groups[0]];
            $groups[0]['pages'] = range(1, $pageCount);
            $photoGroupIndex = $photoPath !== null ? 0 : null;
            $needSplit = false;
        } else {
            @unlink($destPath);
        }
    }
    if (!$needSplit) {
        $filePaths = [0 => $destPath];
    }

    $documents = [];
    foreach ($groups as $gi => $g) {
        $pagesLabel = count($g['pages']) === 1
            ? 'p' . $g['pages'][0]
            : 'p' . $g['pages'][0] . '-' . end($g['pages']);
        $label = $g['code'] !== null ? requirementLabel($g['code']) : 'Unclassified';

        $documents[] = [
            'file_path'         => $filePaths[$gi],
            'file_name'         => $needSplit
                ? preg_replace('#[\\\\/:*?"<>|]+#', '-', $origBase . ' - ' . $label . ' (' . $pagesLabel . ')') . '.pdf'
                : $originalName,
            'requirement_code'  => $g['code'],
            'requirement_label' => $label,
            'confidence'        => $g['confidence'],
            'ai_reason'         => $g['reason'] ?? '',
            'pages'             => $g['pages'],
            'file_hash'         => pdfContentFingerprint($filePaths[$gi]),
            'photo_path'        => $gi === $photoGroupIndex ? $photoPath : '',
        ];
    }

    if ($renderFailed) {
        $warnings[] = 'Some pages could not be converted to images for AI reading. Make sure poppler (pdftoppm) is installed.';
    }
    if ($aiFailed && aiExtractionError() === null) {
        $warnings[] = 'The AI could not read some pages. Check your internet connection and the GROQ_API_KEY value in .env. Fields may not be auto-filled.';
    }

    $imagePath = null;
    if (!empty($options['preview_image']) && $photoPath === null) {
        $imagePath = renderPdfPagePng($documents[0]['file_path'], 1, $uploadDir);
    }
    $timings['split_save'] = microtime(true) - $mark;
    $timings['total'] = microtime(true) - $startedAt;
    error_log('AI upload timings (' . $pageCount . ' page(s)): ' . implode(', ', array_map(
        fn($k, $v) => $k . ' ' . number_format($v, 2) . 's', array_keys($timings), $timings)));

    return [
        'success'       => true,
        'source_name'   => $originalName,
        'source_hash'   => $sourceHash,
        'page_count'    => $pageCount,
        'documents'     => $documents,
        'skipped_pages' => $skipped,
        'extracted'     => $extracted,
        'photo_path'    => $photoPath,
        'ocr_source'    => 'groq_ai',
        'warnings'      => $warnings,
        'classified_by' => $ai['source'],
        'image_path'    => $imagePath,
    ];
}

function pdfContentFingerprint($pdfPath)
{
    $ctx = hash_init('sha256');
    $rendered = 0;
    $pages = min(pdfPageCount($pdfPath), 50);
    for ($p = 1; $p <= $pages; $p++) {
        $base = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('fp_', true);
        $cmd = escapeshellarg(PDFTOPPM_BIN) . ' -gray -r 36 -singlefile -f ' . $p . ' -l ' . $p
            . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base) . ' 2>&1';
        exec($cmd, $out, $code);
        if (is_file($base . '.pgm')) {
            hash_update_file($ctx, $base . '.pgm');
            @unlink($base . '.pgm');
            $rendered++;
        }
    }
    return $rendered > 0 ? hash_final($ctx) : hash_file('sha256', $pdfPath);
}

function readPgmFile($path)
{
    $data = @file_get_contents($path);
    if ($data === false || substr($data, 0, 2) !== 'P5') {
        return null;
    }
    if (!preg_match('/^P5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(?:#[^\n]*\n\s*)*(\d+)\s+(?:#[^\n]*\n\s*)*(\d+)\s/', $data, $m)) {
        return null;
    }
    $width = (int) $m[1];
    $height = (int) $m[2];
    $pixels = substr($data, strlen($m[0]));
    if ($width <= 0 || $height <= 0 || strlen($pixels) < $width * $height) {
        return null;
    }
    return ['width' => $width, 'height' => $height, 'data' => $pixels];
}

function refinePhotoBox($pdfPath, $page, array $box, $margin = 150)
{
    $rx = max(0, $box['x'] - $margin);
    $ry = max(0, $box['y'] - $margin);
    $rw = $box['width'] + ($box['x'] - $rx) + $margin;
    $rh = $box['height'] + ($box['y'] - $ry) + $margin;

    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('photobox_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -gray -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x ' . (int) $rx . ' -y ' . (int) $ry . ' -W ' . (int) $rw . ' -H ' . (int) $rh
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
    exec($cmd, $out, $code);
    $img = readPgmFile($tmpBase . '.pgm');
    @unlink($tmpBase . '.pgm');
    if ($img === null) {
        return $box;
    }

    $w = $img['width'];
    $h = $img['height'];
    $px = $img['data'];
    $dark = 110;  

    $colRun = array_fill(0, $w, 0);
    $rowRun = array_fill(0, $h, 0);
    $colCur = array_fill(0, $w, 0);
    for ($y = 0; $y < $h; $y++) {
        $rowCur = 0;
        $offset = $y * $w;
        for ($x = 0; $x < $w; $x++) {
            if (ord($px[$offset + $x]) < $dark) {
                $colCur[$x]++;
                if ($colCur[$x] > $colRun[$x]) $colRun[$x] = $colCur[$x];
                $rowCur++;
                if ($rowCur > $rowRun[$y]) $rowRun[$y] = $rowCur;
            } else {
                $colCur[$x] = 0;
                $rowCur = 0;
            }
        }
    }

    $findLine = function (array $runs, $minRun, $expected, $tolerance) {
        $best = null;
        foreach ($runs as $pos => $run) {
            if ($run >= $minRun && abs($pos - $expected) <= $tolerance) {
                if ($best === null || abs($pos - $expected) < abs($best - $expected)) {
                    $best = $pos;
                }
            }
        }
        return $best;
    };

    $expLeft = $box['x'] - $rx;
    $expTop  = $box['y'] - $ry;
    $left   = $findLine($colRun, (int) ($box['height'] * 0.6), $expLeft, $margin);
    $right  = $findLine($colRun, (int) ($box['height'] * 0.6), $expLeft + $box['width'], $margin);
    $top    = $findLine($rowRun, (int) ($box['width'] * 0.6), $expTop, $margin);
    $bottom = $findLine($rowRun, (int) ($box['width'] * 0.6), $expTop + $box['height'], $margin);

    $pair = function ($a, $b, $size) {
        if ($a !== null && $b !== null && abs(($b - $a) - $size) <= $size * 0.15) return [$a, $b];
        if ($a !== null) return [$a, $a + $size];
        if ($b !== null) return [$b - $size, $b];
        return null;
    };
    $xs = $pair($left, $right, $box['width']);
    $ys = $pair($top, $bottom, $box['height']);

    $inset = 10;
    $ax0 = $xs ? max(0, $xs[0] + $inset) : 0;
    $ax1 = $xs ? min($w - 1, $xs[1] - $inset) : $w - 1;
    $ay0 = $ys ? max(0, $ys[0] + $inset) : 0;
    $ay1 = $ys ? min($h - 1, $ys[1] - $inset) : $h - 1;

    $samples = [];
    for ($i = 0, $n = $w * $h; $i < $n; $i += 13) {
        $samples[] = ord($px[$i]);
    }
    sort($samples);
    $background = $samples[(int) (count($samples) * 0.85)];
    $inkLevel = max(60, $background - 40);

    $colShare = [];
    for ($x = $ax0; $x <= $ax1; $x++) {
        $count = 0;
        for ($y = $ay0; $y <= $ay1; $y++) {
            if (ord($px[$y * $w + $x]) < $inkLevel) $count++;
        }
        $colShare[$x] = $count / ($ay1 - $ay0 + 1);
    }
    $widestSpan = function (array $share, $minShare, $maxGap) {
        $best = null; $start = null; $last = null;
        foreach ($share as $pos => $v) {
            if ($v >= $minShare) {
                if ($start === null || $pos - $last > $maxGap) $start = $pos;
                $last = $pos;
                if ($best === null || ($last - $start) > ($best[1] - $best[0])) $best = [$start, $last];
            }
        }
        return $best;
    };
    $cols = $widestSpan($colShare, 0.12, 8);

    $content = null;
    if ($cols !== null && ($cols[1] - $cols[0]) >= $box['width'] * 0.35) {
        $rowShare = [];
        for ($y = $ay0; $y <= $ay1; $y++) {
            $count = 0;
            for ($x = $cols[0]; $x <= $cols[1]; $x++) {
                if (ord($px[$y * $w + $x]) < $inkLevel) $count++;
            }
            $rowShare[$y] = $count / ($cols[1] - $cols[0] + 1);
        }
        $rows = $widestSpan($rowShare, 0.22, 8);
        if ($rows !== null) {
            $limit = max($ay0, $rows[0] - (int) ($box['height'] * 0.12));
            while ($rows[0] - 1 >= $limit && ($rowShare[$rows[0] - 1] ?? 0) >= 0.03) {
                $rows[0]--;
            }
        }
        if ($rows !== null && ($rows[1] - $rows[0]) >= $box['height'] * 0.35) {
            $content = [$cols[0], $rows[0], $cols[1], $rows[1]];
        }
    }

    if ($content !== null) {
        [$cx0, $cy0, $cx1, $cy1] = $content;
        $side = max($cx1 - $cx0, $cy1 - $cy0);
        $side = min($side, $ax1 - $ax0, $ay1 - $ay0, (int) ($box['width'] * 1.1));
        $x0 = (int) round(($cx0 + $cx1) / 2 - $side / 2);
        $y0 = (int) round($cy0 - $side * 0.03);
        $x0 = max($ax0, min($x0, $ax1 - $side));
        $y0 = max($ay0, min($y0, $ay1 - $side));
    } elseif ($xs !== null || $ys !== null) {
        $x0 = $ax0; $y0 = $ay0;
        $side = min($ax1 - $ax0, $ay1 - $ay0);
        if (!$xs) $x0 = $box['x'] - $rx;
        if (!$ys) $y0 = $box['y'] - $ry;
    } else {
        return $box;
    }

    if ($side < $box['width'] * 0.5) {
        return $box;
    }
    $result = [
        'x'      => (int) ($rx + $x0),
        'y'      => (int) ($ry + $y0),
        'width'  => (int) $side,
        'height' => (int) $side,
    ];
    if ($content !== null) {
        $result['content'] = [$rx + $content[0], $ry + $content[1], $rx + $content[2], $ry + $content[3]];
    }
    return $result;
}

function savePaddedPhoto($pdfPath, $page, array $box, $outBase, $pad = 0.12, $size = 480)
{
    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('photocrop_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x ' . (int) $box['x'] . ' -y ' . (int) $box['y'] . ' -W ' . (int) $box['width'] . ' -H ' . (int) $box['height']
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
    exec($cmd, $out, $code);
    $raw = @file_get_contents($tmpBase . '.ppm');
    @unlink($tmpBase . '.ppm');
    if ($raw === false || !preg_match('/^P6\s+(\d+)\s+(\d+)\s+(\d+)\s/', $raw, $m)) {
        return null;
    }
    $sw = (int) $m[1];
    $sh = (int) $m[2];
    $src = substr($raw, strlen($m[0]));
    if ($sw <= 0 || $sh <= 0 || strlen($src) < $sw * $sh * 3) {
        return null;
    }

    $ring = [[], [], []];
    for ($x = 0; $x < $sw; $x += 3) {
        foreach ([0, 1, 2] as $c) { $ring[$c][] = ord($src[($x) * 3 + $c]); }    
    }
    for ($y = 0; $y < $sh; $y += 3) {
        foreach ([0, 1, 2] as $c) {
            $ring[$c][] = ord($src[($y * $sw) * 3 + $c]);                      
            $ring[$c][] = ord($src[($y * $sw + $sw - 1) * 3 + $c]);                
        }
    }
    $bg = [];
    foreach ($ring as $c => $values) { sort($values); $bg[$c] = $values[(int) (count($values) / 2)]; }

    $keep = null;
    if (isset($box['content'])) {
        $m = (int) round(max($sw, $sh) * 0.04);
        $keep = [
            $box['content'][0] - $box['x'] - $m,
            $box['content'][1] - $box['y'] - $m,
            $box['content'][2] - $box['x'] + $m,
        ];
    }

    $side = max($sw, $sh);
    $padPx = (int) round($side * $pad);
    $canvas = $side + 2 * $padPx;
    $offX = $padPx + (int) (($side - $sw) / 2);
    $offY = 2 * $padPx;

    $scale = $canvas / $size;
    $rows = '';
    for ($oy = 0; $oy < $size; $oy++) {
        $row = "\0"; 
        for ($ox = 0; $ox < $size; $ox++) {
            $r = $g = $b = 0;
            foreach ([0.25, 0.75] as $fy) {
                foreach ([0.25, 0.75] as $fx) {
                    $cx = (int) (($ox + $fx) * $scale) - $offX;
                    $cy = (int) (($oy + $fy) * $scale) - $offY;
                    $inside = $cx >= 0 && $cy >= 0 && $cx < $sw && $cy < $sh
                        && ($keep === null || ($cx >= $keep[0] && $cx <= $keep[2] && $cy >= $keep[1]));
                    if ($inside) {
                        $i = ($cy * $sw + $cx) * 3;
                        $r += ord($src[$i]); $g += ord($src[$i + 1]); $b += ord($src[$i + 2]);
                    } else {
                        $r += $bg[0]; $g += $bg[1]; $b += $bg[2];
                    }
                }
            }
            $row .= chr($r >> 2) . chr($g >> 2) . chr($b >> 2);
        }
        $rows .= $row;
    }

    $chunk = function ($type, $data) {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $png = "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($rows, 6))
        . $chunk('IEND', '');

    $path = $outBase . '.png';
    return file_put_contents($path, $png) !== false ? $path : null;
}

function pathIsInside($path, $dir)
{
    $realDir  = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false) {
        return false;
    }
    return strpos($realPath, rtrim($realDir, '/\\') . DIRECTORY_SEPARATOR) === 0;
}

function findStoredRequirement($conn, $studentId, $requirementCode)
{
    $stmt = mysqli_prepare($conn, 'SELECT document_id, file_name, document_type FROM documents WHERE student_id = ? AND requirement_code = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'is', $studentId, $requirementCode);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ?: null;
}

function findStoredFileHash($conn, $studentId, $fileHash)
{
    $stmt = mysqli_prepare($conn, 'SELECT document_id, file_name, document_type FROM documents WHERE student_id = ? AND file_hash = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'is', $studentId, $fileHash);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ?: null;
}