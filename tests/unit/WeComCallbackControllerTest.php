<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Controller\WeComCallbackController;
use OCA\ApprovalWeCom\Crypto\WXBizMsgCrypt;
use OCA\ApprovalWeCom\Service\ApprovalExecutor;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Service\WeComService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class WeComCallbackControllerTest extends TestCase {
	use WeComCryptoTestTrait;

	private SettingsService&MockObject $settings;
	private WeComService&MockObject $weComService;
	private ApprovalInfoProvider&MockObject $infoProvider;
	private ApprovalExecutor&MockObject $executor;
	private IUserManager&MockObject $userManager;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getWeComEnabled')->willReturn(true);
		$this->settings->method('getToken')->willReturn(self::TEST_TOKEN);
		$this->settings->method('getEncodingAesKey')->willReturn(self::TEST_AES_KEY);
		$this->settings->method('getCorpId')->willReturn(self::TEST_CORP_ID);

		$this->weComService = $this->createMock(WeComService::class);
		$this->infoProvider = $this->createMock(ApprovalInfoProvider::class);
		$this->executor = $this->createMock(ApprovalExecutor::class);
		$this->userManager = $this->createMock(IUserManager::class);
	}

	private function makeController(): WeComCallbackController {
		return new class(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$this->settings,
			new WXBizMsgCrypt(),
			$this->weComService,
			$this->infoProvider,
			$this->executor,
			$this->userManager,
			$this->createMock(LoggerInterface::class),
		) extends WeComCallbackController {
			public string $mockBody = '';

			protected function getRequestBody(): string {
				return $this->mockBody;
			}
		};
	}

	private function cardEventXml(string $eventKey, string $fromUser = 'wcAlice', string $responseCode = 'rc-click-1'): string {
		return '<xml>'
			. '<ToUserName><![CDATA[' . self::TEST_CORP_ID . ']]></ToUserName>'
			. '<FromUserName><![CDATA[' . $fromUser . ']]></FromUserName>'
			. '<CreateTime>1700000000</CreateTime>'
			. '<MsgType><![CDATA[event]]></MsgType>'
			. '<Event><![CDATA[template_card_event]]></Event>'
			. '<EventKey><![CDATA[' . $eventKey . ']]></EventKey>'
			. '<TaskId><![CDATA[awc_12_3_1]]></TaskId>'
			. '<ResponseCode><![CDATA[' . $responseCode . ']]></ResponseCode>'
			. '<AgentID>1000002</AgentID>'
			. '</xml>';
	}

	public function testVerifyReturnsDecryptedEchostr(): void {
		$echostr = $this->wecomEncrypt('random-echo-string');
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $echostr);

		$response = $this->makeController()->verify($signature, '1700000000', 'nonce1', $echostr);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('random-echo-string', $response->render());
	}

	public function testVerifyRejectsBadSignature(): void {
		$echostr = $this->wecomEncrypt('random-echo-string');
		$response = $this->makeController()->verify(sha1('wrong'), '1700000000', 'nonce1', $echostr);
		$this->assertSame(403, $response->getStatus());
	}

	public function testVerifyDisabled(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getWeComEnabled')->willReturn(false);
		$this->settings = $settings;
		$response = $this->makeController()->verify('sig', '1', 'n', 'echo');
		$this->assertSame(404, $response->getStatus());
	}

	public function testCallbackApproveHappyPath(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('approve_12_3'));
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->weComService->method('getEmailByWeComUserId')->with('wcAlice')->willReturn('alice@example.com');
		$this->userManager->method('getByEmail')->with('alice@example.com')->willReturn([$user]);
		$this->infoProvider->method('resolveApproverUserIds')->with(3)->willReturn(['alice']);
		$this->executor->expects($this->once())->method('execute')
			->with('approve', 12, 3, 'alice')
			->willReturn(['success' => true]);
		$this->weComService->expects($this->once())->method('updateCardButtons')
			->with('rc-click-1', '已批准');

		$response = $controller->callback($signature, '1700000000', 'nonce1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('success', $response->render());
	}

	public function testCallbackRejectHappyPath(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('reject_12_3'));
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->weComService->method('getEmailByWeComUserId')->willReturn('alice@example.com');
		$this->userManager->method('getByEmail')->willReturn([$user]);
		$this->infoProvider->method('resolveApproverUserIds')->willReturn(['alice']);
		$this->executor->expects($this->once())->method('execute')
			->with('reject', 12, 3, 'alice')
			->willReturn(['success' => true]);
		$this->weComService->expects($this->once())->method('updateCardButtons')
			->with('rc-click-1', '已拒绝');

		$this->assertSame('success', $controller->callback($signature, '1700000000', 'nonce1')->render());
	}

	public function testCallbackRejectsBadSignature(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('approve_12_3'));
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';

		$this->executor->expects($this->never())->method('execute');
		$response = $controller->callback(sha1('wrong'), '1700000000', 'nonce1');
		$this->assertSame(403, $response->getStatus());
	}

	public function testCallbackDecryptFailure(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('approve_12_3'), null, 'other-corp');
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$this->executor->expects($this->never())->method('execute');
		$response = $controller->callback($signature, '1700000000', 'nonce1');
		$this->assertSame(400, $response->getStatus());
	}

	public function testCallbackUnknownWeComUser(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('approve_12_3'));
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$this->weComService->method('getEmailByWeComUserId')->willReturn(null);
		$this->executor->expects($this->never())->method('execute');
		$this->weComService->expects($this->once())->method('updateCardButtons')
			->with('rc-click-1', '无法识别用户');

		$this->assertSame('success', $controller->callback($signature, '1700000000', 'nonce1')->render());
	}

	public function testCallbackNonApprover(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('approve_12_3'));
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$this->weComService->method('getEmailByWeComUserId')->willReturn('mallory@example.com');
		$this->userManager->method('getByEmail')->willReturn([$user]);
		$this->infoProvider->method('resolveApproverUserIds')->willReturn(['alice']);
		$this->executor->expects($this->never())->method('execute');
		$this->weComService->expects($this->once())->method('updateCardButtons')
			->with('rc-click-1', '无权审批');

		$this->assertSame('success', $controller->callback($signature, '1700000000', 'nonce1')->render());
	}

	public function testCallbackFailedExecutionUpdatesCardAsStale(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('approve_12_3'));
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->weComService->method('getEmailByWeComUserId')->willReturn('alice@example.com');
		$this->userManager->method('getByEmail')->willReturn([$user]);
		$this->infoProvider->method('resolveApproverUserIds')->willReturn(['alice']);
		$this->executor->method('execute')->willReturn(['success' => false, 'error' => 'not pending']);
		$this->weComService->expects($this->once())->method('updateCardButtons')
			->with('rc-click-1', '已失效或已处理');

		$this->assertSame('success', $controller->callback($signature, '1700000000', 'nonce1')->render());
	}

	public function testCallbackIgnoresNonCardEvents(): void {
		$controller = $this->makeController();
		$inner = '<xml><MsgType><![CDATA[event]]></MsgType><Event><![CDATA[subscribe]]></Event></xml>';
		$encrypt = $this->wecomEncrypt($inner);
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$this->executor->expects($this->never())->method('execute');
		$this->assertSame('success', $controller->callback($signature, '1700000000', 'nonce1')->render());
	}

	public function testCallbackIgnoresMalformedEventKey(): void {
		$controller = $this->makeController();
		$encrypt = $this->wecomEncrypt($this->cardEventXml('bogus_key'));
		$controller->mockBody = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypt);

		$this->executor->expects($this->never())->method('execute');
		$this->assertSame('success', $controller->callback($signature, '1700000000', 'nonce1')->render());
	}
}
