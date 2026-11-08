<?php
const REPORT_TEMPLATE_DIR = __DIR__ . '/images/report-template';
const REPORT_TEMPLATE_DPI = 200;

function reportTemplateFiles()
{
    return [
        'header' => REPORT_TEMPLATE_DIR . '/header.jpg',
        'footer' => REPORT_TEMPLATE_DIR . '/footer.jpg',
        'draft'  => REPORT_TEMPLATE_DIR . '/draft.pdf',
        'preview' => REPORT_TEMPLATE_DIR . '/draft.png',
    ];
}

function reportTemplateIsCustom()
{
    $files = reportTemplateFiles();
    return is_file($files['header']) && is_file($files['footer']);
}

function reportTemplateImages()
{
    $files = reportTemplateFiles();
    if (reportTemplateIsCustom()) {
        return ['header' => $files['header'], 'footer' => $files['footer']];
    }
    return [
        'header' => __DIR__ . '/images/letterhead-header.jpg',
        'footer' => __DIR__ . '/images/letterhead-footer.jpg',
    ];
}

function reportTemplateHeight($imagePath, $pageWidth, $fallbackRatio)
{
    $size = is_file($imagePath) ? @getimagesize($imagePath) : false;
    if ($size && $size[0] > 0) {
        return $pageWidth * $size[1] / $size[0];
    }
    return $pageWidth * $fallbackRatio;
}

function applyReportTemplate($pdf, $pageWidth = 215.9)
{
    $images = reportTemplateImages();
    $pdf->headerImage = $images['header'];
    $pdf->footerImage = $images['footer'];
    $pdf->headerHeight = reportTemplateHeight($images['header'], $pageWidth, 292 / 1700);
    $pdf->footerHeight = reportTemplateHeight($images['footer'], $pageWidth, 215 / 1700);
}

function reportTemplateFindSoffice()
{
    $candidates = array_filter([
        getenv('SOFFICE_BIN') ?: '',
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
    ]);
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function reportTemplatePdftoppm()
{
    if (getenv('PDFTOPPM_BIN')) {
        return getenv('PDFTOPPM_BIN');
    }
    if (defined('PDFTOPPM_BIN')) {
        return PDFTOPPM_BIN;
    }
    foreach (glob(__DIR__ . '/poppler-*/Library/bin/pdftoppm.exe') ?: [] as $path) {
        return $path;
    }
    return 'pdftoppm';
}

function reportTemplateBlankDocx($source, $target)
{
    if (!class_exists('ZipArchive') || !copy($source, $target)) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) {
        return false;
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml !== false && preg_match('/<w:body>(.*)<\/w:body>/s', $xml, $m)) {
        $sectPr = preg_match_all('/<w:sectPr\b.*?<\/w:sectPr>/s', $m[1], $sections) ? end($sections[0]) : '';
        $xml = str_replace($m[0], '<w:body><w:p/>' . $sectPr . '</w:body>', $xml);
        $zip->addFromString('word/document.xml', $xml);
    }
    $zip->close();
    return true;
}

function reportTemplateRun($command)
{
    $output = [];
    $code = 1;
    @exec($command . ' 2>&1', $output, $code);
    return $code === 0;
}

function reportTemplateTempDir()
{
    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rt_' . bin2hex(random_bytes(6));
    return @mkdir($work, 0700, true) ? $work : null;
}

