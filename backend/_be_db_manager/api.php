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
        $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['ok' => false, 'error' => 'Request body must contain valid JSON.']);
    }

    if (!is_array($data)) {
        respond(400, ['ok' => false, 'error' => 'Request body must be a JSON object.']);
    }

    return $data;
}

function validateModuleTable(array $config, string $module, string $table): void
{
    if (!isset($config['modules'][$module])) {
        throw new InvalidArgumentException('Module is not configured.');
    }

    $allowedTables = $config['modules'][$module]['tables'];
    if (is_array($allowedTables) && !in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Table is not part of the selected module.');
    }
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseManager.php';
$config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

try {
    if ($action === 'modules' && $method === 'GET') {
        respond(200, ['ok' => true, 'modules' => $config['modules']]);
    }

    if ($action === 'databases' && $method === 'GET') {
        respond(200, [
            'ok' => true,
            'databases' => $config['databases'],
            'default_database' => $config['database'],
        ]);
    }

    $database = (string) ($_GET['database'] ?? $config['database']);
    $manager = new DatabaseManager($config, $database);

    if ($action === 'status' && $method === 'GET') {
        respond(200, ['ok' => true, 'connection' => $manager->status()]);
    }

    if ($action === 'tables' && $method === 'GET') {
        respond(200, ['ok' => true, 'tables' => $manager->listTables()]);
    }

    if ($action === 'rows' && $method === 'GET') {
        $table = (string) ($_GET['table'] ?? '');
        validateModuleTable($config, (string) ($_GET['module'] ?? 'all'), $table);
        $filters = [];
        if (isset($_GET['filters'])) {
            if (!is_string($_GET['filters'])) {
                respond(400, ['ok' => false, 'error' => 'Row filters must be a JSON string.']);
            }
            try {
                $filters = json_decode($_GET['filters'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                respond(400, ['ok' => false, 'error' => 'Row filters must contain valid JSON.']);
            }
            if (!is_array($filters) || !array_is_list($filters) || count($filters) > 4) {
                respond(400, ['ok' => false, 'error' => 'Row filters must be a list of at most four filters.']);
            }
            foreach ($filters as $filter) {
                if (!is_array($filter) || !is_string($filter['operator'] ?? null) || !is_string($filter['value'] ?? null) || strlen($filter['value']) > 255) {
                    respond(400, ['ok' => false, 'error' => 'Each row filter needs a supported operator and a value of at most 255 characters.']);
                }
                if ($filter['operator'] === 'contains_any') {
                    if (
                        array_diff(array_keys($filter), ['operator', 'columns', 'value']) !== []
                        || !is_array($filter['columns'] ?? null)
                        || !array_is_list($filter['columns'])
                        || count($filter['columns']) < 1
                        || count($filter['columns']) > 10
                        || array_filter($filter['columns'], static fn ($column): bool => !is_string($column)) !== []
                    ) {
                        respond(400, ['ok' => false, 'error' => 'contains_any filters need a list of 1 to 10 column names.']);
                    }
                } elseif (
                    array_diff(array_keys($filter), ['column', 'operator', 'value']) !== []
                    || !is_string($filter['column'] ?? null)
                    || !in_array($filter['operator'], ['equals', 'not_equals', 'contains'], true)
                ) {
                    respond(400, ['ok' => false, 'error' => 'Row filters need a column and a supported operator.']);
                }
            }
        }
        $sort = $_GET['sort'] ?? 'ascending';
        if (!is_string($sort) || !in_array($sort, ['ascending', 'descending'], true)) {
            respond(400, ['ok' => false, 'error' => 'Row sort must be ascending or descending.']);
        }
        $result = $manager->listRows(
            (string) ($_GET['schema'] ?? 'dbo'),
            $table,
            (int) ($_GET['page'] ?? 1),
            (int) ($_GET['page_size'] ?? 25),
            $filters,
            $sort === 'descending'
        );
        respond(200, ['ok' => true, ...$result]);
    }

    if (in_array($action, ['create', 'update', 'delete'], true)) {
        $expectedMethod = ['create' => 'POST', 'update' => 'PUT', 'delete' => 'DELETE'][$action];
        if ($method !== $expectedMethod) {
            header('Allow: ' . $expectedMethod);
            respond(405, ['ok' => false, 'error' => 'Method not allowed for this action.']);
        }

        $body = readRequestBody();
        $schema = (string) ($body['schema'] ?? 'dbo');
        $table = (string) ($body['table'] ?? '');

        if ($table === '' || !isset($body['values']) && $action !== 'delete') {
            respond(400, ['ok' => false, 'error' => 'A table and values object are required.']);
        }
        validateModuleTable($config, (string) ($body['module'] ?? 'all'), $table);

        if ($action === 'create') {
            if (!is_array($body['values'])) {
                respond(400, ['ok' => false, 'error' => 'Values must be a JSON object.']);
            }
            $manager->createRow($schema, $table, $body['values']);
        } elseif ($action === 'update') {
            if (!is_array($body['key'] ?? null) || !is_array($body['values'] ?? null)) {
                respond(400, ['ok' => false, 'error' => 'Update requires key and values objects.']);
            }
            $manager->updateRow($schema, $table, $body['key'], $body['values']);
        } else {
            if (!is_array($body['key'] ?? null)) {
                respond(400, ['ok' => false, 'error' => 'Delete requires a key object.']);
            }
            $manager->deleteRow($schema, $table, $body['key']);
        }

        respond(200, ['ok' => true, 'message' => 'Record ' . $action . 'd.']);
    }

    respond(404, ['ok' => false, 'error' => 'Unknown action.']);
} catch (InvalidArgumentException $exception) {
    respond(400, ['ok' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    respond(503, ['ok' => false, 'error' => $exception->getMessage()]);
}