<?php

namespace ADT\Tracy;

use Tracy\Helpers;
use Tracy\SessionStorage;

/**
 * Tracy session storage (deferred AJAX bar and bluescreen content) without a lock held for
 * the whole request.
 *
 * Tracy's FileSession keeps the file of the tracy-session cookie locked from the start to the
 * end of a request, so concurrent AJAX requests of one browser (a page firing several signals
 * at once) run one after another in debug mode. Here the file is locked only while it is read
 * and while it is written. On write the changes of this request are merged into what other
 * requests wrote in the meantime: added and changed items are written, removed ones (the bar
 * fetched its content) are removed, items of other requests stay.
 *
 * Set by ADT\Configurator::enableTracy() in debug mode.
 */
final class LockFreeFileSession implements SessionStorage
{
	private const COOKIE_NAME = 'tracy-session';
	private const FILE_PREFIX = 'tracy-';
	private const COOKIE_LIFETIME = 31_557_600;
	private const GC_PROBABILITY = 0.03;

	private ?string $path = null;

	/** @var array<string, mixed> */
	private array $data = [];

	/** @var array<string, mixed> state after reading - tells on write what this request changed */
	private array $original = [];

	public function __construct(
		private readonly string $dir,
	) {
	}

	public function isAvailable(): bool
	{
		if ($this->path === null) {
			$this->open();
		}

		return true;
	}

	/** @return array<string, mixed> */
	public function &getData(): array
	{
		return $this->data;
	}

	public function __destruct()
	{
		if ($this->path === null || $this->data === $this->original) {
			return;
		}

		$file = @fopen($this->path, 'c+'); // @ - an error cannot be reported from a destructor
		if ($file === false || !flock($file, LOCK_EX)) {
			return;
		}
		$current = $this->unserialize((string) stream_get_contents($file));
		ftruncate($file, 0);
		rewind($file);
		fwrite($file, serialize($this->merge($current)));
		flock($file, LOCK_UN);
		fclose($file);
	}

	private function open(): void
	{
		if (!is_dir($this->dir)) {
			@mkdir($this->dir, 0700, true); // @ - a concurrent request may have created it
		}

		$id = $_COOKIE[self::COOKIE_NAME] ?? null;
		if (!is_string($id) || !preg_match('#^\w{10}\z#', $id)) {
			$id = Helpers::createId();
			setcookie(self::COOKIE_NAME, $id, [
				'expires' => time() + self::COOKIE_LIFETIME,
				'path' => '/',
				'secure' => Helpers::isHttps(),
				'httponly' => true,
				'samesite' => 'Lax',
			]);
		}
		$this->path = $this->dir . '/' . self::FILE_PREFIX . $id;

		$file = @fopen($this->path, 'r'); // @ - the file may not exist yet
		if ($file !== false) {
			flock($file, LOCK_SH);
			$this->data = $this->original = $this->unserialize((string) stream_get_contents($file));
			flock($file, LOCK_UN);
			fclose($file);
		}

		if (mt_rand() / mt_getrandmax() < self::GC_PROBABILITY) {
			$this->clean();
		}
	}

	/**
	 * Applies the changes of this request (against the state after reading) to the current file
	 * content. Data are [key => [request ID => item]] (bar setup, bluescreens), merged per item.
	 *
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	private function merge(array $current): array
	{
		foreach (array_keys($this->data + $this->original) as $key) {
			$mine = $this->data[$key] ?? null;
			$original = $this->original[$key] ?? null;
			if (!is_array($mine) || !is_array($original ?? [])) {
				if ($mine !== $original) {
					$current[$key] = $mine;
				}
				continue;
			}

			$merged = is_array($current[$key] ?? null) ? $current[$key] : [];
			foreach ($mine as $itemId => $item) {
				if (!array_key_exists($itemId, $original ?? []) || $original[$itemId] !== $item) {
					$merged[$itemId] = $item;
				}
			}
			foreach (array_diff_key($original ?? [], $mine) as $itemId => $_) {
				unset($merged[$itemId]);
			}
			$current[$key] = $merged;
		}

		return $current;
	}

	/** @return array<string, mixed> */
	private function unserialize(string $content): array
	{
		$data = @unserialize($content, ['allowed_classes' => false]); // @ - empty file

		return is_array($data) ? $data : [];
	}

	/** Removes files older than a week (like Tracy's FileSession) */
	private function clean(): void
	{
		$old = strtotime('-1 week');
		foreach (glob($this->dir . '/' . self::FILE_PREFIX . '*') ?: [] as $file) {
			if (@filemtime($file) < $old) { // @ - a concurrent cleanup may have deleted it
				@unlink($file);
			}
		}
	}
}
