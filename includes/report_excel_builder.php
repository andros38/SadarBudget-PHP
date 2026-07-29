<?php
/**
 * Generator XLSX mandiri untuk SadarBudget.
 *
 * Tidak membutuhkan Composer, PhpSpreadsheet, ZipArchive, atau ekstensi tambahan.
 * Paket OOXML ditulis sebagai ZIP "stored" (tanpa kompresi) menggunakan PHP murni.
 */
final class SadarBudgetZipWriter
{
    /** @var array<int,array{name:string,data:string,crc:int,size:int,offset:int,time:int,date:int}> */
    private array $entries = [];

    public function addFile(string $name, string $data): void
    {
        $name = str_replace('\\', '/', ltrim($name, '/'));
        if ($name === '' || str_contains($name, '../')) {
            throw new InvalidArgumentException('Nama berkas ZIP tidak valid.');
        }

        [$dosTime, $dosDate] = $this->dosDateTime();
        $unsignedCrc = (int)sprintf('%u', crc32($data));

        $this->entries[] = [
            'name' => $name,
            'data' => $data,
            'crc' => $unsignedCrc,
            'size' => strlen($data),
            'offset' => 0,
            'time' => $dosTime,
            'date' => $dosDate,
        ];
    }

    public function finish(): string
    {
        // Gunakan ekstensi ZIP resmi bila tersedia. Ini paling kompatibel
        // dengan Microsoft Excel pada XAMPP/Windows.
        if (class_exists('ZipArchive')) {
            $temporaryPath = tempnam(sys_get_temp_dir(), 'sadarbudget-xlsx-');
            if ($temporaryPath === false) {
                throw new RuntimeException('Gagal membuat berkas sementara XLSX.');
            }

            $zip = new ZipArchive();
            $opened = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                @unlink($temporaryPath);
                throw new RuntimeException('Gagal membuat paket XLSX.');
            }

            foreach ($this->entries as $entry) {
                if (!$zip->addFromString($entry['name'], $entry['data'])) {
                    $zip->close();
                    @unlink($temporaryPath);
                    throw new RuntimeException('Gagal menambahkan komponen XLSX: ' . $entry['name']);
                }
            }

            if (!$zip->close()) {
                @unlink($temporaryPath);
                throw new RuntimeException('Gagal menyelesaikan paket XLSX.');
            }

            $output = file_get_contents($temporaryPath);
            @unlink($temporaryPath);

            if ($output === false || $output === '') {
                throw new RuntimeException('Paket XLSX yang dihasilkan kosong.');
            }

            return $output;
        }

        // Fallback PHP murni untuk instalasi tanpa ekstensi ZipArchive.
        return $this->finishStoredZip();
    }

    private function finishStoredZip(): string
    {
        $body = '';
        $centralDirectory = '';
        $utf8Flag = 0x0800;

        foreach ($this->entries as $index => $entry) {
            $offset = strlen($body);
            $this->entries[$index]['offset'] = $offset;
            $nameLength = strlen($entry['name']);

            $body .= pack('V', 0x04034b50)
                . pack('v', 20)
                . pack('v', $utf8Flag)
                . pack('v', 0)
                . pack('v', $entry['time'])
                . pack('v', $entry['date'])
                . pack('V', $entry['crc'])
                . pack('V', $entry['size'])
                . pack('V', $entry['size'])
                . pack('v', $nameLength)
                . pack('v', 0)
                . $entry['name']
                . $entry['data'];
        }

        $centralOffset = strlen($body);

        foreach ($this->entries as $entry) {
            $nameLength = strlen($entry['name']);

            $centralDirectory .= pack('V', 0x02014b50)
                . pack('v', 20)
                . pack('v', 20)
                . pack('v', $utf8Flag)
                . pack('v', 0)
                . pack('v', $entry['time'])
                . pack('v', $entry['date'])
                . pack('V', $entry['crc'])
                . pack('V', $entry['size'])
                . pack('V', $entry['size'])
                . pack('v', $nameLength)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('V', 0)
                . pack('V', $entry['offset'])
                . $entry['name'];
        }

        $entryCount = count($this->entries);
        $centralSize = strlen($centralDirectory);

        $endOfCentralDirectory = pack('V', 0x06054b50)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $entryCount)
            . pack('v', $entryCount)
            . pack('V', $centralSize)
            . pack('V', $centralOffset)
            . pack('v', 0);

        return $body . $centralDirectory . $endOfCentralDirectory;
    }

    /** @return array{0:int,1:int} */
    private function dosDateTime(): array
    {
        $parts = getdate();
        $year = max(1980, (int)$parts['year']);
        $dosTime = ((int)$parts['hours'] << 11)
            | ((int)$parts['minutes'] << 5)
            | ((int)floor((int)$parts['seconds'] / 2));
        $dosDate = (($year - 1980) << 9)
            | ((int)$parts['mon'] << 5)
            | (int)$parts['mday'];

        return [$dosTime, $dosDate];
    }
}

