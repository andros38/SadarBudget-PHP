<?php
/**
 * Generator PDF ringan tanpa library eksternal.
 * Mendukung teks Helvetica, bentuk dasar, multi-halaman, dan warna RGB.
 */
final class SimplePdfDocument
{
    private float $width;
    private float $height;
    private array $pages = [];
    private array $current = [];

    public function __construct(float $width = 842, float $height = 595)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function addPage(): void
    {
        if ($this->current) {
            $this->pages[] = implode("\n", $this->current);
        }
        $this->current = [];
    }

    public function pageWidth(): float
    {
        return $this->width;
    }

    public function pageHeight(): float
    {
        return $this->height;
    }

    public function rect(float $x, float $y, float $w, float $h, array $fill, ?array $stroke = null, float $lineWidth = 1): void
    {
        $commands = ['q'];
        $commands[] = $this->rgb($fill) . ' rg';
        if ($stroke !== null) {
            $commands[] = $this->rgb($stroke) . ' RG';
            $commands[] = $this->num($lineWidth) . ' w';
            $operator = 'B';
        } else {
            $operator = 'f';
        }
        $commands[] = sprintf('%s %s %s %s re %s', $this->num($x), $this->num($y), $this->num($w), $this->num($h), $operator);
        $commands[] = 'Q';
        $this->current[] = implode("\n", $commands);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [220, 226, 235], float $lineWidth = 1): void
    {
        $this->current[] = implode("\n", [
            'q',
            $this->rgb($color) . ' RG',
            $this->num($lineWidth) . ' w',
            sprintf('%s %s m %s %s l S', $this->num($x1), $this->num($y1), $this->num($x2), $this->num($y2)),
            'Q',
        ]);
    }

    public function text(
        float $x,
        float $y,
        string $text,
        float $size = 10,
        bool $bold = false,
        array $color = [28, 39, 60],
        string $align = 'left',
        ?float $maxWidth = null
    ): void {
        $text = $this->sanitize($text);
        if ($maxWidth !== null) {
            $text = $this->truncate($text, $size, $maxWidth, $bold);
        }
        $estimatedWidth = $this->estimateTextWidth($text, $size, $bold);
        if ($align === 'right') {
            $x -= $estimatedWidth;
        } elseif ($align === 'center') {
            $x -= $estimatedWidth / 2;
        }

        $font = $bold ? '/F2' : '/F1';
        $this->current[] = implode("\n", [
            'BT',
            $this->rgb($color) . ' rg',
            sprintf('%s %s Tf', $font, $this->num($size)),
            sprintf('1 0 0 1 %s %s Tm', $this->num($x), $this->num($y)),
            '(' . $this->escape($text) . ') Tj',
            'ET',
        ]);
    }

    public function output(): string
    {
        if ($this->current || !$this->pages) {
            $this->pages[] = implode("\n", $this->current);
            $this->current = [];
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pageIds = [];
        $nextId = 5;
        foreach ($this->pages as $stream) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->num($this->width),
                $this->num($this->height),
                $contentId
            );
            $objects[$contentId] = "<< /Length " . strlen($stream) . ">>\nstream\n{$stream}\nendstream";
        }

        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        $maxId = max(array_keys($objects));
        for ($id = 1; $id <= $maxId; $id++) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";
        return $pdf;
    }

    private function sanitize(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
        return str_replace(["\r", "\n", "\t"], ' ', $text);
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function truncate(string $text, float $size, float $maxWidth, bool $bold = false): string
    {
        if ($this->estimateTextWidth($text, $size, $bold) <= $maxWidth) {
            return $text;
        }

        $suffix = '...';
        while ($text !== '' && $this->estimateTextWidth($text . $suffix, $size, $bold) > $maxWidth) {
            $text = substr($text, 0, -1);
        }

        return rtrim($text) . $suffix;
    }

    /**
     * Mengukur teks menggunakan metrik AFM Helvetica/Helvetica-Bold.
     * Ini membuat alignment kanan dan tengah jauh lebih presisi daripada
     * perkiraan berbasis jumlah karakter.
     */
    private function estimateTextWidth(string $text, float $size, bool $bold = false): float
    {
        $regular = [
            32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,
            40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
            48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,
            58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,64=>1015,
            65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,
            75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,82=>722,83=>667,84=>611,
            85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,
            91=>278,92=>278,93=>278,94=>469,95=>556,96=>333,
            97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,
            107=>500,108=>222,109=>833,110=>556,111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,
            117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
        ];
        $boldWidths = [
            32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,
            40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
            48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,
            58=>333,59=>333,60=>584,61=>584,62=>584,63=>611,64=>975,
            65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>556,
            75=>722,76=>611,77=>833,78=>722,79=>778,80=>667,81=>778,82=>722,83=>667,84=>611,
            85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,
            91=>333,92=>278,93=>333,94=>584,95=>556,96=>333,
            97=>556,98=>611,99=>556,100=>611,101=>556,102=>333,103=>611,104=>611,105=>278,106=>278,
            107=>556,108=>278,109=>889,110=>611,111=>611,112=>611,113=>611,114=>389,115=>556,116=>333,
            117=>611,118=>556,119=>778,120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584,
        ];

        $widths = $bold ? $boldWidths : $regular;
        $total = 0;
        foreach (unpack('C*', $text) ?: [] as $code) {
            $total += $widths[$code] ?? 556;
        }

        return ($total / 1000) * $size;
    }

    private function rgb(array $color): string
    {
        return implode(' ', array_map(fn($value): string => $this->num(max(0, min(255, (int)$value)) / 255), $color));
    }

    private function num(float|int $number): string
    {
        return rtrim(rtrim(number_format((float)$number, 3, '.', ''), '0'), '.');
    }
}
