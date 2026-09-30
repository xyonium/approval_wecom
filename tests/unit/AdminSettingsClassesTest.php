<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Settings\Admin;
use OCA\ApprovalWeCom\Settings\AdminSection;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;

final class AdminSettingsClassesTest extends TestCase {
	private SettingsService&MockObject $settings;
	private ApprovalInfoProvider&MockObject $infoProvider;
	private IURLGenerator&MockObject $urlGenerator;
	private IInitialStateService&MockObject $initialState;
	private IL10N&MockObject $l10n;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->infoProvider = $this->createMock(ApprovalInfoProvider::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->initialState = $this->createMock(IInitialStateService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
	}

	private function makeAdmin(): Admin {
		return new Admin($this->settings, $this->infoProvider, $this->urlGenerator, $this->initialState, $this->l10n);
	}

	public function testAdminSectionMetadata(): void {
		$admin = $this->makeAdmin();
		$this->assertSame('approval_wecom', $admin->getSection());
		$this->assertSame(50, $admin->getPriority());
		$this->assertSame('Approval 企业微信', $admin->getName());
		$this->assertSame([], $admin->getAuthorizedAppConfig());
	}

	public function testAdminFormProvidesInitialState(): void {
		$config = ['wecom_enabled' => true];
		$this->settings->method('getAdminConfig')->willReturn($config);
		$this->infoProvider->method('isApprovalAppEnabled')->willReturn(true);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('approval_wecom.wecomcallback.verify')
			->willReturn('https://nc.example.com/apps/approval_wecom/wecom/callback');

		$provided = [];
		$this->initialState->expects($this->exactly(3))->method('provideInitialState')
			->willReturnCallback(static function (string $app, string $key, $data) use (&$provided): void {
				$provided[$key] = [$app, $data];
			});

		$response = $this->makeAdmin()->getForm();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('adminSettings', $response->getTemplateName());
		$this->assertSame([Application::APP_ID, $config], $provided['config']);
		$this->assertSame([Application::APP_ID, true], $provided['approval-enabled']);
		$this->assertSame([Application::APP_ID, 'https://nc.example.com/apps/approval_wecom/wecom/callback'], $provided['callback-url']);
	}

	public function testAdminSection(): void {
		$this->urlGenerator->method('imagePath')
			->with(Application::APP_ID, 'app.svg')
			->willReturn('/apps/approval_wecom/img/app.svg');

		$section = new AdminSection($this->urlGenerator, $this->l10n);
		$this->assertSame('approval_wecom', $section->getID());
		$this->assertSame('Approval 企业微信', $section->getName());
		$this->assertSame(50, $section->getPriority());
		$this->assertSame('/apps/approval_wecom/img/app.svg', $section->getIcon());
	}
}
