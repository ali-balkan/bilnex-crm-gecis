<?php

declare(strict_types=1);

$sourceUrl = 'https://cdn.gib.gov.tr/api/gibportal-file/file/getFileResources?objectKey=arsiv%2Fyardim-kaynaklar%2Fyararli-bilgiler%2FDefterdarl%C4%B1kveVergiDaireleriListesi.pdf';
$root = dirname(__DIR__);
$dumpText = in_array('--dump-text', $argv, true);
$dumpHex = in_array('--dump-hex', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn(string $arg): bool => !in_array($arg, ['--dump-text', '--dump-hex'], true)));
$pdfPath = $args[0] ?? dirname(__DIR__, 3) . '/DefterdarlikVergiDaireleriListesi.pdf';
$outputPath = $args[1] ?? $root . '/resources/tax-offices.json';

if (!is_file($pdfPath)) {
    fwrite(STDERR, "PDF bulunamadı: {$pdfPath}\n");
    fwrite(STDERR, "Kaynak: {$sourceUrl}\n");
    exit(1);
}

$pdf = file_get_contents($pdfPath);
if ($pdf === false) {
    fwrite(STDERR, "PDF okunamadı: {$pdfPath}\n");
    exit(1);
}

$decodedStreams = [];
preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streamMatches);
foreach ($streamMatches[1] as $stream) {
    $decoded = @gzuncompress($stream);
    if ($decoded === false) {
        $decoded = @zlib_decode($stream);
    }
    if (!is_string($decoded) || $decoded === '') {
        continue;
    }

    $decodedStreams[] = $decoded;
}

$characterMap = pdf_character_map($decodedStreams);
$text = '';
foreach ($decodedStreams as $decoded) {
    $text .= "\n" . pdf_text_from_stream($decoded, $characterMap);
}

$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
$lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $text) ?: [])));
if ($dumpText) {
    echo implode(PHP_EOL, array_slice($lines, 0, 140)) . PHP_EOL;
    exit(0);
}
if ($dumpHex) {
    foreach (array_slice($lines, 13, 24) as $line) {
        echo bin2hex($line) . ' | ' . json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    }
    exit(0);
}
$items = [];
$documentDate = null;

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    if ($documentDate === null && preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $line, $dateMatch)) {
        $documentDate = $dateMatch[1];
    }

    if (!preg_match('/^(\d{1,2})\s+(.+)$/u', $line, $match)) {
        continue;
    }

    $cityCode = str_pad($match[1], 2, '0', STR_PAD_LEFT);
    $city = normalize_tax_text($match[2]);
    if ((int) $cityCode < 1 || (int) $cityCode > 81 || !preg_match('/^[A-ZÇĞİÖŞÜÂÎÛ ]+$/u', $city)) {
        continue;
    }
    $district = normalize_tax_text(str_replace('(**)', '', $lines[$i + 1] ?? ''));
    $code = '';
    $name = '';

    if (preg_match('/^\d{5}$/', $lines[$i + 2] ?? '')) {
        $code = $lines[$i + 2];
        $name = normalize_tax_text($lines[$i + 3] ?? '');
        $i += 3;
    } else {
        $name = normalize_tax_text($lines[$i + 2] ?? '');
        $i += 2;
    }

    if ($city === '' || $district === '' || $name === '') {
        continue;
    }

    $items[] = [
        'city_code' => $cityCode,
        'city' => $city,
        'district' => $district,
        'code' => $code,
        'name' => $name,
    ];
}

$unique = [];
foreach ($items as $item) {
    $unique[$item['code'] . '|' . $item['name']] = $item;
}
$items = array_values($unique);

if (count($items) < 500) {
    fwrite(STDERR, 'Beklenenden az vergi dairesi bulundu: ' . count($items) . "\n");
    exit(1);
}

usort($items, static function (array $a, array $b): int {
    return [$a['city_code'], $a['district'], $a['name']] <=> [$b['city_code'], $b['district'], $b['name']];
});

$payload = [
    'source' => 'Gelir İdaresi Başkanlığı - Defterdarlık ve Vergi Daireleri Listesi',
    'source_url' => $sourceUrl,
    'document_date' => $documentDate,
    'generated_at' => date('c'),
    'items' => $items,
];

$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Klasör oluşturulamadı: {$outputDir}\n");
    exit(1);
}

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false || file_put_contents($outputPath, $json . "\n") === false) {
    fwrite(STDERR, "JSON yazılamadı: {$outputPath}\n");
    exit(1);
}

echo 'tax_offices=' . count($items) . PHP_EOL;
echo 'document_date=' . ($documentDate ?? '-') . PHP_EOL;
echo 'output=' . $outputPath . PHP_EOL;

