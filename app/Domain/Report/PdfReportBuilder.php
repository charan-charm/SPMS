<?php

declare(strict_types=1);

namespace App\Domain\Report;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use TCPDF;

/**
 * SPMS Executive Monthly PDF Report Builder.
 *
 * Generates an authoritative, publication-quality 3-PAGE presentation report:
 *  - Page 1: Executive Monthly Summary (Branding, Period, 4 KPIs, Monthly Insight, Data Provenance & Trust Lineage, Disclosures)
 *  - Page 2: Solar & Energy Performance (Vector Daily Solar Chart, Vector Energy Balance Chart, Consolidated Metrics, Synthesis)
 *  - Page 3: Billing & Data Coverage (Verified Utility Ledger, Billing Period Clarifications, Inverter Coverage Stats, Parameter Availability)
 *
 * Design Invariants:
 *  - STRICT 3-PAGE TARGET (combining related sections, zero section collisions, zero orphaned headers).
 *  - Single canonical report model consuming MonthlyReportService::generateReport().
 *  - Pure vector SVG embedded charts with calibrated coordinate grids.
 *  - Native UTF-8 font subsetting (dejavusans) for flawless rendering of the Indian Rupee symbol (₹).
 *  - Semantic status badges: ACTUAL, ESTIMATED, POTENTIAL, VERIFIED.
 *  - Restrained financial/utility report table styling with subtle row separators (no clunky black grids).
 */
final class PdfReportBuilder
{
    private const TIMEZONE = 'Asia/Kolkata';

    /**
     * Builds and returns raw binary PDF string.
     *
     * @param array<string, mixed> $d Output of MonthlyReportService::generateReport()
     */
    public function build(array $d, string $householdId, PDO $pdo): string
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $genTime = (new DateTimeImmutable('now', $tz))->format('d M Y, h:i A T');
        $monthLabel = $d['period']['label'] ?? 'Monthly Report';

