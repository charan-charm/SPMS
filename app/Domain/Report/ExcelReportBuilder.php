<?php

declare(strict_types=1);

namespace App\Domain\Report;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use ZipArchive;

/**
 * F8 — High-Fidelity OpenXML Excel Report Generator (.xlsx).
 *
 * Produces clean, multi-sheet, presentation-ready household energy reports
 * with DrawingML native charts, verified provenance disclosures, and
 * professional typography and layout.
 *
 * Sheets:
 * 1. Summary — Executive KPIs, plain-English insights, data trust disclosures
 * 2. Monthly Trends — Tabular daily time series with native DrawingML line charts
 * 3. Billing — APSPDCL statements with human-readable headers and verification statuses
 * 4. Solar Data — Row-by-row streaming of GoodWe interval telemetry
 */
final class ExcelReportBuilder
{
    private const TIMEZONE = 'Asia/Kolkata';

    /**
     * Builds and returns raw XLSX archive binary string.
     *
     * @param array<string, mixed> $reportData Output of MonthlyReportService::generateReport()
     */
    public function build(array $reportData, string $householdId, PDO $pdo): string
    {
        $tmpPath = sys_get_temp_dir() . '/spms_report_' . bin2hex(random_bytes(8)) . '.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to initialize XLSX zip archive in temporary storage.');
        }

        try {
            $hasSolarDaily = false;
            $hasDemandDaily = false;
            $dailyCount = count($reportData['daily_trends'] ?? []);

            foreach ($reportData['daily_trends'] ?? [] as $trend) {
                if ($trend['solar_generation_kwh'] !== null) {
                    $hasSolarDaily = true;
                }
                if ($trend['estimated_demand_kwh'] !== null) {
                    $hasDemandDaily = true;
                }
            }

            // We include Chart 1 if we have daily solar data
            $includeChart1 = $hasSolarDaily && $dailyCount > 0;
            // We include Chart 2 if we have both solar and estimated demand data
            $includeChart2 = $hasSolarDaily && $hasDemandDaily && $dailyCount > 0;
            $hasCharts = $includeChart1;

            // 1. [Content_Types].xml
            $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml($hasCharts, $includeChart2));

            // 2. _rels/.rels
            $zip->addFromString('_rels/.rels', $this->buildRootRelsXml());

            // 3. xl/workbook.xml & xl/_rels/workbook.xml.rels
            $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelsXml());

            // 4. xl/styles.xml
            $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

            // 5. xl/worksheets/sheet1.xml (Summary)
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildSummarySheetXml($reportData));

            // 6. xl/worksheets/sheet2.xml (Monthly Trends)
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->buildMonthlyTrendsSheetXml($reportData, $hasCharts));

            if ($hasCharts) {
                $zip->addFromString('xl/worksheets/_rels/sheet2.xml.rels', $this->buildSheet2RelsXml());
                $zip->addFromString('xl/drawings/drawing1.xml', $this->buildDrawingXml($includeChart2));
                $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->buildDrawingRelsXml($includeChart2));
                $zip->addFromString('xl/charts/chart1.xml', $this->buildChart1Xml($reportData['period']['label'], $dailyCount));

                if ($includeChart2) {
                    $zip->addFromString('xl/charts/chart2.xml', $this->buildChart2Xml($reportData['period']['label'], $dailyCount));
                }
            }

            // 7. xl/worksheets/sheet3.xml (Billing)
            $zip->addFromString('xl/worksheets/sheet3.xml', $this->buildBillingSheetXml($reportData));

            // 8. xl/worksheets/sheet4.xml (Solar Telemetry - streamed directly into XML string)
            $zip->addFromString('xl/worksheets/sheet4.xml', $this->buildSolarDataSheetXml($reportData, $householdId, $pdo));

            $zip->close();

            $binary = file_get_contents($tmpPath);
            if ($binary === false) {
                throw new \RuntimeException('Failed to read generated XLSX binary.');
            }

            return $binary;
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function buildContentTypesXml(bool $hasCharts, bool $hasChart2): string
    {
        $chartOverrides = '';
        if ($hasCharts) {
            $chartOverrides .= '  <Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' . "\n";
            $chartOverrides .= '  <Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>' . "\n";
            if ($hasChart2) {
                $chartOverrides .= '  <Override PartName="/xl/charts/chart2.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>' . "\n";
            }
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
{$chartOverrides}</Types>
XML;
    }

    private function buildRootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private function buildWorkbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Summary" sheetId="1" r:id="rId1"/>
    <sheet name="Monthly Trends" sheetId="2" r:id="rId2"/>
    <sheet name="Billing" sheetId="3" r:id="rId3"/>
    <sheet name="Solar Data" sheetId="4" r:id="rId4"/>
  </sheets>
</workbook>
XML;
    }

    private function buildWorkbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>
  <Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function buildStylesXml(): string
    {
        // Style definitions:
        // Fonts:
        // 0: Normal 10pt Segoe UI (#334155)
        // 1: Bold 10pt Segoe UI (#0F172A)
        // 2: Bold 14pt Segoe UI (#0F172A) - Title
        // 3: Bold 11pt Segoe UI (#0284C7) - Subtitle / Accent
        // 4: Regular 9pt Segoe UI (#64748B) - Muted
        // 5: Bold 10pt Segoe UI (#059669) - Emerald / Success
        // 6: Bold 10pt Segoe UI (#D97706) - Amber / Warning
        // 7: Bold 10pt Segoe UI (#FFFFFF) - White Header
        //
        // Fills:
        // 0: None
        // 1: Gray125
        // 2: Slate 900 header (#0F172A)
        // 3: Slate 100 subtle fill (#F1F5F9)
        // 4: Emerald 50 tint (#ECFDF5)
        // 5: Amber 50 tint (#FEF3C7)
        // 6: Sky 50 tint (#F0F9FF)
        //
        // Borders:
        // 0: None
        // 1: Thin border (#CBD5E1)
        // 2: Double bottom border
        //
        // NumFmts:
        // 164: "₹ " #,##0.00
        // 165: 0.00
        // 166: +0.00;-0.00;0.00
        //
        // Xfs:
        // 0: default
        // 1: Title (Font 2, no fill)
        // 2: Subtitle (Font 3, no fill)
        // 3: Muted subtitle (Font 4, no fill)
        // 4: Table header dark (Font 7, Fill 2, Border 1, Center/Left)
        // 5: Table cell text regular (Font 0, Border 1)
        // 6: Table cell text bold (Font 1, Border 1)
        // 7: Table cell numeric (Font 0, Border 1, numFmt 165, Right)
        // 8: Table cell currency (Font 0, Border 1, numFmt 164, Right)
        // 9: Section header bar (Font 1, Fill 3, Border 1)
        // 10: Badge Emerald (Font 5, Fill 4, Border 1, Center)
        // 11: Badge Amber (Font 6, Fill 5, Border 1, Center)
        // 12: Badge Sky (Font 3, Fill 6, Border 1, Center)
        // 13: Numeric 2-decimal bold (Font 1, Border 1, numFmt 165, Right)
        // 14: Numeric signed (Font 1, Border 1, numFmt 166, Right)
        // 15: Table header light subtle (Font 1, Fill 3, Border 1)
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="3">
    <numFmt numFmtId="164" formatCode="&quot;₹ &quot;#,##0.00"/>
    <numFmt numFmtId="165" formatCode="0.00"/>
    <numFmt numFmtId="166" formatCode="+0.00;-0.00;0.00"/>
  </numFmts>
  <fonts count="8">
    <font><sz val="10"/><color rgb="FF334155"/><name val="Segoe UI"/><family val="2"/></font>
    <font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/><family val="2"/></font>
    <font><b/><sz val="14"/><color rgb="FF0F172A"/><name val="Segoe UI"/><family val="2"/></font>
    <font><b/><sz val="11"/><color rgb="FF0284C7"/><name val="Segoe UI"/><family val="2"/></font>
    <font><sz val="9"/><color rgb="FF64748B"/><name val="Segoe UI"/><family val="2"/></font>
    <font><b/><sz val="10"/><color rgb="FF059669"/><name val="Segoe UI"/><family val="2"/></font>
    <font><b/><sz val="10"/><color rgb="FFD97706"/><name val="Segoe UI"/><family val="2"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/><family val="2"/></font>
  </fonts>
  <fills count="7">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F9FF"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="3">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFE2E8F0"/></left>
      <right style="thin"><color rgb="FFE2E8F0"/></right>
      <top style="thin"><color rgb="FFE2E8F0"/></top>
      <bottom style="thin"><color rgb="FFE2E8F0"/></bottom>
      <diagonal/>
    </border>
    <border>
      <left/><right/><top style="thin"><color rgb="FFE2E8F0"/></top>
      <bottom style="double"><color rgb="FF0F172A"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="16">
    <!-- 0: default -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <!-- 1: title -->
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <!-- 2: subtitle -->
    <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <!-- 3: muted subtitle -->
    <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <!-- 4: dark table header -->
    <xf numFmtId="0" fontId="7" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
    <!-- 5: regular text cell -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
    <!-- 6: bold text cell -->
    <xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
    <!-- 7: numeric 2-dec cell -->
    <xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <!-- 8: currency cell -->
    <xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <!-- 9: section header bar -->
    <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
    <!-- 10: emerald badge -->
    <xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <!-- 11: amber badge -->
    <xf numFmtId="0" fontId="6" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <!-- 12: sky badge -->
    <xf numFmtId="0" fontId="3" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <!-- 13: numeric bold -->
    <xf numFmtId="165" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <!-- 14: numeric signed -->
    <xf numFmtId="166" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <!-- 15: light table header -->
    <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
  </cellXfs>
</styleSheet>
XML;
    }

    /**
     * Sheet 1: Summary.
     *
     * @param array<string, mixed> $d
     */
    private function buildSummarySheetXml(array $d): string
    {
        $k = $d['kpis'];
        $p = $d['period'];
        $ins = $d['insight'];
        $genTime = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('d M Y, h:i A T');

        $rows = [];
        $r = 1;

        // Title block
        $rows[] = "<row r=\"{$r}\" ht=\"24\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"1\"><is><t>SPMS — Solar Power Management System</t></is></c></row>";
        $r++;
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"2\"><is><t>Monthly Household Energy Report • {$this->xml($p['label'])}</t></is></c></row>";
        $r++;
        $rows[] = "<row r=\"{$r}\" ht=\"18\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"3\"><is><t>Report Period: {$p['start_date']} to {$p['end_date']} • Generated: {$genTime} • Canonical Timezone: Asia/Kolkata</t></is></c></row>";
        $r++;
        $r++; // Empty row

        // Executive KPIs section
        $rows[] = "<row r=\"{$r}\" ht=\"22\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"9\"><is><t>EXECUTIVE ENERGY SUMMARY</t></is></c><c r=\"B{$r}\" s=\"9\"/><c r=\"C{$r}\" s=\"9\"/><c r=\"D{$r}\" s=\"9\"/><c r=\"E{$r}\" s=\"9\"/></row>";
        $r++;

        $rows[] = "<row r=\"{$r}\" ht=\"22\" customHeight=\"1\">"
            . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"4\"><is><t>Key Metric</t></is></c>"
            . "<c r=\"B{$r}\" t=\"inlineStr\" s=\"4\"><is><t>Quantity / Amount</t></is></c>"
            . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"4\"><is><t>Unit</t></is></c>"
            . "<c r=\"D{$r}\" t=\"inlineStr\" s=\"4\"><is><t>Data Nature</t></is></c>"
            . "<c r=\"E{$r}\" t=\"inlineStr\" s=\"4\"><is><t>Provenance &amp; Trust Lineage</t></is></c>"
            . "</row>";
        $r++;

        // 1. Solar Generation
        $solarVal = $k['solar_generation_kwh'] !== null ? (string) $k['solar_generation_kwh'] : 'N/A';
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\">"
            . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Solar Generation</t></is></c>"
            . ($k['solar_generation_kwh'] !== null
                ? "<c r=\"B{$r}\" s=\"13\"><v>{$solarVal}</v></c>"
                : "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>N/A</t></is></c>")
            . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>kWh</t></is></c>"
            . "<c r=\"D{$r}\" t=\"inlineStr\" s=\"10\"><is><t>ACTUAL</t></is></c>"
            . "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t>GoodWe Inverter SEMS Telemetry</t></is></c>"
            . "</row>";
        $r++;

        // 2. Household Demand
        $demandVal = $k['household_demand_kwh'] !== null ? (string) $k['household_demand_kwh'] : 'Unavailable';
        $demandSrc = match ($k['demand_source'] ?? '') {
            'VERIFIED_BILL_STATEMENT' => 'APSPDCL Verified Utility Statement',
            'HISTORICAL_VERIFIED_BASELINE' => 'Historical Verified Billing Baseline',
            default => 'Unavailable (Requires verified utility statement)',
        };
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\">"
            . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Estimated Household Demand</t></is></c>"
            . ($k['household_demand_kwh'] !== null
                ? "<c r=\"B{$r}\" s=\"13\"><v>{$demandVal}</v></c>"
                : "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>Unavailable</t></is></c>")
            . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>kWh</t></is></c>"
            . "<c r=\"D{$r}\" t=\"inlineStr\" s=\"11\"><is><t>ESTIMATED</t></is></c>"
            . "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml($demandSrc)}</t></is></c>"
            . "</row>";
        $r++;

        // 3. Potential Energy Balance
        $balVal = $k['potential_balance_kwh'] !== null ? (string) $k['potential_balance_kwh'] : 'N/A';
        $balLabel = match ($k['balance_status'] ?? '') {
            'SURPLUS' => 'Net Analytical Surplus (+)',
            'DEFICIT' => 'Net Analytical Deficit (-)',
            'BALANCED' => 'Analytical Balance',
            default => 'Unavailable',
        };
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\">"
            . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Potential Energy Balance</t></is></c>"
            . ($k['potential_balance_kwh'] !== null
                ? "<c r=\"B{$r}\" s=\"14\"><v>{$balVal}</v></c>"
                : "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>N/A</t></is></c>")
            . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>kWh</t></is></c>"
            . "<c r=\"D{$r}\" t=\"inlineStr\" s=\"12\"><is><t>POTENTIAL</t></is></c>"
            . "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml($balLabel)} (Does not claim measured grid feed-in)</t></is></c>"
            . "</row>";
        $r++;

        // 4. Latest Verified Bill (distinct from calendar-month energy cost)
        $billVal = 'Unavailable';
        $billSrc = 'No verified utility statement recorded for this period';
        $billStatus = 'UNAVAILABLE';
        if (!empty($k['latest_verified_bill']) && $k['latest_verified_bill']['amount'] !== null) {
            $billVal = (string) $k['latest_verified_bill']['amount'];
            $lbl = $k['latest_verified_bill']['billing_period_label'] ?? '';
            $billSrc = "APSPDCL Statement (Billing Period: {$lbl})";
            $billStatus = 'VERIFIED';
        } elseif ($k['energy_cost_inr'] !== null) {
            $billVal = (string) $k['energy_cost_inr'];
            $billSrc = ($k['cost_status'] ?? '') === 'VERIFIED_STATEMENT_TOTAL' ? 'APSPDCL Verified Statement Total' : 'Estimated from Canonical Tariff';
            $billStatus = ($k['cost_status'] ?? '') === 'VERIFIED_STATEMENT_TOTAL' ? 'VERIFIED' : 'ESTIMATED';
        }

        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\">"
            . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Latest Verified Bill</t></is></c>"
            . ($billVal !== 'Unavailable'
                ? "<c r=\"B{$r}\" s=\"8\"><v>{$billVal}</v></c>"
                : "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>Unavailable</t></is></c>")
            . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>₹ (INR)</t></is></c>"
            . "<c r=\"D{$r}\" t=\"inlineStr\" s=\"" . ($billStatus === 'VERIFIED' ? '10' : '11') . "\"><is><t>{$billStatus}</t></is></c>"
            . "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml($billSrc)}</t></is></c>"
            . "</row>";
        $r++;

        // 5. Billing Trust Status
        $vCount = $k['bills_summary']['verified_count'] ?? 0;
        $aCount = $k['bills_summary']['awaiting_review_count'] ?? 0;
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\">"
            . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Utility Billing Records</t></is></c>"
            . "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$vCount} verified, {$aCount} awaiting</t></is></c>"
            . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>Statements</t></is></c>"
            . "<c r=\"D{$r}\" t=\"inlineStr\" s=\"" . ($vCount > 0 ? '10' : '11') . "\"><is><t>" . ($vCount > 0 ? 'VERIFIED' : 'NEEDS_REVIEW') . "</t></is></c>"
            . "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t>Only verified bills are used for downstream analytical calculations</t></is></c>"
            . "</row>";
        $r++;

        // 6. Solar Coverage Status
        $sc = $k['solar_coverage'];
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\">"
            . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Solar Inverter Coverage</t></is></c>"
            . "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$sc['days_with_data']} of {$sc['total_days_in_month']} days</t></is></c>"
            . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$sc['readings_count']} readings</t></is></c>"
            . "<c r=\"D{$r}\" t=\"inlineStr\" s=\"" . ($sc['data_status'] === 'FRESH' ? '10' : '11') . "\"><is><t>{$sc['data_status']}</t></is></c>"
            . "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t>Latest reading: " . ($sc['last_reading'] ? $this->xml($sc['last_reading']) : 'None') . "</t></is></c>"
            . "</row>";
        $r++;
        $r++; // Empty row

        // Narrative Analysis Section
        $rows[] = "<row r=\"{$r}\" ht=\"22\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"9\"><is><t>MONTHLY NARRATIVE &amp; FACTUAL INTERPRETATION</t></is></c><c r=\"B{$r}\" s=\"9\"/><c r=\"C{$r}\" s=\"9\"/><c r=\"D{$r}\" s=\"9\"/><c r=\"E{$r}\" s=\"9\"/></row>";
        $r++;

        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Overview Headline:</t></is></c><c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml((string) ($ins['headline'] ?? ''))}</t></is></c></row>";
        $r++;
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Synthesis Summary:</t></is></c><c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml((string) ($ins['narrative'] ?? $ins['summary'] ?? ''))}</t></is></c></row>";
        $r++;
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Solar Generation:</t></is></c><c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml((string) ($ins['solar_statement'] ?? ''))}</t></is></c></row>";
        $r++;
        $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>Household Demand:</t></is></c><c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml((string) ($ins['demand_statement'] ?? ''))}</t></is></c></row>";
        $r++;
        $r++; // Empty row

        // Trust Boundaries & Disclosures Section
        $rows[] = "<row r=\"{$r}\" ht=\"22\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"9\"><is><t>DATA TRUST BOUNDARIES &amp; PROVENANCE DISCLOSURES</t></is></c><c r=\"B{$r}\" s=\"9\"/><c r=\"C{$r}\" s=\"9\"/><c r=\"D{$r}\" s=\"9\"/><c r=\"E{$r}\" s=\"9\"/></row>";
        $r++;

        $rules = [
            'ACTUAL' => 'Solar generation values are directly measured by the GoodWe SEMS inverter system. Zero synthetic extrapolation.',
            'ESTIMATED' => 'Household demand is derived strictly from verified APSPDCL billing statements or verified historical baseline.',
            'POTENTIAL' => 'Energy balance is an analytical comparison between solar generation and estimated demand. It does not represent physical grid export without a bidirectional smart meter.',
            'VERIFIED' => 'Only verified electricity bills are used for authoritative calculations. Statements awaiting review are excluded from baseline calculations.',
        ];

        foreach ($rules as $label => $desc) {
            $rows[] = "<row r=\"{$r}\" ht=\"18\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"6\"><is><t>• {$label}</t></is></c><c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml($desc)}</t></is></c></row>";
            $r++;
        }

        if (!empty($ins['limitations'])) {
            $r++;
            $rows[] = "<row r=\"{$r}\" ht=\"20\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"11\"><is><t>Reporting Limitations Disclosed:</t></is></c></row>";
            $r++;
            foreach ($ins['limitations'] as $lim) {
                $rows[] = "<row r=\"{$r}\" ht=\"18\" customHeight=\"1\"><c r=\"A{$r}\" t=\"inlineStr\" s=\"3\"><is><t>Notice:</t></is></c><c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml($lim)}</t></is></c></row>";
                $r++;
            }
        }

        $sheetData = implode("\n", $rows);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="1" width="30" customWidth="1"/>
    <col min="2" max="2" width="26" customWidth="1"/>
    <col min="3" max="3" width="14" customWidth="1"/>
    <col min="4" max="4" width="18" customWidth="1"/>
    <col min="5" max="5" width="55" customWidth="1"/>
  </cols>
  <sheetData>
{$sheetData}
  </sheetData>
