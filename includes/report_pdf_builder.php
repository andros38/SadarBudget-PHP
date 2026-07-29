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
        'savings_deposit' => [[13, 148, 136], [240, 253, 250]],
        'savings_withdrawal' => [[79, 70, 229], [238, 242, 255]],
        'savings_spend' => [[180, 83, 9], [255, 247, 237]],
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
    $height = 28;
    $bottom = $topY - $height;
    $baseline = pdf_centered_baseline($bottom, $height, 7.1);

    $pdf->rect(44, $bottom, 754, $height, [238, 243, 250]);
    $pdf->text(52, $baseline, 'TANGGAL', 7.1, true, [70, 85, 105]);
    $pdf->text(113, $baseline, 'KATEGORI / TUJUAN', 7.1, true, [70, 85, 105]);
    $pdf->text(236, $baseline, 'JENIS', 7.1, true, [70, 85, 105]);
    $pdf->text(351, $baseline, 'KETERANGAN', 7.1, true, [70, 85, 105]);
    $pdf->text(704, $baseline, 'NILAI', 7.1, true, [70, 85, 105], 'right');
    $pdf->text(790, $bottom + 16.2, 'DAMPAK UANG', 6.2, true, [70, 85, 105], 'right');
    $pdf->text(790, $bottom + 7.2, 'TERSEDIA', 6.2, true, [70, 85, 105], 'right');
}

function report_category_label(array $row): string
{
    $goalName = savings_goal_history_name(
        $row['goal_name'] ?? null,
        null,
        (int)($row['savings_goal_id'] ?? 0)
    );

    if ($row['type'] === 'savings_spend') {
        return transaction_category_history_name($row['category_name'] ?? null) . ' - ' . $goalName;
    }
    if ($row['source'] === 'savings') {
        return 'Tabungan - ' . $goalName;
    }
    return transaction_category_history_name($row['category_name'] ?? null);
}

function draw_transaction_row(SimplePdfDocument $pdf, array $row, float $rowTopY, int $index): void
{
    $rowHeight = 25;
    $rowBottom = $rowTopY - $rowHeight;

    if ($index % 2 === 1) {
        $pdf->rect(44, $rowBottom, 754, $rowHeight, [249, 251, 254]);
    }
    $pdf->line(44, $rowBottom, 798, $rowBottom, [232, 236, 243], 0.55);

    [$typeColor, $typeBg] = report_type_palette($row['type']);
    $date = date('d/m/Y', strtotime($row['transaction_date']));
    $category = report_category_label($row);
    $description = trim(normalize_user_facing_money_terms($row['description'] ?? '')) ?: '-';
    $activityAmount = transaction_amount_prefix($row['type']) . ' ' . format_rupiah($row['amount']);
    $cashEffectValue = transaction_cash_effect($row['type'], (float)$row['amount']);
    $cashEffect = ($cashEffectValue > 0 ? '+ ' : ($cashEffectValue < 0 ? '- ' : '')) . format_rupiah(abs($cashEffectValue));
    $cashColor = $cashEffectValue > 0 ? [21, 128, 61] : ($cashEffectValue < 0 ? [220, 38, 38] : [100, 116, 139]);

    $bodyBaseline = pdf_centered_baseline($rowBottom, $rowHeight, 7.7);
    $pdf->text(52, $bodyBaseline, $date, 7.7, false, [47, 59, 79]);
    $pdf->text(113, $bodyBaseline, $category, 7.7, true, [36, 48, 67], 'left', 132);

    $badgeX = 228;
    $badgeWidth = 114;
    $badgeHeight = 16;
    $badgeBottom = $rowBottom + (($rowHeight - $badgeHeight) / 2);
    $badgeBaseline = pdf_centered_baseline($badgeBottom, $badgeHeight, 6.1);
    $pdf->rect($badgeX, $badgeBottom, $badgeWidth, $badgeHeight, $typeBg);
    $pdf->text($badgeX + 8, $badgeBaseline, transaction_type_label($row['type']), 6.1, true, $typeColor, 'left', $badgeWidth - 16);

    $pdf->text(351, $bodyBaseline, $description, 7.5, false, [71, 85, 105], 'left', 250);
    $pdf->text(704, $bodyBaseline, $activityAmount, 7.7, true, $typeColor, 'right', 92);
    $pdf->text(790, $bodyBaseline, $cashEffect, 7.7, true, $cashColor, 'right', 78);
}

function draw_page_footer(SimplePdfDocument $pdf, int $pageNumber, int $pageCount): void
{
    $pdf->line(44, 38, 798, 38, [222, 228, 238], 0.7);
    $pdf->text(44, 22, 'Dibuat pada ' . date('d/m/Y H:i'), 7.5, false, [112, 126, 148]);
    $pdf->text(798, 22, 'Halaman ' . $pageNumber . ' dari ' . $pageCount, 7.5, true, [80, 94, 116], 'right');
}

function build_finance_report_pdf(array $report, string $month = '', string $type = '', string $userName = 'Pengguna'): string
{
    $firstPageCapacity = 13;
    $nextPageCapacity = 17;
    $rows = $report['rows'];
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
            $cardWidth = 142.8;
            $gap = 10;
            $labels = [
                ['Pemasukan', $report['income'], [21, 128, 61]],
                ['Pengeluaran uang', $report['expense'], [220, 38, 38]],
                ['Transfer tabungan', $report['savings_deposit'], [13, 148, 136]],
                ['Belanja tabungan', $report['savings_spend'], [180, 83, 9]],
                ['Perubahan uang', $report['cash_net'], [79, 70, 229]],
            ];
            foreach ($labels as $i => [$label, $value, $color]) {
                draw_summary_card($pdf, 44 + (($cardWidth + $gap) * $i), 438, $cardWidth, $label, format_rupiah($value), $color);
            }
            $pdf->text(44, 426, 'Pencairan ke uang tersedia: ' . format_rupiah($report['savings_withdrawal']) . '   |   Perubahan saldo keseluruhan: ' . format_rupiah($report['asset_net']), 8.0, true, [79, 70, 229]);
            $tableTop = 406;
        } else {
            $tableTop = 505;
        }

        draw_table_header($pdf, $tableTop);
        if (!$pageRows && $isFirstPage) {
            $pdf->rect(44, $tableTop - 74, 754, 46, [249, 251, 254], [232, 236, 243], 0.6);
            $pdf->text(421, $tableTop - 54, 'Tidak ada transaksi pada filter laporan ini.', 10, false, [100, 116, 139], 'center');
        } else {
            $rowTopY = $tableTop - 28;
            foreach ($pageRows as $rowIndex => $row) {
                draw_transaction_row($pdf, $row, $rowTopY, $rowIndex);
                $rowTopY -= 25;
            }
        }

        draw_page_footer($pdf, $pageIndex + 1, $pageCount);
    }

    return $pdf->output();
}