        // Custom TCPDF subclass with SPMS running headers & footers
        $pdf = new class($monthLabel, $genTime) extends TCPDF {
            public function __construct(
                private readonly string $reportMonth,
                private readonly string $generatedAt
            ) {
                parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
                $this->tcpdflink = false;
            }

            public function Header(): void
            {
                if ($this->page > 1) {
                    $this->SetY(8);
                    $this->SetFont('dejavusans', '', 8);
                    $this->SetTextColor(100, 116, 139); // Slate 500
                    $this->Cell(100, 5, 'SPMS — SOLAR POWER MANAGEMENT SYSTEM', 0, 0, 'L');
                    $this->Cell(80, 5, 'MONTHLY ENERGY REPORT • ' . $this->reportMonth, 0, 1, 'R');
                    $this->SetDrawColor(226, 232, 240); // Slate 200
                    $this->Line(15, 14, 195, 14);
                }
            }

            public function Footer(): void
            {
                $this->SetY(-13);
                $this->SetDrawColor(226, 232, 240);
                $this->Line(15, $this->GetY(), 195, $this->GetY());
                $this->SetY(-11);
                $this->SetFont('dejavusans', '', 7.5);
                $this->SetTextColor(100, 116, 139);
                $this->Cell(65, 4, 'SPMS — Solar Power Management System', 0, 0, 'L');
                $this->Cell(75, 4, 'Report generated: ' . $this->generatedAt, 0, 0, 'C');
                $this->Cell(40, 4, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        };

        // Document Metadata
        $pdf->SetCreator('SPMS');
        $pdf->SetAuthor('Solar Power Management System');
        $pdf->SetTitle("SPMS Monthly Energy Report - {$monthLabel}");
        $pdf->SetSubject('Household Monthly Solar and Energy Report');
        $pdf->SetKeywords('SPMS, Solar, Energy, Report, Monthly, APSPDCL, GoodWe');

        // Margins & Page Setup (15mm left/right, 16mm top, 15mm bottom)
        $pdf->SetMargins(15, 16, 15);
        $pdf->SetHeaderMargin(8);
        $pdf->SetFooterMargin(12);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Build Exact 3 Pages
        $this->renderPage1ExecutiveSummary($pdf, $d, $genTime);
        $this->renderPage2SolarAndEnergyPerformance($pdf, $d);
        $this->renderPage3BillingAndCoverage($pdf, $d);

        return $pdf->Output('', 'S');
    }

    /**
     * PAGE 1 — EXECUTIVE MONTHLY SUMMARY
     */
    private function renderPage1ExecutiveSummary(TCPDF $pdf, array $d, string $genTime): void
    {
        $pdf->AddPage();
        $p = $d['period'];
        $k = $d['kpis'];
        $ins = $d['insight'];

        // Brand Banner Header
        $pdf->SetY(14);
        $pdf->SetFillColor(248, 250, 252); // Slate 50
        $pdf->SetDrawColor(226, 232, 240); // Slate 200
        $pdf->RoundedRect(15, 14, 180, 25, 2.5, '1111', 'DF');

        // Brand Logo & System Title
        $pdf->SetTextColor(16, 185, 129); // Emerald 500
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetXY(20, 18);
        $pdf->Cell(20, 5, 'SPMS', 0, 0, 'L');

        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetXY(38, 18.5);
        $pdf->Cell(95, 5, '•   SOLAR POWER MANAGEMENT SYSTEM', 0, 0, 'L');

        $pdf->SetTextColor(15, 23, 42); // Slate 900
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetXY(20, 25);
        $pdf->Cell(110, 7, 'MONTHLY ENERGY REPORT', 0, 0, 'L');

        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetXY(135, 18);
        $pdf->Cell(55, 5, $p['label'], 0, 0, 'R');

        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetXY(115, 25);
        $pdf->Cell(75, 5, "Period: {$p['start_date']} — {$p['end_date']}", 0, 0, 'R');

        // Section Title: Month at a Glance
        $pdf->SetY(42);
        $pdf->SetFont('dejavusans', 'B', 10.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 5, 'MONTH AT A GLANCE', 0, 1, 'L');

        // 4 Primary KPI Cards in a 2x2 Grid (Each card: width 88mm, height 26mm)
        $cardW = 88;
        $cardH = 26;

        // KPI 1: Solar Generation
        $solarNature = $k['solar_data_nature'] ?? 'ACTUAL';
        $isSimulated = ($solarNature === 'SIMULATED');
        $solarVal = $k['solar_generation_kwh'] !== null ? number_format((float) $k['solar_generation_kwh'], 2) . ' kWh' : 'N/A';
        $solarProv = $k['solar_provenance'] ?? ($isSimulated ? 'Simulated DEMO' : 'GoodWe SEMS Inverter');
        $this->drawKpiCard($pdf, 15, 48, $cardW, $cardH, 'Solar Generation', $solarVal, $solarNature, $solarProv, [217, 119, 6], [16, 185, 129]);

        // KPI 2: Estimated Household Demand (ESTIMATED)
        $demandVal = $k['household_demand_kwh'] !== null ? number_format((float) $k['household_demand_kwh'], 2) . ' kWh' : 'Unavailable';
        $demandProv = $k['demand_provenance'] ?? 'Verified utility statement';
        $this->drawKpiCard($pdf, 107, 48, $cardW, $cardH, 'Estimated Demand', $demandVal, 'ESTIMATED', $demandProv, [2, 132, 199], [2, 132, 199]);

        // KPI 3: Potential Energy Balance (POTENTIAL)
        $balVal = $k['potential_balance_kwh'] !== null ? ($k['potential_balance_kwh'] >= 0 ? '+' : '') . number_format((float) $k['potential_balance_kwh'], 2) . ' kWh' : 'N/A';
        $balProv = $k['balance_provenance'] ?? 'Analytical balance (Solar - Demand)';
        $balColor = ($k['potential_balance_kwh'] ?? 0) >= 0 ? [5, 150, 105] : [220, 38, 38];
        $this->drawKpiCard($pdf, 15, 77, $cardW, $cardH, 'Potential Balance', $balVal, 'POTENTIAL', $balProv, $balColor, [79, 70, 229]);

        // KPI 4: Latest Verified Bill (VERIFIED) with billing period label
        $lvb = $k['latest_verified_bill'] ?? null;
        $billVal = ($lvb && $lvb['amount'] !== null) ? '₹' . number_format((float) $lvb['amount'], 2) : 'Unavailable';
        $billProv = $lvb ? "Billing: {$lvb['billing_period_label']}" : 'No verified bill recorded';
        $this->drawKpiCard($pdf, 107, 77, $cardW, $cardH, 'Latest Verified Bill', $billVal, 'VERIFIED', $billProv, [15, 23, 42], [5, 150, 105]);

        // Section: Monthly Insight (Compact, polished callout)
        $pdf->SetY(107);
        $pdf->SetFillColor(240, 249, 255); // Sky 50
        $pdf->SetDrawColor(186, 230, 253); // Sky 200
        $pdf->RoundedRect(15, 107, 180, 25, 2.5, '1111', 'DF');

        $pdf->SetXY(20, 110);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetTextColor(3, 105, 161); // Sky 700
        $pdf->Cell(170, 4.5, 'MONTHLY INSIGHT', 0, 1, 'L');

        $pdf->SetXY(20, 115.5);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(15, 23, 42); // Slate 900
        $narrative = $ins['narrative'] ?? $ins['headline'] ?? 'Monthly energy balance calculated from actual solar and verified demand history.';
        $pdf->MultiCell(170, 4.2, $narrative, 0, 'L', false);

        // Provenance Line
        $pdf->SetXY(20, 124.5);
        $pdf->SetFont('dejavusans', 'I', 7);
        $pdf->SetTextColor(100, 116, 139);
        $provLine = $ins['provenance_line'] ?? 'ACTUAL solar • ESTIMATED demand • POTENTIAL balance';
        $pdf->Cell(170, 4, $provLine . '  (Grid export telemetry is unmeasured; balance represents potential analytical model).', 0, 0, 'L');

        // Section: Data Provenance & Trust Architecture (Refined Financial Table)
        $pdf->SetY(136);
        $pdf->SetFont('dejavusans', 'B', 10.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 5, 'DATA PROVENANCE & TRUST ARCHITECTURE', 0, 1, 'L');

        $solarBoundary = $isSimulated ? 'Simulated telemetry (Demo Scenario)' : 'Physical inverter generation';

        $provHtml = <<<HTML
<table border="0" cellpadding="5" cellspacing="0" style="font-family: dejavusans; font-size: 7.5pt; width: 100%;">
  <thead>
    <tr style="background-color: #F8FAFC; color: #475569; font-weight: bold; border-bottom: 2px solid #CBD5E1;">
      <th width="24%" style="border-bottom: 1.5px solid #CBD5E1;">Domain / Stream</th>
      <th width="16%" style="border-bottom: 1.5px solid #CBD5E1;">Data Nature</th>
      <th width="34%" style="border-bottom: 1.5px solid #CBD5E1;">Primary Source / Instrument</th>
      <th width="26%" style="border-bottom: 1.5px solid #CBD5E1;">Authority Boundary</th>
    </tr>
  </thead>
  <tbody>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Solar Generation</td>
      <td style="color: #D97706; font-weight: bold; border-bottom: 1px solid #E2E8F0;">{$solarNature}</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$solarProv}</td>
      <td style="color: #64748B; border-bottom: 1px solid #E2E8F0;">{$solarBoundary}</td>
    </tr>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #F8FAFC;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Household Demand</td>
      <td style="color: #0284C7; font-weight: bold; border-bottom: 1px solid #E2E8F0;">ESTIMATED</td>
      <td style="border-bottom: 1px solid #E2E8F0;">Verified APSPDCL Billing History</td>
      <td style="color: #64748B; border-bottom: 1px solid #E2E8F0;">Tariff statements / baseline</td>
    </tr>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Energy Balance</td>
      <td style="color: #4F46E5; font-weight: bold; border-bottom: 1px solid #E2E8F0;">POTENTIAL</td>
      <td style="border-bottom: 1px solid #E2E8F0;">Analytical Model (Solar - Demand)</td>
      <td style="color: #64748B; border-bottom: 1px solid #E2E8F0;">Analytical balance</td>
    </tr>
    <tr style="border-bottom: 1px solid #CBD5E1; background-color: #F8FAFC;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #CBD5E1;">Electricity Billing</td>
      <td style="color: #059669; font-weight: bold; border-bottom: 1px solid #CBD5E1;">VERIFIED</td>
      <td style="border-bottom: 1px solid #CBD5E1;">Official APSPDCL Statement</td>
      <td style="color: #64748B; border-bottom: 1px solid #CBD5E1;">Utility meter reading cycle</td>
    </tr>
  </tbody>
</table>
HTML;
        $pdf->writeHTMLCell(180, 0, 15, 143, $provHtml, 0, 1, false, true, 'L', true);

        // Reporting Disclosures & Limitations Box
        $pdf->SetY(194);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect(15, 194, 180, 22, 2, '1111', 'DF');

        $pdf->SetXY(19, 196.5);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(170, 4, 'Reporting Disclosures & Limitations:', 0, 1, 'L');

        $pdf->SetXY(19, 201);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(71, 85, 105);
        $discList = "• Potential energy balance is an analytical comparison of generation vs estimated demand, NOT a measured grid export.\n• Household demand is modeled from verified utility billing history; smart meter telemetry is not connected to SPMS.\n• Values are strictly household-scoped and calculated under Asia/Kolkata timezone boundaries.";
        $pdf->MultiCell(170, 3.8, $discList, 0, 'L', false);
    }

