<?php declare(strict_types=1);

namespace DataAccessKit;

use DataAccessKit\Exception\PersistenceException;
use DataAccessKit\Fixture\User;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Spatie\Snapshots\MatchesSnapshots;
use function array_map;
use function dirname;
use function get_class;
use function getenv;
use function implode;
use function iterator_to_array;
use function sprintf;
use function str_replace;
use const DIRECTORY_SEPARATOR;

#[Group("database")]
class PersistenceTest extends TestCase
{
	use MatchesSnapshots;

	private object $queryLogger;
	private Connection $connection;
	private Persistence $persistence;

	public function setUp(): void
	{
		if (getenv("DATABASE_URL") === false) {
			$this->markTestSkipped("Environment variable DATABASE_URL not set");
		}

		$this->queryLogger = new class extends AbstractLogger {
			/** @var string[] */
			public array $queries = [];
			public bool $enabled = true;

			public function log($level, $message, array $context = []): void
			{
				if ($this->enabled && isset($context["sql"])) {
					$this->queries[] = $context["sql"];
				}
			}
		};
		$this->connection = DriverManager::getConnection(
			(new DsnParser())->parse(getenv("DATABASE_URL")),
			(new Configuration())->setMiddlewares([new Middleware($this->queryLogger)])
		);
		$this->persistence = new Persistence($this->connection, new Registry(new DefaultNameConverter()), new DefaultValueConverter());

		$this->setUpUsersTable();
	}

	private function setUpUsersTable(): void
	{
		$this->queryLogger->enabled = false;
		try {
			$this->connection->executeStatement("DROP TABLE IF EXISTS users");

			$platform = $this->connection->getDatabasePlatform();
			if ($platform instanceof AbstractMySQLPlatform) {
				$this->connection->executeStatement("CREATE TABLE users (
    			user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, 
    			first_name VARCHAR(255),
    			last_name VARCHAR(255),
    			full_name VARCHAR(255) GENERATED ALWAYS AS (CONCAT(first_name, ' ', last_name)) STORED
    		)");
			} else if ($platform instanceof PostgreSQLPlatform) {
				$this->connection->executeStatement("CREATE TABLE users (
    			user_id SERIAL PRIMARY KEY,
    			first_name VARCHAR(255),
    			last_name VARCHAR(255),
    			full_name VARCHAR(255) GENERATED ALWAYS AS (first_name || ' ' || last_name) STORED
    		)");
			} else if ($platform instanceof SQLitePlatform) {
				$this->connection->executeStatement("CREATE TABLE users (
    			user_id INTEGER PRIMARY KEY, 
    			first_name TEXT,
    			last_name TEXT,
    			full_name TEXT GENERATED ALWAYS AS (first_name || ' ' || last_name) STORED
    		)");
			} else {
				throw new LogicException(sprintf("Unsupported database platform [%s].", get_class($platform)));
			}

			$this->connection->executeStatement("INSERT INTO users (first_name, last_name) VALUES ('Alice', 'Smith')");
			$this->connection->executeStatement("INSERT INTO users (first_name, last_name) VALUES ('Bob', 'Jones')");
		} finally {
			$this->queryLogger->enabled = true;
		}
	}

	protected function getSnapshotDirectory(): string
	{
		$rc = new ReflectionClass($this);
		return dirname($rc->getFileName()) . DIRECTORY_SEPARATOR .
			str_replace("Test", "", $rc->getShortName()) . DIRECTORY_SEPARATOR .
			str_replace("Platform", "", (new ReflectionClass($this->connection->getDatabasePlatform()))->getShortName());
	}

	protected function getSnapshotId(): string
	{
		return (new ReflectionClass($this))->getShortName() . '__' .
			$this->nameWithDataSet() .
			($this->snapshotIncrementor > 1 ? '__' . $this->snapshotIncrementor : '');
	}

	private function assertQueriesSnapshot()
	{
		$this->assertMatchesSnapshot(
			implode("", array_map(fn(string $it) => $it . ";\n", $this->queryLogger->queries)),
		);
	}

	public function testSelectSubsetOfColumns(): void
	{
		$users = iterator_to_array($this->persistence->select(User::class, "SELECT user_id FROM users"));
		$this->assertCount(2, $users);
		$this->assertEquals(1, $users[0]->id);
		$this->assertEquals(2, $users[1]->id);
		foreach ($users as $user) {
			foreach ((new ReflectionClass($user))->getProperties() as $rp) {
				if ($rp->getName() === "id") {
					$this->assertTrue($rp->isInitialized($user), sprintf("Property [%s] must be initialized.", $rp->getName()));
				} else {
					$this->assertFalse($rp->isInitialized($user), sprintf("Property [%s] must NOT be initialized.", $rp->getName()));
				}
			}
		}

		$this->assertQueriesSnapshot();
	}

	public function testSelectAllColumns(): void
	{
		$users = iterator_to_array($this->persistence->select(User::class, "SELECT user_id, first_name, last_name, full_name FROM users LIMIT 1"));
		$this->assertCount(1, $users);
		$user = $users[0];
		foreach ((new ReflectionClass($user))->getProperties() as $rp) {
			$this->assertTrue($rp->isInitialized($user), sprintf("Property [%s] is not initialized.", $rp->getName()));
		}
		$this->assertEquals(1, $user->id);
		$this->assertEquals("Alice", $user->firstName);
		$this->assertEquals("Smith", $user->lastName);
		$this->assertEquals("Alice Smith", $user->fullName);

		$this->assertQueriesSnapshot();
	}

