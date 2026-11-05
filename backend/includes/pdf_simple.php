<?php
/**
 * v10.29 - Generador mínimo de PDF (sin librerías) para el "CV de
 * experiencia": cuando el postulante no sube un CV y en su lugar cuenta su
 * experiencia laboral en el formulario, se arma un PDF con una portada que
 * dice "Experiencia laboral", el nombre y el RUT de la persona, y el texto
 * que escribió -- así su carpeta siempre tiene un CV.pdf.
 *
 * Solo texto, tipografía estándar del PDF (Helvetica, codificación
 * WinAnsi: soporta tildes y ñ). Si el texto es largo, sigue en más páginas.
 */

function pdfTextoWinAnsi(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $conv = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    if ($conv === false || $conv === null) {
        $conv = preg_replace('/[^\x20-\x7E\n]/', '?', $s);
    }
    return $conv;
}

function pdfEscapar(string $s): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

/** Parte un texto en líneas de como máximo $maxCaracteres, sin cortar palabras. */
function pdfEnvolver(string $texto, int $maxCaracteres): array
{
    $lineas = [];
    foreach (explode("\n", $texto) as $parrafo) {
        $parrafo = trim($parrafo);
        if ($parrafo === '') {
            $lineas[] = '';
            continue;
        }
        $actual = '';
        foreach (preg_split('/\s+/', $parrafo) as $palabra) {
            while (strlen($palabra) > $maxCaracteres) { // palabra interminable: se corta
                if ($actual !== '') {
                    $lineas[] = $actual;
                    $actual = '';
                }
                $lineas[] = substr($palabra, 0, $maxCaracteres);
                $palabra = substr($palabra, $maxCaracteres);
            }
            if ($actual === '') {
                $actual = $palabra;
            } elseif (strlen($actual) + 1 + strlen($palabra) <= $maxCaracteres) {
                $actual .= ' ' . $palabra;
            } else {
                $lineas[] = $actual;
                $actual = $palabra;
            }
        }
        if ($actual !== '') {
            $lineas[] = $actual;
        }
    }
    return $lineas;
}

function generarPdfExperiencia(string $nombre, string $rut, string $experiencia, string $obra = ''): string
{
    $nombre = pdfTextoWinAnsi($nombre);
    $rut = pdfTextoWinAnsi($rut);
    $experiencia = pdfTextoWinAnsi($experiencia);
    $obra = pdfTextoWinAnsi($obra);

    $anchoPagina = 595;
    $altoPagina = 842;
    $margen = 56;
    $tamanoTexto = 11;
    $interlineado = 16;
    // Helvetica mide ~0.5 em de promedio; 0.55 deja margen de seguridad.
    $maxCaracteres = (int)floor(($anchoPagina - 2 * $margen) / ($tamanoTexto * 0.55));

    $paginas = [];

    // --- Portada (primera página): título, nombre y RUT -------------------
    $c = "0.15 0.15 0.15 rg\n";
    $c .= "BT /F2 24 Tf {$margen} 760 Td (" . pdfEscapar('EXPERIENCIA LABORAL') . ") Tj ET\n";
    $c .= "0.88 0.27 0.05 rg {$margen} 745 90 3 re f\n"; // línea de acento (naranjo ICAFAL)
    $c .= "0.15 0.15 0.15 rg\n";
    $c .= "BT /F1 10 Tf {$margen} 715 Td (" . pdfEscapar('NOMBRE') . ") Tj ET\n";
    $c .= "BT /F2 15 Tf {$margen} 696 Td (" . pdfEscapar($nombre) . ") Tj ET\n";
    $c .= "BT /F1 10 Tf {$margen} 668 Td (" . pdfEscapar('RUT / DOCUMENTO') . ") Tj ET\n";
    $c .= "BT /F2 15 Tf {$margen} 649 Td (" . pdfEscapar($rut) . ") Tj ET\n";
    $c .= "0.8 0.8 0.8 RG 0.7 w {$margen} 625 m " . ($anchoPagina - $margen) . " 625 l S\n";
    $c .= "0.15 0.15 0.15 rg BT /F2 12 Tf {$margen} 600 Td (" . pdfEscapar('Experiencia declarada por el postulante') . ") Tj ET\n";

    $y = 576;
    $lineas = pdfEnvolver($experiencia, $maxCaracteres);
    $c .= "BT /F1 {$tamanoTexto} Tf {$interlineado} TL {$margen} {$y} Td\n";
    $primera = true;
    $minY = 90;
    foreach ($lineas as $linea) {
        if ($y < $minY) {
            // Nueva página.
            $c .= "ET\n";
            $paginas[] = $c;
            $c = "0.15 0.15 0.15 rg\n";
            $y = $altoPagina - 70;
            $c .= "BT /F1 {$tamanoTexto} Tf {$interlineado} TL {$margen} {$y} Td\n";
            $primera = true;
        }
        if (!$primera) {
            $c .= "T*\n";
        }
        $c .= '(' . pdfEscapar($linea) . ") Tj\n";
        $primera = false;
        $y -= $interlineado;
    }
    $c .= "ET\n";
    $paginas[] = $c;

    // Pie en todas las páginas.
    $pie = 'Documento generado por la plataforma de contratacion ICAFAL' . ($obra !== '' ? ' - ' . $obra : '')
        . ' a partir de los datos ingresados por el postulante.';
    foreach ($paginas as $i => $contenido) {
        $paginas[$i] = $contenido
            . "0.5 0.5 0.5 rg BT /F1 8 Tf {$margen} 40 Td (" . pdfEscapar($pie) . ") Tj ET\n";
    }

    // --- Armado del archivo PDF ---------------------------------------------
    $objetos = [];
    $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $nPaginas = count($paginas);
    for ($i = 0; $i < $nPaginas; $i++) {
        $kids[] = (5 + $i * 2) . ' 0 R';
    }
    $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $nPaginas . ' >>';
    $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objetos[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    foreach ($paginas as $i => $contenido) {
        $idPagina = 5 + $i * 2;
        $idContenido = $idPagina + 1;
        $objetos[$idPagina] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$anchoPagina} {$altoPagina}] "
            . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$idContenido} 0 R >>";
        $objetos[$idContenido] = '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . "endstream";
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    ksort($objetos);
    foreach ($objetos as $id => $cuerpo) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$cuerpo}\nendobj\n";
    }
    $inicioXref = strlen($pdf);
    $total = count($objetos) + 1;
    $pdf .= "xref\n0 {$total}\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size {$total} /Root 1 0 R >>\nstartxref\n{$inicioXref}\n%%EOF\n";
    return $pdf;
}
