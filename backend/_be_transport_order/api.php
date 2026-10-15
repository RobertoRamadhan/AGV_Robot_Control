<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function readRequestBody(): array
{
    try {
        $body = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['ok' => false, 'error' => 'Request body must contain valid JSON.']);
    }

    if (!is_array($body)) {
        respond(400, ['ok' => false, 'error' => 'Request body must be a JSON object.']);
    }
    return $body;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TransportOrderService.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($_GET['action'] ?? '');
    if (!in_array($action, ['dashboard', 'history', 'topup-pallets', 'delete-topup-pallet', 'dispatch-to-station', 'dispatch-trolley-to-topup', 'topup-at-station', 'refill', 'create-task', 'send-request', 'confirm-pickup', 'confirm-arrival'], true)) {
        respond(404, ['ok' => false, 'error' => 'Unknown action.']);
    }
    $expectedMethod = in_array($action, ['dashboard', 'history', 'topup-pallets'], true) ? 'GET' : 'POST';
    if ($method !== $expectedMethod) {
        header('Allow: ' . $expectedMethod);
        respond(405, ['ok' => false, 'error' => 'Method not allowed for this action.']);
    }

    $body = null;
    if ($method === 'POST') {
        $body = readRequestBody();
        if ($action === 'refill' && (
            !is_string($body['code'] ?? null)
            || !is_string($body['pallet_type'] ?? null)
            || array_diff(array_keys($body), ['code', 'pallet_type']) !== []
        )) {
            respond(400, ['ok' => false, 'error' => 'Pallet code and pallet_type are required.']);
        }
        if ($action === 'delete-topup-pallet' && (
            !is_string($body['code'] ?? null)
            || array_diff(array_keys($body), ['code']) !== []
        )) {
            respond(400, ['ok' => false, 'error' => 'Pallet code is required.']);
        }
        if ($action === 'dispatch-to-station' && (
            !is_string($body['area'] ?? null)
            || !is_string($body['pallet_type'] ?? null)
            || array_diff(array_keys($body), ['area', 'pallet_type']) !== []
        )) {
            respond(400, ['ok' => false, 'error' => 'Station and pallet_type are required.']);
        }
        if ($action === 'topup-at-station' && (
            !is_string($body['area'] ?? null)
            || !is_string($body['pallet_type'] ?? null)
            || array_diff(array_keys($body), ['area', 'pallet_type']) !== []
        )) {
            respond(400, ['ok' => false, 'error' => 'Station and pallet_type are required.']);
        }
        if ($action === 'create-task' && (
            !is_string($body['task_type'] ?? null)
            || !is_string($body['pallet_type'] ?? null)
            || !is_string($body['code'] ?? '')
            || !is_string($body['area'] ?? '')
            || array_diff(array_keys($body), ['task_type', 'pallet_type', 'code', 'area']) !== []
        )) {
            respond(400, [
                'ok' => false,
                'error' => 'task_type and pallet_type are required; code and area must be strings when provided.',
            ]);
        }
        if ($action === 'send-request' && (
            !is_string($body['task_id'] ?? null)
            || array_diff(array_keys($body), ['task_id']) !== []
        )) {
            respond(400, ['ok' => false, 'error' => 'task_id is required.']);
        }
        if ($action === 'dispatch-trolley-to-topup' && (
            !is_string($body['task_id'] ?? null)
            || array_diff(array_keys($body), ['task_id']) !== []
        )) {
            respond(400, ['ok' => false, 'error' => 'Trolley task_id is required.']);
        }
        if (in_array($action, ['confirm-pickup', 'confirm-arrival'], true) && (
            !is_string($body['task_id'] ?? null)
            || array_diff(array_keys($body), ['task_id']) !== []
        )) {
            respond(400, ['ok' => false, 'error' => 'task_id is required.']);
        }
    }

    $config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_be_db_manager'
        . DIRECTORY_SEPARATOR . 'config.php';
    $database = new DatabaseManager($config, (string) $config['database']);
    $service = new TransportOrderService($database);

    if ($action === 'dashboard' && $method === 'GET') {
        respond(200, $service->dashboard());
    }

    if ($action === 'topup-pallets' && $method === 'GET') {
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            respond(400, ['ok' => false, 'error' => 'TopUp inventory page must be a positive integer.']);
        }
        respond(200, $service->topupPallets($page));
    }

    if ($action === 'delete-topup-pallet' && $method === 'POST') {
        $service->deleteTopupPallet($body['code']);
        respond(200, ['ok' => true]);
    }

    if ($action === 'dispatch-to-station' && $method === 'POST') {
        respond(200, [
            'ok' => true,
            'task' => $service->refillPalletAtStation($body['area'], $body['pallet_type']),
        ]);
    }

    if ($action === 'dispatch-trolley-to-topup' && $method === 'POST') {
        respond(200, [
            'ok' => true,
            'task' => $service->dispatchTrolleyToTopup($body['task_id']),
        ]);
    }

    if ($action === 'topup-at-station' && $method === 'POST') {
        respond(200, [
            'ok' => true,
            'task' => $service->refillPalletAtStation($body['area'], $body['pallet_type']),
        ]);
    }

    if ($action === 'history' && $method === 'GET') {
        $search = $_GET['search'] ?? '';
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
        if (!is_string($search) || strlen($search) > 255) {
            respond(400, ['ok' => false, 'error' => 'History search must not exceed 255 characters.']);
        }
        if ($page === false || $page < 1) {
            respond(400, ['ok' => false, 'error' => 'History page must be a positive integer.']);
        }
        respond(200, $service->historyPage($page, $search));
    }

    if ($action === 'refill' && $method === 'POST') {
        respond(201, ['ok' => true, 'task' => $service->refillPallet($body['code'], $body['pallet_type'])]);
    }

    if ($action === 'send-request' && $method === 'POST') {
        respond(200, ['ok' => true, 'task' => $service->queuePalletRequest($body['task_id'])]);
    }

    if ($action === 'confirm-pickup' && $method === 'POST') {
        respond(200, ['ok' => true, 'task' => $service->confirmAgvPickup($body['task_id'])]);
    }

    if ($action === 'confirm-arrival' && $method === 'POST') {
        respond(200, ['ok' => true, 'task' => $service->confirmArrival($body['task_id'])]);
    }

    if ($action === 'create-task' && $method === 'POST') {
        respond(201, [
            'ok' => true,
            'task' => $service->createTask(
                $body['task_type'],
                $body['code'] ?? '',
                $body['area'] ?? '',
                $body['pallet_type']
            ),
        ]);
    }

} catch (InvalidArgumentException $error) {
    respond(400, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    respond(503, ['ok' => false, 'error' => $error->getMessage()]);
}
