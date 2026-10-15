<?php
declare(strict_types=1);

$databaseSetting = trim((string) (getenv('DB_SQLSRV_DATABASES') ?: 'WH2'));
$databaseNames = array_values(array_filter(array_map(
    'trim',
    explode(',', $databaseSetting)
)));
$defaultDatabase = trim((string) (getenv('DB_SQLSRV_DATABASE') ?: 'WH2'));
if ($defaultDatabase !== '' && !in_array($defaultDatabase, $databaseNames, true)) {
    array_unshift($databaseNames, $defaultDatabase);
}

return [
    'server' => trim((string) (getenv('DB_SQLSRV_SERVER') ?: 'localhost\\SQLEXPRESS')),
    'port' => trim((string) (getenv('DB_SQLSRV_PORT') ?: '')),
    'database' => $defaultDatabase !== '' ? $defaultDatabase : ($databaseNames[0] ?? ''),
    'databases' => array_values(array_unique($databaseNames)),
    'modules' => [
        'all' => ['label' => 'Semua data', 'tables' => null],
        'point_binding' => ['label' => 'Point Binding', 'tables' => ['tb_07_area_ship_bind']],
        'transport_order' => [
            'label' => 'Transport Order',
            'tables' => [
                'tb_01_pallet_topup',
                'tb_02_pallet_prod',
                'tb_03_pallet_ship',
                'tb_09_task_active',
                'tb_10_task_history',
            ],
        ],
        'waypoint_interlock' => [
            'label' => 'Waypoint Interlock',
            'tables' => [
                'tb_04_routes_topup_to_prod',
                'tb_05_routes_prod_to_ship',
                'tb_06_routes_ship_to_topup',
                'tb_08_area_strict',
            ],
        ],
    ],
    'username' => (string) (getenv('DB_SQLSRV_USERNAME') ?: ''),
    'password' => (string) (getenv('DB_SQLSRV_PASSWORD') ?: ''),
    'trusted_connection' => filter_var(
        getenv('DB_SQLSRV_TRUSTED_CONNECTION') ?: 'true',
        FILTER_VALIDATE_BOOLEAN
    ),
    'encrypt' => filter_var(getenv('DB_SQLSRV_ENCRYPT') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'trust_server_certificate' => filter_var(
        getenv('DB_SQLSRV_TRUST_SERVER_CERTIFICATE') ?: 'false',
        FILTER_VALIDATE_BOOLEAN
    ),
];