function xlsx_xml_escape(mixed $value): string
{
    $text = (string)$value;
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_inline_string_cell(string $reference, mixed $value, int $styleId = 0): string
{
    $text = (string)$value;
    $space = ($text !== trim($text)) ? ' xml:space="preserve"' : '';
    return '<c r="' . $reference . '" s="' . $styleId . '" t="inlineStr"><is><t' . $space . '>'
        . xlsx_xml_escape($text)
        . '</t></is></c>';
}

function xlsx_number_cell(string $reference, float|int $value, int $styleId = 0): string
{
    $number = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    if ($number === '' || $number === '-0') {
        $number = '0';
    }
    return '<c r="' . $reference . '" s="' . $styleId . '" t="n"><v>' . $number . '</v></c>';
}

function xlsx_excel_date_serial(string $date): int
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$parsed) {
        return 0;
    }

    $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
    return (int)$base->diff($parsed)->format('%r%a');
}

function xlsx_date_cell(string $reference, string $date, int $styleId = 0): string
{
    return xlsx_number_cell($reference, xlsx_excel_date_serial($date), $styleId);
}

function xlsx_report_row_label(array $row): string
{
    $goalName = savings_goal_history_name(
        $row['goal_name'] ?? null,
        null,
        (int)($row['savings_goal_id'] ?? 0)
    );

    if ($row['type'] === 'savings_spend') {
        return transaction_category_history_name($row['category_name'] ?? null) . ' — ' . $goalName;
    }

    return $row['source'] === 'savings'
        ? 'Tabungan — ' . $goalName
        : transaction_category_history_name($row['category_name'] ?? null);
}

