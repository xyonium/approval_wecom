<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Settings;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	public function getID(): string {
		return 'approval_wecom';
	}

	public function getName(): string {
		return $this->l10n->t('Approval 企业微信');
	}

	public function getPriority(): int {
		return 50;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}
}
