<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\TestCase;

abstract class AbstractIntegrationTestCase extends TestCase
{
    protected ?string $originalBinlogFormat = null;
    protected ?string $originalBinlogRowImage = null;
    protected ?string $originalBinlogRowMetadata = null;
    protected ?\PDO $pdo = null;
    protected ?array $dbConfig = null;
    protected ?array $replicationConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!$databaseUrl) {
            return;
        }

        $parsedUrl = parse_url($databaseUrl);
        $this->dbConfig = [
            'host' => $parsedUrl['host'] ?? 'localhost',
            'port' => $parsedUrl['port'] ?? 3306,
            'user' => $parsedUrl['user'] ?? 'root',
            'password' => $parsedUrl['pass'] ?? '',
        ];

        $replicationUrl = $_ENV['REPLICATION_DATABASE_URL'] ?? getenv('REPLICATION_DATABASE_URL');
        if ($replicationUrl) {
            $replicationParsedUrl = parse_url($replicationUrl);
            $this->replicationConfig = [
                'host' => $replicationParsedUrl['host'] ?? $this->dbConfig['host'],
                'port' => $replicationParsedUrl['port'] ?? $this->dbConfig['port'],
                'user' => $replicationParsedUrl['user'] ?? 'replication_test',
                'password' => $replicationParsedUrl['pass'] ?? 'replication_test',
            ];
        } else {
            $this->replicationConfig = $this->dbConfig;
        }

        try {
            $this->pdo = new \PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']}",
                $this->dbConfig['user'],
                $this->dbConfig['password']
            );

            $stmt = $this->pdo->query("SELECT @@GLOBAL.binlog_format");
            $this->originalBinlogFormat = $stmt->fetchColumn();

            $stmt = $this->pdo->query("SELECT @@GLOBAL.binlog_row_image");
            $this->originalBinlogRowImage = $stmt->fetchColumn();

            $stmt = $this->pdo->query("SELECT @@GLOBAL.binlog_row_metadata");
            $this->originalBinlogRowMetadata = $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_format = ?");
            $stmt->execute(['ROW']);

            $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_image = ?");
            $stmt->execute(['FULL']);

            $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_metadata = ?");
            $stmt->execute(['FULL']);

        } catch (\Exception $e) {
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) {
            parent::tearDown();
            return;
        }

        try {
            if ($this->originalBinlogFormat !== null) {
                $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_format = ?");
                $stmt->execute([$this->originalBinlogFormat]);
            }
            if ($this->originalBinlogRowImage !== null) {
                $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_image = ?");
                $stmt->execute([$this->originalBinlogRowImage]);
            }
            if ($this->originalBinlogRowMetadata !== null) {
                $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_metadata = ?");
                $stmt->execute([$this->originalBinlogRowMetadata]);
            }
        } catch (\Exception $e) {
        }

        $this->pdo = null;
        $this->dbConfig = null;
        $this->replicationConfig = null;

        parent::tearDown();
    }

    protected function createConnectionUrl(array $params = []): string
    {
        if ($this->dbConfig === null) {
            throw new \Exception('Database configuration not available');
        }

        $database = isset($params['database']) ? '/' . $params['database'] : '';
        unset($params['database']);

        $queryParams = array_merge(['server_id' => '100'], $params);
        $queryString = http_build_query($queryParams);

        if (!empty($this->dbConfig['password'])) {
            return sprintf(
                'mysql://%s:%s@%s:%d%s?%s',
                $this->dbConfig['user'],
                $this->dbConfig['password'],
                $this->dbConfig['host'],
                $this->dbConfig['port'],
                $database,
                $queryString
            );
        } else {
            return sprintf(
                'mysql://%s@%s:%d%s?%s',
                $this->dbConfig['user'],
                $this->dbConfig['host'],
                $this->dbConfig['port'],
                $database,
                $queryString
            );
        }
    }

    protected function createReplicationConnectionUrl(array $params = []): string
    {
        if ($this->replicationConfig === null) {
            throw new \Exception('Replication configuration not available');
        }

        $database = isset($params['database']) ? '/' . $params['database'] : '';
        unset($params['database']);

        $queryParams = array_merge(['server_id' => '100'], $params);
        $queryString = http_build_query($queryParams);

        if (!empty($this->replicationConfig['password'])) {
            return sprintf(
                'mysql://%s:%s@%s:%d%s?%s',
                $this->replicationConfig['user'],
                $this->replicationConfig['password'],
                $this->replicationConfig['host'],
                $this->replicationConfig['port'],
                $database,
                $queryString
            );
        } else {
            return sprintf(
                'mysql://%s@%s:%d%s?%s',
                $this->replicationConfig['user'],
                $this->replicationConfig['host'],
                $this->replicationConfig['port'],
                $database,
                $queryString
            );
        }
    }

    protected function requireDatabase(): void
    {
        if ($this->pdo === null) {
            $this->markTestSkipped('DATABASE_URL environment variable is required');
        }
    }
}