    /**
     * PAGE 2 — SOLAR & ENERGY PERFORMANCE (Both Charts Consolidated on Page 2)
     */
    private function renderPage2SolarAndEnergyPerformance(TCPDF $pdf, array $d): void
    {
        $pdf->AddPage();
        $p = $d['period'];
        $k = $d['kpis'];
        $trends = $d['daily_trends'] ?? [];
        $daysInMonth = (int) ($p['days_in_month'] ?? 30);
        $hasDemand = $k['household_demand_kwh'] !== null;

        // Section Title
        $pdf->SetY(17);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 5, 'SOLAR & ENERGY PERFORMANCE', 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $isSimulated = (($k['solar_data_nature'] ?? '') === 'SIMULATED');
        $solarHeader = $isSimulated ? 'Daily Simulated Telemetry vs Verified Utility Demand Baseline • ' : 'Daily GoodWe Inverter Generation vs Verified Utility Demand Baseline • ';
        $pdf->Cell(0, 4, $solarHeader . $p['label'], 0, 1, 'L');

        // Chart 1: Daily Solar Generation Bar Chart (Height 50mm, Width 180mm)
        $svgBar = $this->generateSolarBarChartSvg($trends, $daysInMonth, $isSimulated);
        $tmpSvgBar = sys_get_temp_dir() . '/spms_solar_chart_' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($tmpSvgBar, $svgBar);
        $pdf->ImageSVG($tmpSvgBar, 15, 27, 180, 50);
        @unlink($tmpSvgBar);

        // Chart 2: Solar vs Estimated Daily Demand Baseline Line Chart (Height 50mm, Width 180mm)
        $svgLine = $this->generateEnergyBalanceChartSvg($trends, $daysInMonth, $hasDemand);
        $tmpSvgLine = sys_get_temp_dir() . '/spms_balance_chart_' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($tmpSvgLine, $svgLine);
        $pdf->ImageSVG($tmpSvgLine, 15, 80, 180, 50);
        @unlink($tmpSvgLine);

        // Consolidated Chart Caption / Legend
        $pdf->SetY(132);
        $pdf->SetFont('dejavusans', 'I', 7);
        $pdf->SetTextColor(100, 116, 139);
        $solarLegend = $isSimulated ? 'SIMULATED Solar Generation (Demo Telemetry)' : 'ACTUAL Solar Generation (GoodWe)';
        $pdf->Cell(180, 4, "Legend: Solid Amber = {$solarLegend} • Dashed Sky = ESTIMATED Daily Demand Baseline (Verified History)", 0, 1, 'C');

        // Consolidated Performance & Synthesis Table (Clean Financial Styling)
        $solarVals = [];
        $maxVal = -1.0;
        $minVal = 999999.0;
        $maxDay = null;
        $minDay = null;

        foreach ($trends as $t) {
            if ($t['solar_generation_kwh'] !== null) {
                $v = (float) $t['solar_generation_kwh'];
                $solarVals[] = $v;
                if ($v > $maxVal) {
                    $maxVal = $v;
                    $maxDay = $t['day'];
                }
                if ($v < $minVal) {
                    $minVal = $v;
                    $minDay = $t['day'];
                }
            }
        }

        $daysWithData = count($solarVals);
        $totalGen = $daysWithData > 0 ? array_sum($solarVals) : null;
        $avgGen = $daysWithData > 0 ? ($totalGen / $daysWithData) : null;

        $totalGenStr = $totalGen !== null ? number_format($totalGen, 2) . ' kWh' : 'N/A';
        $avgGenStr = $avgGen !== null ? number_format($avgGen, 2) . ' kWh / day' : 'N/A';
        $highestStr = ($maxDay !== null && $maxVal >= 0) ? "Day {$maxDay} (" . number_format($maxVal, 2) . ' kWh)' : 'N/A';
        $lowestStr = ($minDay !== null && $minVal < 999998.0) ? "Day {$minDay} (" . number_format($minVal, 2) . ' kWh)' : 'N/A';
        $coverageStr = "{$daysWithData} of {$daysInMonth} days (" . round(($daysWithData / max(1, $daysInMonth)) * 100) . '%)';
        $statusStr = $k['solar_coverage']['data_status'] ?? 'NO_DATA';

        $balKwh = $k['potential_balance_kwh'] !== null ? ($k['potential_balance_kwh'] >= 0 ? '+' : '') . number_format((float) $k['potential_balance_kwh'], 2) . ' kWh' : 'N/A';
        $balStatus = $k['balance_status'] ?? 'UNAVAILABLE';
        $balColor = $balStatus === 'SURPLUS' ? '#059669' : ($balStatus === 'DEFICIT' ? '#DC2626' : '#4F46E5');
        $demandStatement = $d['insight']['demand_statement'] ?? 'Estimated demand baseline derived from verified utility billing history.';

        $perfHtml = <<<HTML
<table border="0" cellpadding="4.5" cellspacing="0" style="font-family: dejavusans; font-size: 7.5pt; width: 100%;">
  <thead>
    <tr style="background-color: #F8FAFC; color: #475569; font-weight: bold; border-bottom: 1.5px solid #CBD5E1;">
      <th width="28%" style="border-bottom: 1.5px solid #CBD5E1;">Solar Generation Metric</th>
      <th width="22%" style="border-bottom: 1.5px solid #CBD5E1;">Measured Value</th>
      <th width="26%" style="border-bottom: 1.5px solid #CBD5E1;">Energy Balance Synthesis</th>
      <th width="24%" style="border-bottom: 1.5px solid #CBD5E1;">Analytical Assessment</th>
    </tr>
  </thead>
  <tbody>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Total Solar Generation</td>
      <td style="color: #D97706; font-weight: bold; border-bottom: 1px solid #E2E8F0;">{$totalGenStr}</td>
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Analytical Balance</td>
      <td style="font-weight: bold; color: {$balColor}; border-bottom: 1px solid #E2E8F0;">{$balKwh}</td>
    </tr>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #F8FAFC;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Daily Average (active days)</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$avgGenStr}</td>
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Balance Status</td>
      <td style="color: {$balColor}; font-weight: bold; border-bottom: 1px solid #E2E8F0;">{$balStatus}</td>
    </tr>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Peak / Lowest Yield Days</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$highestStr} / {$lowestStr}</td>
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Demand Baseline Source</td>
      <td style="color: #64748B; border-bottom: 1px solid #E2E8F0;">Verified APSPDCL History</td>
    </tr>
    <tr style="border-bottom: 1px solid #CBD5E1; background-color: #F8FAFC;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #CBD5E1;">Telemetry Coverage &amp; Health</td>
      <td style="border-bottom: 1px solid #CBD5E1;">{$coverageStr} • <b style="color: #059669;">{$statusStr}</b></td>
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #CBD5E1;">Model Type</td>
      <td style="color: #64748B; border-bottom: 1px solid #CBD5E1;">Potential Analytical Comparison</td>
    </tr>
  </tbody>
</table>
HTML;
        $pdf->writeHTMLCell(180, 0, 15, 138, $perfHtml, 0, 1, false, true, 'L', true);