</worksheet>
XML;
    }

    /**
     * Sheet 2: Monthly Trends.
     *
     * @param array<string, mixed> $reportData
     */
    private function buildMonthlyTrendsSheetXml(array $reportData, bool $hasCharts): string
    {
        $trends = $reportData['daily_trends'] ?? [];
        $rows = [];

        // Row 1: Header
        $rows[] = '<row r="1" ht="24" customHeight="1">'
            . '<c r="A1" t="inlineStr" s="4"><is><t>Date</t></is></c>'
            . '<c r="B1" t="inlineStr" s="4"><is><t>Solar Generation (kWh)</t></is></c>'
            . '<c r="C1" t="inlineStr" s="4"><is><t>Estimated Household Demand (kWh)</t></is></c>'
            . '<c r="D1" t="inlineStr" s="4"><is><t>Potential Balance (kWh)</t></is></c>'
            . '</row>';

        $r = 2;
        foreach ($trends as $item) {
            $date = (string) $item['date'];
            $solar = $item['solar_generation_kwh'];
            $demand = $item['estimated_demand_kwh'];
            $balance = $item['potential_balance_kwh'];

            $solarXml = ($solar !== null)
                ? "<c r=\"B{$r}\" s=\"7\"><v>{$solar}</v></c>"
                : "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>N/A</t></is></c>";

            $demandXml = ($demand !== null)
                ? "<c r=\"C{$r}\" s=\"7\"><v>{$demand}</v></c>"
                : "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>Unavailable</t></is></c>";

            $balanceXml = ($balance !== null)
                ? "<c r=\"D{$r}\" s=\"14\"><v>{$balance}</v></c>"
                : "<c r=\"D{$r}\" t=\"inlineStr\" s=\"5\"><is><t>N/A</t></is></c>";

            $rows[] = "<row r=\"{$r}\" ht=\"18\" customHeight=\"1\">"
                . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$date}</t></is></c>"
                . $solarXml
                . $demandXml
                . $balanceXml
                . "</row>";
            $r++;
        }

        $sheetData = implode("\n", $rows);
        $drawingTag = $hasCharts ? '  <drawing r:id="rId1"/>' : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheetViews>
    <sheetView tabSelected="0" workbookViewId="0">
      <pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>
    </sheetView>
  </sheetViews>
  <cols>
    <col min="1" max="1" width="16" customWidth="1"/>
    <col min="2" max="2" width="24" customWidth="1"/>
    <col min="3" max="3" width="34" customWidth="1"/>
    <col min="4" max="4" width="24" customWidth="1"/>
  </cols>
  <sheetData>
{$sheetData}
  </sheetData>
{$drawingTag}
</worksheet>
XML;
    }

    private function buildSheet2RelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>
