<?php declare(strict_types=1);

namespace DataAccessKit;

use DataAccessKit\Fixture\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use function get_class;
use function getenv;
use function iterator_to_array;
use function sprintf;

#[Group("database")]
class PersistenceTest extends TestCase
{

	private Connection $connection;
	private Persistence $persistence;

	public function setUp(): void
	{
		if (getenv("DATABASE_URL") === false) {
			$this->markTestSkipped("Environment variable DATABASE_URL not set");
		}

		$dsnParser = new DsnParser();
		$this->connection = DriverManager::getConnection($dsnParser->parse(getenv("DATABASE_URL")));
		$this->persistence = new Persistence($this->connection, new Registry(new DefaultNameConverter()), new DefaultValueConverter());
	}

	private function setUpUsersTable(): void
	{
		$this->connection->executeStatement("DROP TABLE IF EXISTS users");

		$platform = $this->connection->getDatabasePlatform();
		if ($platform instanceof AbstractMySQLPlatform) {
			$this->connection->executeStatement("CREATE TABLE users (user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(255))");
		} else if ($platform instanceof PostgreSQLPlatform) {
			$this->connection->executeStatement("CREATE TABLE users (user_id SERIAL PRIMARY KEY, first_name VARCHAR(255))");
		} else if ($platform instanceof SQLitePlatform) {
			$this->connection->executeStatement("CREATE TABLE users (user_id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT)");
		} else {
			throw new LogicException(sprintf("Unsupported database platform [%s].", get_class($platform)));
		}

		$this->connection->executeStatement("INSERT INTO users (first_name) VALUES ('Alice')");
		$this->connection->executeStatement("INSERT INTO users (first_name) VALUES ('Bob')");
	}

	public function testSelect(): void
	{
		$this->setUpUsersTable();
		$users = iterator_to_array($this->persistence->select(User::class, "SELECT user_id, first_name FROM users"));
		$this->assertCount(2, $users);
		$this->assertEquals(1, $users[0]->id);
		$this->assertEquals("Alice", $users[0]->firstName);
		$this->assertEquals(2, $users[1]->id);
		$this->assertEquals("Bob", $users[1]->firstName);
	}

	public function testSelectScalar(): void
	{
		$this->setUpUsersTable();
		$count = $this->persistence->selectScalar("SELECT COUNT(*) FROM users");
		$this->assertEquals(2, $count);
	}

	public function testExecute(): void
	{
		$this->setUpUsersTable();
		$this->persistence->execute("DELETE FROM users WHERE user_id = 1");
		$count = $this->persistence->selectScalar("SELECT COUNT(*) FROM users");
		$this->assertEquals(1, $count);
	}

	public function testInsert(): void
	{
		$this->setUpUsersTable();
		$user = new User();
		$user->firstName = "Charlie";
		$this->persistence->insert($user);
		$this->assertEquals(3, $user->id);

		$users = iterator_to_array($this->persistence->select(User::class, "SELECT user_id, first_name FROM users WHERE user_id = ?", [$user->id]));
		$this->assertCount(1, $users);
		$this->assertEquals($user->id, $users[0]->id);
		$this->assertEquals($user->firstName, $users[0]->firstName);
	}

	public function testInsertAll(): void
	{
		$this->setUpUsersTable();

		$user1 = new User();
		$user1->firstName = "Charlie";

		$user2 = new User();
		$user2->firstName = "David";

		$this->persistence->insertAll([$user1, $user2]);

		if ($this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
			$rp = new ReflectionProperty(User::class, "id");
			$this->assertFalse($rp->isInitialized($user1));
			$this->assertFalse($rp->isInitialized($user2));
		} else {
			$this->assertEquals(3, $user1->id);
			$this->assertEquals(4, $user2->id);
		}
	}

}
