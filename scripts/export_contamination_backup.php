<?php
$backupDir = dirname(__DIR__) . '/storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=spms_real', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$targets = [
    'solar_readings' => [
        'id' => [
            '7780de64-879c-46f5-b940-fe2575e9c85b',
            '76844526-8dd7-4841-8fb3-f7dc9e451db6',
            '878906ca-ffa9-4f3b-a91f-d4c81fa212a5',
            'e5b7b19b-23cd-47e7-a0c6-d2a2dbaefebe',
            'e4861b1e-6ed7-422e-999b-bce7e36bb031',
        ],
        'primary_key' => 'id',
        'dependency_order' => 4, // deleted last among contaminated records
    ],
    'household_forecasts' => [
        'id' => [
            '90224317-4488-ad02-a175-2281ad103f7c',
            'd5d235f6-ad55-c3d1-533d-d4a07d356d13',
            'f9b6c78a-0571-2638-5452-29bbf702355b',
        ],
        'primary_key' => 'id',
        'dependency_order' => 3,
    ],
    'insights' => [
        'id' => [
            '5cbf402d-a11a-4be4-b8a6-ffc978bbdba1',
            'edbc7316-530d-4b74-88a4-48b83983c979',
            '71f5a606-c81b-4be6-8942-da2722e61d08',
            '7e771785-fa5f-4678-af08-e76b8e9a2619',
            'c746c3de-fb8c-4de2-b7dd-dc9137067eac',
            'c9a3a67e-8191-4583-a5f7-1796864d4d55',
        ],
        'primary_key' => 'id',
        'dependency_order' => 3,
    ],
    'recommendations' => [
        'id' => [
            '6a533be1-2273-6021-3b25-70ce7f8593a7',
            '55af66c7-4376-cf83-6687-ae7b5e539cb2',
            '97902e55-753c-9175-28ad-7abd27e6d797',
        ],
        'primary_key' => 'id',
        'dependency_order' => 2,
    ],
    'recommendation_outcomes' => [
        'id' => [
            '8f65695d-fa28-a8c8-add0-6c49502125c4',
            '7f1190e7-f90f-e3f4-b7df-282040883276',
            'cc632df5-d70f-819a-1e1c-d1843e39ade2',
        ],
        'primary_key' => 'id',
        'dependency_order' => 1, // must be deleted before recommendations due to FK
    ],
    'notifications' => [
        'id' => [
            '8b358366-f7d3-4378-999a-3dd28e0ba0c2',
        ],
        'primary_key' => 'id',
        'dependency_order' => 1, // deleted before recommendations to prevent orphaned reference
    ],
];

$backupData = [
    'metadata' => [
        'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        'source_database' => 'spms_real',
        'description' => 'Recovery backup of 5 simulated solar readings accidentally inserted into spms_real and their directly derived downstream records.',
        'target_household_id' => '68a5374d-10f0-49a0-b845-03f4acbd5fa8',
        'target_household_name' => 'Charan',
        'total_tables' => count($targets),
        'total_records' => 0,
        'table_summary' => [],
    ],
    'schema_definitions' => [],
    'records' => [],
];

$totalRecords = 0;
foreach ($targets as $table => $cfg) {
    $ids = $cfg['id'];
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `{$cfg['primary_key']}` IN ($inClause)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get column types
    $colsStmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $columns = $colsStmt->fetchAll(PDO::FETCH_ASSOC);

    $backupData['schema_definitions'][$table] = $columns;
    $backupData['records'][$table] = $rows;
    $count = count($rows);
    $totalRecords += $count;
    $backupData['metadata']['table_summary'][$table] = [
        'requested_count' => count($ids),
        'backed_up_count' => $count,
        'dependency_order' => $cfg['dependency_order'],
        'ids' => $ids,
    ];
}

$backupData['metadata']['total_records'] = $totalRecords;

$backupFilePath = $backupDir . '/solar_contamination_backup_20261023.json';
$jsonOutput = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($backupFilePath, $jsonOutput);

$sha256 = hash_file('sha256', $backupFilePath);
$bytes = filesize($backupFilePath);

echo "Backup created successfully.\n";
echo "Path: " . $backupFilePath . "\n";
echo "Size: " . $bytes . " bytes\n";
echo "SHA256: " . $sha256 . "\n";
echo "Total records backed up: " . $totalRecords . "\n";
foreach ($backupData['metadata']['table_summary'] as $t => $info) {
    echo "  - $t: " . $info['backed_up_count'] . " rows\n";
}