function build_finance_report_xlsx(array $report, string $month = '', string $type = ''): string
{
    $summaryRows = [
        ['Total pemasukan', (float)$report['income']],
        ['Pengeluaran dari uang tersedia', (float)$report['expense']],
        ['Transfer ke tabungan', (float)$report['savings_deposit']],
        ['Pencairan ke uang tersedia', (float)$report['savings_withdrawal']],
        ['Pengeluaran dari tabungan', (float)$report['savings_spend']],
        ['Perubahan uang tersedia', (float)$report['cash_net']],
        ['Perubahan saldo keseluruhan', (float)$report['asset_net']],
    ];

    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="30" customHeight="1">'
        . xlsx_inline_string_cell('A1', APP_NAME . ' — Laporan Keuangan Pribadi', 1)
        . '</row>';
    $sheetRows[] = '<row r="2">'
        . xlsx_inline_string_cell('A2', 'Periode', 2)
        . xlsx_inline_string_cell('B2', $month !== '' ? format_month_id($month . '-01') : 'Semua periode', 8)
        . '</row>';
    $sheetRows[] = '<row r="3">'
        . xlsx_inline_string_cell('A3', 'Filter jenis', 2)
        . xlsx_inline_string_cell('B3', $type !== '' ? transaction_type_label($type) : 'Semua jenis', 8)
        . '</row>';

    $rowNumber = 4;
    foreach ($summaryRows as [$label, $value]) {
        $sheetRows[] = '<row r="' . $rowNumber . '">'
            . xlsx_inline_string_cell('A' . $rowNumber, $label, 2)
            . xlsx_number_cell('B' . $rowNumber, $value, 9)
            . '</row>';
        $rowNumber++;
    }

    $headerRow = 12;
    $sheetRows[] = '<row r="11" ht="8" customHeight="1"/>';
    $sheetRows[] = '<row r="12" ht="26" customHeight="1">'
        . xlsx_inline_string_cell('A12', 'Tanggal', 3)
        . xlsx_inline_string_cell('B12', 'Kategori / Tujuan', 3)
        . xlsx_inline_string_cell('C12', 'Keterangan', 3)
        . xlsx_inline_string_cell('D12', 'Jenis', 3)
        . xlsx_inline_string_cell('E12', 'Nilai aktivitas', 3)
        . xlsx_inline_string_cell('F12', 'Dampak uang tersedia', 3)
        . '</row>';

    $rowNumber = 13;
    foreach ($report['rows'] as $row) {
        $amount = (float)$row['amount'];
        $activityValue = (transaction_amount_prefix($row['type']) === '+' ? 1 : -1) * $amount;
        $cashEffect = transaction_cash_effect($row['type'], $amount);
        $description = trim(normalize_user_facing_money_terms($row['description'] ?? ''));

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="23" customHeight="1">'
            . xlsx_date_cell('A' . $rowNumber, (string)$row['transaction_date'], 5)
            . xlsx_inline_string_cell('B' . $rowNumber, xlsx_report_row_label($row), 8)
            . xlsx_inline_string_cell('C' . $rowNumber, $description !== '' ? $description : '-', 8)
            . xlsx_inline_string_cell('D' . $rowNumber, transaction_type_label((string)$row['type']), 8)
            . xlsx_number_cell('E' . $rowNumber, $activityValue, 6)
            . xlsx_number_cell('F' . $rowNumber, $cashEffect, 6)
            . '</row>';
        $rowNumber++;
    }

    $lastRow = max(12, $rowNumber - 1);

    $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>'
        . '<dimension ref="A1:F' . $lastRow . '"/>'
        . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
        . '<pane ySplit="12" topLeftCell="A13" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A13" sqref="A13"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>'
        . '<col min="1" max="1" width="26" customWidth="1"/>'
        . '<col min="2" max="2" width="31" customWidth="1"/>'
        . '<col min="3" max="3" width="42" customWidth="1"/>'
        . '<col min="4" max="4" width="27" customWidth="1"/>'
        . '<col min="5" max="6" width="19" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        // Urutan elemen mengikuti skema SpreadsheetML. Microsoft Excel
        // mengharuskan autoFilter muncul sebelum mergeCells.
        . '<autoFilter ref="A12:F' . $lastRow . '"/>'
        . '<mergeCells count="10">'
        . '<mergeCell ref="A1:F1"/>'
        . '<mergeCell ref="B2:F2"/><mergeCell ref="B3:F3"/>'
        . '<mergeCell ref="B4:F4"/><mergeCell ref="B5:F5"/><mergeCell ref="B6:F6"/>'
        . '<mergeCell ref="B7:F7"/><mergeCell ref="B8:F8"/><mergeCell ref="B9:F9"/><mergeCell ref="B10:F10"/>'
        . '</mergeCells>'
        . '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        . '<numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0;[Red]-&quot;Rp&quot; #,##0;&quot;Rp&quot; 0"/>'
        . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
        . '</numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF172033"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF3730A3"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDE9FE"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD8E0EB"/></left><right style="thin"><color rgb="FFD8E0EB"/></right><top style="thin"><color rgb="FFD8E0EB"/></top><bottom style="thin"><color rgb="FFD8E0EB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="3" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $packageRelationshipsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<fileVersion appName="xl" lastEdited="7" lowestEdited="7" rupBuild="0"/>'
        . '<workbookPr date1904="0"/>'
        . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="14000"/></bookViews>'
        . '<sheets><sheet name="Laporan" sheetId="1" r:id="rId1"/></sheets>'
        . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';

    $workbookRelationshipsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $corePropertiesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xlsx_xml_escape(APP_NAME . ' — Laporan Keuangan Pribadi') . '</dc:title>'
        . '<dc:creator>' . xlsx_xml_escape(APP_NAME) . '</dc:creator>'
        . '<cp:lastModifiedBy>' . xlsx_xml_escape(APP_NAME) . '</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appPropertiesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>SadarBudget</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
        . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
        . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Laporan</vt:lpstr></vt:vector></TitlesOfParts>'
        . '<Company></Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>1.0</AppVersion>'
        . '</Properties>';

    $zip = new SadarBudgetZipWriter();
    $zip->addFile('[Content_Types].xml', $contentTypesXml);
    $zip->addFile('_rels/.rels', $packageRelationshipsXml);
    $zip->addFile('docProps/app.xml', $appPropertiesXml);
    $zip->addFile('docProps/core.xml', $corePropertiesXml);
    $zip->addFile('xl/workbook.xml', $workbookXml);
    $zip->addFile('xl/_rels/workbook.xml.rels', $workbookRelationshipsXml);
    $zip->addFile('xl/styles.xml', $stylesXml);
    $zip->addFile('xl/worksheets/sheet1.xml', $worksheetXml);

    return $zip->finish();
}
