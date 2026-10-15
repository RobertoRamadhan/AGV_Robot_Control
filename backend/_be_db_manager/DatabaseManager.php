<?php
declare(strict_types=1);

final class DatabaseManager
{
    private PDO $pdo;
    private string $database;

    public function __construct(private readonly array $config, string $database)
    {
        if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The PHP PDO_SQLSRV driver is not installed or enabled.');
        }

        if (!in_array($database, $config['databases'], true)) {
            throw new InvalidArgumentException('Database is not configured for DB Manager.');
        }

        if ($config['server'] === '') {
            throw new RuntimeException('DB_SQLSRV_SERVER is not configured.');
        }

        if (!$config['trusted_connection'] && ($config['username'] === '' || $config['password'] === '')) {
            throw new RuntimeException('DB_SQLSRV_USERNAME and DB_SQLSRV_PASSWORD are required.');
        }

        $server = $config['server'];
        if ($config['port'] !== '') {
            $server .= ',' . $config['port'];
        }

        $dsn = sprintf(
            'sqlsrv:Server=%s;Database=%s;Encrypt=%d;TrustServerCertificate=%d',
            $server,
            $database,
            $config['encrypt'] ? 1 : 0,
            $config['trust_server_certificate'] ? 1 : 0
        );

        $this->database = $database;
        $this->pdo = new PDO(
            $dsn,
            $config['trusted_connection'] ? '' : $config['username'],
            $config['trusted_connection'] ? '' : $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function status(): array
    {
        $statement = $this->pdo->query(
            'SELECT CONVERT(nvarchar(128), SERVERPROPERTY(\'ServerName\')) AS server_name, '
            . 'DB_NAME() AS database_name, CONVERT(varchar(33), SYSUTCDATETIME(), 126) AS checked_at'
        );

        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function listTables(): array
    {
        $statement = $this->pdo->query(
            'SELECT s.name AS schema_name, t.name AS table_name, '
            . 'COALESCE(SUM(p.rows), 0) AS row_count '
            . 'FROM sys.tables t '
            . 'JOIN sys.schemas s ON s.schema_id = t.schema_id '
            . 'LEFT JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1) '
            . 'WHERE t.is_ms_shipped = 0 AND t.name <> \'sysdiagrams\' '
            . 'GROUP BY s.name, t.name ORDER BY s.name, t.name'
        );

        return array_map(static function (array $table): array {
            $table['row_count'] = (int) $table['row_count'];
            return $table;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listRows(
        string $schema,
        string $table,
        int $page,
        int $pageSize,
        array $filters = [],
        bool $descending = false
    ): array
    {
        $metadata = $this->tableMetadata($schema, $table);
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $identifier = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
        $predicates = [];
        $filterValues = [];
        foreach ($filters as $filter) {
            $column = $filter['column'] ?? null;
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;
            if (!is_string($operator) || !is_string($value)) {
                throw new InvalidArgumentException('Row filters require a column, operator, and string value.');
            }

            if ($operator === 'contains_any') {
                $columns = $filter['columns'] ?? null;
                if (!is_array($columns) || $columns === []) {
                    throw new InvalidArgumentException('contains_any filters require at least one column.');
                }
                $escapedValue = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
                $searchPredicates = [];
                foreach ($columns as $searchColumn) {
                    if (!is_string($searchColumn)) {
                        throw new InvalidArgumentException('contains_any columns must be strings.');
                    }
                    $searchPredicates[] = 'CONVERT(nvarchar(max), '
                        . $this->validatedFilterColumn($metadata['columns'], $searchColumn)
                        . ") LIKE ? ESCAPE '\\'";
                    $filterValues[] = '%' . $escapedValue . '%';
                }
                $predicates[] = '(' . implode(' OR ', $searchPredicates) . ')';
            } else {
                if (!is_string($column)) {
                    throw new InvalidArgumentException('Row filters require a column, operator, and string value.');
                }
                $quotedColumn = $this->validatedFilterColumn($metadata['columns'], $column);
                if ($operator === 'equals' || $operator === 'not_equals') {
                    $predicates[] = $quotedColumn . ($operator === 'equals' ? ' = ?' : ' <> ?');
                    $filterValues[] = $value;
                } elseif ($operator === 'contains') {
                    $escapedValue = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
                    $predicates[] = 'CONVERT(nvarchar(max), ' . $quotedColumn . ") LIKE ? ESCAPE '\\'";
                    $filterValues[] = '%' . $escapedValue . '%';
                } else {
                    throw new InvalidArgumentException('Row filter operator is not supported.');
                }
            }
        }
        $where = $predicates === [] ? '' : ' WHERE ' . implode(' AND ', $predicates);
        $countStatement = $this->pdo->prepare('SELECT COUNT_BIG(*) FROM ' . $identifier . $where);
        $countStatement->execute($filterValues);
        $count = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($count / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $primaryKeys = array_values(array_filter(
            $metadata['columns'],
            static fn (array $column): bool => $column['is_primary_key']
        ));
        $orderBy = $primaryKeys === []
            ? '(SELECT NULL)'
            : implode(', ', array_map(
                fn (array $column): string => $this->quoteIdentifier($column['column_name']),
                $primaryKeys
            ));

        $sql = 'SELECT * FROM ' . $identifier . $where . ' ORDER BY ' . $orderBy
            . ($descending ? ' DESC' : ' ASC')
            . ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $pageSize . ' ROWS ONLY';
        $rowStatement = $this->pdo->prepare($sql);
        $rowStatement->execute($filterValues);
        $rows = $rowStatement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'columns' => $metadata['columns'],
            'primary_key' => array_column($primaryKeys, 'column_name'),
            'rows' => array_map([$this, 'normalizeRow'], $rows),
            'page' => $page,
            'page_size' => $pageSize,
            'total_rows' => $count,
            'total_pages' => $totalPages,
        ];
    }

    private function validatedFilterColumn(array $columns, string $name): string
    {
        foreach ($columns as $column) {
            if ($column['column_name'] === $name) {
                return $this->quoteIdentifier($name);
            }
        }
        throw new InvalidArgumentException('A row filter references an unknown column.');
    }

    public function createRow(string $schema, string $table, array $values): void
    {
        $metadata = $this->tableMetadata($schema, $table);
        $writable = $this->writableValues($metadata['columns'], $values);
        $identifier = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);

        if ($writable === []) {
            $this->pdo->exec('INSERT INTO ' . $identifier . ' DEFAULT VALUES');
            return;
        }

        $columns = array_keys($writable);
        $sql = 'INSERT INTO ' . $identifier . ' ('
            . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns))
            . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($writable));
    }

    public function createRowWithNextIntegerId(
        string $schema,
        string $table,
        string $idColumn,
        array $values
    ): void {
        $metadata = $this->tableMetadata($schema, $table);
        $idMetadata = null;
        foreach ($metadata['columns'] as $column) {
            if ($column['column_name'] === $idColumn) {
                $idMetadata = $column;
                break;
            }
        }
        if (
            $idMetadata === null
            || !$idMetadata['is_writable']
            || !$idMetadata['is_primary_key']
            || !in_array($idMetadata['data_type'], ['tinyint', 'smallint', 'int', 'bigint'], true)
        ) {
            throw new InvalidArgumentException('The generated ID column must be a writable integer primary key.');
        }
        if (array_key_exists($idColumn, $values)) {
            throw new InvalidArgumentException('The generated ID must not be supplied.');
        }

        $identifier = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
        $quotedId = $this->quoteIdentifier($idColumn);
        $this->pdo->beginTransaction();
        try {
            $nextId = $this->pdo->query(
                'SELECT ISNULL(MAX(' . $quotedId . '), 0) + 1 FROM ' . $identifier . ' WITH (TABLOCKX, HOLDLOCK)'
            )->fetchColumn();
            $this->createRow($schema, $table, [$idColumn => $nextId, ...$values]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function updateRow(string $schema, string $table, array $key, array $values): void
    {
        $metadata = $this->tableMetadata($schema, $table);
        $primaryKeys = array_values(array_filter(
            $metadata['columns'],
            static fn (array $column): bool => $column['is_primary_key']
        ));
        if ($primaryKeys === []) {
            $this->updateByRowLocator($schema, $table, $metadata['columns'], $key, $values);
            return;
        }
        $keyValues = $this->validatedKey($primaryKeys, $key);
        $writable = $this->writableValues($metadata['columns'], $values);

        if ($writable === []) {
            throw new InvalidArgumentException('No editable values were provided.');
        }

        $identifier = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
        $set = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column) . ' = ?',
            array_keys($writable)
        ));
        $where = implode(' AND ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column) . ' = ?',
            array_keys($keyValues)
        ));

        $statement = $this->pdo->prepare('UPDATE ' . $identifier . ' SET ' . $set . ' WHERE ' . $where);
        $statement->execute([...array_values($writable), ...array_values($keyValues)]);
    }

