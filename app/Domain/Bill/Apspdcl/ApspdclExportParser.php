<?php

declare(strict_types=1);

namespace App\Domain\Bill\Apspdcl;

use App\Domain\Bill\BillField;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillSource;
use App\Domain\Bill\BillValidation;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\QualityCode;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use InvalidArgumentException;

/**
 * APSPDCL HTML-based Excel export (.xls) deterministic parser & normalizer.
 *
 * APSPDCL utility statements exported as Excel (.xls) are HTML-formatted tables.
 * This parser:
 * - Safely processes HTML using DOMDocument with entity-loader disabled;
 * - Defends against formula injection in cell contents;
 * - Normalizes header variations;
 * - Preserves explicit NULL semantics (missing readings = NULL, never 0.0);
 * - Extracts canonical SPMS bill fields;
 * - Preserves utility-specific charges (FSA, Surcharge, True-Up, etc.) in structured metadata;
 * - Computes deterministic cross-checks (METER_CONSUMPTION, SOLAR_PRODUCTION, NET_UNITS);
 * - Extracts the utility service / SC number from the HTML header area above the data table
 *   so that household ownership validation can confirm bill provenance correctly.
 */
final class ApspdclExportParser
{
    private const HEADER_MAP = [
        's.no' => 's_no',
        'sno' => 's_no',
        'sl.no' => 's_no',
        'slno' => 's_no',
        'bill date' => 'bill_date',
        'bill month' => 'bill_month',
        'meter status' => 'meter_status',
        'due date' => 'due_date',
        'disconnection date' => 'disconnection_date',
        'opening reading' => 'opening_reading',
        'closing reading' => 'closing_reading',
        'net units' => 'net_units',
        'bill amt' => 'bill_amt',
        'bill amount' => 'bill_amt',
        'ec' => 'ec',
        'cc' => 'cc',
        'surcharge' => 'surcharge',
        'fixed charges' => 'fixed_charges',
        'fsa' => 'fsa',
        'true up charges' => 'true_up_charges',
        'true up' => 'true_up_charges',
        'ied' => 'ied',
        'ed' => 'ed',
        'other charges' => 'other_charges',
        'tod charges' => 'tod_charges',
        'irda' => 'irda',
        'rmd' => 'rmd',
        'units' => 'units',
        'opening reading kvah' => 'opening_reading_kvah',
        'closing reading kvah' => 'closing_reading_kvah',
        'units kvah' => 'units_kvah',
        'solar opening reading' => 'solar_opening_reading',
        'solar closing reading' => 'solar_closing_reading',
        'solar units' => 'solar_units',
    ];

    /**
     * Parse HTML content into structured monthly bill records.
     *
     * @return list<array{
     *     values: array<string, string|null>,
     *     validations: list<BillValidation>,
     *     quality_flags: list<BillQualityFlag>,
     *     assessment: string,
     *     assessment_reason: string,
     *     raw_metadata: array<string, mixed>
     * }>
     */
    public function parse(string $htmlContent): array
    {
        if (trim($htmlContent) === '') {
            throw new InvalidArgumentException('Empty APSPDCL export content.');
        }

        $dom = new DOMDocument();
        $previousEntityLoader = null;
        if (\PHP_VERSION_ID < 80000) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }
        $previousErrors = libxml_use_internal_errors(true);

        try {
            // Encode as UTF-8 HTML fragment
            $utf8Html = mb_encode_numericentity($htmlContent, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
            $loaded = $dom->loadHTML($utf8Html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_use_internal_errors($previousErrors);
            if (\PHP_VERSION_ID < 80000 && $previousEntityLoader !== null) {
                libxml_disable_entity_loader($previousEntityLoader);
            }
        }

        if (!$loaded) {
            throw new InvalidArgumentException('Could not parse APSPDCL export as valid HTML table.');
        }

        // 0. Extract service number from HTML header area before the data table.
        //    All rows in one export share the same service account, so we extract
        //    it once from the surrounding HTML and inject it into every record.
        $serviceNumber = self::extractServiceNumberFromHtml($dom);

        $xpath = new DOMXPath($dom);
        $tables = $xpath->query('//table');

        if ($tables === false || $tables->length === 0) {
            throw new InvalidArgumentException('No HTML table found in APSPDCL export.');
        }

        /** @var DOMElement $table */
        $table = $tables->item(0);
        $rows = $table->getElementsByTagName('tr');

        if ($rows->length < 2) {
            throw new InvalidArgumentException('APSPDCL export table has insufficient rows (header + at least 1 data row required).');
        }

        // 1. Extract header row
        $headers = [];
        /** @var DOMElement $firstRow */
        $firstRow = $rows->item(0);
        $headerCells = [];
        foreach ($firstRow->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['th', 'td'], true)) {
                $headerCells[] = $child;
            }
        }

