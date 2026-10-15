<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_be_db_manager'
    . DIRECTORY_SEPARATOR . 'DatabaseManager.php';

final class TransportOrderService
{
    private const PRODUCTION_AREAS = ['Station 01', 'Station 02'];
    private const TROLLEY_CAPACITY = 2;
    private const TOPUP_NEEDS_REFILL = ['', 'EMPTY', 'NEEDS_REFILL', 'NEEDS TOPUP', 'TOPUP_REQUIRED'];
    private const PALLET_READY = ['READY', 'AVAILABLE'];

    public function __construct(private readonly DatabaseManager $database)
    {
    }

    public function dashboard(): array
    {
        $productionRows = $this->allRows('tb_02_pallet_prod');
        $shippingRows = $this->allRows('tb_03_pallet_ship');
        $activeTasks = $this->allRows('tb_09_task_active');
        $busyTrolleys = count(array_filter($activeTasks, fn (array $task): bool => $this->usesTrolley($task)));

        $topupRequests = array_values(array_map(
            fn (array $row): array => [
                'task_id' => (string) ($row['task_id'] ?? ''),
                'area' => $this->displayProductionArea((string) ($row['code_dest'] ?? '')),
                'type' => trim((string) ($row['pallet_type'] ?? '')) ?: 'Unassigned',
                'created_at' => $row['created_at'] ?? null,
            ],
            array_filter($activeTasks, fn (array $row): bool =>
                strtoupper((string) ($row['task_type'] ?? '')) === 'PALLET_REQUEST'
                && strtoupper((string) ($row['status'] ?? '')) === 'WAITING_TOPUP'
            )
        ));
        $topupDeliveries = array_values(array_map(
            fn (array $row): array => [
                'task_id' => (string) ($row['task_id'] ?? ''),
                'pallet_code' => $this->generatedPalletCodeForTask((string) ($row['task_id'] ?? '')),
                'area' => $this->displayProductionArea((string) ($row['code_dest'] ?? '')),
                'type' => trim((string) ($row['pallet_type'] ?? '')) ?: 'Unassigned',
                'status' => strtoupper((string) ($row['status'] ?? '')),
                'created_at' => $row['created_at'] ?? null,
            ],
            array_filter($activeTasks, fn (array $row): bool =>
                strtoupper((string) ($row['task_type'] ?? '')) === 'PALLET_REQUEST'
                && in_array(strtoupper((string) ($row['status'] ?? '')), ['PENDING', 'IN_PROGRESS'], true)
            )
        ));
        $trolleyReturns = array_values(array_map(
            fn (array $row): array => [
                'task_id' => (string) ($row['task_id'] ?? ''),
                'pallet_type' => trim((string) ($row['pallet_type'] ?? '')) ?: 'Unassigned',
                'status' => strtoupper((string) ($row['status'] ?? '')),
                'created_at' => $row['created_at'] ?? null,
            ],
            array_filter($activeTasks, fn (array $row): bool =>
                $this->isActiveTrolleyReturn($row)
                && in_array(strtoupper((string) ($row['status'] ?? '')), ['PENDING', 'IN_PROGRESS'], true)
            )
        ));
        $productionTrolleyTasks = array_values(array_map(
            fn (array $row): array => [
                'task_id' => (string) ($row['task_id'] ?? ''),
                'pallet_code' => (string) ($row['code_source'] ?? ''),
                'area' => $this->displayProductionArea((string) ($row['code_dest'] ?? '')),
                'pallet_type' => trim((string) ($row['pallet_type'] ?? '')) ?: 'Unassigned',
            ],
            array_filter($activeTasks, fn (array $row): bool =>
                $this->isActiveTrolleyReturn($row)
                && strtoupper((string) ($row['status'] ?? '')) === 'WAITING_PRODUCTION'
            )
        ));
        $shippingTrolleyTasks = array_values(array_map(
            fn (array $row): array => [
                'task_id' => (string) ($row['task_id'] ?? ''),
                'pallet_type' => trim((string) ($row['pallet_type'] ?? '')) ?: 'Unassigned',
                'pallet_code' => $this->palletCodeForTask($shippingRows, (string) ($row['task_id'] ?? '')),
            ],
            array_filter($activeTasks, fn (array $row): bool =>
                $this->isActiveTrolleyReturn($row)
                && strtoupper((string) ($row['status'] ?? '')) === 'WAITING_SHIPPING'
            )
        ));
        $productionDeliveries = array_values(array_map(
            fn (array $row): array => [
                'task_id' => (string) ($row['task_id'] ?? ''),
                'pallet_code' => $this->palletCodeForTask($productionRows, (string) ($row['task_id'] ?? '')),
                'area' => $this->displayProductionArea((string) ($row['code_source'] ?? '')),
                'status' => strtoupper((string) ($row['status'] ?? '')),
                'created_at' => $row['created_at'] ?? null,
            ],
            array_filter($activeTasks, fn (array $row): bool =>
                strtoupper((string) ($row['task_type'] ?? '')) === 'PRODUCTION_TO_SHIP'
                && in_array(strtoupper((string) ($row['status'] ?? '')), ['PENDING', 'IN_PROGRESS'], true)
            )
        ));
        $productionPallets = array_values(array_map(
            fn (array $row): array => $this->palletView($row),
            array_filter($productionRows, fn (array $row): bool => $this->isReady($row))
        ));
        $shippingPallets = array_values(array_map(
            fn (array $row): array => $this->palletView($row),
            array_filter($shippingRows, fn (array $row): bool => $this->isReady($row))
        ));

        $productionAreas = [];
        foreach (self::PRODUCTION_AREAS as $areaName) {
            $productionAreas[$areaName] = [
                'name' => $areaName,
                'code' => $areaName,
                'note' => 'Production area',
                'pallets' => 0,
                'status' => 'EMPTY',
            ];
        }
        foreach ($productionRows as $row) {
            $area = $this->displayProductionArea((string) ($row['group_area'] ?? ''));
            if ($area === '' || !isset($productionAreas[$area])) {
                continue;
            }
            if ($this->isReady($row)) {
                $productionAreas[$area]['pallets']++;
            }
        }
        foreach ($productionAreas as $area => &$productionArea) {
            if ($productionArea['pallets'] > 0) {
                $productionArea['status'] = 'READY';
                continue;
            }
            foreach ($activeTasks as $task) {
                $type = strtoupper((string) ($task['task_type'] ?? ''));
                $taskStatus = strtoupper((string) ($task['status'] ?? ''));
                if (
                    $type === 'PALLET_REQUEST'
                    && strcasecmp($this->displayProductionArea((string) ($task['code_dest'] ?? '')), $area) === 0
                    && in_array($taskStatus, ['WAITING_TOPUP', 'PENDING', 'IN_PROGRESS'], true)
                ) {
                    $productionArea['status'] = $taskStatus === 'WAITING_TOPUP' ? 'WAITING_TOPUP' : 'IN_TRANSIT';
                    break;
                }
                if (
                    $type === 'PRODUCTION_TO_SHIP'
                    && in_array($taskStatus, ['PENDING', 'IN_PROGRESS'], true)
                    && strcasecmp($this->displayProductionArea((string) ($task['code_source'] ?? '')), $area) === 0
                ) {
                    $productionArea['status'] = 'IN_TRANSIT';
                    break;
                }
            }
        }
        unset($productionArea);

        $requestAreas = [];
        foreach (self::PRODUCTION_AREAS as $area) {
            $requestAreas[$area] = [
                'name' => $area,
                'code' => $area,
                'note' => 'Operator request location',
                'availability' => 'Available',
                'can_request' => true,
            ];
        }
        foreach ($productionRows as $pallet) {
            $area = $this->displayProductionArea((string) ($pallet['group_area'] ?? ''));
            if (isset($requestAreas[$area]) && $this->isReady($pallet)) {
                $requestAreas[$area]['availability'] = 'Production area occupied';
                $requestAreas[$area]['can_request'] = false;
            }
        }
        foreach ($activeTasks as $task) {
            $destination = $this->displayProductionArea((string) ($task['code_dest'] ?? ''));
            if (
                strtoupper((string) ($task['task_type'] ?? '')) === 'PALLET_REQUEST'
                && in_array(strtoupper((string) ($task['status'] ?? '')), ['WAITING_TOPUP', 'PENDING', 'IN_PROGRESS'], true)
                && isset($requestAreas[$destination])
            ) {
                $requestAreas[$destination]['availability'] =
                    strtoupper((string) ($task['status'] ?? '')) === 'WAITING_TOPUP'
                        ? 'Request active'
                        : 'Pallet in transit';
                $requestAreas[$destination]['can_request'] = false;
            }
            $source = $this->displayProductionArea((string) ($task['code_source'] ?? ''));
            if (
                strtoupper((string) ($task['task_type'] ?? '')) === 'PRODUCTION_TO_SHIP'
                && in_array(strtoupper((string) ($task['status'] ?? '')), ['PENDING', 'IN_PROGRESS'], true)
                && isset($requestAreas[$source])
            ) {
                $requestAreas[$source]['availability'] = 'Pallet is being sent to Shipping';
                $requestAreas[$source]['can_request'] = false;
            }
        }
        if ($busyTrolleys >= self::TROLLEY_CAPACITY) {
            foreach ($requestAreas as &$requestArea) {
                if ($requestArea['can_request']) {
                    $requestArea['availability'] = 'Both trolleys are in use';
                    $requestArea['can_request'] = false;
                }
            }
            unset($requestArea);
        }
        $shippingAreas = [];
        foreach (self::PRODUCTION_AREAS as $area) {
            $shippingAreas[$area] = [
                'name' => $area,
                'pallets' => 0,
                'status' => 'EMPTY',
            ];
        }
        foreach ($shippingRows as $row) {
            $area = $this->displayProductionArea((string) ($row['group_area'] ?? ''));
            if (isset($shippingAreas[$area]) && $this->isReady($row)) {
                $shippingAreas[$area]['pallets']++;
                $shippingAreas[$area]['status'] = 'READY';
            }
        }
        foreach ($activeTasks as $task) {
            $type = strtoupper((string) ($task['task_type'] ?? ''));
            $area = $this->palletArea($productionRows, (string) ($task['code_source'] ?? ''));
            if (!isset($shippingAreas[$area])) {
                continue;
            }
            if (
                $type === 'PRODUCTION_TO_SHIP'
                && in_array(strtoupper((string) ($task['status'] ?? '')), ['PENDING', 'IN_PROGRESS'], true)
                && $shippingAreas[$area]['pallets'] === 0
            ) {
                $shippingAreas[$area]['status'] = 'IN_TRANSIT';
            }
        }

        return [
            'ok' => true,
            'dashboard' => [
                'topup_requests' => $topupRequests,
                'topup_deliveries' => $topupDeliveries,
                'trolley_returns' => $trolleyReturns,
                'production_trolley_tasks' => $productionTrolleyTasks,
                'shipping_trolley_tasks' => $shippingTrolleyTasks,
                'production_deliveries' => $productionDeliveries,
                'request_areas' => array_values($requestAreas),
                'trolleys' => [
                    'capacity' => self::TROLLEY_CAPACITY,
                    'busy' => $busyTrolleys,
                    'available' => max(0, self::TROLLEY_CAPACITY - $busyTrolleys),
                ],
                'production_areas' => array_values($productionAreas),
                'production_pallets' => $productionPallets,
                'shipping_pallets' => $shippingPallets,
                'shipping_areas' => array_values($shippingAreas),
                'stats' => [
                    'active_requests' => count($activeTasks),
                    'in_transit' => count($activeTasks),
                    'production_pallets' => count($productionPallets),
                ],
            ],
        ];
    }