    public function deleteRow(string $schema, string $table, array $key): void
    {
        $metadata = $this->tableMetadata($schema, $table);
        $primaryKeys = array_values(array_filter(
            $metadata['columns'],
            static fn (array $column): bool => $column['is_primary_key']
        ));
        if ($primaryKeys === []) {
            $this->deleteByRowLocator($schema, $table, $metadata['columns'], $key);
            return;
        }
        $keyValues = $this->validatedKey($primaryKeys, $key);
        $identifier = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
        $where = implode(' AND ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column) . ' = ?',
            array_keys($keyValues)
        ));

        $this->pdo->prepare('DELETE FROM ' . $identifier . ' WHERE ' . $where)
            ->execute(array_values($keyValues));
    }

    private function updateByRowLocator(
        string $schema,
        string $table,
        array $columns,
        array $key,
        array $values
    ): void {
        $locator = $this->validatedRowLocator($columns, $key);
        $writable = $this->writableValues($columns, $values);
        if ($writable === []) {
            throw new InvalidArgumentException('No editable values were provided.');
        }

        $identifier = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
        [$where, $whereValues] = $this->rowLocatorWhere($columns, $locator);
        $this->pdo->beginTransaction();
        try {
            $this->assertUniqueRow($identifier, $where, $whereValues);
            $set = implode(', ', array_map(
                fn (string $column): string => $this->quoteIdentifier($column) . ' = ?',
                array_keys($writable)
            ));
            $this->pdo->prepare('UPDATE ' . $identifier . ' SET ' . $set . ' WHERE ' . $where)
                ->execute([...array_values($writable), ...$whereValues]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function deleteByRowLocator(string $schema, string $table, array $columns, array $key): void
    {
        $locator = $this->validatedRowLocator($columns, $key);
        $identifier = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
        [$where, $whereValues] = $this->rowLocatorWhere($columns, $locator);
        $this->pdo->beginTransaction();
        try {
            $this->assertUniqueRow($identifier, $where, $whereValues);
            $this->pdo->prepare('DELETE FROM ' . $identifier . ' WHERE ' . $where)->execute($whereValues);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function validatedRowLocator(array $columns, array $key): array
    {
        $row = $key['__row'] ?? null;
        if (!is_array($row) || count($row) !== count($columns)) {
            throw new InvalidArgumentException('A complete original row is required for this table.');
        }

        foreach ($columns as $column) {
            $name = $column['column_name'];
            if (!array_key_exists($name, $row)) {
                throw new InvalidArgumentException('A complete original row is required for this table.');
            }
            if ($row[$name] !== null && !is_scalar($row[$name])) {
                throw new InvalidArgumentException('Original row values must be scalar values or null.');
            }
        }
        if (array_diff(array_keys($row), array_column($columns, 'column_name')) !== []) {
            throw new InvalidArgumentException('The original row contains unknown fields.');
        }

        return $row;
    }

    private function rowLocatorWhere(array $columns, array $row): array
    {
        $predicates = [];
        $values = [];
        foreach ($columns as $column) {
            $name = $column['column_name'];
            if (in_array($column['data_type'], ['text', 'ntext', 'image', 'xml', 'geography', 'geometry', 'hierarchyid'], true)) {
                throw new InvalidArgumentException(
                    'This table has no primary key and contains a column that cannot safely identify a row.'
                );
            }
            if ($row[$name] === null) {
                $predicates[] = $this->quoteIdentifier($name) . ' IS NULL';
            } else {
                $predicates[] = $this->quoteIdentifier($name) . ' = ?';
                $values[] = $row[$name];
            }
        }

        return [implode(' AND ', $predicates), $values];
    }

    private function assertUniqueRow(string $identifier, string $where, array $values): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT_BIG(*) FROM ' . $identifier . ' WITH (UPDLOCK, HOLDLOCK) WHERE ' . $where
        );
        $statement->execute($values);
        $matches = (int) $statement->fetchColumn();
        if ($matches !== 1) {
            throw new InvalidArgumentException($matches === 0
                ? 'This row has changed or no longer exists. Refresh the table and try again.'
                : 'Identical rows were found. Add a primary key to this table before editing or deleting rows.');
        }
    }

    private function tableMetadata(string $schema, string $table): array
    {
        $objectStatement = $this->pdo->prepare(
            'SELECT t.object_id FROM sys.tables t '
            . 'JOIN sys.schemas s ON s.schema_id = t.schema_id '
            . 'WHERE s.name = ? AND t.name = ? AND t.is_ms_shipped = 0 AND t.name <> \'sysdiagrams\''
        );
        $objectStatement->execute([$schema, $table]);
        $objectId = $objectStatement->fetchColumn();

        if ($objectId === false) {
            throw new InvalidArgumentException('Table was not found in the selected database.');
        }

        $columnStatement = $this->pdo->prepare(
            'SELECT c.name AS column_name, ty.name AS data_type, c.max_length, c.precision, c.scale, '
            . 'c.is_nullable, c.is_identity, c.is_computed, '
            . 'CASE WHEN pk.column_id IS NULL THEN 0 ELSE 1 END AS is_primary_key, '
            . 'CASE WHEN c.is_identity = 1 OR c.is_computed = 1 OR ty.name IN (\'timestamp\', \'rowversion\') '
            . 'THEN 0 ELSE 1 END AS is_writable '
            . 'FROM sys.columns c '
            . 'JOIN sys.types ty ON ty.user_type_id = c.user_type_id '
            . 'LEFT JOIN (SELECT ic.object_id, ic.column_id FROM sys.indexes i '
            . 'JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id '
            . 'WHERE i.is_primary_key = 1) pk ON pk.object_id = c.object_id AND pk.column_id = c.column_id '
            . 'WHERE c.object_id = ? ORDER BY c.column_id'
        );
        $columnStatement->execute([$objectId]);
        $columns = array_map(static function (array $column): array {
            foreach (['is_nullable', 'is_identity', 'is_computed', 'is_primary_key', 'is_writable'] as $flag) {
                $column[$flag] = (bool) $column[$flag];
            }
            return $column;
        }, $columnStatement->fetchAll(PDO::FETCH_ASSOC));

        return ['object_id' => $objectId, 'columns' => $columns];
    }

    private function writableValues(array $columns, array $values): array
    {
        $allowed = [];
        foreach ($columns as $column) {
            if ($column['is_writable']) {
                $allowed[$column['column_name']] = true;
            }
        }

        $unknown = array_diff(array_keys($values), array_keys($allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException('One or more fields are unknown or read-only.');
        }

        foreach ($values as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('Field values must be scalar values or null.');
            }
        }

        return $values;
    }

    private function validatedKey(array $primaryKeys, array $key): array
    {
        if ($primaryKeys === []) {
            throw new InvalidArgumentException('Update and delete require a primary key on the table.');
        }

        $keyValues = [];
        foreach ($primaryKeys as $column) {
            $name = $column['column_name'];
            if (!array_key_exists($name, $key) || $key[$name] === null) {
                throw new InvalidArgumentException('A complete primary key is required.');
            }
            if (!is_scalar($key[$name])) {
                throw new InvalidArgumentException('Primary-key values must be scalar.');
            }
            $keyValues[$name] = $key[$name];
        }

        return $keyValues;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    private function normalizeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_resource($value)) {
                $row[$column] = stream_get_contents($value);
            } elseif ($value instanceof DateTimeInterface) {
                $row[$column] = $value->format('Y-m-d H:i:s.uP');
            }
        }

        return $row;
    }
}