        foreach ($headerCells as $cell) {
            $rawText = self::sanitizeFormula(trim($cell->textContent));
            $normalizedHeader = self::normalizeHeaderKey($rawText);
            $headers[] = $normalizedHeader;
        }

        // 2. Extract data rows
        $parsedRows = [];
        for ($i = 1; $i < $rows->length; $i++) {
            /** @var DOMElement $row */
            $row = $rows->item($i);
            $cells = [];
            foreach ($row->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                    $cells[] = $child;
                }
            }
            if ($cells === []) {
                continue;
            }

            $rowData = [];
            foreach ($cells as $colIdx => $cell) {
                $header = $headers[$colIdx] ?? 'col_' . $colIdx;
                $val = self::sanitizeFormula(trim($cell->textContent));
                $rowData[$header] = $val !== '' ? $val : null;
            }

            $parsedRows[] = $rowData;
        }

        if ($parsedRows === []) {
            throw new InvalidArgumentException('No data rows found in APSPDCL export table.');
        }

        // 3. Normalize into canonical SPMS records
        $records = $this->normalizeRecords($parsedRows);

        // 4. Inject the document-level service number into every record.
        //    If the individual row already carries its own service_number (rare),
        //    that takes precedence; otherwise use the header-extracted value.
        if ($serviceNumber !== null) {
            foreach ($records as &$record) {
                if (($record['values'][\App\Domain\Bill\BillField::SERVICE_NUMBER] ?? null) === null) {
                    $record['values'][\App\Domain\Bill\BillField::SERVICE_NUMBER] = $serviceNumber;
                }
            }
            unset($record);
        }

        return $records;
    }

    /**
     * Extract the utility service / SC number from the HTML document.
     *
     * In APSPDCL exports:
     *   1. Action links in the statement carry: calcbill?uscno=<13-digit-number>
     *   2. The XML/HTML sheet header often contains: Consumption_<13-digit-number>
     *   3. Surrounding text or headers carry: SC No / Service No / USC No: <number>
     *
     * The extracted number is returned as raw digits only (no separators), so that
     * ServiceNumber::canonicalize() can normalize it consistently with the
     * household's registered service number.
     */
    private static function extractServiceNumberFromHtml(DOMDocument $dom): ?string
    {
        $xpath = new DOMXPath($dom);

        // 1. In APSPDCL statements, each row carries an action link with uscno=<13-digit USC number>
        $linkNodes = $xpath->query('//a[contains(@href, "uscno=")]');
        if ($linkNodes !== false) {
            foreach ($linkNodes as $link) {
                if ($link instanceof DOMElement) {
                    $href = $link->getAttribute('href');
                    if (preg_match('/[?&]uscno=([0-9]{10,15})/i', $href, $m)) {
                        return $m[1];
                    }
                }
            }
        }

        // 2. Check for Excel sheet name pattern Consumption_<10-15 digits>
        $rawHtml = $dom->saveHTML() ?: '';
        if (preg_match('/Consumption_([0-9]{10,15})/i', $rawHtml, $m)) {
            return $m[1];
        }

        // 3. Check non-table text (headers, h1-h6, paragraphs, spans outside tables).
        $outsideTableNodes = $xpath->query('//body//text()[not(ancestor::table)]');
        $outsideText = '';
        if ($outsideTableNodes !== false) {
            foreach ($outsideTableNodes as $node) {
                $outsideText .= ' ' . $node->nodeValue;
            }
        }
        $outsideText = preg_replace('/\s+/', ' ', $outsideText) ?? $outsideText;
        if (($found = self::findServiceNumberInText($outsideText, false)) !== null) {
            return $found;
        }

        // 4. If not found outside tables, inspect table cells with strict keyword labelling
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $fullText = preg_replace('/\s+/', ' ', $body->textContent) ?? '';
            return self::findServiceNumberInText($fullText, true);
        }

        return null;
    }

    private static function findServiceNumberInText(string $text, bool $labelOnly = false): ?string
    {
        // Pass 1: labelled keyword search.
        // Accepts: SC No, SC Number, Service No, Service Number, USC No, USC Number, Account No, Consumer No.
        if (preg_match(
            '/(?:SC|Service|USC|Account|Consumer)\s*(?:No\.?|Number\.?|ID\.?)?\s*[:\-]?\s*([0-9][0-9\-\.\/]{3,23}|[0-9]{2,6}(?:\s+[0-9]{2,6}){1,3})/i',
            $text,
            $m
        ) === 1) {
            $digits = preg_replace('/[^\d]/', '', $m[1]) ?? '';
            if ($digits !== '' && strlen($digits) >= 4 && strlen($digits) <= 24) {
                return $digits;
            }
        }

        if ($labelOnly) {
            return null;
        }

        // Pass 2: positional — look for 9-to-14 consecutive digit runs separated by delimiters.
        if (preg_match_all('/\b(\d{3,5}[\s\-\/\.]\d{3,5}[\s\-\/\.]\d{2,5})\b/', $text, $matches) > 0) {
            foreach ($matches[1] as $candidate) {
                $digits = preg_replace('/[^\d]/', '', $candidate) ?? '';
                $len = strlen($digits);
                if ($len >= 9 && $len <= 14) {
                    return $digits;
                }
            }
        }

        // Pass 3: delimited 10-13 digit run in non-table text only
        if (preg_match('/\b(\d{10,13})\b/', $text, $m3) === 1) {
            return $m3[1];
        }

        return null;
    }

    /**
     * @param list<array<string, string|null>> $rows
     * @return list<array{
     *     values: array<string, string|null>,
     *     validations: list<BillValidation>,
     *     quality_flags: list<BillQualityFlag>,
     *     assessment: string,
     *     assessment_reason: string,
     *     raw_metadata: array<string, mixed>
     * }>
     */
    private function normalizeRecords(array $rows): array
    {
        $records = [];

        // Parse dates for all rows to allow chronologically linking start/end
        $parsedDates = [];
        foreach ($rows as $idx => $row) {
            $billDateStr = $row['bill_date'] ?? null;
            $parsedDates[$idx] = $billDateStr !== null ? self::parseDate($billDateStr) : null;
        }

        // Identify solar commissioning point from the export history.
        // APSPDCL export rows are arranged newest-first ($rows[0] = newest, $rows[count-1] = oldest).
        // Find the oldest row index that has positive solar generation or solar closing reading.
        $earliestSolarRowIdx = null;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $r = $rows[$i];
            $su = self::parseDecimal($r['solar_units'] ?? null, 3);
            $sc = self::parseDecimal($r['solar_closing_reading'] ?? null, 3);
            if (($su !== null && (float) $su > 0.0) || ($sc !== null && (float) $sc > 0.0)) {
                $earliestSolarRowIdx = $i;
                break;
            }
        }

        foreach ($rows as $idx => $row) {
            $billDate = $parsedDates[$idx];
            $dueDate = isset($row['due_date']) && $row['due_date'] !== null ? self::parseDate($row['due_date']) : null;

            // In APSPDCL exports, bill_date is the reading/closing date of the period.
            // Billing period start is derived from the preceding row's bill_date, or approximately 30 days prior.
            $periodEnd = $billDate;
            $periodStart = null;
            if ($periodEnd !== null) {
                // If there's an older row adjacent, use its bill_date as start
                $adjacentOlder = $parsedDates[$idx + 1] ?? null;
                if ($adjacentOlder !== null && $adjacentOlder < $periodEnd) {
                    $periodStart = $adjacentOlder;
                } else {
                    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
                    if ($dt !== false) {
                        $periodStart = $dt->modify('-1 month')->format('Y-m-d');
                    }
                }
            }

            $openingMeter = self::parseDecimal($row['opening_reading'] ?? null, 3);
            $closingMeter = self::parseDecimal($row['closing_reading'] ?? null, 3);
            $consumptionKwh = self::parseDecimal($row['units'] ?? null, 3);

            // Determine solar applicability for this period.
            // If the row is chronologically older than the earliest solar activity row,
            // solar was not yet installed; preserve NULL semantics and do not manufacture 0.
            $isPreSolar = ($earliestSolarRowIdx === null) || ($idx > $earliestSolarRowIdx);
            $solarApplicability = $isPreSolar ? 'BEFORE_SOLAR_INSTALLATION' : 'SOLAR_ACTIVE';

            $openingSolar = $isPreSolar ? null : self::parseDecimal($row['solar_opening_reading'] ?? null, 3);
            $closingSolar = $isPreSolar ? null : self::parseDecimal($row['solar_closing_reading'] ?? null, 3);
            $solarUnits = $isPreSolar ? null : self::parseDecimal($row['solar_units'] ?? null, 3);
            $netUnits = self::parseDecimal($row['net_units'] ?? null, 3);

            $openingKvah = self::parseDecimal($row['opening_reading_kvah'] ?? null, 3);
            $closingKvah = self::parseDecimal($row['closing_reading_kvah'] ?? null, 3);
            $consumptionKvah = self::parseDecimal($row['units_kvah'] ?? null, 3);

            $billAmount = self::parseDecimal($row['bill_amt'] ?? null, 2);
            $energyCharges = self::parseDecimal($row['ec'] ?? null, 2);
            $customerCharges = self::parseDecimal($row['cc'] ?? null, 2);
            $fixedCharges = self::parseDecimal($row['fixed_charges'] ?? null, 2);
            $otherCharges = self::parseDecimal($row['other_charges'] ?? null, 2);

            $fsa = self::parseDecimal($row['fsa'] ?? null, 2);
            $ed = self::parseDecimal($row['ed'] ?? null, 2);
            $ied = self::parseDecimal($row['ied'] ?? null, 2);
            $trueUp = self::parseDecimal($row['true_up_charges'] ?? null, 2);
            $surcharge = self::parseDecimal($row['surcharge'] ?? null, 2);
            $irda = self::parseDecimal($row['irda'] ?? null, 2);

            $taxAdjTotal = 0.0;
            $hasTaxAdj = false;
            foreach ([$fsa, $ed, $ied, $trueUp, $surcharge, $irda] as $comp) {
                if ($comp !== null) {
                    $taxAdjTotal += (float) $comp;
                    $hasTaxAdj = true;
                }
            }
            $taxesAdjustments = $hasTaxAdj ? number_format($taxAdjTotal, 2, '.', '') : null;

            // Raw line items preserved in metadata
            $rawMetadata = [
                's_no' => $row['s_no'] ?? null,
                'source_statement_serial' => $row['s_no'] ?? null,
                'solar_applicability' => $solarApplicability,
                'bill_month' => $row['bill_month'] ?? null,
                'meter_status' => $row['meter_status'] ?? null,
                'disconnection_date' => $row['disconnection_date'] ?? null,
                'surcharge' => $surcharge,
                'fsa' => $fsa,
                'true_up_charges' => $trueUp,
                'ied' => $ied,
                'ed' => $ed,
                'tod_charges' => self::parseDecimal($row['tod_charges'] ?? null, 2),
                'irda' => $irda,
                'rmd' => self::parseDecimal($row['rmd'] ?? null, 2),
            ];

            // 4. Arithmetic Validations
            $validations = [];
            $flags = [];

            // Rule 1: METER_CONSUMPTION (closing - opening == consumption)
            if ($closingMeter !== null && $openingMeter !== null && $consumptionKwh !== null) {
                $expected = (float) $closingMeter - (float) $openingMeter;
                $expectedStr = number_format($expected, 3, '.', '');
                $status = abs($expected - (float) $consumptionKwh) < 0.001
                    ? ValidationStatus::PASS
                    : ValidationStatus::CONFLICT;
                $validations[] = BillValidation::create(
                    ValidationRule::METER_CONSUMPTION,
                    $status,
                    $expectedStr,
                    $consumptionKwh
                );
                if ($status === ValidationStatus::CONFLICT) {
                    $flags[] = BillQualityFlag::create(QualityCode::CONFLICTING_METER_READINGS, 'consumption_kwh');
                }
            } else {
                $validations[] = BillValidation::create(
                    ValidationRule::METER_CONSUMPTION,
                    ValidationStatus::NOT_CHECKED,
                    null,
                    null,
                    ValidationStatus::REASON_MISSING_OPERANDS
                );
            }

            // Rule 2: SOLAR_PRODUCTION (closing_solar - opening_solar == solar_units)
            if ($isPreSolar) {
                $validations[] = BillValidation::create(
                    ValidationRule::SOLAR_PRODUCTION,
                    ValidationStatus::NOT_CHECKED,
                    null,
                    null,
                    'BEFORE_SOLAR_INSTALLATION'
                );
            } elseif ($closingSolar !== null && $openingSolar !== null && $solarUnits !== null) {
                $expectedSolar = (float) $closingSolar - (float) $openingSolar;
                $expectedSolarStr = number_format($expectedSolar, 3, '.', '');
                $statusSolar = abs($expectedSolar - (float) $solarUnits) < 0.001
                    ? ValidationStatus::PASS
                    : ValidationStatus::CONFLICT;
                $validations[] = BillValidation::create(
                    ValidationRule::SOLAR_PRODUCTION,
                    $statusSolar,
                    $expectedSolarStr,
                    $solarUnits
                );
            } else {
                $validations[] = BillValidation::create(
                    ValidationRule::SOLAR_PRODUCTION,
                    ValidationStatus::NOT_CHECKED,
                    null,
                    null,
                    ValidationStatus::REASON_MISSING_OPERANDS
                );
                if ($solarUnits === null && ($openingSolar !== null || $closingSolar !== null)) {
                    $flags[] = BillQualityFlag::create(QualityCode::MISSING_SOLAR_READINGS, 'solar_units_kwh');
                }
            }

            // Rule 3: NET_UNITS (consumption - solar_units == net_units)
            if ($isPreSolar) {
                // When solar was not yet active, Net Units must equal Consumption kWh
                if ($consumptionKwh !== null && $netUnits !== null) {
                    $expectedNet = (float) $consumptionKwh;
                    $expectedNetStr = number_format($expectedNet, 3, '.', '');
                    $statusNet = abs($expectedNet - (float) $netUnits) < 0.001
                        ? ValidationStatus::PASS
                        : ValidationStatus::CONFLICT;
                    $validations[] = BillValidation::create(
                        ValidationRule::NET_UNITS,
                        $statusNet,
                        $expectedNetStr,
                        $netUnits
                    );
                    if ($statusNet === ValidationStatus::CONFLICT) {
                        $flags[] = BillQualityFlag::create(QualityCode::INCONSISTENT_NET_UNITS, 'net_units_kwh');
                    }
                } else {
                    $validations[] = BillValidation::create(
                        ValidationRule::NET_UNITS,
                        ValidationStatus::NOT_CHECKED,
                        null,
                        null,
                        ValidationStatus::REASON_MISSING_OPERANDS
                    );
                }
            } elseif ($consumptionKwh !== null && $solarUnits !== null && $netUnits !== null) {
                $expectedNet = (float) $consumptionKwh - (float) $solarUnits;
                $expectedNetStr = number_format($expectedNet, 3, '.', '');
                $statusNet = abs($expectedNet - (float) $netUnits) < 0.001
                    ? ValidationStatus::PASS
                    : ValidationStatus::CONFLICT;
                $validations[] = BillValidation::create(
                    ValidationRule::NET_UNITS,
                    $statusNet,
                    $expectedNetStr,
                    $netUnits
                );
                if ($statusNet === ValidationStatus::CONFLICT) {
                    $flags[] = BillQualityFlag::create(QualityCode::INCONSISTENT_NET_UNITS, 'net_units_kwh');
                }
            } else {
                $validations[] = BillValidation::create(
                    ValidationRule::NET_UNITS,
                    ValidationStatus::NOT_CHECKED,
                    null,
                    null,
                    ValidationStatus::REASON_MISSING_OPERANDS
                );
            }

            // Quality flags for missing crucial information
            if ($consumptionKwh === null) {
                $flags[] = BillQualityFlag::create(QualityCode::MISSING_CONSUMPTION, 'consumption_kwh');
            }
            if ($periodStart === null || $periodEnd === null) {
                $flags[] = BillQualityFlag::create(QualityCode::MISSING_BILLING_PERIOD, null);
            }

            $assessment = DocumentAssessment::VALID;
            $assessmentReason = 'APSPDCL export parsed successfully';
            if ($flags !== []) {
                $assessment = DocumentAssessment::VALID_WITH_LIMITATIONS;
                $assessmentReason = 'APSPDCL export parsed with ' . count($flags) . ' limitation(s)';
            }

            $values = [];
            foreach (BillField::ALL as $f) {
                $values[$f] = null;
            }

            $values[BillField::BILLING_PERIOD_START] = $periodStart;
            $values[BillField::BILLING_PERIOD_END] = $periodEnd;
            $values[BillField::BILL_DATE] = $billDate;
            $values[BillField::DUE_DATE] = $dueDate;

            $values['consumption_kwh'] = $consumptionKwh;
            $values['previous_meter_reading'] = $openingMeter;
            $values['present_meter_reading'] = $closingMeter;

            $values['previous_solar_reading'] = $openingSolar;
            $values['present_solar_reading'] = $closingSolar;
            $values['solar_units_kwh'] = $solarUnits;
            $values['net_units_kwh'] = $netUnits;

            $values['opening_kvah'] = $openingKvah;
            $values['closing_kvah'] = $closingKvah;
            $values['consumption_kvah'] = $consumptionKvah;

            $values['bill_amount'] = $billAmount;
            $values['energy_charges'] = $energyCharges;
            $values['customer_service_charges'] = $customerCharges;
            $values['fixed_charges'] = $fixedCharges;
            $values['other_charges'] = $otherCharges;
            $values['taxes_adjustments'] = $taxesAdjustments;

            $values['utility_provider'] = 'APSPDCL';
            $values[BillField::SOURCE] = BillSource::APSPDCL_EXPORT;
            $values[BillField::SOURCE_METADATA_JSON] = json_encode($rawMetadata, JSON_THROW_ON_ERROR);

            $records[] = [
                'values' => $values,
                'validations' => $validations,
                'quality_flags' => $flags,
                'assessment' => $assessment,
                'assessment_reason' => $assessmentReason,
                'raw_metadata' => $rawMetadata,
            ];
        }

        return $records;
    }

    /**
     * Map arbitrary header text to standard internal identifier.
     */
    private static function normalizeHeaderKey(string $header): string
    {
        $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9.]/', ' ', $header) ?? ''));
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return self::HEADER_MAP[$clean] ?? str_replace(' ', '_', $clean);
    }

    /**
     * Parse date string into strict Y-m-d format.
     */
    public static function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || in_array(strtoupper($raw), ['NA', 'N/A', '-', 'NULL'], true)) {
            return null;
        }

        $formats = [
            'F j, Y',       // e.g. "September 1, 2026"
            'M j, Y',       // e.g. "Sep 1, 2026"
            'd/m/Y',        // e.g. "15/09/2026"
            'd-m-Y',        // e.g. "01-09-2026"
            'Y-m-d',        // e.g. "2026-09-01"
            'd.m.Y',        // e.g. "01.09.2026"
            'm/Y',          // e.g. "09/2026"
            'd-M-y',        // e.g. "16-SEP-26"
            'd-M-Y',        // e.g. "16-SEP-2026"
            'j-M-y',        // e.g. "1-SEP-26"
            'j-M-Y',        // e.g. "1-SEP-2026"
        ];

        foreach ($formats as $fmt) {
            $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Parse numeric string with exact decimal scale.
     */
    public static function parseDecimal(?string $raw, int $scale): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '' || in_array(strtoupper($raw), ['NA', 'N/A', '-', 'NULL'], true)) {
            return null;
        }

        // Remove commas and currency signs
        $cleaned = preg_replace('/[₹,$\s]/u', '', $raw);

        if ($cleaned === null || !is_numeric($cleaned)) {
            return null;
        }

        $val = (float) $cleaned;
        return number_format($val, $scale, '.', '');
    }

    /**
     * Protect against CSV/HTML formula injection in cell contents.
     */
    public static function sanitizeFormula(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            // If it's not a plain number (e.g. -177 or +25), quote or strip trigger
            if (!is_numeric($trimmed)) {
                return "'" . $value;
            }
        }

        return $value;
    }
}