function pdf_character_map(array $streams): array
{
    $map = [];
    foreach ($streams as $stream) {
        if (!str_contains($stream, 'begincmap')) {
            continue;
        }

        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (!preg_match_all('/<([0-9A-Fa-f]{4})>\s+<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($pairs as $pair) {
                    $map[hexdec($pair[1])] = utf16be_hex_to_utf8($pair[2]);
                }
            }
        }

        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                $lines = preg_split('/\R+/', trim($block)) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/<([0-9A-Fa-f]{4})>\s+<([0-9A-Fa-f]{4})>\s+<([0-9A-Fa-f]+)>/', $line, $range)) {
                        $start = hexdec($range[1]);
                        $end = hexdec($range[2]);
                        $base = hexdec($range[3]);
                        for ($code = $start; $code <= $end; $code++) {
                            $map[$code] = utf16be_codepoint_to_utf8($base + ($code - $start));
                        }
                    } elseif (preg_match('/<([0-9A-Fa-f]{4})>\s+<([0-9A-Fa-f]{4})>\s+\[(.*?)\]/', $line, $range)) {
                        $start = hexdec($range[1]);
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $range[3], $values);
                        foreach ($values[1] as $index => $hexValue) {
                            $map[$start + $index] = utf16be_hex_to_utf8($hexValue);
                        }
                    }
                }
            }
        }
    }

    return $map;
}

function utf16be_hex_to_utf8(string $hex): string
{
    $bytes = hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);
    if ($bytes === false) {
        return '';
    }

    return iconv('UTF-16BE', 'UTF-8//IGNORE', $bytes) ?: '';
}

function utf16be_codepoint_to_utf8(int $codepoint): string
{
    return utf16be_hex_to_utf8(str_pad(strtoupper(dechex($codepoint)), 4, '0', STR_PAD_LEFT));
}

function pdf_text_from_stream(string $stream, array $characterMap): string
{
    $parts = [];
    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $arrayMatches)) {
        foreach ($arrayMatches[1] as $arrayBody) {
            $parts[] = pdf_decode_text_tokens($arrayBody, $characterMap);
        }
    }
    if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)\s*Tj/s', $stream, $textMatches)) {
        foreach ($textMatches[0] as $operator) {
            if (preg_match('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $operator, $match)) {
                $parts[] = pdf_decode_literal_string($match[1]);
            }
        }
    }

    return implode("\n", array_filter($parts, static fn(string $value): bool => trim($value) !== ''));
}

function pdf_decode_text_tokens(string $body, array $characterMap): string
{
    $text = '';
    preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)|<([0-9A-Fa-f\s]+)>|(-?\d+(?:\.\d+)?)/s', $body, $tokens, PREG_SET_ORDER);
    foreach ($tokens as $token) {
        if (isset($token[1]) && $token[1] !== '') {
            $text .= pdf_decode_literal_string($token[1]);
        } elseif (isset($token[2]) && $token[2] !== '') {
            $text .= pdf_decode_hex_string($token[2], $characterMap);
        } elseif (isset($token[3]) && (float) $token[3] < -120) {
            $text .= ' ';
        }
    }

    return $text;
}

function pdf_decode_literal_string(string $value): string
{
    $value = preg_replace_callback('/\\\\([0-7]{1,3}|[nrtbf\\\\()])/', static function (array $match): string {
        return match ($match[1]) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\b",
            'f' => "\f",
            '\\', '(', ')' => $match[1],
            default => chr(octdec($match[1])),
        };
    }, $value) ?? $value;

    return pdf_convert_encoding($value);
}

function pdf_decode_hex_string(string $value, array $characterMap): string
{
    $hex = preg_replace('/\s+/', '', $value) ?? '';
    if ($hex === '') {
        return '';
    }

    $bytes = strlen($hex) % 2 === 0 ? hex2bin($hex) : false;
    if ($bytes !== false && !str_contains($bytes, "\x00") && preg_match('/^[\x09\x0A\x0D\x20-\x7E]+$/', $bytes) === 1) {
        return $bytes;
    }

    if (strlen($hex) % 4 === 0) {
        $text = '';
        foreach (str_split($hex, 4) as $cidHex) {
            $code = hexdec($cidHex);
            $text .= $characterMap[$code] ?? utf16be_hex_to_utf8($cidHex);
        }
        return $text;
    }

    if (strlen($hex) % 2 === 1) {
        $hex .= '0';
    }

    $bytes = hex2bin($hex);
    if ($bytes === false) {
        return '';
    }

    return pdf_convert_encoding($bytes);
}

function pdf_convert_encoding(string $value): string
{
    if (str_starts_with($value, "\xFE\xFF")) {
        return iconv('UTF-16BE', 'UTF-8//IGNORE', substr($value, 2)) ?: '';
    }
    if (strlen($value) >= 2 && str_contains($value, "\x00") && substr_count($value, "\x00") >= max(1, (int) floor(strlen($value) / 3))) {
        return iconv('UTF-16BE', 'UTF-8//IGNORE', $value) ?: '';
    }
    if (preg_match('//u', $value) === 1) {
        return $value;
    }

    return iconv('Windows-1254', 'UTF-8//IGNORE', $value) ?: '';
}

function normalize_tax_text(string $value): string
{
    $value = str_replace(["\xc2\xa0", "\t"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}