        // Mandatory Methodological Disclosure Box
        $pdf->SetY(188);
        $pdf->SetFillColor(254, 252, 232); // Amber 50
        $pdf->SetDrawColor(253, 230, 138); // Amber 200
        $pdf->RoundedRect(15, 188, 180, 24, 2, '1111', 'DF');

        $pdf->SetXY(19, 191);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(180, 83, 9); // Amber 700
        $pdf->Cell(170, 3.5, 'MANDATORY METHODOLOGICAL DISCLOSURE', 0, 1, 'L');

        $pdf->SetXY(19, 195.5);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(71, 85, 105);
        $discSolar = $isSimulated
            ? "1. Potential balance is an analytical comparison of simulated generation versus estimated demand baseline, NOT a physical measured grid export or import."
            : "1. Potential balance is an analytical comparison of actual generation versus estimated demand baseline, NOT a physical measured grid export or import.";
        $discText = "{$discSolar}\n2. Smart meter bidirectional telemetry is not connected to SPMS; actual grid feed-in is determined exclusively by APSPDCL net metering statements.\n3. Baseline demand is derived from verified utility billing history without fabricating interval demand.";
        $pdf->MultiCell(170, 3.8, $discText, 0, 'L', false);
    }

    /**
     * PAGE 3 — BILLING & DATA COVERAGE
     */
    private function renderPage3BillingAndCoverage(TCPDF $pdf, array $d): void
    {
        $pdf->AddPage();
        $p = $d['period'];
        $k = $d['kpis'];
        $bills = $d['bills'] ?? [];
        $sc = $k['solar_coverage'] ?? [];
        $daysInMonth = (int) ($p['days_in_month'] ?? 30);
        $daysWithData = (int) ($sc['days_with_data'] ?? 0);
        $readingsCount = (int) ($sc['readings_count'] ?? 0);
        $status = $sc['data_status'] ?? 'NO_DATA';
        $statusColor = $status === 'FRESH' ? '#059669' : ($status === 'PARTIAL' ? '#D97706' : '#64748B');

        // Section 1 Title: Billing Summary
        $pdf->SetY(17);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 5, 'VERIFIED BILLING SUMMARY', 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 4, 'Authoritative APSPDCL electricity statements covering or overlapping ' . $p['label'], 0, 1, 'L');

        // Notice: Billing Period vs Report Month Clarification (Callout Box)
        $pdf->SetY(26);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect(15, 26, 180, 12, 1.5, '1111', 'DF');

        $pdf->SetXY(18, 28);
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(174, 3.5, 'Important Billing Period Clarification:', 0, 1, 'L');

        $pdf->SetXY(18, 31.8);
        $pdf->SetFont('dejavusans', '', 6.8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(174, 3.5, 'Utility billing cycles follow APSPDCL meter-reading schedules (e.g. 01 Sep → 01 Oct) and may not exactly coincide with calendar month dates (01 Sep → 30 Sep). Bill amounts reflect the authoritative statement periods.', 0, 1, 'L');

        // Itemized Utility Statements Table (Financial Report Layout)
        $tableRows = '';
        if (empty($bills)) {
            $tableRows = '<tr><td colspan="9" align="center" style="padding: 10px; color: #64748B;">No verified electricity statements recorded for this reporting period.</td></tr>';
        } else {
            foreach ($bills as $b) {
                $statusColor = $b['status'] === 'VERIFIED' ? '#059669' : '#D97706';
                $pLabel = ($b['billing_period_start'] ?? '') . ' → ' . ($b['billing_period_end'] ?? '');
                $sn = htmlspecialchars((string) ($b['service_number'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $cVal = $b['consumption_kwh'] !== null ? number_format((float) $b['consumption_kwh'], 1) : '—';
                $sVal = $b['solar_export_units_kwh'] !== null ? number_format((float) $b['solar_export_units_kwh'], 1) : '—';
                $nVal = $b['net_units_kwh'] !== null ? number_format((float) $b['net_units_kwh'], 1) : '—';
                $bAmt = $b['bill_amount'] !== null ? '₹' . number_format((float) $b['bill_amount'], 2) : '—';
                $ecVal = $b['energy_charges'] !== null ? '₹' . number_format((float) $b['energy_charges'], 2) : '—';
                $fcVal = $b['fixed_charges'] !== null ? '₹' . number_format((float) $b['fixed_charges'], 2) : '—';

                $tableRows .= <<<ROW
<tr style="border-bottom: 1px solid #E2E8F0; background-color: #FFFFFF;">
  <td style="font-weight: bold; border-bottom: 1px solid #E2E8F0;">{$pLabel}</td>
  <td style="color: #64748B; border-bottom: 1px solid #E2E8F0;">{$sn}</td>
  <td align="right" style="border-bottom: 1px solid #E2E8F0;">{$cVal}</td>
  <td align="right" style="color: #0284C7; font-weight: bold; border-bottom: 1px solid #E2E8F0;">{$sVal}</td>
  <td align="right" style="border-bottom: 1px solid #E2E8F0;">{$nVal}</td>
  <td align="right" style="border-bottom: 1px solid #E2E8F0;"><b>{$bAmt}</b></td>
  <td align="right" style="border-bottom: 1px solid #E2E8F0;">{$ecVal}</td>
  <td align="right" style="border-bottom: 1px solid #E2E8F0;">{$fcVal}</td>
  <td align="center" style="border-bottom: 1px solid #E2E8F0;"><b style="color: {$statusColor};">{$b['status']}</b></td>
</tr>
ROW;
            }
        }

        $billsHtml = <<<HTML
<table border="0" cellpadding="4.5" cellspacing="0" style="font-family: dejavusans; font-size: 7.2pt; width: 100%;">
  <thead>
    <tr style="background-color: #F8FAFC; color: #475569; font-weight: bold; border-bottom: 1.5px solid #CBD5E1;">
      <th width="19%" style="border-bottom: 1.5px solid #CBD5E1;">Billing Period</th>
      <th width="12%" style="border-bottom: 1.5px solid #CBD5E1;">Service No.</th>
      <th width="10%" align="right" style="border-bottom: 1.5px solid #CBD5E1;">Demand</th>
      <th width="12%" align="right" style="border-bottom: 1.5px solid #CBD5E1;">Solar Export</th>
      <th width="10%" align="right" style="border-bottom: 1.5px solid #CBD5E1;">Net Units</th>
      <th width="12%" align="right" style="border-bottom: 1.5px solid #CBD5E1;">Bill Total</th>
      <th width="9%" align="right" style="border-bottom: 1.5px solid #CBD5E1;">Energy</th>
      <th width="8%" align="right" style="border-bottom: 1.5px solid #CBD5E1;">Fixed</th>
      <th width="10%" align="center" style="border-bottom: 1.5px solid #CBD5E1;">Status</th>
    </tr>
  </thead>
  <tbody>
    {$tableRows}
  </tbody>
</table>
HTML;
        $pdf->writeHTMLCell(180, 0, 15, 41, $billsHtml, 0, 1, false, true, 'L', true);

        // Terminology Guidance Notice (Compact Box)
        $pdf->SetY(67);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 4, 'TARIFF & METER TERMINOLOGY DISTINCTIONS', 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 6.8);
        $pdf->SetTextColor(71, 85, 105);
        $notes = <<<TEXT
• "Solar Export Units" reflects excess solar energy exported across the bidirectional meter during daytime hours, NOT total solar generation. Inverter generation consumed directly behind the meter is not recorded by the utility meter.
• "Net Units" = Gross Consumption Units minus Solar Export Units, subject to APSPDCL net metering settlement rules.
• Only statements in VERIFIED state have passed automated validation and authoritative human confirmation.
TEXT;
        $pdf->MultiCell(180, 3.6, $notes, 0, 'L', false);

        // Section 2 Title: Solar Data Coverage
        $pdf->SetY(87);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 5, 'SOLAR DATA COVERAGE & INVERTER HEALTH', 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 4, 'GoodWe SEMS interval telemetry health & coverage analysis for ' . $p['label'], 0, 1, 'L');

        // Telemetry Coverage Summary Table
        $coverageHtml = <<<HTML
<table border="0" cellpadding="4.5" cellspacing="0" style="font-family: dejavusans; font-size: 7.5pt; width: 100%;">
  <thead>
    <tr style="background-color: #F8FAFC; color: #475569; font-weight: bold; border-bottom: 1.5px solid #CBD5E1;">
      <th width="25%" style="border-bottom: 1.5px solid #CBD5E1;">Days with Data</th>
      <th width="25%" style="border-bottom: 1.5px solid #CBD5E1;">Interval Readings</th>
      <th width="25%" style="border-bottom: 1.5px solid #CBD5E1;">Coverage Span</th>
      <th width="25%" style="border-bottom: 1.5px solid #CBD5E1;">Telemetry Freshness</th>
    </tr>
  </thead>
  <tbody>
    <tr style="border-bottom: 1px solid #CBD5E1; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #CBD5E1;">{$daysWithData} of {$daysInMonth} days</td>
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #CBD5E1;">{$readingsCount} readings</td>
      <td style="border-bottom: 1px solid #CBD5E1;">{$p['start_date']} — {$p['end_date']}</td>
      <td style="border-bottom: 1px solid #CBD5E1;"><b style="color: {$statusColor};">{$status}</b></td>
    </tr>
  </tbody>
</table>
HTML;
        $pdf->writeHTMLCell(180, 0, 15, 96, $coverageHtml, 0, 1, false, true, 'L', true);

        // Parameter Availability Table (Compact Professional Layout)
        $params = $sc['parameter_availability'] ?? [
            'power' => $daysWithData > 0,
            'pv_power' => $daysWithData > 0,
            'ac_voltage' => false,
            'ac_current' => false,
            'frequency' => false,
            'temperature' => false,
        ];

        $renderAvail = static fn (bool $avail): string => $avail
            ? '<span style="color: #059669; font-weight: bold;">Available</span>'
            : '<span style="color: #64748B;">Unavailable / Limited</span>';

        $isSimulated = (($k['solar_data_nature'] ?? '') === 'SIMULATED');
        $telPrefix = $isSimulated ? 'SIMULATED (DEMO)' : 'ACTUAL';

        $paramHtml = <<<HTML
<table border="0" cellpadding="4" cellspacing="0" style="font-family: dejavusans; font-size: 7.2pt; width: 100%;">
  <thead>
    <tr style="background-color: #F8FAFC; color: #475569; font-weight: bold; border-bottom: 1.5px solid #CBD5E1;">
      <th width="32%" style="border-bottom: 1.5px solid #CBD5E1;">Inverter Parameter</th>
      <th width="20%" style="border-bottom: 1.5px solid #CBD5E1;">Unit</th>
      <th width="28%" style="border-bottom: 1.5px solid #CBD5E1;">Measurement Nature</th>
      <th width="20%" style="border-bottom: 1.5px solid #CBD5E1;">Availability</th>
    </tr>
  </thead>
  <tbody>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Active Output Power</td>
      <td style="border-bottom: 1px solid #E2E8F0;">Watts (W)</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$telPrefix} Inverter Telemetry</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$renderAvail($params['power'] ?? false)}</td>
    </tr>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #F8FAFC;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">PV Generation Power</td>
      <td style="border-bottom: 1px solid #E2E8F0;">Watts (W)</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$telPrefix} DC Solar Input</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$renderAvail($params['pv_power'] ?? false)}</td>
    </tr>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">Daily Cumulative Energy</td>
      <td style="border-bottom: 1px solid #E2E8F0;">Kilowatt-hours (kWh)</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$telPrefix} Daily Totalizer</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$renderAvail($daysWithData > 0)}</td>
    </tr>
    <tr style="border-bottom: 1px solid #F1F5F9; background-color: #F8FAFC;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #E2E8F0;">AC Voltage / Current</td>
      <td style="border-bottom: 1px solid #E2E8F0;">Volts (V) / Amperes (A)</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$telPrefix} Grid Parameters</td>
      <td style="border-bottom: 1px solid #E2E8F0;">{$renderAvail($params['ac_voltage'] ?? false)}</td>
    </tr>
    <tr style="border-bottom: 1px solid #CBD5E1; background-color: #FFFFFF;">
      <td style="font-weight: bold; color: #1E293B; border-bottom: 1px solid #CBD5E1;">Grid Frequency / Temp</td>
      <td style="border-bottom: 1px solid #CBD5E1;">Hertz (Hz) / Celsius (°C)</td>
      <td style="border-bottom: 1px solid #CBD5E1;">{$telPrefix} Inverter Telemetry</td>
      <td style="border-bottom: 1px solid #CBD5E1;">{$renderAvail($params['frequency'] ?? false)}</td>
    </tr>
  </tbody>
