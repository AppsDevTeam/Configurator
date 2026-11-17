<?php

declare(strict_types=1);

// Front controller of BootstrapWebTest, run by `php -S`; the project root comes from env FIXTURE_ROOT

require __DIR__ . '/../../../vendor/autoload.php';

// PHP-FPM with clear_env = no passes the environment of the container in $_SERVER, php -S does not
$_SERVER += getenv();

$root = (string) getenv('FIXTURE_ROOT');
require $root . '/app/Bootstrap.php';

(new \FixtureApp\Bootstrap($root))->bootUI();

$logger = \Tracy\Debugger::getLogger();
if ($logger instanceof \ADT\ErrorLogger) {
	$logger->mailer = function ($message, string $email, ?string $exceptionFile, ?string $exceptionMdFile) use ($root): void {
		file_put_contents($root . '/log/mail.txt', implode("\n", [
			(string) $message,
			$exceptionFile ? (string) file_get_contents($exceptionFile) : '',
			$exceptionMdFile ? (string) file_get_contents($exceptionMdFile) : '',
		]));
	};
}

function processRequest(string $password, string $note): never
{
	throw new \RuntimeException('fixture-exception ' . $note);
}

processRequest((string) ($_POST['password'] ?? ''), (string) ($_POST['note'] ?? ''));
