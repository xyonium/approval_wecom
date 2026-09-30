<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Settings;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IDelegatedSettings;

class Admin implements IDelegatedSettings {
	public function __construct(
		private SettingsService $settings,
		private ApprovalInfoProvider $infoProvider,
		private IURLGenerator $urlGenerator,
		private IInitialStateService $initialState,
		private IL10N $l10n,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState(Application::APP_ID, 'config', $this->settings->getAdminConfig());
		$this->initialState->provideInitialState(Application::APP_ID, 'approval-enabled', $this->infoProvider->isApprovalAppEnabled());
		// shown in the UI so the admin can paste it into the WeCom app config
		$this->initialState->provideInitialState(
			Application::APP_ID,
			'callback-url',
			$this->urlGenerator->linkToRouteAbsolute('approval_wecom.wecomcallback.verify'),
		);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'approval_wecom';
	}

	public function getPriority(): int {
		return 50;
	}

	public function getName(): ?string {
		return $this->l10n->t('Approval 企业微信');
	}

	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