</Relationships>
XML;
    }

    private function buildDrawingXml(bool $hasChart2): string
    {
        // Chart 1: Daily Solar Generation (placed at Col F (5), Row 1 to Col O (14), Row 16)
        $chart1Anchor = <<<'XML'
  <xdr:twoCellAnchor>
    <xdr:from><xdr:col>5</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>1</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>
    <xdr:to><xdr:col>14</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>16</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>
    <xdr:graphicFrame macro="">
      <xdr:nvGraphicFramePr>
        <xdr:cNvPr id="2" name="Chart 1"/>
        <xdr:cNvGraphicFramePr/>
      </xdr:nvGraphicFramePr>
      <xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>
      <a:graphic>
        <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">
          <c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" r:id="rId1"/>
        </a:graphicData>
      </a:graphic>
    </xdr:graphicFrame>
    <xdr:clientData/>
  </xdr:twoCellAnchor>
XML;

        $chart2Anchor = '';
        if ($hasChart2) {
            // Chart 2: Solar Generation vs Estimated Household Demand (placed at Col F (5), Row 18 to Col O (14), Row 33)
            $chart2Anchor = <<<'XML'
  <xdr:twoCellAnchor>
    <xdr:from><xdr:col>5</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>18</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>
    <xdr:to><xdr:col>14</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>33</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>
    <xdr:graphicFrame macro="">
      <xdr:nvGraphicFramePr>
        <xdr:cNvPr id="3" name="Chart 2"/>
        <xdr:cNvGraphicFramePr/>
      </xdr:nvGraphicFramePr>
      <xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>
      <a:graphic>
        <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">
          <c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" r:id="rId2"/>
        </a:graphicData>
      </a:graphic>
    </xdr:graphicFrame>
    <xdr:clientData/>
  </xdr:twoCellAnchor>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
{$chart1Anchor}
{$chart2Anchor}
</xdr:wsDr>
XML;
    }

    private function buildDrawingRelsXml(bool $hasChart2): string
    {
        $rel2 = $hasChart2
            ? '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart2.xml"/>' . "\n"
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/>
{$rel2}</Relationships>
XML;
    }

    /**
     * Chart 1: Daily Solar Generation (kWh).
     */
    private function buildChart1Xml(string $monthLabel, int $daysCount): string
    {
        $lastRow = $daysCount + 1;

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <c:date1904 val="0"/>
  <c:lang val="en-US"/>
  <c:chart>
    <c:title>
      <c:tx><c:v>Daily Solar Generation (kWh) • {$this->xml($monthLabel)}</c:v></c:tx>
      <c:layout/>
    </c:title>
    <c:autoTitleDeleted val="0"/>
    <c:plotArea>
      <c:layout/>
      <c:lineChart>
        <c:grouping val="standard"/>
        <c:ser>
          <c:idx val="0"/>
          <c:order val="0"/>
          <c:tx>
            <c:strRef>
              <c:f>'Monthly Trends'!\$B\$1</c:f>
              <c:strCache>
                <c:ptCount val="1"/>
                <c:pt idx="0"><c:v>Solar Generation (kWh)</c:v></c:pt>
              </c:strCache>
            </c:strRef>
          </c:tx>
          <c:spPr>
            <a:ln w="25400">
              <a:solidFill><a:srgbClr val="F59E0B"/></a:solidFill>
            </a:ln>
          </c:spPr>
          <c:cat>
            <c:strRef>
              <c:f>'Monthly Trends'!\$A\$2:\$A{$lastRow}</c:f>
            </c:strRef>
          </c:cat>
          <c:val>
            <c:numRef>
              <c:f>'Monthly Trends'!\$B\$2:\$B{$lastRow}</c:f>
              <c:numCache>
                <c:formatCode>0.00</c:formatCode>
              </c:numCache>
            </c:numRef>
          </c:val>
          <c:smooth val="0"/>
        </c:ser>
        <c:axId val="15001"/>
        <c:axId val="15002"/>
      </c:lineChart>
      <c:catAx>
        <c:axId val="15001"/>
        <c:scaling><c:orientation val="minMax"/></c:scaling>
        <c:delete val="0"/>
        <c:axPos val="b"/>
        <c:majorTickMark val="out"/>
        <c:minorTickMark val="none"/>
        <c:tickLblPos val="nextTo"/>
        <c:crossAx val="15002"/>
        <c:crosses val="autoZero"/>
        <c:auto val="1"/>
        <c:lblAlgn val="ctr"/>
        <c:lblOffset val="100"/>
      </c:catAx>
      <c:valAx>
        <c:axId val="15002"/>
        <c:scaling><c:orientation val="minMax"/></c:scaling>
        <c:delete val="0"/>
        <c:axPos val="l"/>
        <c:majorGridlines/>
        <c:numFmt formatCode="0.00" sourceLinked="0"/>
        <c:majorTickMark val="out"/>
        <c:minorTickMark val="none"/>
        <c:tickLblPos val="nextTo"/>
        <c:crossAx val="15001"/>
        <c:crosses val="autoZero"/>
      </c:valAx>
    </c:plotArea>
    <c:legend>
      <c:legendPos val="b"/>
      <c:layout/>
    </c:legend>
    <c:plotVisOnly val="1"/>
  </c:chart>
</c:chartSpace>
XML;
    }

    /**
     * Chart 2: Solar Generation vs Estimated Household Demand (kWh).
     */
    private function buildChart2Xml(string $monthLabel, int $daysCount): string
    {
        $lastRow = $daysCount + 1;

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <c:date1904 val="0"/>
  <c:lang val="en-US"/>
  <c:chart>
    <c:title>
      <c:tx><c:v>Solar Generation vs Estimated Household Demand (kWh) • {$this->xml($monthLabel)}</c:v></c:tx>
      <c:layout/>
    </c:title>
    <c:autoTitleDeleted val="0"/>
    <c:plotArea>
      <c:layout/>
      <c:lineChart>
        <c:grouping val="standard"/>
        <!-- Series 1: Solar Generation -->
        <c:ser>
          <c:idx val="0"/>
          <c:order val="0"/>
          <c:tx>
            <c:strRef>
              <c:f>'Monthly Trends'!\$B\$1</c:f>
              <c:strCache>
                <c:ptCount val="1"/>
                <c:pt idx="0"><c:v>Solar Generation (kWh)</c:v></c:pt>
              </c:strCache>
            </c:strRef>
          </c:tx>
          <c:spPr>
            <a:ln w="25400">
              <a:solidFill><a:srgbClr val="F59E0B"/></a:solidFill>
            </a:ln>
          </c:spPr>
          <c:cat>
            <c:strRef>
              <c:f>'Monthly Trends'!\$A\$2:\$A{$lastRow}</c:f>
            </c:strRef>
          </c:cat>
          <c:val>
            <c:numRef>
              <c:f>'Monthly Trends'!\$B\$2:\$B{$lastRow}</c:f>
              <c:numCache>
                <c:formatCode>0.00</c:formatCode>
              </c:numCache>
            </c:numRef>
          </c:val>
          <c:smooth val="0"/>
        </c:ser>
        <!-- Series 2: Estimated Household Demand -->
        <c:ser>
          <c:idx val="1"/>
          <c:order val="1"/>
          <c:tx>
            <c:strRef>
              <c:f>'Monthly Trends'!\$C\$1</c:f>
              <c:strCache>
                <c:ptCount val="1"/>
                <c:pt idx="0"><c:v>Estimated Household Demand (kWh)</c:v></c:pt>
              </c:strCache>
            </c:strRef>
          </c:tx>
          <c:spPr>
            <a:ln w="25400">
              <a:solidFill><a:srgbClr val="0284C7"/></a:solidFill>
            </a:ln>
          </c:spPr>
          <c:cat>
            <c:strRef>
              <c:f>'Monthly Trends'!\$A\$2:\$A{$lastRow}</c:f>
            </c:strRef>
          </c:cat>
          <c:val>
            <c:numRef>
              <c:f>'Monthly Trends'!\$C\$2:\$C{$lastRow}</c:f>
              <c:numCache>
                <c:formatCode>0.00</c:formatCode>
              </c:numCache>
            </c:numRef>
          </c:val>
          <c:smooth val="0"/>
        </c:ser>
        <c:axId val="16001"/>
        <c:axId val="16002"/>
      </c:lineChart>
      <c:catAx>
        <c:axId val="16001"/>
        <c:scaling><c:orientation val="minMax"/></c:scaling>
        <c:delete val="0"/>
        <c:axPos val="b"/>
        <c:majorTickMark val="out"/>
        <c:minorTickMark val="none"/>
        <c:tickLblPos val="nextTo"/>
        <c:crossAx val="16002"/>
        <c:crosses val="autoZero"/>
        <c:auto val="1"/>
        <c:lblAlgn val="ctr"/>
        <c:lblOffset val="100"/>
      </c:catAx>
      <c:valAx>
        <c:axId val="16002"/>
        <c:scaling><c:orientation val="minMax"/></c:scaling>
        <c:delete val="0"/>
        <c:axPos val="l"/>
        <c:majorGridlines/>
        <c:numFmt formatCode="0.00" sourceLinked="0"/>
        <c:majorTickMark val="out"/>
        <c:minorTickMark val="none"/>
        <c:tickLblPos val="nextTo"/>
        <c:crossAx val="16001"/>
        <c:crosses val="autoZero"/>
      </c:valAx>
    </c:plotArea>
    <c:legend>
      <c:legendPos val="b"/>
      <c:layout/>
    </c:legend>
    <c:plotVisOnly val="1"/>
  </c:chart>
</c:chartSpace>
XML;
    }

    /**
     * Sheet 3: Billing.
     *
     * @param array<string, mixed> $reportData
     */
    private function buildBillingSheetXml(array $reportData): string
    {
        $bills = $reportData['bills'] ?? [];
        $rows = [];

        // Row 1: Table Headers
        $rows[] = '<row r="1" ht="24" customHeight="1">'
            . '<c r="A1" t="inlineStr" s="4"><is><t>Billing Period Start</t></is></c>'
            . '<c r="B1" t="inlineStr" s="4"><is><t>Billing Period End</t></is></c>'
            . '<c r="C1" t="inlineStr" s="4"><is><t>Service Number</t></is></c>'
            . '<c r="D1" t="inlineStr" s="4"><is><t>Consumption (kWh)</t></is></c>'
            . '<c r="E1" t="inlineStr" s="4"><is><t>Solar Export Units (kWh)</t></is></c>'
            . '<c r="F1" t="inlineStr" s="4"><is><t>Net Units (kWh)</t></is></c>'
            . '<c r="G1" t="inlineStr" s="4"><is><t>Bill Amount (₹)</t></is></c>'
            . '<c r="H1" t="inlineStr" s="4"><is><t>Energy Charge (₹)</t></is></c>'
            . '<c r="I1" t="inlineStr" s="4"><is><t>Fixed Charge (₹)</t></is></c>'
            . '<c r="J1" t="inlineStr" s="4"><is><t>Other Charges (₹)</t></is></c>'
            . '<c r="K1" t="inlineStr" s="4"><is><t>Verification Status</t></is></c>'
            . '<c r="L1" t="inlineStr" s="4"><is><t>Source</t></is></c>'
            . '</row>';

        if (empty($bills)) {
            $rows[] = '<row r="2" ht="20" customHeight="1">'
                . '<c r="A2" t="inlineStr" s="5"><is><t>No utility statements recorded for this month.</t></is></c>'
                . '</row>';
        } else {
            $r = 2;
            foreach ($bills as $b) {
                $statusStyle = $b['status'] === 'VERIFIED' ? '10' : '11';
                $cKwh = $b['consumption_kwh'] !== null ? "<c r=\"D{$r}\" s=\"7\"><v>{$b['consumption_kwh']}</v></c>" : "<c r=\"D{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
                $sKwh = $b['solar_export_units_kwh'] !== null ? "<c r=\"E{$r}\" s=\"7\"><v>{$b['solar_export_units_kwh']}</v></c>" : "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
                $nKwh = $b['net_units_kwh'] !== null ? "<c r=\"F{$r}\" s=\"7\"><v>{$b['net_units_kwh']}</v></c>" : "<c r=\"F{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
                $amt = $b['bill_amount'] !== null ? "<c r=\"G{$r}\" s=\"8\"><v>{$b['bill_amount']}</v></c>" : "<c r=\"G{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
                $ec = $b['energy_charges'] !== null ? "<c r=\"H{$r}\" s=\"8\"><v>{$b['energy_charges']}</v></c>" : "<c r=\"H{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
                $fc = $b['fixed_charges'] !== null ? "<c r=\"I{$r}\" s=\"8\"><v>{$b['fixed_charges']}</v></c>" : "<c r=\"I{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
                $oc = $b['other_charges'] !== null ? "<c r=\"J{$r}\" s=\"8\"><v>{$b['other_charges']}</v></c>" : "<c r=\"J{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";

                $rows[] = "<row r=\"{$r}\" ht=\"18\" customHeight=\"1\">"
                    . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$b['billing_period_start']}</t></is></c>"
                    . "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$b['billing_period_end']}</t></is></c>"
                    . "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml($b['service_number'])}</t></is></c>"
                    . $cKwh
                    . $sKwh
                    . $nKwh
                    . $amt
                    . $ec
                    . $fc
                    . $oc
                    . "<c r=\"K{$r}\" t=\"inlineStr\" s=\"{$statusStyle}\"><is><t>{$b['status']}</t></is></c>"
                    . "<c r=\"L{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$this->xml($b['source'])}</t></is></c>"
                    . "</row>";
                $r++;
            }
        }

        $sheetData = implode("\n", $rows);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews>
    <sheetView tabSelected="0" workbookViewId="0">
      <pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>
    </sheetView>
  </sheetViews>
  <cols>
    <col min="1" max="1" width="18" customWidth="1"/>
    <col min="2" max="2" width="18" customWidth="1"/>
    <col min="3" max="3" width="18" customWidth="1"/>
    <col min="4" max="4" width="20" customWidth="1"/>
    <col min="5" max="5" width="24" customWidth="1"/>
    <col min="6" max="6" width="18" customWidth="1"/>
    <col min="7" max="7" width="18" customWidth="1"/>
    <col min="8" max="8" width="18" customWidth="1"/>
    <col min="9" max="9" width="18" customWidth="1"/>
    <col min="10" max="10" width="18" customWidth="1"/>
    <col min="11" max="11" width="20" customWidth="1"/>
    <col min="12" max="12" width="18" customWidth="1"/>
  </cols>
  <sheetData>
{$sheetData}
  </sheetData>
</worksheet>
XML;
    }

    /**
     * Sheet 4: Solar Data (Streamed from database for target month).
     *
     * @param array<string, mixed> $reportData
     */
    private function buildSolarDataSheetXml(array $reportData, string $householdId, PDO $pdo): string
    {
        $startDate = $reportData['period']['start_date'] . ' 00:00:00';
        $endDate = $reportData['period']['end_date'] . ' 23:59:59';

        $stmt = $pdo->prepare(<<<'SQL'
            SELECT 
                measurement_time,
                power_w,
                ac_voltage_v,
                ac_current_a,
                ac_frequency_hz,
                pv_power_w,
                temperature_c,
                daily_generation_kwh,
                total_generation_kwh,
                inverter_status,
                data_status
            FROM solar_readings
            WHERE household_id = :household_id
              AND measurement_time >= :start_dt
              AND measurement_time <= :end_dt
            ORDER BY measurement_time ASC, id ASC
        SQL);
        $stmt->execute([
            'household_id' => $householdId,
            'start_dt' => $startDate,
            'end_dt' => $endDate,
        ]);

        $rows = [];
        $rows[] = '<row r="1" ht="24" customHeight="1">'
            . '<c r="A1" t="inlineStr" s="4"><is><t>Measurement Time (IST)</t></is></c>'
            . '<c r="B1" t="inlineStr" s="4"><is><t>Active Power (W)</t></is></c>'
            . '<c r="C1" t="inlineStr" s="4"><is><t>AC Voltage (V)</t></is></c>'
            . '<c r="D1" t="inlineStr" s="4"><is><t>AC Current (A)</t></is></c>'
            . '<c r="E1" t="inlineStr" s="4"><is><t>Frequency (Hz)</t></is></c>'
            . '<c r="F1" t="inlineStr" s="4"><is><t>PV Power (W)</t></is></c>'
            . '<c r="G1" t="inlineStr" s="4"><is><t>Temperature (°C)</t></is></c>'
            . '<c r="H1" t="inlineStr" s="4"><is><t>Daily Generation (kWh)</t></is></c>'
            . '<c r="I1" t="inlineStr" s="4"><is><t>Total Generation (kWh)</t></is></c>'
            . '<c r="J1" t="inlineStr" s="4"><is><t>Inverter Status</t></is></c>'
            . '<c r="K1" t="inlineStr" s="4"><is><t>Data Status</t></is></c>'
            . '</row>';

        $r = 2;
        $count = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $count++;
            $mTime = (string) ($row['measurement_time'] ?? '');
            $pW = $row['power_w'] !== null ? "<c r=\"B{$r}\" s=\"7\"><v>{$row['power_w']}</v></c>" : "<c r=\"B{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $acV = $row['ac_voltage_v'] !== null ? "<c r=\"C{$r}\" s=\"7\"><v>{$row['ac_voltage_v']}</v></c>" : "<c r=\"C{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $acA = $row['ac_current_a'] !== null ? "<c r=\"D{$r}\" s=\"7\"><v>{$row['ac_current_a']}</v></c>" : "<c r=\"D{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $freq = $row['ac_frequency_hz'] !== null ? "<c r=\"E{$r}\" s=\"7\"><v>{$row['ac_frequency_hz']}</v></c>" : "<c r=\"E{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $pvW = $row['pv_power_w'] !== null ? "<c r=\"F{$r}\" s=\"7\"><v>{$row['pv_power_w']}</v></c>" : "<c r=\"F{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $temp = $row['temperature_c'] !== null ? "<c r=\"G{$r}\" s=\"7\"><v>{$row['temperature_c']}</v></c>" : "<c r=\"G{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $dKwh = $row['daily_generation_kwh'] !== null ? "<c r=\"H{$r}\" s=\"7\"><v>{$row['daily_generation_kwh']}</v></c>" : "<c r=\"H{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $tKwh = $row['total_generation_kwh'] !== null ? "<c r=\"I{$r}\" s=\"7\"><v>{$row['total_generation_kwh']}</v></c>" : "<c r=\"I{$r}\" t=\"inlineStr\" s=\"5\"><is><t></t></is></c>";
            $invStat = $this->xml((string) ($row['inverter_status'] ?? ''));
            $dataStat = $this->xml((string) ($row['data_status'] ?? 'FRESH'));

            $rows[] = "<row r=\"{$r}\" ht=\"18\" customHeight=\"1\">"
                . "<c r=\"A{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$mTime}</t></is></c>"
                . $pW
                . $acV
                . $acA
                . $freq
                . $pvW
                . $temp
                . $dKwh
                . $tKwh
                . "<c r=\"J{$r}\" t=\"inlineStr\" s=\"5\"><is><t>{$invStat}</t></is></c>"
                . "<c r=\"K{$r}\" t=\"inlineStr\" s=\"10\"><is><t>{$dataStat}</t></is></c>"
                . "</row>";
            $r++;
        }

        if ($count === 0) {
            $rows[] = '<row r="2" ht="20" customHeight="1">'
                . '<c r="A2" t="inlineStr" s="5"><is><t>No inverter telemetry readings recorded for this month.</t></is></c>'
                . '</row>';
        }

        $sheetData = implode("\n", $rows);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews>
    <sheetView tabSelected="0" workbookViewId="0">
      <pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>
    </sheetView>
  </sheetViews>
  <cols>
    <col min="1" max="1" width="22" customWidth="1"/>
    <col min="2" max="2" width="16" customWidth="1"/>
    <col min="3" max="3" width="16" customWidth="1"/>
    <col min="4" max="4" width="16" customWidth="1"/>
    <col min="5" max="5" width="16" customWidth="1"/>
    <col min="6" max="6" width="16" customWidth="1"/>
    <col min="7" max="7" width="16" customWidth="1"/>
    <col min="8" max="8" width="22" customWidth="1"/>
    <col min="9" max="9" width="22" customWidth="1"/>
    <col min="10" max="10" width="18" customWidth="1"/>
    <col min="11" max="11" width="16" customWidth="1"/>
  </cols>
  <sheetData>
{$sheetData}
  </sheetData>
</worksheet>
XML;
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