</table>
HTML;
        $pdf->writeHTMLCell(180, 0, 15, 114, $paramHtml, 0, 1, false, true, 'L', true);

        // Protocol & Data Architecture Note Box
        $pdf->SetY(154);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect(15, 154, 180, 22, 2, '1111', 'DF');

        $pdf->SetXY(18, 156.5);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(170, 3.5, 'TELEMETRY INGESTION INTEGRITY ARCHITECTURE', 0, 1, 'L');

        $pdf->SetXY(18, 161);
        $pdf->SetFont('dejavusans', '', 6.8);
        $pdf->SetTextColor(71, 85, 105);
        $integText = <<<TEXT
1. GoodWe SEMS Cloud synchronization captures high-resolution interval telemetry at 5-minute sampling rates.
2. Ingestion pipeline enforces strict schema integrity: negative generation values, forward-dated records, and anomalous spikes exceeding rated inverter capacity are quarantined.
3. For deep electrical auditing and interval time series, refer to the accompanying SPMS Detailed Excel Workbook (.xlsx) Sheet 4 (Solar Data).
TEXT;
        $pdf->MultiCell(170, 3.8, $integText, 0, 'L', false);
    }

    /**
     * Draw a compact professional KPI card on the PDF.
     */
    private function drawKpiCard(
        TCPDF $pdf,
        float $x,
        float $y,
        float $w,
        float $h,
        string $title,
        string $value,
        string $badge,
        string $context,
        array $valColor,
        array $badgeColor
    ): void {
        // Background & Subtle Border
        $pdf->SetFillColor(248, 250, 252); // Slate 50
        $pdf->SetDrawColor(226, 232, 240); // Slate 200
        $pdf->RoundedRect($x, $y, $w, $h, 2, '1111', 'DF');

        // Header: Title & Semantic Badge
        $pdf->SetXY($x + 3.5, $y + 2.5);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(71, 85, 105); // Slate 600
        $pdf->Cell($w - 28, 4, $title, 0, 0, 'L');

        // Badge pill
        $badgeX = $x + $w - 24;
        $pdf->SetFillColor(
            min(255, (int) ($badgeColor[0] * 0.15 + 230)),
            min(255, (int) ($badgeColor[1] * 0.15 + 230)),
            min(255, (int) ($badgeColor[2] * 0.15 + 230))
        );
        $pdf->SetDrawColor($badgeColor[0], $badgeColor[1], $badgeColor[2]);
        $pdf->RoundedRect($badgeX, $y + 2.5, 20, 3.8, 1.2, '1111', 'DF');

        $pdf->SetXY($badgeX, $y + 2.5);
        $pdf->SetFont('dejavusans', 'B', 5.8);
        $pdf->SetTextColor($badgeColor[0], $badgeColor[1], $badgeColor[2]);
        $pdf->Cell(20, 3.8, $badge, 0, 0, 'C');

        // Value
        $pdf->SetXY($x + 3.5, $y + 8.5);
        $pdf->SetFont('dejavusans', 'B', 11.5);
        $pdf->SetTextColor($valColor[0], $valColor[1], $valColor[2]);
        $pdf->Cell($w - 7, 6.5, $value, 0, 0, 'L');

        // Context / Provenance Subtitle
        $pdf->SetXY($x + 3.5, $y + 17.5);
        $pdf->SetFont('dejavusans', '', 6.8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($w - 7, 3.5, $context, 0, 0, 'L');
    }

    /**
     * Generate pure SVG for Chart 1: Daily Solar Generation Bar Chart.
     *
     * @param list<array<string, mixed>> $trends
     */
    private function generateSolarBarChartSvg(array $trends, int $daysInMonth, bool $isSimulated = false): string
    {
        $w = 560;
        $h = 165;
        $plotX = 42;
        $plotY = 18;
        $plotW = 502;
        $plotH = 118;

        // Find max value
        $maxVal = 5.0;
        $hasData = false;
        foreach ($trends as $t) {
            if ($t['solar_generation_kwh'] !== null) {
                $maxVal = max($maxVal, (float) $t['solar_generation_kwh']);
                $hasData = true;
            }
        }
        $yMax = max(5.0, ceil($maxVal * 1.15));

        // Precompute boundaries
        $plotBottom = $plotY + $plotH;
        $plotRight = $plotX + $plotW;

        // Build SVG
        $svg = [];
        $svg[] = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 {$w} {$h}\">";
        $svg[] = "  <rect width=\"{$w}\" height=\"{$h}\" fill=\"#FFFFFF\" rx=\"4\"/>";

        // Chart Title
        $chartSub = $isSimulated ? 'SIMULATED Demo Telemetry' : 'ACTUAL GoodWe Telemetry';
        $svg[] = "  <text x=\"{$plotX}\" y=\"12\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"9\" font-weight=\"bold\" fill=\"#0F172A\">Daily Solar Generation (kWh)</text>";
        $svg[] = "  <text x=\"{$plotRight}\" y=\"12\" text-anchor=\"end\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#D97706\">{$chartSub}</text>";

        // Y Grid lines and labels (4 ticks)
        for ($i = 0; $i <= 4; $i++) {
            $yVal = round(($yMax / 4) * $i, 1);
            $yPos = round($plotBottom - ($plotH * ($i / 4)), 1);
            $svg[] = "  <line x1=\"{$plotX}\" y1=\"{$yPos}\" x2=\"{$plotRight}\" y2=\"{$yPos}\" stroke=\"#F1F5F9\" stroke-width=\"1\"/>";
            $svg[] = "  <text x=\"" . ($plotX - 6) . "\" y=\"" . ($yPos + 3) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#64748B\" text-anchor=\"end\">{$yVal}</text>";
        }

        // Axes lines
        $svg[] = "  <line x1=\"{$plotX}\" y1=\"{$plotY}\" x2=\"{$plotX}\" y2=\"{$plotBottom}\" stroke=\"#CBD5E1\" stroke-width=\"1\"/>";
        $svg[] = "  <line x1=\"{$plotX}\" y1=\"{$plotBottom}\" x2=\"{$plotRight}\" y2=\"{$plotBottom}\" stroke=\"#CBD5E1\" stroke-width=\"1\"/>";

        if (!$hasData) {
            $noDataText = $isSimulated ? 'No simulated solar telemetry available for this month' : 'No actual GoodWe solar telemetry available for this month';
            $svg[] = "  <text x=\"" . ($plotX + $plotW / 2) . "\" y=\"" . ($plotY + $plotH / 2) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"9\" fill=\"#94A3B8\" text-anchor=\"middle\">{$noDataText}</text>";
        } else {
            $slotW = $plotW / max(1, $daysInMonth);
            $barW = max(2.5, $slotW * 0.68);

            foreach ($trends as $t) {
                $day = (int) $t['day'];
                $val = $t['solar_generation_kwh'];
                $centerX = $plotX + ($day - 0.5) * $slotW;

                if ($val !== null && $val > 0) {
                    $barH = max(1.5, ($val / $yMax) * $plotH);
                    $barX = round($centerX - ($barW / 2), 1);
                    $barY = round($plotBottom - $barH, 1);
                    $svg[] = "  <rect x=\"{$barX}\" y=\"{$barY}\" width=\"" . round($barW, 1) . "\" height=\"" . round($barH, 1) . "\" fill=\"#F59E0B\" rx=\"1.5\"/>";
                }

                // X labels on key intervals
                if ($day === 1 || $day === 5 || $day === 10 || $day === 15 || $day === 20 || $day === 25 || $day === $daysInMonth) {
                    $svg[] = "  <text x=\"" . round($centerX, 1) . "\" y=\"" . ($plotBottom + 12) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#64748B\" text-anchor=\"middle\">{$day}</text>";
                    $svg[] = "  <line x1=\"" . round($centerX, 1) . "\" y1=\"{$plotBottom}\" x2=\"" . round($centerX, 1) . "\" y2=\"" . ($plotBottom + 3) . "\" stroke=\"#CBD5E1\" stroke-width=\"1\"/>";
                }
            }
        }

        // X Axis title
        $svg[] = "  <text x=\"" . ($plotX + $plotW / 2) . "\" y=\"" . ($h - 2) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#64748B\" text-anchor=\"middle\">Day of Month</text>";
        $svg[] = "</svg>";

        return implode("\n", $svg);
    }

    /**
     * Generate pure SVG for Chart 2: Energy Balance Comparison Line Chart.
     *
     * @param list<array<string, mixed>> $trends
     */
    private function generateEnergyBalanceChartSvg(array $trends, int $daysInMonth, bool $hasDemand): string
    {
        $w = 560;
        $h = 165;
        $plotX = 42;
        $plotY = 18;
        $plotW = 502;
        $plotH = 118;

        // Calculate max scale
        $maxVal = 5.0;
        $hasSolar = false;
        foreach ($trends as $t) {
            if ($t['solar_generation_kwh'] !== null) {
                $maxVal = max($maxVal, (float) $t['solar_generation_kwh']);
                $hasSolar = true;
            }
            if ($t['estimated_demand_kwh'] !== null) {
                $maxVal = max($maxVal, (float) $t['estimated_demand_kwh']);
            }
        }
        $yMax = max(5.0, ceil($maxVal * 1.15));

        // Precompute boundaries
        $plotBottom = $plotY + $plotH;
        $plotRight = $plotX + $plotW;

        $svg = [];
        $svg[] = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 {$w} {$h}\">";
        $svg[] = "  <rect width=\"{$w}\" height=\"{$h}\" fill=\"#FFFFFF\" rx=\"4\"/>";

        // Chart Title
        $svg[] = "  <text x=\"{$plotX}\" y=\"12\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"9\" font-weight=\"bold\" fill=\"#0F172A\">Solar Generation vs Estimated Demand Baseline (kWh)</text>";
        $svg[] = "  <text x=\"{$plotRight}\" y=\"12\" text-anchor=\"end\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#0284C7\">Analytical Baseline</text>";

        // Y Grid lines and labels (4 ticks)
        for ($i = 0; $i <= 4; $i++) {
            $yVal = round(($yMax / 4) * $i, 1);
            $yPos = round($plotBottom - ($plotH * ($i / 4)), 1);
            $svg[] = "  <line x1=\"{$plotX}\" y1=\"{$yPos}\" x2=\"{$plotRight}\" y2=\"{$yPos}\" stroke=\"#F1F5F9\" stroke-width=\"1\"/>";
            $svg[] = "  <text x=\"" . ($plotX - 6) . "\" y=\"" . ($yPos + 3) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#64748B\" text-anchor=\"end\">{$yVal}</text>";
        }

        // Axes lines
        $svg[] = "  <line x1=\"{$plotX}\" y1=\"{$plotY}\" x2=\"{$plotX}\" y2=\"{$plotBottom}\" stroke=\"#CBD5E1\" stroke-width=\"1\"/>";
        $svg[] = "  <line x1=\"{$plotX}\" y1=\"{$plotBottom}\" x2=\"{$plotRight}\" y2=\"{$plotBottom}\" stroke=\"#CBD5E1\" stroke-width=\"1\"/>";

        $slotW = $plotW / max(1, $daysInMonth);

        // Draw Estimated Demand Baseline (Step / Dashed line)
        if ($hasDemand) {
            $demandPoints = [];
            foreach ($trends as $t) {
                if ($t['estimated_demand_kwh'] !== null) {
                    $day = (int) $t['day'];
                    $posX = round($plotX + ($day - 0.5) * $slotW, 1);
                    $posY = round($plotBottom - ((float) $t['estimated_demand_kwh'] / $yMax) * $plotH, 1);
                    $demandPoints[] = "{$posX},{$posY}";
                }
            }
            if (!empty($demandPoints)) {
                $svg[] = "  <polyline points=\"" . implode(' ', $demandPoints) . "\" fill=\"none\" stroke=\"#0284C7\" stroke-width=\"2\" stroke-dasharray=\"4,4\"/>";
            }
        }

        // Draw Solar Generation Line (Solid Amber)
        if ($hasSolar) {
            $solarPoints = [];
            foreach ($trends as $t) {
                if ($t['solar_generation_kwh'] !== null) {
                    $day = (int) $t['day'];
                    $posX = round($plotX + ($day - 0.5) * $slotW, 1);
                    $posY = round($plotBottom - ((float) $t['solar_generation_kwh'] / $yMax) * $plotH, 1);
                    $solarPoints[] = "{$posX},{$posY}";
                }
            }
            if (!empty($solarPoints)) {
                $svg[] = "  <polyline points=\"" . implode(' ', $solarPoints) . "\" fill=\"none\" stroke=\"#F59E0B\" stroke-width=\"2.2\"/>";
                foreach ($solarPoints as $pt) {
                    [$cx, $cy] = explode(',', $pt);
                    $svg[] = "  <circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"2.2\" fill=\"#F59E0B\"/>";
                }
            }
        }

        // X labels on key intervals
        for ($day = 1; $day <= $daysInMonth; $day++) {
            if ($day === 1 || $day === 5 || $day === 10 || $day === 15 || $day === 20 || $day === 25 || $day === $daysInMonth) {
                $posX = round($plotX + ($day - 0.5) * $slotW, 1);
                $svg[] = "  <text x=\"{$posX}\" y=\"" . ($plotBottom + 12) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#64748B\" text-anchor=\"middle\">{$day}</text>";
                $svg[] = "  <line x1=\"{$posX}\" y1=\"{$plotBottom}\" x2=\"{$posX}\" y2=\"" . ($plotBottom + 3) . "\" stroke=\"#CBD5E1\" stroke-width=\"1\"/>";
            }
        }

        if (!$hasSolar && !$hasDemand) {
            $svg[] = "  <text x=\"" . ($plotX + $plotW / 2) . "\" y=\"" . ($plotY + $plotH / 2) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"9\" fill=\"#94A3B8\" text-anchor=\"middle\">No comparison data available for this month</text>";
        }

        // X Axis title
        $svg[] = "  <text x=\"" . ($plotX + $plotW / 2) . "\" y=\"" . ($h - 2) . "\" font-family=\"DejaVu Sans, Arial, sans-serif\" font-size=\"7.5\" fill=\"#64748B\" text-anchor=\"middle\">Day of Month</text>";
        $svg[] = "</svg>";

        return implode("\n", $svg);
    }
}
