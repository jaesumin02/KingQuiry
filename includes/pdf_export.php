<?php
function pdf_escape_text(string $value): string
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('(', '\\(', $value);
    $value = str_replace(')', '\\)', $value);
    $value = str_replace("\r\n", "\n", $value);
    $value = str_replace("\n", ' ', $value);

    $converted = @iconv('UTF-8', 'CP1252//IGNORE', $value);
    return $converted === false ? $value : $converted;
}

function pdf_build_document(array $lines): string
{
    $content = "BT\n/F1 12 Tf\n";
    $yPosition = 770;
    foreach ($lines as $line) {
        if ((string) $line === '') {
            $yPosition -= 14;
            continue;
        }

        $content .= "50 {$yPosition} Td\n";
        $content .= '(' . pdf_escape_text((string) $line) . ") Tj\n";
        $yPosition -= 18;
    }
    $content .= "ET\n";

    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
        "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }

    $xrefPosition = strlen($pdf);
    $pdf .= "xref\n0 " . count($offsets) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($index = 1; $index < count($offsets); $index++) {
        $pdf .= sprintf('%010d 00000 n \n', $offsets[$index]);
    }

    $pdf .= "trailer\n";
    $pdf .= "<< /Size " . count($offsets) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xrefPosition . "\n";
    $pdf .= "%%EOF";

    return $pdf;
}
