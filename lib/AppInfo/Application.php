<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'approval_wecom';

	// Mirror of OCA\Approval states (kept as local constants so this app
	// never needs to load a class from the approval app).
	public const STATE_APPROVED = 2;
	public const STATE_REJECTED = 3;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
