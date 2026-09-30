<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The nextcloud/ocp package ships the public API stubs without a composer
// autoload section (Nextcloud registers OCP classes itself at runtime).
// Register them manually for the standalone test runs.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'OCP\\')) {
		$path = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($path)) {
			require $path;
		}
	}
});
