<?php

declare(strict_types=1);

namespace ADT\Configurator\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * ADT\Bootstrap over a generated project (.env, config/, app/Bootstrap.php) in a temporary directory.
 * Parameters one by one - getParameters() would evaluate the dynamic baseUrl, which needs nette/http.
 * Each test in its own process: Configurator keeps the included configs in a static property and
 * loadEnv() fills $_ENV.
 */
final class BootstrapTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/adt-bootstrap-test-' . uniqid();
		mkdir($this->root . '/log', 0777, true);
		mkdir($this->root . '/temp');
		$this->write('.env', "STAGE=local\nAPP_DEBUG=1\nAPP_DEVELOPERS=[]\nAPP_ENV=\nAPP_TEST=0\n");
		$this->write('config/common.neon', "parameters:\n\tfrom: common\n");
		$this->write('config/interface/console.neon', "parameters:\n\tinterface: console\n");
		$this->write('config/stage/local.neon', "parameters:\n\tstage: local\n");
		$this->write('config/extra.neon', "parameters:\n\textra: yes\n");
		$this->write('config/tenant.neon', "parameters:\n\ttenant: yes\n");
		$this->write('config/test.neon', "parameters:\n\tfrom: test\n\ttestDbName: from_neon\n\ttestShardSuffix: ''\n");
		$this->write('app/Bootstrap.php', <<<'PHP'
			<?php
			namespace FixtureApp;

			class Bootstrap extends \ADT\Bootstrap
			{
				public bool $initCalled = false;

				protected function init(): void
				{
					$this->initCalled = true;
					// nette/http, latte ... are not dependencies of the library
					$this->configurator->defaultExtensions = array_intersect_key(
						$this->configurator->defaultExtensions,
						array_flip(['php', 'constants', 'extensions', 'decorator', 'di', 'search', 'inject']),
					);
				}

				protected function addConfigs(string $interface): void
				{
					$this->configurator->addConfig('tenant');
				}
			}
			PHP);
		require_once $this->root . '/app/Bootstrap.php';
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	#[RunInSeparateProcess]
	public function testConsoleContainerWithConfigsInOrder(): void
	{
		$_ENV['APP_ENV'] = 'extra';
		$bootstrap = new \FixtureApp\Bootstrap();
		$this->assertTrue($bootstrap->initCalled);

		$container = $bootstrap->bootConsole();

		// root directory derived from app/Bootstrap.php
		$this->assertSame(realpath($this->root), realpath($container->getParameter('rootDir')));
		$this->assertSame('common', $container->getParameter('from'));
		$this->assertSame('console', $container->getParameter('interface'));
		$this->assertSame('local', $container->getParameter('stage'));
		$this->assertTrue($container->getParameter('extra'));
		$this->assertTrue($container->getParameter('tenant'));
		$this->assertTrue($container->getParameter('debugMode'));
	}

	#[RunInSeparateProcess]
	public function testTestModeAddsTestConfigDatabaseAndShard(): void
	{
		putenv('APP_TEST=1');
		putenv('TEST_DB_NAME=own_db');
		putenv('TEST_SHARD=3');

		$container = (new \FixtureApp\Bootstrap($this->root))->bootConsole();

		$this->assertSame('test', $container->getParameter('from'));
		$this->assertSame('own_db', $container->getParameter('testDbName'));
		$this->assertSame('_3', $container->getParameter('testShardSuffix'));
	}

	#[RunInSeparateProcess]
	public function testInvalidShardMeansNoSuffix(): void
	{
		putenv('APP_TEST=1');
		putenv('TEST_SHARD=../x');

		$container = (new \FixtureApp\Bootstrap($this->root))->bootConsole();

		$this->assertSame('', $container->getParameter('testShardSuffix'));
		$this->assertSame('from_neon', $container->getParameter('testDbName'));
	}

	private function write(string $file, string $content): void
	{
		$path = $this->root . '/' . $file;
		@mkdir(dirname($path), 0777, true);
		file_put_contents($path, $content);
	}
}
