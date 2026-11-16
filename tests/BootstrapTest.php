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
		$this->write('config/project.neon', "parameters:\n\tdatabase: db_%project%\n");
		$this->write('config/project/clinic.neon', "parameters:\n\tproject: clinic\n");
		$this->write('config/env/local-clinic.neon', "parameters:\n\tdomain: clinic.loc\n");
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
	public function testErrorPageOfPackageWithoutProjectOne(): void
	{
		$this->write('config/interface/ui.neon', "parameters:\n\tinterface: ui\n");

		(new \FixtureApp\Bootstrap())->bootUI();

		$this->assertSame(realpath(__DIR__ . '/../src/error.phtml'), realpath(\Tracy\Debugger::$errorTemplate));
	}

	#[RunInSeparateProcess]
	public function testProjectErrorPageReplacesPackageOne(): void
	{
		$this->write('config/interface/ui.neon', "parameters:\n\tinterface: ui\n");
		$this->write('app/error.phtml', "<?php echo 'project error page';\n");

		(new \FixtureApp\Bootstrap())->bootUI();

		$this->assertSame(realpath($this->root . '/app/error.phtml'), realpath(\Tracy\Debugger::$errorTemplate));
	}

	#[RunInSeparateProcess]
	public function testTenantAddsProjectAndStageConfigs(): void
	{
		$_ENV['APP_ENV'] = 'project/clinic';

		$container = (new \FixtureApp\Bootstrap($this->root))->bootConsole();

		$this->assertSame('db_clinic', $container->getParameter('database'));
		$this->assertSame('clinic.loc', $container->getParameter('domain'));
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

	#[RunInSeparateProcess]
	public function testSecretsAreHiddenInErrorLog(): void
	{
		// Built at runtime: BlueScreen shows the source of this test too, the values must not be in it
		$secret = fn(string $name): string => 'sec' . 'ret-' . $name;
		$secrets = [
			'DB_PASSWORD' => $secret('db-password'),
			'MAILAPI_KEY' => $secret('mailapi-key'),
			'ZOHO_REFRESH_TOKEN' => $secret('refresh-token'),
			'APP_DEVELOPERS' => '["' . $secret('developer-key') . '"]',
			'HTTP_COOKIE' => 'PHPSESSID=' . $secret('session-id'),
		];
		$_ENV = $secrets + $_ENV + ['PROJECT_URL' => 'visible-project-url'];
		$_SERVER = $secrets + $_SERVER;
		$_COOKIE = ['PHPSESSID' => $secret('session-id'), 'nette-debug' => $secret('developer-key')];

		(new \FixtureApp\Bootstrap($this->root))->bootConsole();
		$file = $this->root . '/log/exception.html';
		\Tracy\Debugger::getBlueScreen()->renderToFile(new \RuntimeException('boom'), $file);

		// the environment is in the HTML log only, the .md one (for AI agents) has none
		$this->assertStringContainsString('visible-project-url', (string) file_get_contents($file));
		foreach ([$file, substr($file, 0, -5) . '.md'] as $log) {
			$this->assertStringNotContainsString($secret(''), (string) file_get_contents($log), $log);
		}
	}

	private function write(string $file, string $content): void
	{
		$path = $this->root . '/' . $file;
		@mkdir(dirname($path), 0777, true);
		file_put_contents($path, $content);
	}
}
