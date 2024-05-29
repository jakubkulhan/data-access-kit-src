<?php declare(strict_types=1);

namespace DataAccessKit;

use DataAccessKit\Fixture\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use function getenv;
use function iterator_to_array;
use function var_dump;

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
		$this->connection->executeStatement("CREATE TABLE users (user_id INT PRIMARY KEY, first_name VARCHAR(255))");
		$this->connection->executeStatement("INSERT INTO users (user_id, first_name) VALUES (1, 'Alice')");
		$this->connection->executeStatement("INSERT INTO users (user_id, first_name) VALUES (2, 'Bob')");
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

}