	public function testSelectScalar(): void
	{
		$count = $this->persistence->selectScalar("SELECT COUNT(*) FROM users");
		$this->assertEquals(2, $count);
		$this->assertQueriesSnapshot();
	}

	public function testExecute(): void
	{
		$affected = $this->persistence->execute("DELETE FROM users WHERE user_id = 1");
		$this->assertEquals(1, $affected);
		$count = $this->persistence->selectScalar("SELECT COUNT(*) FROM users");
		$this->assertEquals(1, $count);
		$this->assertQueriesSnapshot();
	}

	public function testInsert(): void
	{
		$user = new User();
		$user->firstName = "Charlie";
		$user->lastName = "Brown";
		$this->persistence->insert($user);
		$this->assertEquals(3, $user->id);

		$selectUsers = iterator_to_array($this->persistence->select(User::class, "SELECT user_id, first_name, last_name, full_name FROM users WHERE user_id = ?", [$user->id]));
		$this->assertCount(1, $selectUsers);
		$selectUser = $selectUsers[0];
		$this->assertEquals($user->id, $selectUser->id);
		$this->assertEquals($user->firstName, $selectUser->firstName);
		$this->assertEquals($user->lastName, $selectUser->lastName);
		$this->assertEquals($user->firstName . " " . $user->lastName, $selectUser->fullName);

		$this->assertQueriesSnapshot();
	}

	public function testInsertAll(): void
	{
		if ($this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
			$this->markTestSkipped();
		}

		$user1 = new User();
		$user1->firstName = "Charlie";
		$user1->lastName = "Brown";

		$user2 = new User();
		$user2->firstName = "David";
		$user2->lastName = "White";

		$this->persistence->insert([$user1, $user2]);

		$this->assertEquals(3, $user1->id);
		$this->assertEquals(4, $user2->id);

		$this->assertQueriesSnapshot();
	}

	public function testInsertAllMySQL(): void
	{
		if (!$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
			$this->markTestSkipped();
		}

		$user1 = new User();
		$user1->firstName = "Charlie";
		$user1->lastName = "Brown";

		$user2 = new User();
		$user2->firstName = "David";
		$user2->lastName = "White";

		$this->expectException(PersistenceException::class);
		$this->expectExceptionMessage("does not support INSERT ... RETURNING");
		$this->persistence->insert([$user1, $user2]);
	}

	public function testUpsert(): void
	{
		$user = new User();
		$user->id = 1;
		$user->firstName = "Charlie";
		$user->lastName = "Brown";

		$this->persistence->upsert($user);
		$this->assertEquals(1, $user->id);

		$selectUsers = iterator_to_array($this->persistence->select(User::class, "SELECT user_id, first_name, last_name, full_name FROM users WHERE user_id = ?", [$user->id]));
		$this->assertCount(1, $selectUsers);
		$this->assertEquals($user->id, $selectUsers[0]->id);
		$this->assertEquals($user->firstName, $selectUsers[0]->firstName);

		$this->assertQueriesSnapshot();
	}

	public function testUpsertAll(): void
	{
		$user1 = new User();
		$user1->id = 1;
		$user1->firstName = "Charlie";
		$user1->lastName = "Brown";

		$user2 = new User();
		$user2->id = 2;
		$user2->firstName = "David";
		$user2->lastName = "White";

		$users = [$user1, $user2];

		$this->persistence->upsert($users);
		$this->assertEquals("Charlie Brown", $user1->fullName);
		$this->assertEquals("David White", $user2->fullName);

		$selectUsers = iterator_to_array($this->persistence->select(User::class, "SELECT user_id, first_name, last_name, full_name FROM users"));
		$this->assertCount(2, $selectUsers);
		foreach ($selectUsers as $index => $selectUser) {
			$user = $users[$index];
			$this->assertEquals($user->id, $selectUser->id);
			$this->assertEquals($user->firstName, $selectUser->firstName);
			$this->assertEquals($user->lastName, $selectUser->lastName);
			$this->assertEquals($user->firstName . " " . $user->lastName, $selectUser->fullName);
		}

		$this->assertQueriesSnapshot();
	}

	public function testUpdate(): void
	{
		$user = iterator_to_array($this->persistence->select(User::class, "SELECT user_id, first_name, last_name, full_name FROM users WHERE user_id = 1"))[0];
		$user->firstName = "Charlie";
		$user->lastName = "Brown";
		$this->persistence->update($user);
		$this->assertEquals("Charlie Brown", $user->fullName);

		$this->assertQueriesSnapshot();
	}

	public function testDelete(): void
	{
		$user = new User();
		$user->id = 1;
		$this->persistence->delete($user);

		$selectUsers = iterator_to_array($this->persistence->select(User::class, "SELECT user_id FROM users"));
		$this->assertCount(1, $selectUsers);
		$this->assertEquals(2, $selectUsers[0]->id);

		$this->assertQueriesSnapshot();
	}

	public function testDeleteAll(): void
	{
		$user1 = new User();
		$user1->id = 1;

		$user2 = new User();
		$user2->id = 2;

		$this->persistence->delete([$user1, $user2]);

		$selectUsers = iterator_to_array($this->persistence->select(User::class, "SELECT user_id FROM users"));
		$this->assertCount(0, $selectUsers);

		$this->assertQueriesSnapshot();
	}

}