    public function topupPallets(int $page): array
    {
        $result = $this->database->listRows('dbo', 'tb_01_pallet_topup', max(1, $page), 100);
        return [
            'ok' => true,
            'inventory' => [
                'pallets' => array_map(fn (array $row): array => [
                    ...$this->palletView($row),
                    'needs_refill' => $this->needsRefill($row),
                    'available' => trim((string) ($row['task_id'] ?? '')) === ''
                        && ($this->needsRefill($row) || $this->isReady($row)),
                ], $result['rows']),
                'page' => $result['page'],
                'total_rows' => $result['total_rows'],
                'total_pages' => $result['total_pages'],
            ],
        ];
    }

    public function deleteTopupPallet(string $code): void
    {
        $code = $this->requiredText($code, 'Pallet code');
        $this->database->transaction(function (PDO $pdo) use ($code): void {
            $statement = $pdo->prepare(
                "DELETE FROM [dbo].[tb_01_pallet_topup]
                 WHERE [code] = ? AND ([task_id] IS NULL OR LTRIM(RTRIM([task_id])) = '')"
            );
            $statement->execute([$code]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('This TopUp pallet is unavailable or assigned to an active task.');
            }
        });
    }

    public function historyPage(int $page, string $search): array
    {
        $search = trim($search);
        if (strlen($search) > 255) {
            throw new InvalidArgumentException('History search must not exceed 255 characters.');
        }

        $activeTasks = array_values(array_filter(
            $this->allRows('tb_09_task_active'),
            fn (array $row): bool => $this->isVisibleHistoryRow($row)
                && ($search === '' || $this->historyRowMatches($row, $search))
        ));
        $historyPageSize = max(1, 100 - min(count($activeTasks), 99));
        $filters = [
            ['column' => 'task_type', 'operator' => 'not_equals', 'value' => 'TROLLEY_RETURN'],
            ['column' => 'task_type', 'operator' => 'not_equals', 'value' => 'SHIP_TO_TOPUP'],
        ];
        if ($search !== '') {
            $filters[] = [
                'operator' => 'contains_any',
                'columns' => ['task_id', 'task_type', 'code_source', 'code_dest', 'pallet_type', 'status', 'created_at'],
                'value' => $search,
            ];
        }
        $result = $this->database->listRows(
            'dbo',
            'tb_10_task_history',
            max(1, $page),
            $historyPageSize,
            $filters,
            true
        );
        $rows = array_map(fn (array $row): array => $this->taskView($row), $result['rows']);
        if ($result['page'] === 1) {
            foreach ($activeTasks as $task) {
                $rows[] = $this->taskView($task);
            }
        }
        usort($rows, static fn (array $left, array $right): int =>
            strcmp((string) $right['created_at'], (string) $left['created_at'])
        );

        return [
            'ok' => true,
            'history' => [
                'entries' => $rows,
                'page' => $result['page'],
                'page_size' => 100,
                'total_rows' => $result['total_rows'] + count($activeTasks),
                'total_pages' => $result['total_pages'],
            ],
        ];
    }

