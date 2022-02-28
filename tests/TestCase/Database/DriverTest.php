<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.2.12
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Database;

use Cake\Database\Driver;
use Cake\Database\Driver\Sqlserver;
use Cake\Database\DriverInterface;
use Cake\Database\Exception\MissingConnectionException;
use Cake\Database\Log\QueryLogger;
use Cake\Database\Query;
use Cake\Database\QueryCompiler;
use Cake\Database\Schema\TableSchema;
use Cake\Database\Statement\Statement;
use Cake\Database\ValueBinder;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use DateTime;
use Exception;
use PDO;
use PDOException;
use PDOStatement;
use TestApp\Database\Driver\RetryDriver;
use TestApp\Database\Driver\StubDriver;

/**
 * Tests Driver class
 */
class DriverTest extends TestCase
{
    /**
     * @var \Cake\Database\Driver|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $driver;

    /**
     * Setup.
     */
    public function setUp(): void
    {
        parent::setUp();

        Log::setConfig('queries', [
            'className' => 'Array',
            'scopes' => ['queriesLog'],
        ]);

        $this->driver = $this->getMockForAbstractClass(
            StubDriver::class,
            [],
            '',
            true,
            true,
            true,
            ['_connect', 'prepare']
        );
    }

    public function tearDown(): void
    {
        parent::tearDown();
        Log::drop('queries');
    }

    /**
     * Test if building the object throws an exception if we're not passing
     * required config data.
     */
    public function testConstructorException(): void
    {
        $arg = ['login' => 'Bear'];
        try {
            $this->getMockForAbstractClass(Driver::class, [$arg]);
        } catch (Exception $e) {
            $this->assertStringContainsString(
                'Please pass "username" instead of "login" for connecting to the database',
                $e->getMessage()
            );
        }
    }

    /**
     * Test the constructor.
     */
    public function testConstructor(): void
    {
        $arg = ['quoteIdentifiers' => true];
        $driver = $this->getMockForAbstractClass(Driver::class, [$arg]);

        $this->assertTrue($driver->isAutoQuotingEnabled());

        $arg = ['username' => 'GummyBear'];
        $driver = $this->getMockForAbstractClass(Driver::class, [$arg]);

        $this->assertFalse($driver->isAutoQuotingEnabled());
    }

    /**
     * Tests default implementation of feature support check.
     */
    public function testSupports(): void
    {
        $this->assertTrue($this->driver->supports(DriverInterface::FEATURE_SAVEPOINT));
        $this->assertTrue($this->driver->supports(DriverInterface::FEATURE_QUOTE));

        $this->assertFalse($this->driver->supports(DriverInterface::FEATURE_CTE));
        $this->assertFalse($this->driver->supports(DriverInterface::FEATURE_JSON));
        $this->assertFalse($this->driver->supports(DriverInterface::FEATURE_WINDOW));

        $this->assertFalse($this->driver->supports('this-is-fake'));
    }

    /**
     * Test schemaValue().
     * Uses a provider for all the different values we can pass to the method.
     *
     * @dataProvider schemaValueProvider
     * @param mixed $input
     */
    public function testSchemaValue($input, string $expected): void
    {
        $result = $this->driver->schemaValue($input);
        $this->assertSame($expected, $result);
    }

    /**
     * Test schemaValue().
     * Asserting that quote() is being called because none of the conditions were met before.
     */
    public function testSchemaValueConnectionQuoting(): void
    {
        $value = 'string';

        $connection = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['quote'])
            ->getMock();

        $connection
            ->expects($this->once())
            ->method('quote')
            ->with($value, PDO::PARAM_STR)
            ->will($this->returnValue('string'));

        $this->driver->expects($this->any())
            ->method('_connect')
            ->willReturn($connection);

