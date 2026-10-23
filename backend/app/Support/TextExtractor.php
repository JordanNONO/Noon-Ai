<?php

namespace App\Support;

use Smalot\PdfParser\Parser;

/** Extrait le texte des fichiers joints (PDF, Word, texte, CSV…). */
class TextExtractor
{
    public static function fromBytes(string $bytes, string $mime, string $name = ''): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        try {
            if ($mime === 'application/pdf' || $ext === 'pdf') {
                return trim((new Parser)->parseContent($bytes)->getText());
            }
            if (in_array($ext, ['docx', 'pptx', 'xlsx']) || str_contains($mime, 'officedocument')) {
                return self::office($bytes, $ext);
            }
            if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'md', 'csv', 'json', 'xml', 'html', 'log', 'php', 'js', 'ts', 'py', 'sql'])) {
                return mb_convert_encoding($bytes, 'UTF-8', 'UTF-8, ISO-8859-1');
            }
        } catch (\Throwable $e) {
            return '[Lecture du fichier impossible : '.$e->getMessage().']';
        }

        return '';
    }

    private static function office(string $bytes, string $ext): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'opx');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return '';
        }
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^(word/document\.xml|ppt/slides/slide\d+\.xml|xl/sharedStrings\.xml)$#', $n)) {
                $xml = $zip->getFromIndex($i);
                $xml = preg_replace('#</w:p>|</a:p>|</si>#', "\n", $xml);
                $parts[$n] = trim(html_entity_decode(strip_tags($xml)));
            }
        }
        $zip->close();
        @unlink($tmp);
        ksort($parts, SORT_NATURAL);

        return trim(implode("\n\n", $parts));
    }
}