function reportTemplateRemoveDir($dir)
{
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_dir($file)) {
            reportTemplateRemoveDir($file);
        } else {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function reportTemplateDocxToPdf($docx, $work)
{
    $pdf = $work . DIRECTORY_SEPARATOR . 'template.pdf';

    $soffice = reportTemplateFindSoffice();
    if ($soffice !== null) {
        $profile = 'file:///' . ltrim(str_replace('\\', '/', $work), '/') . '/profile';
        reportTemplateRun(escapeshellarg($soffice) . ' -env:UserInstallation=' . escapeshellarg($profile)
            . ' --headless --convert-to pdf --outdir ' . escapeshellarg($work) . ' ' . escapeshellarg($docx));
        if (is_file($pdf) && filesize($pdf) > 0) {
            return $pdf;
        }
    }

    if (stripos(PHP_OS, 'WIN') === 0) {
        $script = '$ErrorActionPreference = "Stop"; $word = $null; $doc = $null; '
            . 'try { $word = New-Object -ComObject Word.Application; $word.Visible = $false; $word.DisplayAlerts = 0; '
            . '$doc = $word.Documents.Open(' . reportTemplatePsString(realpath($docx) ?: $docx) . ', $false, $true, $false); '
            . '$doc.ExportAsFixedFormat(' . reportTemplatePsString($pdf) . ', 17); } '
            . 'finally { if ($doc) { $doc.Close(0) }; if ($word) { $word.Quit() } }';
        $encoded = base64_encode(reportTemplateUtf16($script));
        reportTemplateRun('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded);
        if (is_file($pdf) && filesize($pdf) > 0) {
            return $pdf;
        }
    }

    return null;
}

function reportTemplateUtf16($text)
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'UTF-16LE', $text);
        if ($converted !== false) {
            return $converted;
        }
    }
    $out = '';
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        $code = strlen($char) === 1 ? ord($char) : 63;
        $out .= chr($code & 255) . chr($code >> 8);
    }
    return $out;
}

function reportTemplatePsString($value)
{
    return "'" . str_replace("'", "''", (string) $value) . "'";
}

function reportTemplatePrepareDraft($sourcePath, $extension, &$error)
{
    $files = reportTemplateFiles();
    @unlink($files['draft']);
    @unlink($files['preview']);

    if ($extension === 'docx') {
        $work = reportTemplateTempDir();
        if ($work === null) {
            $error = 'The template could not be prepared.';
            return null;
        }
        $docx = $work . DIRECTORY_SEPARATOR . 'template.docx';
        if (!reportTemplateBlankDocx($sourcePath, $docx)) {
            copy($sourcePath, $docx);
        }
        $converted = reportTemplateDocxToPdf($docx, $work);
        $ok = $converted !== null && copy($converted, $files['draft']);
        reportTemplateRemoveDir($work);
        if (!$ok) {
            $error = 'The Word file could not be converted. Install LibreOffice (free) or Microsoft Word on this computer, or save the template as PDF in Word and upload the PDF.';
            return null;
        }
    } elseif ($extension === 'pdf') {
        if (!copy($sourcePath, $files['draft'])) {
            $error = 'The template could not be saved. Check that the images folder can be written to.';
            return null;
        }
    } else {
        $error = 'Upload a Word (.docx) or PDF file.';
        return null;
    }

    $bin = escapeshellarg(reportTemplatePdftoppm());
    $previewPrefix = substr($files['preview'], 0, -4);
    reportTemplateRun($bin . ' -png -r 100 -f 1 -l 1 -singlefile ' . escapeshellarg($files['draft']) . ' ' . escapeshellarg($previewPrefix));

    $work = reportTemplateTempDir();
    $page = null;
    if ($work !== null) {
        $prefix = $work . DIRECTORY_SEPARATOR . 'page';
        reportTemplateRun($bin . ' -gray -r ' . REPORT_TEMPLATE_DPI . ' -f 1 -l 1 -singlefile '
            . escapeshellarg($files['draft']) . ' ' . escapeshellarg($prefix));
        $page = reportTemplateReadPgm($prefix . '.pgm');
        reportTemplateRemoveDir($work);
    }
    if ($page === null || !is_file($files['preview'])) {
        @unlink($files['draft']);
        @unlink($files['preview']);
        $error = 'The PDF could not be read. Check that it is a valid PDF.';
        return null;
    }
    return $page;
}

function reportTemplateReadPgm($path)
{
    $data = is_file($path) ? @file_get_contents($path) : false;
    if ($data === false || !preg_match('/\AP5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s+(\d+)\s/', $data, $m)) {
        return null;
    }
    $width = (int) $m[1];
    $height = (int) $m[2];
    $pixels = substr($data, strlen($m[0]));
    if ($width < 1 || $height < 1 || strlen($pixels) < $width * $height) {
        return null;
    }
    return ['width' => $width, 'height' => $height, 'pixels' => $pixels];
}

