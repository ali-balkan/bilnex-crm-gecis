<?php

final class ReadOnlySqlServerConnection
{
    private ?PDO $pdo = null;

    public function __construct(private array $config)
    {
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        self::assertReadOnlySql($sql);
        if ($this->usesDotnetBridge()) {
            return $this->fetchAllWithDotnetBridge($sql, $params);
        }

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $params);
        return $rows[0] ?? null;
    }

    public static function assertReadOnlySql(string $sql): void
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? '');
        $normalized = ltrim($normalized, " \t\n\r\0\x0B;");
        $firstToken = strtolower(strtok($normalized, " \t\n\r(") ?: '');

        if (!in_array($firstToken, ['select', 'with'], true)) {
            throw new InvalidArgumentException('SQL Server katmani sadece okuma sorgularina izin verir.');
        }

        $withoutStrings = preg_replace("/'([^']|'')*'/", "''", strtolower($normalized)) ?? '';
        $blocked = [
            'insert ', 'update ', 'delete ', 'merge ', 'alter ', 'drop ', 'create ',
            'truncate ', 'exec ', 'execute ', 'grant ', 'revoke ', 'deny ', 'backup ',
            'restore ', 'sp_rename', 'into ', 'set identity_insert',
        ];

        foreach ($blocked as $keyword) {
            if (str_contains($withoutStrings, $keyword)) {
                throw new InvalidArgumentException('SQL Server katmani yazma veya sema degisikligi iceren sorguyu reddetti.');
            }
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!extension_loaded('pdo_sqlsrv')) {
            throw new RuntimeException('pdo_sqlsrv PHP eklentisi yuklu degil. SQL Server okuma katmani hazir, ancak baglanti icin driver kurulumu gerekir.');
        }

        $serverCandidates = $this->serverCandidates();
        $database = $this->config['database'] ?? '';
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        $trustCertificate = !empty($this->config['trust_server_certificate']) ? 'yes' : 'no';
        $loginTimeout = (int) ($this->config['login_timeout'] ?? 5);
        $loginTimeout = max(1, min($loginTimeout, 30));

        $lastException = null;
        foreach ($serverCandidates as $server) {
            $dsn = sprintf(
                'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=%s;LoginTimeout=%d',
                $server,
                $database,
                $trustCertificate,
                $loginTimeout
            );

            try {
                $this->pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => $loginTimeout,
                ]);
                $this->config['server'] = $server;
                return $this->pdo;
            } catch (PDOException $exception) {
                $lastException = $exception;
            }
        }

        $detail = $lastException instanceof PDOException ? ' Son hata: ' . $lastException->getMessage() : '';
        throw new RuntimeException(
            'SQL Server baglantisi kurulamadi. Denenen adresler: ' . implode(', ', $serverCandidates) . '.' . $detail,
            0,
            $lastException
        );
    }

    private function serverCandidates(): array
    {
        $servers = $this->config['server_candidates'] ?? [$this->config['server'] ?? ''];
        if (!is_array($servers)) {
            $servers = [$servers];
        }

        $result = [];
        foreach ($servers as $server) {
            $server = trim((string) $server);
            if ($server !== '' && !in_array($server, $result, true)) {
                $result[] = $server;
            }
        }

        if (!$result) {
            $result[] = '';
        }

        return $result;
    }

    private function activeServer(): string
    {
        $servers = $this->serverCandidates();
        return $servers[0] ?? '';
    }

    private function bridgeEnvironment(): array
    {
        return array_merge($_ENV, [
            'BILNEX_SQL_SERVER' => (string) ($this->config['server'] ?? $this->activeServer()),
            'BILNEX_SQL_DATABASE' => (string) ($this->config['database'] ?? ''),
            'BILNEX_SQL_USERNAME' => (string) ($this->config['username'] ?? ''),
            'BILNEX_SQL_PASSWORD' => (string) ($this->config['password'] ?? ''),
        ]);
    }

    private function usesDotnetBridge(): bool
    {
        return !empty($this->config['dotnet_bridge']) && !extension_loaded('pdo_sqlsrv');
    }

    private function fetchAllWithDotnetBridge(string $sql, array $params): array
    {
        $script = dirname(__DIR__) . '/scripts/sqlserver-readonly-query.ps1';
        if (!is_file($script)) {
            throw new RuntimeException('SQL Server read-only bridge script bulunamadi.');
        }

        $queryBase64 = base64_encode($sql);
        $paramsBase64 = base64_encode(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $command = [
            'powershell.exe',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $script,
            '-QueryBase64',
            $queryBase64,
            '-ParamsBase64',
            $paramsBase64,
        ];

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->bridgeEnvironment();

        $process = proc_open($command, $descriptorSpec, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('SQL Server read-only bridge baslatilamadi.');
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException('SQL Server read-only bridge hatasi: ' . trim($error));
        }

        $decoded = json_decode($output, true);
        if ($decoded === null || $decoded === '') {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        return [$decoded];
    }
}
