<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_be_db_manager'
    . DIRECTORY_SEPARATOR . 'DatabaseManager.php';

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

final class WaypointInterlockService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly string $robotApiBaseUrl
    ) {
    }

    public function evaluate(): array
    {
        $rows = [];
        $page = 1;
        do {
            $result = $this->database->listRows('dbo', 'tb_08_area_strict', $page, 100);
            array_push($rows, ...$result['rows']);
            $page++;
        } while ($page <= $result['total_pages']);

        $results = [];
        $errors = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if ((int) ($row['activate'] ?? 0) !== 1) {
                $results[] = [
                    'id' => $id,
                    'result' => 'skipped',
                    'reason' => 'Interlock is not activated.',
                ];
                continue;
            }

            try {
                $results[] = $this->evaluateRule($row);
            } catch (Throwable $error) {
                $errors[] = ['id' => $id, 'error' => $error->getMessage()];
                $results[] = ['id' => $id, 'result' => 'error', 'error' => $error->getMessage()];
            }
        }

        return [
            'ok' => $errors === [],
            'checked_at' => gmdate(DATE_ATOM),
            'table' => 'dbo.tb_08_area_strict',
            'rules_checked' => count($rows),
            'errors' => $errors,
            'results' => $results,
        ];
    }

    public function setRuleActivation(int $id, bool $activate): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Rule ID must be a positive integer.');
        }

        $result = $this->database->listRows(
            'dbo',
            'tb_08_area_strict',
            1,
            1,
            [['column' => 'id', 'operator' => 'equals', 'value' => (string) $id]]
        );
        $row = $result['rows'][0] ?? null;
        if ($row === null) {
            throw new InvalidArgumentException('Waypoint interlock rule was not found.');
        }

        $wasActive = (int) ($row['activate'] ?? 0) === 1;
        $status = strtoupper(trim((string) ($row['status'] ?? '')));
        $isStopped = in_array($status, ['STOP', 'OFF'], true);
        $storedCheckpointRobot = trim((string) ($row['checkpoint_agv'] ?? ''));
        $storedStrictRobot = trim((string) ($row['strict_agv'] ?? ''));
        if (
            $wasActive === $activate
            && ($activate || ($status === 'OFF' && $storedCheckpointRobot === '' && $storedStrictRobot === ''))
        ) {
            return ['id' => $id, 'activate' => $activate, 'changed' => false];
        }

        $values = [
            'activate' => $activate ? 1 : 0,
            'checkpoint_agv' => null,
            'strict_agv' => null,
            'status' => $activate ? 'RUN' : 'OFF',
        ];
        if (!$activate) {
            if ($isStopped) {
                if ($storedStrictRobot === '') {
                    throw new RuntimeException(
                        'The rule is stopped but strict_agv is empty; it cannot be safely deactivated.'
                    );
                }
                $this->sendVehicleCommand('gotoSiteResume', [$storedStrictRobot]);
            }
        }

        $this->database->updateRow('dbo', 'tb_08_area_strict', ['id' => $id], $values);

        return ['id' => $id, 'activate' => $activate, 'changed' => true];
    }

    private function evaluateRule(array $row): array
    {
        $checkpointArea = trim((string) ($row['checkpoint_area'] ?? ''));
        $strictArea = trim((string) ($row['strict_area'] ?? ''));
        if ($checkpointArea === '' || $strictArea === '') {
            throw new InvalidArgumentException('Both checkpoint_area and strict_area must be configured.');
        }

        $checkpointRobots = $this->robotsInBlock($checkpointArea);
        $strictRobots = $this->robotsInBlock($strictArea);
        $storedCheckpointRobot = trim((string) ($row['checkpoint_agv'] ?? ''));
        $storedStrictRobot = trim((string) ($row['strict_agv'] ?? ''));
        $checkpointRobot = $this->selectCheckpointRobot($checkpointRobots, $storedCheckpointRobot);
        $wasStopped = in_array(
            strtoupper(trim((string) ($row['status'] ?? ''))),
            ['STOP', 'OFF'],
            true
        );
        $action = 'no-change';
        $nextStatus = 'RUN';
        $nextCheckpointRobot = $checkpointRobot;
        $nextStrictRobot = $strictRobots[0] ?? null;

        if ($wasStopped) {
            if ($storedCheckpointRobot === '') {
                throw new RuntimeException(
                    'status is STOP but checkpoint_agv is empty; cannot verify that the priority robot passed strict_area.'
                );
            }
            if ($storedStrictRobot === '') {
                throw new RuntimeException('status is STOP but strict_agv is empty; cannot safely resume a vehicle.');
            }

            $priorityRobotStillPassing = $this->containsRobot($checkpointRobots, $storedCheckpointRobot)
                || $this->containsRobot($strictRobots, $storedCheckpointRobot);
            if (!$priorityRobotStillPassing && $checkpointRobot !== null) {
                $nextCheckpointRobot = $checkpointRobot;
                $nextStrictRobot = $storedStrictRobot;
                $nextStatus = 'STOP';
                $action = 'priority-handoff';
            } elseif (!$priorityRobotStillPassing) {
                $this->sendVehicleCommand('gotoSiteResume', [$storedStrictRobot]);
                $nextCheckpointRobot = null;
                $nextStrictRobot = null;
                $action = 'resumed';
            } else {
                $nextCheckpointRobot = $storedCheckpointRobot;
                $nextStrictRobot = $storedStrictRobot;
                $nextStatus = 'STOP';
            }
        } elseif ($checkpointRobot !== null) {
            $strictCandidates = array_values(array_filter(
                $strictRobots,
                fn (string $robot): bool => strcasecmp($robot, $checkpointRobot) !== 0
            ));
            if ($strictCandidates !== []) {
                $strictRobot = $this->selectStrictRobot($strictCandidates, '', true);
                if ($strictRobot === null) {
                    throw new RuntimeException('No unique robot could be selected from strict_area.');
                }
                $this->sendVehicleCommand('gotoSitePause', [$strictRobot]);
                $nextCheckpointRobot = $checkpointRobot;
                $nextStrictRobot = $strictRobot;
                $nextStatus = 'STOP';
                $action = 'paused';
            }
        }

        $this->database->updateRow(
            'dbo',
            'tb_08_area_strict',
            ['id' => $row['id']],
            [
                'checkpoint_agv' => $nextCheckpointRobot,
                'strict_agv' => $nextStrictRobot,
                'status' => $nextStatus,
            ]
        );

        return [
            'id' => $row['id'],
            'checkpoint_area' => $checkpointArea,
            'checkpoint_agv' => $nextCheckpointRobot,
            'strict_area' => $strictArea,
            'strict_agv' => $nextStrictRobot,
            'status' => $nextStatus,
            'result' => $action,
        ];
    }

    private function selectCheckpointRobot(array $robots, string $storedRobot): ?string
    {
        if ($robots === []) {
            return null;
        }
        if ($storedRobot !== '' && $this->containsRobot($robots, $storedRobot)) {
            foreach ($robots as $robot) {
                if (strcasecmp($robot, $storedRobot) === 0) {
                    return $robot;
                }
            }
        }
        if (count($robots) > 1) {
            throw new RuntimeException(
                'checkpoint_area contains multiple robots and the priority robot cannot be identified safely.'
            );
        }

        return $robots[0];
    }

    private function containsRobot(array $robots, string $name): bool
    {
        foreach ($robots as $robot) {
            if (strcasecmp($robot, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function selectStrictRobot(array $robots, string $storedRobot, bool $isBlocked): ?string
    {
        if ($robots === []) {
            return null;
        }

        if ($storedRobot !== '') {
            foreach ($robots as $robot) {
                if (strcasecmp($robot, $storedRobot) === 0) {
                    return $robot;
                }
            }
        }

        if ($isBlocked && count($robots) > 1) {
            throw new RuntimeException(
                'strict_area contains multiple robots and strict_agv does not identify one of them.'
            );
        }

        return $robots[0];
    }

    private function robotsInBlock(string $block): array
    {
        $url = rtrim($this->robotApiBaseUrl, '/')
            . '/getRobotsInBlock?'
            . http_build_query(['block' => $block]);
        $response = $this->request($url, 'GET');

        try {
            $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('getRobotsInBlock returned invalid JSON.', 0, $error);
        }

        if (!is_array($payload) || !isset($payload['robots']) || !is_array($payload['robots'])) {
            throw new RuntimeException('getRobotsInBlock response must contain a robots array.');
        }

        $robots = [];
        foreach ($payload['robots'] as $robot) {
            if (!is_string($robot) || trim($robot) === '') {
                throw new RuntimeException('Every value in the robots array must be a non-empty robot name.');
            }
            $robots[] = trim($robot);
        }

        return $robots;
    }

    private function sendVehicleCommand(string $endpoint, array $vehicles): void
    {
        $url = rtrim($this->robotApiBaseUrl, '/') . '/' . $endpoint;
        $response = $this->request(
            $url,
            'POST',
            json_encode(['vehicles' => array_values($vehicles)], JSON_THROW_ON_ERROR)
        );

        if (trim($response) === '') {
            return;
        }

        try {
            $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (is_array($payload) && (
            ($payload['ok'] ?? true) === false
            || ($payload['success'] ?? true) === false
        )) {
            throw new RuntimeException($endpoint . ' rejected the vehicle command.');
        }
    }

    private function request(string $url, string $method, ?string $body = null): string
    {
        $options = [
            'http' => [
                'method' => $method,
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ];
        if ($body !== null) {
            $options['http']['header'] .= "Content-Type: application/json\r\n";
            $options['http']['content'] = $body;
        }

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException(
                'Robot API request failed: ' . ($error['message'] ?? 'unknown network error')
            );
        }

        $status = null;
        foreach ($http_response_header ?? [] as $statusLine) {
            if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
                $status = (int) $matches[1];
            }
        }
        if ($status === null) {
            throw new RuntimeException('Robot API returned an invalid HTTP status line.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Robot API returned HTTP ' . $status . '.');
        }

        return $response;
    }
}

$config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_be_db_manager'
    . DIRECTORY_SEPARATOR . 'config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($_GET['action'] ?? '');
    if ($method !== 'POST') {
        header('Allow: POST');
        respond(405, ['ok' => false, 'error' => 'Use POST for waypoint interlock actions.']);
    }
    if ($action === 'create-rule') {
        $body = readRequestBody();
        if (
            !is_string($body['checkpoint_area'] ?? null)
            || !is_string($body['strict_area'] ?? null)
        ) {
            respond(400, ['ok' => false, 'error' => 'Both area names must be strings.']);
        }
        $checkpointArea = trim($body['checkpoint_area']);
        $strictArea = trim($body['strict_area']);
        if ($checkpointArea === '' || $strictArea === '') {
            respond(400, ['ok' => false, 'error' => 'Both checkpoint_area and strict_area are required.']);
        }
        if (array_diff(array_keys($body), ['checkpoint_area', 'strict_area']) !== []) {
            respond(400, ['ok' => false, 'error' => 'Only checkpoint_area and strict_area may be provided.']);
        }

        $database = (string) ($config['database'] ?? '');
        $manager = new DatabaseManager($config, $database);
        $manager->createRowWithNextIntegerId(
            'dbo',
            'tb_08_area_strict',
            'id',
            [
                'checkpoint_area' => $checkpointArea,
                'strict_area' => $strictArea,
                'checkpoint_agv' => null,
                'strict_agv' => null,
                'status' => 'RUN',
                'activate' => 1,
            ]
        );
        respond(201, ['ok' => true, 'message' => 'Waypoint interlock rule created.']);
    }
    if ($action === 'set-activation') {
        $body = readRequestBody();
        if (
            !is_int($body['id'] ?? null)
            || !is_bool($body['activate'] ?? null)
            || array_diff(array_keys($body), ['id', 'activate']) !== []
        ) {
            respond(400, ['ok' => false, 'error' => 'A rule ID and boolean activate value are required.']);
        }

        $database = (string) ($config['database'] ?? '');
        $manager = new DatabaseManager($config, $database);
        $baseUrl = trim((string) (getenv('WAYPOINT_ROBOT_API_BASE_URL') ?: 'http://idgbthb2a1vw103:8088'));
        $urlParts = parse_url($baseUrl);
        if (
            filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !is_array($urlParts)
            || !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('WAYPOINT_ROBOT_API_BASE_URL must be a valid HTTP or HTTPS URL.');
        }
        $result = (new WaypointInterlockService($manager, $baseUrl))
            ->setRuleActivation($body['id'], $body['activate']);
        respond(200, ['ok' => true, ...$result]);
    }
    if ($action !== 'evaluate') {
        respond(404, ['ok' => false, 'error' => 'Unknown action.']);
    }

    readRequestBody();
    $database = (string) ($config['database'] ?? '');
    $manager = new DatabaseManager($config, $database);
    $baseUrl = trim((string) (getenv('WAYPOINT_ROBOT_API_BASE_URL') ?: 'http://idgbthb2a1vw103:8088'));
    $urlParts = parse_url($baseUrl);
    if (
        filter_var($baseUrl, FILTER_VALIDATE_URL) === false
        || !is_array($urlParts)
        || !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true)
    ) {
        throw new InvalidArgumentException('WAYPOINT_ROBOT_API_BASE_URL must be a valid HTTP or HTTPS URL.');
    }

    $result = (new WaypointInterlockService($manager, $baseUrl))->evaluate();
    respond($result['ok'] ? 200 : 502, $result);
} catch (InvalidArgumentException $error) {
    respond(400, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    respond(503, ['ok' => false, 'error' => $error->getMessage()]);
}
