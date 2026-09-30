<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\AppInfo;

use OCA\ApprovalWeCom\Listener\TagAssignmentListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\SystemTag\TagAssignedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'approval_wecom';

	// Mirror of OCA\Approval states (kept as local constants so this app
	// never needs to load a class from the approval app).
	public const STATE_APPROVED = 2;
	public const STATE_REJECTED = 3;

	// Mirror of OCA\Approval approver entity types (approval_rule_approvers.entity_type).
	public const TYPE_USER = 0;
	public const TYPE_GROUP = 1;
	public const TYPE_CIRCLE = 2;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(TagAssignedEvent::class, TagAssignmentListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
