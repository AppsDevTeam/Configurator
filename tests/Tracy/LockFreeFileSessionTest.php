<?php

declare(strict_types=1);

namespace ADT\Configurator\Tests\Tracy;

use ADT\Tracy\LockFreeFileSession;
use PHPUnit\Framework\TestCase;

final class LockFreeFileSessionTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/tracy-session-test-' . uniqid();
		$_COOKIE['tracy-session'] = 'abcdef1234';
	}

	protected function tearDown(): void
	{
		array_map('unlink', glob($this->dir . '/*') ?: []);
		@rmdir($this->dir);
		unset($_COOKIE['tracy-session']);
	}

	public function testConcurrentRequestsMergeTheirChanges(): void
	{
		$this->write(fn(array &$data) => $data['setup']['old'] = ['code' => 'old']);

		// Two requests read the same state, then each writes its own changes
		$first = $this->open();
		$second = $this->open();
		$first->getData()['setup']['first'] = ['code' => 'a'];
		$second->getData()['setup']['second'] = ['code' => 'b'];
		unset($second->getData()['setup']['old']); // the bar fetched its content
		unset($first, $second);

		$this->assertSame(['first' => ['code' => 'a'], 'second' => ['code' => 'b']], $this->open()->getData()['setup']);
	}

	public function testChangedItemOverwritesTheStoredOne(): void
	{
		$this->write(fn(array &$data) => $data['setup']['id'] = ['code' => 'a']);
		$this->write(fn(array &$data) => $data['setup']['id']['code'] .= 'b');

		$this->assertSame(['id' => ['code' => 'ab']], $this->open()->getData()['setup']);
	}

	public function testUnchangedRequestDoesNotOverwriteOthers(): void
	{
		$this->write(fn(array &$data) => $data['setup']['x'] = ['code' => 'x']);
		$reader = $this->open();
		$this->write(fn(array &$data) => $data['setup']['y'] = ['code' => 'y']);
		unset($reader);

		$this->assertSame(['x', 'y'], array_keys($this->open()->getData()['setup']));
	}

	public function testMissingFileStartsEmpty(): void
	{
		$this->assertSame([], $this->open()->getData());
		$this->assertDirectoryExists($this->dir);
	}

	private function open(): LockFreeFileSession
	{
		$session = new LockFreeFileSession($this->dir);
		$session->isAvailable();

		return $session;
	}

	/** @param callable(array<string, mixed>&): void $change */
	private function write(callable $change): void
	{
		$session = $this->open();
		$data = &$session->getData();
		$change($data);
	}
}
