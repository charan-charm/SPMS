<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bill\Apspdcl\ApspdclExportParser;
use App\Domain\Bill\BillSource;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use Tests\Support\Assert;
use Tests\TestResult;

final class ApspdclExportParserTest
{
    public static function run(): array
    {
        return [
            TestResult::check('ApspdclExportParser parses observed owner statement row accurately', function (): void {
                $html = <<<HTML
                <!DOCTYPE html>
                <html>
                <body>
                <table>
                    <thead>
                        <tr>
                            <th>Bill Date</th>
                            <th>Due Date</th>
                            <th>Opening Reading</th>
                            <th>Closing Reading</th>
                            <th>Units</th>
                            <th>Solar Opening Reading</th>
                            <th>Solar Closing Reading</th>
                            <th>Solar Units</th>
                            <th>Net Units</th>
                            <th>Opening Reading KVAH</th>
                            <th>Closing Reading KVAH</th>
                            <th>Units KVAH</th>
                            <th>EC</th>
                            <th>CC</th>
                            <th>Fixed Charges</th>
                            <th>FSA</th>
                            <th>Surcharge</th>
                            <th>Bill Amt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>05-03-2024</td>
                            <td>20-03-2024</td>
                            <td>1000</td>
                            <td>1145</td>
                            <td>145</td>
                            <td>2000</td>
                            <td>2322</td>
                            <td>322</td>
                            <td>-177</td>
                            <td>500</td>
                            <td>650</td>
                            <td>150</td>
                            <td>0.00</td>
                            <td>75.00</td>
                            <td>20.00</td>
                            <td>12.50</td>
                            <td>5.00</td>
                            <td>180.00</td>
                        </tr>
                    </tbody>
                </table>
                </body>
                </html>
                HTML;

                $parser = new ApspdclExportParser();
                $rows = $parser->parse($html);

                Assert::same(1, count($rows), 'expected 1 parsed monthly statement row');
                $row = $rows[0];
                $values = $row['values'];
                $validations = $row['validations'];

                // 1. Dates
                Assert::same('2024-03-05', $values['bill_date']);
                Assert::same('2024-03-20', $values['due_date']);
                Assert::same('2024-03-05', $values['billing_period_end']);
                Assert::same('2024-02-05', $values['billing_period_start']);
                Assert::same(BillSource::APSPDCL_EXPORT, $values['source']);

                // 2. Canonical consumption, solar, and net units
                Assert::same('145.000', $values['consumption_kwh']);
                Assert::same('1000.000', $values['previous_meter_reading']);
                Assert::same('1145.000', $values['present_meter_reading']);
                Assert::same('2000.000', $values['previous_solar_reading']);
                Assert::same('2322.000', $values['present_solar_reading']);
                Assert::same('322.000', $values['solar_units_kwh']);
                Assert::same('-177.000', $values['net_units_kwh'], 'negative net units must be preserved verbatim');

                // 3. Electrical measurements (KVAH)
                Assert::same('500.000', $values['opening_kvah']);
                Assert::same('650.000', $values['closing_kvah']);
                Assert::same('150.000', $values['consumption_kvah']);

                // 4. Financial amounts
                Assert::same('180.00', $values['bill_amount']);
                Assert::same('0.00', $values['energy_charges']);
                Assert::same('75.00', $values['customer_service_charges']);
                Assert::same('20.00', $values['fixed_charges']);

                // 5. Source metadata preserves non-canonical line items (FSA, Surcharge)
                Assert::true($values['source_metadata_json'] !== null);
                $meta = json_decode($values['source_metadata_json'], true);
                Assert::same('12.50', $meta['fsa'] ?? null);
                Assert::same('5.00', $meta['surcharge'] ?? null);

                // 6. Validations pass
                $ruleMap = [];
                foreach ($validations as $v) {
                    $ruleMap[$v->rule()] = $v->status();
                }
                Assert::same(ValidationStatus::PASS, $ruleMap[ValidationRule::METER_CONSUMPTION] ?? null);
                Assert::same(ValidationStatus::PASS, $ruleMap[ValidationRule::SOLAR_PRODUCTION] ?? null);
                Assert::same(ValidationStatus::PASS, $ruleMap[ValidationRule::NET_UNITS] ?? null);
            }),

            TestResult::check('ApspdclExportParser preserves explicit NULL semantics when solar readings are absent', function (): void {
                $html = <<<HTML
                <table>
                    <tr>
                        <th>Bill Date</th><th>Opening Reading</th><th>Closing Reading</th><th>Units</th><th>Bill Amt</th>
                    </tr>
                    <tr>
                        <td>15-05-2024</td><td>2100</td><td>2350</td><td>250</td><td>850.00</td>
                    </tr>
                </table>
                HTML;

                $parser = new ApspdclExportParser();
                $rows = $parser->parse($html);

                Assert::same(1, count($rows));
                $values = $rows[0]['values'];

                Assert::same('250.000', $values['consumption_kwh']);
                Assert::same(null, $values['previous_solar_reading'], 'missing solar reading must stay NULL');
                Assert::same(null, $values['present_solar_reading'], 'missing solar reading must stay NULL');
                Assert::same(null, $values['solar_units_kwh'], 'missing solar units must stay NULL, never 0.000');
                Assert::same(null, $values['opening_kvah']);

                $validations = $rows[0]['validations'];
                $ruleMap = [];
                foreach ($validations as $v) {
                    $ruleMap[$v->rule()] = $v->status();
                }
                Assert::same(ValidationStatus::PASS, $ruleMap[ValidationRule::METER_CONSUMPTION] ?? null);
                Assert::same(ValidationStatus::NOT_CHECKED, $ruleMap[ValidationRule::SOLAR_PRODUCTION] ?? null);
            }),

            TestResult::check('ApspdclExportParser sanitizes formula injection characters in text fields', function (): void {
                $html = <<<HTML
                <table>
                    <tr>
                        <th>Bill Date</th><th>Units</th><th>Bill Amt</th>
                    </tr>
                    <tr>
                        <td>=15-05-2024</td><td>+100</td><td>-500.00</td>
                    </tr>
                </table>
                HTML;

                $parser = new ApspdclExportParser();
                $rows = $parser->parse($html);

                Assert::same(1, count($rows));
                $values = $rows[0]['values'];

                Assert::false(str_starts_with($values['bill_date'] ?? '', '='));
            }),
        ];
    }
}
