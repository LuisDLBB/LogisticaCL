<?php

namespace App\Modules\ProviderPayments\Services;

class SimplePdf
{
    private const WIDTH = 595.28;

    private const HEIGHT = 841.89;

    /** @var array<int, string> */
    private array $pages = [];

    private ?string $jpeg = null;

    private int $imageWidth = 0;

    private int $imageHeight = 0;

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function text(float $x, float $y, string $value, float $size = 10, bool $bold = false, string $color = '222222'): void
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) ?: '';
        $escaped = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
        $this->append($this->color($color, false).sprintf(' BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            $bold ? 'F2' : 'F1', $size, $x, self::HEIGHT - $y, $escaped)."\n");
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = 'D5DDE0', float $width = 1): void
    {
        $this->append($this->color($color, true).sprintf(' %.2F w %.2F %.2F m %.2F %.2F l S',
            $width, $x1, self::HEIGHT - $y1, $x2, self::HEIGHT - $y2)."\n");
    }

    public function rect(float $x, float $y, float $width, float $height, string $fill): void
    {
        $this->append($this->color($fill, false).sprintf(' %.2F %.2F %.2F %.2F re f',
            $x, self::HEIGHT - $y - $height, $width, $height)."\n");
    }

    public function circle(float $cx, float $cy, float $radius, string $fill): void
    {
        $cy = self::HEIGHT - $cy;
        $k = $radius * 0.5522847498;
        $this->append($this->color($fill, false).sprintf(
            ' %.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c f',
            $cx + $radius, $cy,
            $cx + $radius, $cy + $k, $cx + $k, $cy + $radius, $cx, $cy + $radius,
            $cx - $k, $cy + $radius, $cx - $radius, $cy + $k, $cx - $radius, $cy,
            $cx - $radius, $cy - $k, $cx - $k, $cy - $radius, $cx, $cy - $radius,
            $cx + $k, $cy - $radius, $cx + $radius, $cy - $k, $cx + $radius, $cy
        )."\n");
    }

    public function jpeg(string $path, float $x, float $y, float $width, float $height): void
    {
        $dimensions = getimagesize($path);
        if ($dimensions === false || $dimensions[2] !== IMAGETYPE_JPEG) {
            return;
        }
        $this->jpeg = file_get_contents($path) ?: null;
        $this->imageWidth = $dimensions[0];
        $this->imageHeight = $dimensions[1];
        $this->append(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I1 Do Q',
            $width, $height, $x, self::HEIGHT - $y - $height)."\n");
    }

    public function output(): string
    {
        $count = count($this->pages);
        foreach (array_keys($this->pages) as $index) {
            $this->pages[$index] .= $this->color('7A8589', false).sprintf(
                ' BT /F1 8 Tf 1 0 0 1 515 25 Tm (Pagina %d/%d) Tj ET', $index + 1, $count
            )."\n";
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $imageReference = '';
        $next = 5;
        if ($this->jpeg !== null) {
            $objects[$next] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
                $this->imageWidth, $this->imageHeight, strlen($this->jpeg))."\nstream\n".$this->jpeg."\nendstream";
            $imageReference = '/XObject << /I1 '.$next.' 0 R >>';
            $next++;
        }
        $pageIds = [];
        foreach ($this->pages as $content) {
            $contentId = $next++;
            $pageId = $next++;
            $objects[$contentId] = '<< /Length '.strlen($content).' >>'."\nstream\n".$content.'endstream';
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> %s >> /Contents %d 0 R >>',
                self::WIDTH, self::HEIGHT, $imageReference, $contentId);
            $pageIds[] = $pageId;
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id): string => $id.' 0 R', $pageIds)).'] /Count '.$count.' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach (array_keys($objects) as $id) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF", count($objects) + 1, $xref);

        return $pdf;
    }

    private function append(string $command): void
    {
        $page = count($this->pages) - 1;
        $this->pages[$page] .= $command;
    }

    private function color(string $hex, bool $stroke): string
    {
        [$red, $green, $blue] = array_map(fn (string $part): float => hexdec($part) / 255, str_split($hex, 2));

        return sprintf('%.3F %.3F %.3F %s', $red, $green, $blue, $stroke ? 'RG' : 'rg');
    }
}
