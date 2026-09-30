<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Service\WeComService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class WeComServiceTest extends TestCase {
	private IClient&MockObject $client;
	/** @var array<string, string> */
	private array $cacheStore = [];
	/** @var list<array{uri: string, options: array}> */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cacheStore = [];
		$this->requests = [];
		$this->client = $this->createMock(IClient::class);
	}

	private function response(array $data): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode($data));
		return $response;
	}

	/**
	 * @param list<IResponse> $getResponses queued responses for client->get()
	 * @param list<IResponse> $postResponses queued responses for client->post()
	 * @param array<string, string> $settings
	 */
	private function makeService(array $getResponses = [], array $postResponses = [], array $settings = [
		'corpid' => 'corp1', 'corpsecret' => 'sec', 'agentid' => '1000002', 'token' => 'tok', 'encodingaeskey' => 'aes',
	], ?IUserManager $userManager = null): WeComService {
		$this->client->method('get')->willReturnCallback(function (string $uri, array $options = []) use (&$getResponses) {
			$this->requests[] = ['uri' => $uri, 'options' => $options];
			$response = array_shift($getResponses);
			if ($response === null) {
				throw new \RuntimeException('unexpected GET ' . $uri);
			}
			return $response;
		});
		$this->client->method('post')->willReturnCallback(function (string $uri, array $options = []) use (&$postResponses) {
			$this->requests[] = ['uri' => $uri, 'options' => $options];
			$response = array_shift($postResponses);
			if ($response === null) {
				throw new \RuntimeException('unexpected POST ' . $uri);
			}
			return $response;
		});

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cacheStore[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value, int $ttl = 0) {
			$this->cacheStore[$key] = $value;
			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key) {
			unset($this->cacheStore[$key]);
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $settings[$key] ?? $default
		);
		$appConfig->method('getValueBool')->willReturn(true);

		$userManager = $userManager ?? $this->createMock(IUserManager::class);

		return new WeComService(
			$clientService,
			$cacheFactory,
			new SettingsService($appConfig),
			$userManager,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testGetAccessTokenFetchesAndCaches(): void {
		$service = $this->makeService([
			$this->response(['errcode' => 0, 'access_token' => 'token-1', 'expires_in' => 7200]),
		]);
		$this->assertSame('token-1', $service->getAccessToken());
		// second call must be served from cache (only one queued GET response)
		$this->assertSame('token-1', $service->getAccessToken());
		$this->assertCount(1, $this->requests);
		$this->assertSame('corp1', $this->requests[0]['options']['query']['corpid']);
	}

	public function testGetAccessTokenNullWhenNotConfigured(): void {
		$service = $this->makeService([], [], ['corpid' => '', 'corpsecret' => '']);
		$this->assertNull($service->getAccessToken());
		$this->assertCount(0, $this->requests);
	}

	public function testGetAccessTokenNullOnApiError(): void {
		$service = $this->makeService([
			$this->response(['errcode' => 40001, 'errmsg' => 'invalid credential']),
		]);
		$this->assertNull($service->getAccessToken());
	}

	public function testGetWeComUserIdByEmail(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([], [
			$this->response(['errcode' => 0, 'userid' => 'wc-alice']),
		]);
		$this->assertSame('wc-alice', $service->getWeComUserIdByEmail('alice@example.com'));
		$this->assertSame('alice@example.com', $this->requests[0]['options']['json']['email']);
	}

	public function testGetWeComUserIdByEmailNullOnErrcode(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([], [
			$this->response(['errcode' => 46003, 'errmsg' => 'no user']),
		]);
		$this->assertNull($service->getWeComUserIdByEmail('nobody@example.com'));
	}

	public function testApiCallRefreshesTokenOnceOnExpiry(): void {
		$this->cacheStore['access_token'] = 'expired-token';
		$service = $this->makeService(
			[$this->response(['errcode' => 0, 'access_token' => 'fresh-token', 'expires_in' => 7200])],
			[
				$this->response(['errcode' => 42001, 'errmsg' => 'token expired']),
				$this->response(['errcode' => 0, 'userid' => 'wc-alice']),
			],
		);
		$this->assertSame('wc-alice', $service->getWeComUserIdByEmail('alice@example.com'));
		$this->assertCount(3, $this->requests);
		// retried call carries the fresh token
		$this->assertSame('fresh-token', $this->requests[2]['options']['query']['access_token']);
	}

	public function testSendApprovalCardsReturnsTaskIdAndResponseCode(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([], [
			$this->response(['errcode' => 0, 'response_code' => 'rc-send-1', 'msgid' => 'm1']),
		]);
		$result = $service->sendApprovalCards(['wc-a', 'wc-b'], 'Alice', '合同.pdf', 12, 3, 'https://nc.example.com/f/12');
		$this->assertNotNull($result);
		$this->assertSame('rc-send-1', $result['responseCode']);
		$this->assertStringStartsWith('awc_12_3_', $result['taskId']);

		$body = $this->requests[0]['options']['json'];
		$this->assertSame('wc-a|wc-b', $body['touser']);
		$this->assertSame('template_card', $body['msgtype']);
		$this->assertSame(1000002, $body['agentid']);
		$this->assertSame('button_interaction', $body['template_card']['card_type']);
		$this->assertSame('approve_12_3', $body['template_card']['button_list'][0]['key']);
		$this->assertSame('reject_12_3', $body['template_card']['button_list'][1]['key']);
	}

	public function testSendApprovalCardsNullWhenNoRecipients(): void {
		$service = $this->makeService();
		$this->assertNull($service->sendApprovalCards([], 'Alice', 'f.pdf', 12, 3, 'https://nc/f/12'));
	}

	public function testSendApprovalCardsNullOnError(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([], [
			$this->response(['errcode' => 60011, 'errmsg' => 'no privilege']),
		]);
		$this->assertNull($service->sendApprovalCards(['wc-a'], 'Alice', 'f.pdf', 12, 3, 'https://nc/f/12'));
	}

	public function testUpdateCardButtons(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([], [
			$this->response(['errcode' => 0]),
			$this->response(['errcode' => 40097]),
		]);
		$this->assertTrue($service->updateCardButtons('rc-1', '已批准'));
		$this->assertFalse($service->updateCardButtons('rc-2', '已批准'));
		$body = $this->requests[0]['options']['json'];
		$this->assertSame('rc-1', $body['response_code']);
		$this->assertSame('已批准', $body['button']['replace_name']);
	}

	public function testReplaceCardWithNotice(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([], [
			$this->response(['errcode' => 0]),
		]);
		$this->assertTrue($service->replaceCardWithNotice('rc-1', '审批已通过', '该审批请求已结束'));
		$body = $this->requests[0]['options']['json'];
		$this->assertSame('text_notice', $body['template_card']['card_type']);
		$this->assertSame('审批已通过', $body['template_card']['main_title']['title']);
	}

	public function testGetEmailByWeComUserId(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([
			$this->response(['errcode' => 0, 'userid' => 'wc-alice', 'email' => 'alice@example.com']),
		]);
		$this->assertSame('alice@example.com', $service->getEmailByWeComUserId('wc-alice'));
	}

	public function testGetEmailByWeComUserIdFallsBackToBizMail(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$service = $this->makeService([
			$this->response(['errcode' => 0, 'userid' => 'wc-alice', 'biz_mail' => 'alice@biz.example.com']),
		]);
		$this->assertSame('alice@biz.example.com', $service->getEmailByWeComUserId('wc-alice'));
	}

	public function testSendNoticeToNcUser(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('alice@example.com');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alice')->willReturn($user);

		$service = $this->makeService([], [
			$this->response(['errcode' => 0, 'userid' => 'wc-alice']),
			$this->response(['errcode' => 0, 'msgid' => 'm1']),
		], userManager: $userManager);

		$service->sendNoticeToNcUser('alice', '你的审批请求已批准');
		$this->assertCount(2, $this->requests);
		$body = $this->requests[1]['options']['json'];
		$this->assertSame('text', $body['msgtype']);
		$this->assertSame('wc-alice', $body['touser']);
		$this->assertSame('你的审批请求已批准', $body['text']['content']);
	}

	public function testSendNoticeSkipsUserWithoutEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn(null);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$service = $this->makeService(userManager: $userManager);

		$service->sendNoticeToNcUser('alice', 'hi');
		$this->assertCount(0, $this->requests);
	}

	public function testSendTestMessage(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('admin@example.com');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('admin')->willReturn($user);

		$service = $this->makeService([], [
			$this->response(['errcode' => 0, 'userid' => 'wc-admin']),
			$this->response(['errcode' => 0, 'msgid' => 'm1']),
		], userManager: $userManager);

		$this->assertSame(['success' => true], $service->sendTestMessage('admin'));
	}

	public function testSendTestMessageFailsWithoutEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn(null);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$service = $this->makeService(userManager: $userManager);

		$result = $service->sendTestMessage('admin');
		$this->assertFalse($result['success']);
		$this->assertSame('no_email', $result['error']);
	}

	public function testNeverThrowsOnHttpException(): void {
		$this->cacheStore['access_token'] = 'cached-token';
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$this->client->method('post')->willThrowException(new \RuntimeException('network down'));
		$this->client->method('get')->willThrowException(new \RuntimeException('network down'));

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn('cached-token');
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('x');
		$appConfig->method('getValueBool')->willReturn(true);

		$service = new WeComService(
			$clientService,
			$cacheFactory,
			new SettingsService($appConfig),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertNull($service->getWeComUserIdByEmail('a@b.c'));
		$this->assertFalse($service->updateCardButtons('rc', 'x'));
	}
}