    public function refillPallet(string $code, string $palletType): array
    {
        $code = $this->requiredText($code, 'Pallet code');
        $palletType = $this->validatePalletType($palletType);
        $taskId = $this->newTaskId();

        $this->database->transaction(function (PDO $pdo) use ($code, $palletType, $taskId): void {
            $pallet = $this->lockPallet($pdo, 'tb_01_pallet_topup', $code);
            if ($pallet === null || !$this->needsRefill($pallet)) {
                throw new InvalidArgumentException('This pallet is not waiting for refill.');
            }

            $this->updatePallet($pdo, 'tb_01_pallet_topup', $code, [
                'status' => 'READY',
                'pallet_type' => $palletType,
                'task_id' => null,
                'update_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->insertTask($pdo, 'tb_10_task_history', [
                'task_id' => $taskId,
                'task_type' => 'TOPUP_STATION',
                'status' => 'COMPLETED',
                'pallet_type' => $palletType,
                'code_source' => $code,
                'code_dest' => $code,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });

        return ['task_id' => $taskId, 'pallet_code' => $code, 'status' => 'COMPLETED'];
    }

    public function refillPalletAtStation(string $area, string $palletType): array
    {
        $area = $this->normalizeProductionArea($area);
        $palletType = $this->validatePalletType($palletType);
        $taskId = '';
        $now = gmdate('Y-m-d H:i:s');

        $this->database->transaction(function (PDO $pdo) use (
            $area,
            $palletType,
            $now,
            &$taskId
        ): void {
            $activeRequestStatement = $pdo->prepare(
                "SELECT TOP (1) [task_id], [pallet_type], [created_at]
                 FROM [dbo].[tb_09_task_active] WITH (UPDLOCK, HOLDLOCK)
                 WHERE [task_type] = 'PALLET_REQUEST' AND [code_dest] IN (?, ?)
                   AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) = 'WAITING_TOPUP'"
            );
            $activeRequestStatement->execute($this->productionAreaDatabaseValues($area));
            $activeRequest = $activeRequestStatement->fetch(PDO::FETCH_ASSOC);
            if ($activeRequest === false) {
                throw new InvalidArgumentException('This station is waiting for a Production request.');
            }
            if (strcasecmp(trim((string) ($activeRequest['pallet_type'] ?? '')), $palletType) !== 0) {
                throw new InvalidArgumentException('Select the pallet type requested by this station.');
            }
            $taskId = (string) $activeRequest['task_id'];

            $areaPallet = $pdo->prepare(
                "SELECT TOP (1) [code] FROM [dbo].[tb_02_pallet_prod] WITH (UPDLOCK, HOLDLOCK)
                 WHERE [group_area] IN (?, ?)
                   AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) IN ('READY', 'AVAILABLE')"
            );
            $areaPallet->execute($this->productionAreaDatabaseValues($area));
            if ($areaPallet->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new InvalidArgumentException('This production area already has a pallet.');
            }
            if ($this->hasProductionShipmentInProgress($pdo, $area)) {
                throw new InvalidArgumentException('This production area is sending a pallet to Shipping.');
            }

            $queueTask = $pdo->prepare(
                "UPDATE [dbo].[tb_09_task_active]
                 SET [status] = 'PENDING', [code_source] = 'TopUp Station', [code_dest] = ?, [finished_at] = NULL
                 WHERE [task_id] = ?
                   AND UPPER(LTRIM(RTRIM(COALESCE([task_type], '')))) = 'PALLET_REQUEST'
                   AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) = 'WAITING_TOPUP'"
            );
            $queueTask->execute([$area, $taskId]);
            if ($queueTask->rowCount() !== 1) {
                throw new RuntimeException('The pallet request could not be queued for the AGV.');
            }
        });