        $this->driver->schemaValue($value);
    }

    /**
     * Test lastInsertId().
     */
    public function testLastInsertId(): void
    {
        $connection = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lastInsertId'])
            ->getMock();

        $connection
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('all-the-bears');

        $this->driver->expects($this->any())
            ->method('_connect')
            ->willReturn($connection);

        $this->assertSame('all-the-bears', $this->driver->lastInsertId());
    }

    /**
     * Test isConnected().
     */
    public function testIsConnected(): void
    {
        $this->assertFalse($this->driver->isConnected());

        $connection = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $connection
            ->expects($this->once())
            ->method('query')
            ->willReturn(new PDOStatement());

        $this->driver->expects($this->any())
            ->method('_connect')
            ->willReturn($connection);

        $this->driver->connect();

        $this->assertTrue($this->driver->isConnected());
    }

    /**
     * test autoQuoting().
     */
    public function testAutoQuoting(): void
    {
        $this->assertFalse($this->driver->isAutoQuotingEnabled());

        $this->assertSame($this->driver, $this->driver->enableAutoQuoting(true));
        $this->assertTrue($this->driver->isAutoQuotingEnabled());

        $this->driver->disableAutoQuoting();
        $this->assertFalse($this->driver->isAutoQuotingEnabled());
    }

    /**
     * Test compileQuery().
     */
    public function testCompileQuery(): void
    {
        $compiler = $this->getMockBuilder(QueryCompiler::class)
            ->onlyMethods(['compile'])
            ->getMock();

        $compiler
            ->expects($this->once())
            ->method('compile')
            ->willReturn('1');

        $driver = $this->getMockBuilder(Driver::class)
            ->onlyMethods(['newCompiler', 'queryTranslator'])
            ->getMockForAbstractClass();

        $driver
            ->expects($this->once())
            ->method('newCompiler')
            ->willReturn($compiler);

        $driver
            ->expects($this->once())
            ->method('queryTranslator')
            ->willReturn(function ($query) {
                return $query;
            });

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $query->method('type')->will($this->returnValue('select'));

        $result = $driver->compileQuery($query, new ValueBinder());

        $this->assertIsArray($result);
        $this->assertSame($query, $result[0]);
        $this->assertSame('1', $result[1]);
    }

    /**
     * Test newCompiler().
     */
    public function testNewCompiler(): void
    {
        $this->assertInstanceOf(QueryCompiler::class, $this->driver->newCompiler());
    }

    /**
     * Test newTableSchema().
     */
    public function testNewTableSchema(): void
    {
        $tableName = 'articles';
        $actual = $this->driver->newTableSchema($tableName);
        $this->assertInstanceOf(TableSchema::class, $actual);
        $this->assertSame($tableName, $actual->name());
    }

    public function testConnectRetry(): void
    {
        $this->skipIf(!ConnectionManager::get('test')->getDriver() instanceof Sqlserver);

        $driver = new RetryDriver();

        try {
            $driver->connect();
        } catch (MissingConnectionException) {
        }

        $this->assertSame(4, $driver->getConnectRetries());
    }

    /**
     * Test __destruct().
     */
    public function testDestructor(): void
    {
        $this->driver->disconnect();

        $this->expectException(MissingConnectionException::class);
        $this->driver->getConnection();
    }

    /**
     * Data provider for testSchemaValue().
     *
     * @return array
     */
    public function schemaValueProvider(): array
    {
        return [
            [null, 'NULL'],
            [false, 'FALSE'],
            [true, 'TRUE'],
            [1, '1'],
            ['0', '0'],
            ['42', '42'],
        ];
    }

    /**
     * Tests that queries are logged when executed without params
     */
    public function testExecuteNoParams(): void
    {
        $inner = $this->getMockBuilder(PDOStatement::class)->getMock();

        $statement = $this->getMockBuilder(Statement::class)
            ->setConstructorArgs([$inner, $this->driver])
            ->onlyMethods(['queryString','rowCount','execute'])
            ->getMock();
        $statement->expects($this->any())->method('queryString')->will($this->returnValue('SELECT bar FROM foo'));
        $statement->method('rowCount')->will($this->returnValue(3));
        $statement->method('execute')->will($this->returnValue(true));

        $this->driver->expects($this->any())
            ->method('prepare')
            ->willReturn($statement);
        $this->driver->setLogger(new QueryLogger(['connection' => 'test']));

        $this->driver->execute('SELECT bar FROM foo');

        $messages = Log::engine('queries')->read();
        $this->assertCount(1, $messages);
        $this->assertMatchesRegularExpression('/^debug: connection=test duration=[\d\.]+ rows=3 SELECT bar FROM foo$/', $messages[0]);
    }

    /**
     * Tests that queries are logged when executed with bound params
     */
    public function testExecuteWithBinding(): void
    {
        $inner = $this->getMockBuilder(PDOStatement::class)->getMock();

        $statement = $this->getMockBuilder(Statement::class)
            ->setConstructorArgs([$inner, $this->driver])
            ->onlyMethods(['queryString','rowCount','execute'])
            ->getMock();
        $statement->method('rowCount')->will($this->returnValue(3));
        $statement->method('execute')->will($this->returnValue(true));
        $statement->expects($this->any())->method('queryString')->will($this->returnValue('SELECT bar FROM foo WHERE a=:a AND b=:b'));

        $this->driver->setLogger(new QueryLogger(['connection' => 'test']));
        $this->driver->expects($this->any())
            ->method('prepare')
            ->willReturn($statement);

        $this->driver->execute(
            'SELECT bar FROM foo WHERE a=:a AND b=:b',
            [
                'a' => 1,
                'b' => new DateTime('2013-01-01'),
            ],
            ['b' => 'date']
        );

        $messages = Log::engine('queries')->read();
        $this->assertCount(1, $messages);
        $this->assertMatchesRegularExpression("/^debug: connection=test duration=\d+ rows=3 SELECT bar FROM foo WHERE a='1' AND b='2013-01-01'$/", $messages[0]);
    }

    /**
     * Tests that queries are logged despite database errors
     */
    public function testExecuteWithError(): void
    {
        $inner = $this->getMockBuilder(PDOStatement::class)->getMock();

        $statement = $this->getMockBuilder(Statement::class)
            ->setConstructorArgs([$inner, $this->driver])
            ->onlyMethods(['queryString','rowCount','execute'])
            ->getMock();
        $statement->expects($this->any())->method('queryString')->will($this->returnValue('SELECT bar FROM foo'));
        $statement->method('rowCount')->will($this->returnValue(0));
        $statement->method('execute')->will($this->throwException(new PDOException()));

        $this->driver->setLogger(new QueryLogger(['connection' => 'test']));
        $this->driver->expects($this->any())
            ->method('prepare')
            ->willReturn($statement);

        try {
            $this->driver->execute('SELECT foo FROM bar');
        } catch (PDOException $e) {
        }

        $messages = Log::engine('queries')->read();
        $this->assertCount(1, $messages);
        $this->assertMatchesRegularExpression('/^debug: connection=test duration=\d+ rows=0 SELECT bar FROM foo$/', $messages[0]);
    }
}
