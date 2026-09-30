<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Controller\ConfigController;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Service\WeComService;
use OCA\ApprovalWeCom\Settings\Admin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use PHPUnit\Framework\MockObject\MockObject;

final class ConfigControllerTest extends TestCase {
	private SettingsService&MockObject $settings;
	private ApprovalInfoProvider&MockObject $infoProvider;
	private WeComService&MockObject $weComService;
	private ISystemTagManager&MockObject $tagManager;
	private IRequest&MockObject $request;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->infoProvider = $this->createMock(ApprovalInfoProvider::class);
		$this->weComService = $this->createMock(WeComService::class);
		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function makeController(?string $userId = 'admin'): ConfigController {
		return new ConfigController(
			Application::APP_ID,
			$this->request,
			$this->settings,
			$this->infoProvider,
			$this->weComService,
			$this->tagManager,
			$userId,
		);
	}

	public function testGetConfigReturnsAdminConfig(): void {
		$config = ['wecom_enabled' => true, 'corpid' => 'corp1', 'has_corpsecret' => true];
		$this->settings->method('getAdminConfig')->willReturn($config);

		$response = $this->makeController()->getConfig();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame($config, $response->getData());
	}

	public function testSetConfigSavesParamsAndReturnsFreshConfig(): void {
		$params = ['archive_folder' => 'docs', 'wecom_enabled' => true];
		$this->request->method('getParams')->willReturn($params);
		$this->settings->expects($this->once())->method('saveAdminConfig')->with($params);
		$this->settings->method('getAdminConfig')->willReturn(['archive_folder' => 'docs']);

		$response = $this->makeController()->setConfig();
		$this->assertSame(['archive_folder' => 'docs'], $response->getData());
	}

	public function testGetApprovalRuleTags(): void {
		$this->infoProvider->method('isApprovalAppEnabled')->willReturn(true);
		$rules = [
			['ruleId' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12],
			['ruleId' => 4, 'tagPending' => 13, 'tagApproved' => 14, 'tagRejected' => 15],
		];
		$this->infoProvider->method('getAllRuleTags')->willReturn($rules);

		$tag10 = $this->createMock(ISystemTag::class);
		$tag10->method('getId')->willReturn('10');
		$tag10->method('getName')->willReturn('pending-tag');
		$tag11 = $this->createMock(ISystemTag::class);
		$tag11->method('getId')->willReturn('11');
		$tag11->method('getName')->willReturn('approved-tag');
		$this->tagManager->method('getTagsByIds')->with([10, 11, 12, 13, 14, 15])
			->willReturn(['10' => $tag10, '11' => $tag11]);

		$data = $this->makeController()->getApprovalRuleTags()->getData();
		$this->assertTrue($data['enabled']);
		$this->assertSame($rules, $data['rules']);
		$this->assertSame([
			['id' => 10, 'name' => 'pending-tag'],
			['id' => 11, 'name' => 'approved-tag'],
		], $data['tags']);
	}

	public function testGetApprovalRuleTagsWhenApprovalDisabled(): void {
		$this->infoProvider->method('isApprovalAppEnabled')->willReturn(false);
		$this->infoProvider->method('getAllRuleTags')->willReturn([]);
		$this->tagManager->method('getTagsByIds')->with([])->willReturn([]);

		$data = $this->makeController()->getApprovalRuleTags()->getData();
		$this->assertFalse($data['enabled']);
		$this->assertSame([], $data['rules']);
		$this->assertSame([], $data['tags']);
	}

	public function testSendTestMessage(): void {
		$this->weComService->method('sendTestMessage')->with('admin')->willReturn(['success' => true]);
		$this->assertSame(['success' => true], $this->makeController()->sendTestMessage()->getData());
	}

	public function testSendTestMessagePropagatesError(): void {
		$this->weComService->method('sendTestMessage')->willReturn(['success' => false, 'error' => 'no_email']);
		$data = $this->makeController()->sendTestMessage()->getData();
		$this->assertSame(['success' => false, 'error' => 'no_email'], $data);
	}

	public function testSendTestMessageRequiresUser(): void {
		$this->weComService->expects($this->never())->method('sendTestMessage');
		$response = $this->makeController(null)->sendTestMessage();
		$this->assertSame(400, $response->getStatus());
	}

	public function testAllMethodsRequireAdminSettingAttribute(): void {
		foreach (['getConfig', 'setConfig', 'getApprovalRuleTags', 'sendTestMessage'] as $method) {
			$attrs = (new \ReflectionMethod(ConfigController::class, $method))
				->getAttributes(AuthorizedAdminSetting::class);
			$this->assertNotEmpty($attrs, $method . ' missing AuthorizedAdminSetting');
			$this->assertSame(Admin::class, $attrs[0]->newInstance()->getSettings());
		}
	}
}
