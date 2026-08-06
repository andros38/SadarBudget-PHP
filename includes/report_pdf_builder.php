<?php
require_once __DIR__ . '/pdf_document.php';

function report_period_label(string $month): string
{
    if ($month === '') {
        return 'Semua periode';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m', $month);
    return $date ? $date->format('m/Y') : $month;
}

function pdf_centered_baseline(float $boxBottom, float $boxHeight, float $fontSize): float
{
    return $boxBottom + ($boxHeight / 2) - ($fontSize * 0.25);
}

function report_type_palette(string $type): array
{
    return match ($type) {
        'income' => [[21, 128, 61], [236, 253, 243]],
        'expense' => [[220, 38, 38], [254, 242, 242]],
        default => [[70, 85, 105], [241, 245, 249]],
    };
}

function draw_report_header(SimplePdfDocument $pdf, string $period, string $typeLabel, string $userName, bool $compact = false): void
{
    $pageWidth = $pdf->pageWidth();
    $headerY = $compact ? 520 : 512;
    $headerHeight = $compact ? 46 : 58;
    $headerBlockHeight = $headerHeight + ($compact ? 29 : 25);

    $pdf->rect(0, $headerY, $pageWidth, $headerBlockHeight, [55, 48, 163]);
    $pdf->rect(0, $headerY, 9, $headerBlockHeight, [13, 148, 136]);
    $pdf->text(44, $headerY + $headerHeight - 10, strtoupper(APP_NAME), $compact ? 16 : 19, true, [255, 255, 255]);
    $pdf->text(44, $headerY + $headerHeight - 29, 'Laporan transaksi keuangan pribadi', 9, false, [218, 228, 255]);
    $pdf->rect(44, $headerY + 9, 5, 5, [45, 212, 191]);
    $pdf->text(57, $headerY + 7.5, 'Pengguna: ' . ($userName !== '' ? $userName : 'Pengguna'), 8.2, true, [153, 246, 228], 'left', 360);
    $pdf->text($pageWidth - 44, $headerY + $headerHeight - 10, 'Periode: ' . $period, 9, true, [255, 255, 255], 'right');
    $pdf->text($pageWidth - 44, $headerY + $headerHeight - 29, 'Jenis: ' . $typeLabel, 8.5, false, [218, 228, 255], 'right');
}

function draw_summary_card(SimplePdfDocument $pdf, float $x, float $y, float $w, string $label, string $value, array $accent): void
{
    $pdf->rect($x, $y, $w, 52, [255, 255, 255], [222, 228, 238], 0.8);
    $pdf->rect($x, $y, 4, 52, $accent);
    $pdf->text($x + 12, $y + 34, strtoupper($label), 5.9, true, [99, 113, 135], 'left', $w - 18);
    $pdf->text($x + 12, $y + 13, $value, 9.7, true, [24, 34, 52], 'left', $w - 18);
}

function draw_table_header(SimplePdfDocument $pdf, float $topY): void
{
    $height = 26;
    $bottom = $topY - $height;
    $baseline = pdf_centered_baseline($bottom, $height, 6.8);

    $pdf->rect(44, $bottom, 754, $height, [238, 243, 250]);
    $pdf->text(52, $baseline, 'TANGGAL', 6.8, true, [70, 85, 105]);
    $pdf->text(112, $baseline, 'KATEGORI', 6.8, true, [70, 85, 105]);
    $pdf->text(244, $baseline, 'KETERANGAN', 6.8, true, [70, 85, 105]);
    $pdf->text(538, $baseline, 'JENIS', 6.8, true, [70, 85, 105], 'center');
    $pdf->text(690, $baseline, 'NOMINAL', 6.8, true, [70, 85, 105], 'right');
    $pdf->text(790, $bottom + 16.0, 'SALDO', 6.1, true, [70, 85, 105], 'right');
    $pdf->text(790, $bottom + 7.2, 'BERJALAN', 5.8, true, [70, 85, 105], 'right');
}

function report_category_label(array $row): string
{
    return transaction_category_history_name($row['category_name'] ?? null);
}

function draw_transaction_row(SimplePdfDocument $pdf, array $row, float $rowTopY, int $index): void
{
    $rowHeight = 18;
    $rowBottom = $rowTopY - $rowHeight;

    if ($index % 2 === 1) {
        $pdf->rect(44, $rowBottom, 754, $rowHeight, [249, 251, 254]);
    }
    $pdf->line(44, $rowBottom, 798, $rowBottom, [232, 236, 243], 0.55);

    [$typeColor, $typeBg] = report_type_palette($row['type']);
    $date = date('d/m/Y', strtotime($row['transaction_date']));
    $category = report_category_label($row);
    $description = trim(normalize_user_facing_money_terms($row['description'] ?? '')) ?: '-';

    // Nominal adalah besar transaksi pada baris tersebut. Saldo setelah
    // transaksi adalah saldo berjalan setelah pemasukan/pengeluaran diterapkan.
    $nominal = format_rupiah(abs((float)$row['amount']));
    $balanceAfterValue = (float)($row['balance_after'] ?? 0);
    $balanceAfter = ($balanceAfterValue < 0 ? '- ' : '') . format_rupiah(abs($balanceAfterValue));
    $balanceColor = $balanceAfterValue < 0 ? [220, 38, 38] : [55, 48, 163];

    $bodyBaseline = pdf_centered_baseline($rowBottom, $rowHeight, 6.8);
    $pdf->text(52, $bodyBaseline, $date, 6.8, false, [47, 59, 79], 'left', 54);
    $pdf->text(112, $bodyBaseline, $category, 6.8, true, [36, 48, 67], 'left', 124);
    $pdf->text(244, $bodyBaseline, $description, 6.7, false, [71, 85, 105], 'left', 238);

    $badgeWidth = 82;
    $badgeHeight = 13;
    $badgeX = 497;
    $badgeBottom = $rowBottom + (($rowHeight - $badgeHeight) / 2);
    $badgeBaseline = pdf_centered_baseline($badgeBottom, $badgeHeight, 5.4);
    $pdf->rect($badgeX, $badgeBottom, $badgeWidth, $badgeHeight, $typeBg);
    $pdf->text($badgeX + ($badgeWidth / 2), $badgeBaseline, transaction_type_label($row['type']), 5.4, true, $typeColor, 'center', $badgeWidth - 12);

    $pdf->text(690, $bodyBaseline, $nominal, 6.8, true, [36, 48, 67], 'right', 92);
    $pdf->text(790, $bodyBaseline, $balanceAfter, 6.8, true, $balanceColor, 'right', 92);
}

function draw_page_footer(SimplePdfDocument $pdf, int $pageNumber, int $pageCount): void
{
    $pdf->line(44, 38, 798, 38, [222, 228, 238], 0.7);
    $pdf->text(44, 22, 'Dibuat pada ' . date('d/m/Y H:i'), 7.5, false, [112, 126, 148]);
    $pdf->text(798, 22, 'Halaman ' . $pageNumber . ' dari ' . $pageCount, 7.5, true, [80, 94, 116], 'right');
}

function build_finance_report_pdf(array $report, string $month = '', string $type = '', string $userName = 'Pengguna'): string
{
    $firstPageCapacity = 19;
    $nextPageCapacity = 24;
    // Laporan ekspor dibaca kronologis agar perubahan saldo berjalan terlihat
    // alami dari transaksi terlama menuju transaksi terbaru.
    $rows = array_reverse($report['rows']);
    $pageChunks = [array_splice($rows, 0, $firstPageCapacity)];
    while ($rows) {
        $pageChunks[] = array_splice($rows, 0, $nextPageCapacity);
    }

    $pdf = new SimplePdfDocument(842, 595);
    $pageCount = count($pageChunks);
    $period = report_period_label($month);
    $typeLabel = $type ? transaction_type_label($type) : 'Semua jenis';

    foreach ($pageChunks as $pageIndex => $pageRows) {
        $pdf->addPage();
        $isFirstPage = $pageIndex === 0;
        draw_report_header($pdf, $period, $typeLabel, $userName, !$isFirstPage);

        if ($isFirstPage) {
            $cardWidth = (754 - 20) / 3;
            $gap = 10;
            $labels = [
                ['Pemasukan', $report['income'], [21, 128, 61]],
                ['Pengeluaran', $report['expense'], [220, 38, 38]],
                ['Perubahan saldo', $report['net'], [79, 70, 229]],
            ];
            foreach ($labels as $i => [$label, $value, $color]) {
                draw_summary_card($pdf, 44 + (($cardWidth + $gap) * $i), 438, $cardWidth, $label, format_rupiah($value), $color);
            }
            $pdf->text(44, 426, 'Nominal adalah nilai transaksi. Saldo setelah menunjukkan saldo berjalan sesudah transaksi diterapkan.', 7.3, true, [79, 70, 229]);
            $tableTop = 408;
        } else {
            $tableTop = 505;
        }

        draw_table_header($pdf, $tableTop);
        if (!$pageRows && $isFirstPage) {
            $pdf->rect(44, $tableTop - 74, 754, 46, [249, 251, 254], [232, 236, 243], 0.6);
            $pdf->text(421, $tableTop - 54, 'Tidak ada transaksi pada filter laporan ini.', 10, false, [100, 116, 139], 'center');
        } else {
            $rowTopY = $tableTop - 26;
            foreach ($pageRows as $rowIndex => $row) {
                draw_transaction_row($pdf, $row, $rowTopY, $rowIndex);
                $rowTopY -= 18;
            }
        }

        draw_page_footer($pdf, $pageIndex + 1, $pageCount);
    }

    return $pdf->output();
}