        return [
            'task_id' => $taskId,
            'pallet_code' => $this->generatedPalletCodeForTask($taskId),
            'pallet_type' => $palletType,
            'area' => $area,
            'status' => 'PENDING',
        ];
    }

    public function queuePalletRequest(string $taskId): array
    {
        $taskId = $this->requiredText($taskId, 'Request task ID');
        foreach ($this->allRows('tb_09_task_active') as $activeTask) {
            if (
                (string) ($activeTask['task_id'] ?? '') === $taskId
                && strtoupper((string) ($activeTask['task_type'] ?? '')) === 'PALLET_REQUEST'
                && strtoupper((string) ($activeTask['status'] ?? '')) === 'WAITING_TOPUP'
            ) {
                return $this->refillPalletAtStation(
                    (string) ($activeTask['code_dest'] ?? ''),
                    (string) ($activeTask['pallet_type'] ?? '')
                );
            }
        }

        throw new InvalidArgumentException('This pallet request is no longer waiting for TopUp.');
    }

    public function dispatchTrolleyToTopup(string $taskId): array
    {
        $taskId = $this->requiredText($taskId, 'Trolley task ID');
        $palletType = '';
        $this->database->transaction(function (PDO $pdo) use ($taskId, &$palletType): void {
            $trolley = $pdo->prepare(
                "SELECT TOP (1) * FROM [dbo].[tb_09_task_active] WITH (UPDLOCK, ROWLOCK)
                 WHERE [task_id] = ?
                   AND UPPER(LTRIM(RTRIM(COALESCE([task_type], '')))) = 'TROLLEY_RETURN'
                   AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) = 'WAITING_SHIPPING'"
            );
            $trolley->execute([$taskId]);
            $task = $trolley->fetch(PDO::FETCH_ASSOC);
            if ($task === false) {
                throw new InvalidArgumentException('This empty trolley is not waiting at Shipping.');
            }

            $palletType = trim((string) ($task['pallet_type'] ?? ''));
            $releasePallet = $pdo->prepare(
                'UPDATE [dbo].[tb_03_pallet_ship] SET [task_id] = NULL, [update_at] = ? WHERE [task_id] = ?'
            );
            $releasePallet->execute([gmdate('Y-m-d H:i:s'), $taskId]);

            $statement = $pdo->prepare(
                "UPDATE [dbo].[tb_09_task_active]
                 SET [status] = 'PENDING', [code_source] = 'Shipping',
                     [code_dest] = 'TopUp Station', [finished_at] = NULL
                 WHERE [task_id] = ?
                   AND UPPER(LTRIM(RTRIM(COALESCE([task_type], '')))) = 'TROLLEY_RETURN'
                   AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) = 'WAITING_SHIPPING'"
            );
            $statement->execute([$taskId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The empty trolley could not be queued for AGV return.');
            }
        });

        return [
            'task_id' => $taskId,
            'task_type' => 'TROLLEY_RETURN',
            'pallet_type' => $palletType,
            'code_source' => 'Shipping',
            'code_dest' => 'TopUp Station',
            'status' => 'PENDING',
        ];
    }

    public function confirmAgvPickup(string $taskId): array
    {
        $taskId = $this->requiredText($taskId, 'Request task ID');
        $palletCode = '';
        $area = '';

        $this->database->transaction(function (PDO $pdo) use ($taskId, &$palletCode, &$area): void {
            $taskStatement = $pdo->prepare(
                'SELECT * FROM [dbo].[tb_09_task_active] WITH (UPDLOCK, ROWLOCK) WHERE [task_id] = ?'
            );
            $taskStatement->execute([$taskId]);
            $task = $taskStatement->fetch(PDO::FETCH_ASSOC);
            if ($task === false || strtoupper((string) ($task['status'] ?? '')) !== 'PENDING') {
                throw new InvalidArgumentException('Only a queued transport task can be marked as picked up.');
            }

            $taskType = strtoupper((string) ($task['task_type'] ?? ''));
            if (!in_array($taskType, ['PALLET_REQUEST', 'PRODUCTION_TO_SHIP', 'TROLLEY_RETURN'], true)) {
                throw new InvalidArgumentException('Only a queued transport task can be marked as picked up.');
            }

            $area = $this->displayProductionArea((string) ($task['code_dest'] ?? ''));
            if ($taskType === 'PRODUCTION_TO_SHIP') {
                $palletStatement = $pdo->prepare(
                    'SELECT TOP (1) * FROM [dbo].[tb_02_pallet_prod] WITH (UPDLOCK, ROWLOCK) WHERE [task_id] = ?'
                );
                $palletStatement->execute([$taskId]);
                $pallet = $palletStatement->fetch(PDO::FETCH_ASSOC);
                if ($pallet === false || strtoupper(trim((string) ($pallet['status'] ?? ''))) !== 'IN_PROGRESS') {
                    throw new InvalidArgumentException('The production pallet is no longer assigned to this delivery.');
                }
                $palletCode = (string) ($pallet['code'] ?? '');
            } elseif ($taskType === 'PALLET_REQUEST') {
                $palletCode = $this->generatedPalletCodeForTask($taskId);
            }

            $update = $pdo->prepare(
                "UPDATE [dbo].[tb_09_task_active]
                 SET [status] = 'IN_PROGRESS'
                 WHERE [task_id] = ? AND [status] = 'PENDING'"
            );
            $update->execute([$taskId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The AGV pickup could not update the transport task.');
            }
        });

        return [
            'task_id' => $taskId,
            'pallet_code' => $palletCode,
            'code_dest' => $area,
            'status' => 'IN_PROGRESS',
        ];
    }

    public function confirmArrival(string $taskId): array
    {
        $taskId = $this->requiredText($taskId, 'Transport task ID');
        $result = [];

        $this->database->transaction(function (PDO $pdo) use ($taskId, &$result): void {
            $statement = $pdo->prepare(
                'SELECT * FROM [dbo].[tb_09_task_active] WITH (UPDLOCK, ROWLOCK) WHERE [task_id] = ?'
            );
            $statement->execute([$taskId]);
            $task = $statement->fetch(PDO::FETCH_ASSOC);
            if ($task === false) {
                throw new InvalidArgumentException('This transport order is no longer active.');
            }

            $taskType = strtoupper((string) ($task['task_type'] ?? ''));
            $status = strtoupper((string) ($task['status'] ?? ''));
            $now = gmdate('Y-m-d H:i:s');
            if ($taskType === 'TROLLEY_RETURN') {
                if (!in_array($status, ['PENDING', 'IN_PROGRESS'], true)) {
                    throw new InvalidArgumentException('This trolley is not returning to TopUp.');
                }
                $this->insertTask($pdo, 'tb_10_task_history', [
                    'task_id' => $taskId,
                    'task_type' => 'TROLLEY_RETURN',
                    'status' => 'COMPLETED',
                    'pallet_type' => $task['pallet_type'] ?? null,
                    'code_source' => 'Shipping',
                    'code_dest' => 'TopUp Station',
                    'created_at' => $task['created_at'] ?? $now,
                    'finished_at' => $now,
                ]);
                $deleteTask = $pdo->prepare('DELETE FROM [dbo].[tb_09_task_active] WHERE [task_id] = ?');
                $deleteTask->execute([$taskId]);
                if ($deleteTask->rowCount() !== 1) {
                    throw new RuntimeException('The returned trolley task could not be completed.');
                }
                $result = [
                    'task_id' => $taskId,
                    'task_type' => $taskType,
                    'status' => 'COMPLETED',
                    'destination' => 'TopUp Station',
                ];
                return;
            }

            if ($status !== 'IN_PROGRESS') {
                throw new InvalidArgumentException('Confirm AGV pickup before confirming arrival.');
            }

            $palletType = trim((string) ($task['pallet_type'] ?? ''));
            $nextTaskId = $this->newTaskId();
            if ($taskType === 'PALLET_REQUEST') {
                $area = $this->normalizeProductionArea((string) ($task['code_dest'] ?? ''));
                $palletCode = $this->generatedPalletCodeForTask($taskId);
                $this->insertPallet($pdo, 'tb_02_pallet_prod', [
                    'code' => $palletCode,
                    'status' => 'READY',
                    'pallet_type' => $palletType,
                    'task_id' => $nextTaskId,
                    'update_at' => $now,
                    'group_area' => $area,
                ]);
                $this->insertTask($pdo, 'tb_10_task_history', [
                    'task_id' => $taskId,
                    'task_type' => 'PALLET_REQUEST',
                    'status' => 'COMPLETED',
                    'pallet_type' => $palletType,
                    'code_source' => 'TopUp Station',
                    'code_dest' => $area,
                    'created_at' => $task['created_at'] ?? $now,
                    'finished_at' => $now,
                ]);
                $updateCycle = $pdo->prepare(
                    "UPDATE [dbo].[tb_09_task_active]
                     SET [task_id] = ?, [task_type] = 'TROLLEY_RETURN', [status] = 'WAITING_PRODUCTION',
                         [code_source] = ?, [code_dest] = ?, [finished_at] = NULL
                     WHERE [task_id] = ?"
                );
                $updateCycle->execute([$nextTaskId, $palletCode, $area, $taskId]);
                if ($updateCycle->rowCount() !== 1) {
                    throw new RuntimeException('The trolley could not be reserved at Production.');
                }
                $destination = $area;
            } elseif ($taskType === 'PRODUCTION_TO_SHIP') {
                $area = $this->normalizeProductionArea((string) ($task['code_source'] ?? ''));
                $palletStatement = $pdo->prepare(
                    'SELECT TOP (1) * FROM [dbo].[tb_02_pallet_prod] WITH (UPDLOCK, ROWLOCK) WHERE [task_id] = ?'
                );
                $palletStatement->execute([$taskId]);
                $pallet = $palletStatement->fetch(PDO::FETCH_ASSOC);
                if ($pallet === false || strtoupper(trim((string) ($pallet['status'] ?? ''))) !== 'IN_PROGRESS') {
                    throw new InvalidArgumentException('The production pallet is no longer assigned to this shipment.');
                }
                $palletCode = (string) $pallet['code'];
                $delete = $pdo->prepare('DELETE FROM [dbo].[tb_02_pallet_prod] WHERE [code] = ? AND [task_id] = ?');
                $delete->execute([$palletCode, $taskId]);
                if ($delete->rowCount() !== 1) {
                    throw new RuntimeException('The pallet could not be moved out of Production inventory.');
                }
                $pallet['status'] = 'READY';
                $pallet['task_id'] = $nextTaskId;
                $pallet['update_at'] = $now;
                $this->insertPallet($pdo, 'tb_03_pallet_ship', $pallet);
                $this->insertTask($pdo, 'tb_10_task_history', [
                    'task_id' => $taskId,
                    'task_type' => 'PRODUCTION_TO_SHIP',
                    'status' => 'COMPLETED',
                    'pallet_type' => $palletType,
                    'code_source' => $area,
                    'code_dest' => 'Shipping',
                    'created_at' => $task['created_at'] ?? $now,
                    'finished_at' => $now,
                ]);
                $updateReturn = $pdo->prepare(
                    "UPDATE [dbo].[tb_09_task_active]
                     SET [task_id] = ?, [task_type] = 'TROLLEY_RETURN', [status] = 'WAITING_SHIPPING',
                         [code_source] = 'Shipping', [code_dest] = 'TopUp Station', [finished_at] = NULL
                     WHERE [task_id] = ?"
                );
                $updateReturn->execute([$nextTaskId, $taskId]);
                if ($updateReturn->rowCount() !== 1) {
                    throw new RuntimeException('The empty trolley could not be queued at Shipping.');
                }
                $destination = 'Shipping';
            } else {
                throw new InvalidArgumentException('This transport order does not support arrival confirmation.');
            }

            $result = [
                'task_id' => $taskId,
                'task_type' => $taskType,
                'next_task_id' => $nextTaskId,
                'status' => 'COMPLETED',
                'pallet_code' => $palletCode,
                'destination' => $destination,
            ];
        });

        return $result;
    }

    public function createTask(string $taskType, string $code, string $area, string $palletType): array
    {
        $taskType = strtoupper(trim($taskType));
        $code = trim($code);
        $area = trim($area);
        $palletType = trim($palletType);
        $taskId = $this->newTaskId();

        if ($taskType === 'PALLET_REQUEST') {
            $area = $this->normalizeProductionArea($this->requiredText($area, 'Destination area'));
            $palletType = $this->validatePalletType($palletType);
            $this->database->transaction(function (PDO $pdo) use ($taskId, $area, $palletType): void {
                $busyTrolleys = $pdo->query(
                    "SELECT COUNT_BIG(*) FROM [dbo].[tb_09_task_active] AS [active_task] WITH (UPDLOCK, HOLDLOCK)
                     WHERE (
                       (UPPER(LTRIM(RTRIM(COALESCE([active_task].[task_type], '')))) = 'PALLET_REQUEST'
                         AND UPPER(LTRIM(RTRIM(COALESCE([active_task].[status], '')))) IN ('WAITING_TOPUP', 'PENDING', 'IN_PROGRESS'))
                       OR (UPPER(LTRIM(RTRIM(COALESCE([active_task].[task_type], '')))) = 'PRODUCTION_TO_SHIP'
                         AND UPPER(LTRIM(RTRIM(COALESCE([active_task].[status], '')))) IN ('PENDING', 'IN_PROGRESS'))
                       OR (UPPER(LTRIM(RTRIM(COALESCE([active_task].[task_type], '')))) = 'TROLLEY_RETURN'
                         AND (
                           (UPPER(LTRIM(RTRIM(COALESCE([active_task].[status], '')))) = 'WAITING_PRODUCTION'
                             AND UPPER(LTRIM(RTRIM(COALESCE([active_task].[code_dest], ''))))
                                 IN ('STATION 01', 'AREA 1', 'STATION 02', 'AREA 2'))
                           OR (UPPER(LTRIM(RTRIM(COALESCE([active_task].[status], ''))))
                                 IN ('WAITING_SHIPPING', 'PENDING', 'IN_PROGRESS')
                             AND UPPER(LTRIM(RTRIM(COALESCE([active_task].[code_source], '')))) = 'SHIPPING'
                             AND UPPER(LTRIM(RTRIM(COALESCE([active_task].[code_dest], '')))) = 'TOPUP STATION')
                         ))
                     )"
                )->fetchColumn();
                if ((int) $busyTrolleys >= self::TROLLEY_CAPACITY) {
                    throw new InvalidArgumentException('Both trolleys are in use. Try again after an AGV confirms a trolley has returned.');
                }

                $activeRequest = $pdo->prepare(
                    "SELECT TOP (1) [task_id] FROM [dbo].[tb_09_task_active] WITH (UPDLOCK, HOLDLOCK)
                     WHERE [task_type] = 'PALLET_REQUEST' AND [code_dest] IN (?, ?)
                       AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) IN ('WAITING_TOPUP', 'PENDING', 'IN_PROGRESS')"
                );
                $activeRequest->execute($this->productionAreaDatabaseValues($area));
                if ($activeRequest->fetch(PDO::FETCH_ASSOC) !== false) {
                    throw new InvalidArgumentException('This area already has a pallet request in progress.');
                }

                $areaPallet = $pdo->prepare(
                    "SELECT TOP (1) [code] FROM [dbo].[tb_02_pallet_prod] WITH (UPDLOCK, HOLDLOCK)
                     WHERE [group_area] IN (?, ?)
                       AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) IN ('READY', 'AVAILABLE')"
                );
                $areaPallet->execute($this->productionAreaDatabaseValues($area));
                if ($areaPallet->fetch(PDO::FETCH_ASSOC) !== false) {
                    throw new InvalidArgumentException('This production area already has a pallet. Send it to Shipping before requesting another.');
                }
                if ($this->hasProductionShipmentInProgress($pdo, $area)) {
                    throw new InvalidArgumentException('This production area is sending a pallet to Shipping.');
                }

                $this->insertTask($pdo, 'tb_09_task_active', [
                    'task_id' => $taskId,
                    'task_type' => 'PALLET_REQUEST',
                    'status' => 'WAITING_TOPUP',
                    'pallet_type' => $palletType,
                    'code_source' => null,
                    'code_dest' => $area,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'finished_at' => null,
                ]);
            });

            return [
                'task_id' => $taskId,
                'task_type' => $taskType,
                'status' => 'WAITING_TOPUP',
                'code_source' => null,
                'code_dest' => $area,
            ];
        }

        if ($taskType === 'PRODUCTION_TO_SHIP') {
            $code = $this->requiredText($code, 'Production pallet code');
            $taskId = '';
            $area = '';
            $this->database->transaction(function (PDO $pdo) use (
                $code,
                &$taskId,
                &$area
            ): void {
                $pallet = $this->lockPallet($pdo, 'tb_02_pallet_prod', $code);
                if ($pallet === null || !$this->isReady($pallet) || trim((string) ($pallet['task_id'] ?? '')) === '') {
                    throw new InvalidArgumentException('The production pallet is no longer available to ship. Refresh the page and try again.');
                }

                $taskId = (string) $pallet['task_id'];
                $area = $this->normalizeProductionArea((string) ($pallet['group_area'] ?? ''));
                $trolleyTask = $pdo->prepare(
                    "SELECT TOP (1) [task_id] FROM [dbo].[tb_09_task_active] WITH (UPDLOCK, ROWLOCK)
                     WHERE [task_id] = ?
                       AND UPPER(LTRIM(RTRIM(COALESCE([task_type], '')))) = 'TROLLEY_RETURN'
                       AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) = 'WAITING_PRODUCTION'"
                );
                $trolleyTask->execute([$taskId]);
                if ($trolleyTask->fetch(PDO::FETCH_ASSOC) === false) {
                    throw new InvalidArgumentException('This pallet is not attached to an available trolley at Production.');
                }
                $updatePallet = $pdo->prepare(
                    "UPDATE [dbo].[tb_02_pallet_prod]
                     SET [status] = 'IN_PROGRESS', [update_at] = ?
                     WHERE [code] = ? AND [task_id] = ? AND [status] IN ('READY', 'AVAILABLE')"
                );
                $updatePallet->execute([gmdate('Y-m-d H:i:s'), $code, $taskId]);
                if ($updatePallet->rowCount() !== 1) {
                    throw new InvalidArgumentException('The Production pallet is no longer ready to ship.');
                }
                $updateTask = $pdo->prepare(
                    "UPDATE [dbo].[tb_09_task_active]
                     SET [task_type] = 'PRODUCTION_TO_SHIP', [status] = 'PENDING',
                         [code_source] = ?, [code_dest] = 'Shipping', [finished_at] = NULL
                     WHERE [task_id] = ?
                       AND UPPER(LTRIM(RTRIM(COALESCE([task_type], '')))) = 'TROLLEY_RETURN'
                       AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) = 'WAITING_PRODUCTION'"
                );
                $updateTask->execute([$area, $taskId]);
                if ($updateTask->rowCount() !== 1) {
                    throw new RuntimeException('The AGV task could not be queued for Shipping.');
                }
            });

            return [
                'task_id' => $taskId,
                'task_type' => $taskType,
                'status' => 'PENDING',
                'code_source' => $area,
                'code_dest' => 'Shipping',
            ];
        } else {
            throw new InvalidArgumentException('Unsupported transport task type.');
        }
    }

    private function allRows(string $table): array
    {
        $rows = [];
        $page = 1;
        do {
            $result = $this->database->listRows('dbo', $table, $page, 100);
            array_push($rows, ...$result['rows']);
            $page++;
        } while ($page <= $result['total_pages']);

        return $rows;
    }

    private function palletView(array $row): array
    {
        $type = trim((string) ($row['pallet_type'] ?? '')) ?: 'Unassigned';
        return [
            'code' => (string) ($row['code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'type' => $type,
            'area' => $this->displayProductionArea((string) ($row['group_area'] ?? '')),
            'updated_at' => $row['update_at'] ?? null,
        ];
    }

    private function palletArea(array $pallets, string $code): string
    {
        foreach ($pallets as $pallet) {
            if (strcasecmp((string) ($pallet['code'] ?? ''), $code) === 0) {
                return $this->displayProductionArea((string) ($pallet['group_area'] ?? ''));
            }
        }
        return '';
    }

    private function palletCodeForTask(array $pallets, string $taskId): string
    {
        foreach ($pallets as $pallet) {
            if ((string) ($pallet['task_id'] ?? '') === $taskId) {
                return (string) ($pallet['code'] ?? '');
            }
        }
        return '';
    }

    private function generatedPalletCodeForTask(string $taskId): string
    {
        return 'PLT-' . substr($taskId, 3);
    }

    private function taskView(array $row): array
    {
        $taskType = strtoupper((string) ($row['task_type'] ?? ''));
        $category = match ($taskType) {
            'PALLET_REQUEST' => 'Pallet Request',
            'PRODUCTION_TO_SHIP' => 'Production to Ship',
            'SHIP_TO_TOPUP' => 'Ship to TopUp',
            'TROLLEY_RETURN' => 'Trolley Return',
            'TOPUP_STATION' => 'TopUp Station',
            default => $taskType,
        };
        $source = (string) ($row['code_source'] ?? '');
        $destination = (string) ($row['code_dest'] ?? '');

        return [
            'id' => (string) ($row['task_id'] ?? ''),
            'category' => $category,
            'area' => $taskType === 'PALLET_REQUEST' ? $this->displayProductionArea($destination) : $source,
            'type' => $taskType === 'TROLLEY_RETURN'
                ? '—'
                : (trim((string) ($row['pallet_type'] ?? '')) ?: 'Unassigned'),
            'from' => $source,
            'to' => $this->displayProductionArea($destination),
            'status' => $this->displayStatus((string) ($row['status'] ?? 'PENDING')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'time' => $row['created_at'] ?? null,
        ];
    }

    private function isVisibleHistoryRow(array $row): bool
    {
        return !in_array(
            strtoupper((string) ($row['task_type'] ?? '')),
            ['TROLLEY_RETURN', 'SHIP_TO_TOPUP'],
            true
        );
    }

    private function historyRowMatches(array $row, string $search): bool
    {
        $needle = strtolower($search);
        foreach ($this->taskView($row) as $value) {
            if (str_contains(strtolower((string) ($value ?? '')), $needle)) {
                return true;
            }
        }
        return false;
    }

    private function displayStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'WAITING_TOPUP' => 'Waiting TopUp',
            'PENDING' => 'Pending',
            'IN_PROGRESS', 'RUN' => 'In Progress',
            'COMPLETED', 'DONE' => 'Completed',
            'CANCELLED', 'CANCELED' => 'Cancelled',
            default => $status,
        };
    }

    private function needsRefill(array $pallet): bool
    {
        return in_array(strtoupper(trim((string) ($pallet['status'] ?? ''))), self::TOPUP_NEEDS_REFILL, true);
    }

    private function isReady(array $pallet): bool
    {
        return in_array(strtoupper(trim((string) ($pallet['status'] ?? ''))), self::PALLET_READY, true);
    }

    private function hasProductionShipmentInProgress(PDO $pdo, string $area): bool
    {
        $statement = $pdo->prepare(
            "SELECT TOP (1) [task_id] FROM [dbo].[tb_09_task_active] WITH (UPDLOCK, HOLDLOCK)
             WHERE UPPER(LTRIM(RTRIM(COALESCE([task_type], '')))) = 'PRODUCTION_TO_SHIP'
               AND [code_source] IN (?, ?)
               AND UPPER(LTRIM(RTRIM(COALESCE([status], '')))) IN ('PENDING', 'IN_PROGRESS')"
        );
        $statement->execute($this->productionAreaDatabaseValues($area));

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function isActiveTrolleyReturn(array $task): bool
    {
        if (strtoupper(trim((string) ($task['task_type'] ?? ''))) !== 'TROLLEY_RETURN') {
            return false;
        }

        $status = strtoupper(trim((string) ($task['status'] ?? '')));
        if ($status === 'WAITING_PRODUCTION') {
            return in_array(
                $this->displayProductionArea((string) ($task['code_dest'] ?? '')),
                self::PRODUCTION_AREAS,
                true
            );
        }

        return in_array($status, ['WAITING_SHIPPING', 'PENDING', 'IN_PROGRESS'], true)
            && strcasecmp(trim((string) ($task['code_source'] ?? '')), 'Shipping') === 0
            && strcasecmp(trim((string) ($task['code_dest'] ?? '')), 'TopUp Station') === 0;
    }

    private function usesTrolley(array $task): bool
    {
        $taskType = strtoupper(trim((string) ($task['task_type'] ?? '')));
        $status = strtoupper(trim((string) ($task['status'] ?? '')));

        return ($taskType === 'PALLET_REQUEST'
                && in_array($status, ['WAITING_TOPUP', 'PENDING', 'IN_PROGRESS'], true))
            || ($taskType === 'PRODUCTION_TO_SHIP'
                && in_array(
                    $status,
                    ['PENDING', 'IN_PROGRESS'],
                    true
                ))
            || $this->isActiveTrolleyReturn($task);
    }

    private function validatePalletType(string $palletType): string
    {
        $palletType = trim($palletType);
        if (!in_array($palletType, ['Plastic Pallet', 'Wooden Pallet', 'Blue Pallet'], true)) {
            throw new InvalidArgumentException('Pallet type must be Plastic Pallet, Wooden Pallet, or Blue Pallet.');
        }

        return $palletType;
    }

    private function normalizeProductionArea(string $area): string
    {
        $area = $this->displayProductionArea($area);
        if (in_array($area, self::PRODUCTION_AREAS, true)) {
            return $area;
        }
        throw new InvalidArgumentException('Production area must be Station 01 or Station 02.');
    }

    private function displayProductionArea(string $area): string
    {
        $area = trim($area);
        foreach (self::PRODUCTION_AREAS as $productionArea) {
            if (strcasecmp($area, $productionArea) === 0) {
                return $productionArea;
            }
        }
        $legacyAreas = [
            'Area 1' => 'Station 01',
            'Area 2' => 'Station 02',
        ];
        foreach ($legacyAreas as $legacyArea => $productionArea) {
            if (strcasecmp($area, $legacyArea) === 0) {
                return $productionArea;
            }
        }
        return $area;
    }

    private function productionAreaDatabaseValues(string $area): array
    {
        $area = $this->normalizeProductionArea($area);
        return $area === 'Station 01'
            ? ['Station 01', 'Area 1']
            : ['Station 02', 'Area 2'];
    }

    private function optionalPalletType(string $palletType): string
    {
        if ($palletType === '') {
            return '';
        }
        return $this->validatePalletType($palletType);
    }

    private function requiredText(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255) {
            throw new InvalidArgumentException($label . ' is required and must not exceed 255 characters.');
        }

        return $value;
    }

    private function findPallet(string $table, string $code): ?array
    {
        foreach ($this->allRows($table) as $pallet) {
            if (strcasecmp((string) ($pallet['code'] ?? ''), $code) === 0) {
                return $pallet;
            }
        }

        return null;
    }

    private function findRouteDestination(
        string $routeTable,
        string $sourceColumn,
        string $area,
        string $palletCode,
        string $destinationColumn
    ): ?string {
        foreach ($this->allRows($routeTable) as $route) {
            $routeSource = trim((string) ($route[$sourceColumn] ?? ''));
            if ($routeSource !== '' && (
                strcasecmp($routeSource, $area) === 0
                || strcasecmp($routeSource, $palletCode) === 0
            )) {
                return $this->requiredText((string) ($route[$destinationColumn] ?? ''), 'Route destination');
            }
        }
        return null;
    }

    private function newTaskId(): string
    {
        return 'TO-' . gmdate('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function lockPallet(PDO $pdo, string $table, string $code): ?array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM [dbo].[' . $table . '] WITH (UPDLOCK, ROWLOCK) WHERE [code] = ?'
        );
        $statement->execute([$code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function insertPallet(PDO $pdo, string $table, array $pallet): void
    {
        $this->insertTask($pdo, $table, [
            'code' => (string) ($pallet['code'] ?? ''),
            'status' => (string) ($pallet['status'] ?? 'READY'),
            'pallet_type' => $pallet['pallet_type'] ?? null,
            'task_id' => $pallet['task_id'] ?? null,
            'update_at' => $pallet['update_at'] ?? gmdate('Y-m-d H:i:s'),
            'group_area' => $pallet['group_area'] ?? null,
        ]);
    }

    private function updatePallet(PDO $pdo, string $table, string $code, array $values): void
    {
        $columns = array_keys($values);
        $set = implode(', ', array_map(static fn (string $column): string => '[' . $column . '] = ?', $columns));
        $statement = $pdo->prepare('UPDATE [dbo].[' . $table . '] SET ' . $set . ' WHERE [code] = ?');
        $statement->execute([...array_values($values), $code]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The pallet record could not be updated.');
        }
    }

    private function insertTask(PDO $pdo, string $table, array $values): void
    {
        $columns = array_keys($values);
        $quotedColumns = implode(', ', array_map(static fn (string $column): string => '[' . $column . ']', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $pdo->prepare(
            'INSERT INTO [dbo].[' . $table . '] (' . $quotedColumns . ') VALUES (' . $placeholders . ')'
        );
        $statement->execute(array_values($values));
    }
}