function reportTemplateSuggestCuts(array $page)
{
    $width = $page['width'];
    $height = $page['height'];
    $step = max(1, (int) floor($width / 400));
    $rows = [];
    for ($y = 0; $y < $height; $y++) {
        $offset = $y * $width;
        $dark = false;
        for ($x = 0; $x < $width; $x += $step) {
            if (ord($page['pixels'][$offset + $x]) < 230) {
                $dark = true;
                break;
            }
        }
        $rows[$y] = $dark;
    }
    $gap = max(8, (int) round($height * 0.012));

    $headerEnd = (int) round($height * 0.12);
    $first = array_search(true, $rows, true);
    if ($first !== false && $first < $height * 0.3) {
        $blank = 0;
        for ($y = $first; $y < $height * 0.35; $y++) {
            $blank = $rows[$y] ? 0 : $blank + 1;
            if ($blank >= $gap) {
                $headerEnd = $y - $blank + (int) ceil($gap / 2) + 1;
                break;
            }
        }
    }

    $footerStart = (int) round($height * 0.88);
    $last = null;
    for ($y = $height - 1; $y >= 0; $y--) {
        if ($rows[$y]) {
            $last = $y;
            break;
        }
    }
    if ($last !== null && $last > $height * 0.7) {
        $blank = 0;
        for ($y = $last; $y > $height * 0.6; $y--) {
            $blank = $rows[$y] ? 0 : $blank + 1;
            if ($blank >= $gap) {
                $footerStart = $y + $blank - (int) ceil($gap / 2) - 1;
                break;
            }
        }
    }

    return ['header_end' => $headerEnd, 'footer_start' => max($headerEnd + 1, $footerStart)];
}

function reportTemplateSaveCuts($headerEnd, $footerStart, $width, $height, &$error)
{
    $files = reportTemplateFiles();
    if (!is_file($files['draft'])) {
        $error = 'Upload the template again.';
        return false;
    }
    $width = (int) $width;
    $height = (int) $height;
    if ($width < 100 || $height < 100) {
        $error = 'Upload the template again.';
        return false;
    }
    $headerEnd = max(20, min($height - 40, (int) $headerEnd));
    $footerStart = max($headerEnd + 20, min($height - 20, (int) $footerStart));
    if ($headerEnd > $height * 0.45 || $footerStart < $height * 0.55) {
        $error = 'The header or footer is too tall. Move the lines closer to the edges of the page.';
        return false;
    }

    $work = reportTemplateTempDir();
    if ($work === null) {
        $error = 'The template could not be saved.';
        return false;
    }
    $bin = escapeshellarg(reportTemplatePdftoppm());
    $parts = [
        'header' => [0, $headerEnd],
        'footer' => [$footerStart, $height - $footerStart],
    ];
    $made = [];
    foreach ($parts as $name => [$top, $partHeight]) {
        $prefix = $work . DIRECTORY_SEPARATOR . $name;
        reportTemplateRun($bin . ' -jpeg -jpegopt quality=92 -r ' . REPORT_TEMPLATE_DPI . ' -f 1 -l 1 -singlefile'
            . ' -x 0 -y ' . (int) $top . ' -W ' . $width . ' -H ' . (int) $partHeight . ' '
            . escapeshellarg($files['draft']) . ' ' . escapeshellarg($prefix));
        if (!is_file($prefix . '.jpg')) {
            reportTemplateRun($bin . ' -jpeg -r ' . REPORT_TEMPLATE_DPI . ' -f 1 -l 1 -singlefile'
                . ' -x 0 -y ' . (int) $top . ' -W ' . $width . ' -H ' . (int) $partHeight . ' '
                . escapeshellarg($files['draft']) . ' ' . escapeshellarg($prefix));
        }
        if (!is_file($prefix . '.jpg') || !@getimagesize($prefix . '.jpg')) {
            reportTemplateRemoveDir($work);
            $error = 'The template could not be saved.';
            return false;
        }
        $made[$name] = $prefix . '.jpg';
    }
    foreach ($made as $name => $path) {
        @unlink($files[$name]);
        if (!copy($path, $files[$name])) {
            reportTemplateRemoveDir($work);
            $error = 'The template could not be saved. Check that the images folder can be written to.';
            return false;
        }
    }
    reportTemplateRemoveDir($work);
    @unlink($files['draft']);
    @unlink($files['preview']);
    return true;
}