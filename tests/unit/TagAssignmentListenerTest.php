<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Listener\TagAssignmentListener;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\ArchiveService;
use OCA\ApprovalWeCom\Service\RequestRegistry;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Service\WeComService;
use OCP\EventDispatcher\Event;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\TagAssignedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class TagAssignmentListenerTest extends TestCase {
	private ApprovalInfoProvider&MockObject $infoProvider;
	private RequestRegistry&MockObject $registry;
	private SettingsService&MockObject $settings;
	private WeComService&MockObject $weComService;
	private ArchiveService&MockObject $archiveService;
	private IUserManager&MockObject $userManager;
	private IRootFolder&MockObject $rootFolder;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		parent::setUp();
		$this->infoProvider = $this->createMock(ApprovalInfoProvider::class);
		$this->registry = $this->createMock(RequestRegistry::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->weComService = $this->createMock(WeComService::class);
		$this->archiveService = $this->createMock(ArchiveService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->infoProvider->method('isApprovalAppEnabled')->willReturn(true);
		$this->settings->method('getPendingTagIds')->willReturn([10]);
		$this->settings->method('getApprovedTagIds')->willReturn([11]);
		$this->settings->method('getRejectedTagIds')->willReturn([12]);
		$this->settings->method('getWeComEnabled')->willReturn(true);
		$this->settings->method('isWeComConfigured')->willReturn(true);
		$this->settings->method('getArchiveEnabled')->willReturn(true);
	}

	private function makeListener(): TagAssignmentListener {
		return new TagAssignmentListener(
			$this->infoProvider,
			$this->registry,
			$this->settings,
			$this->weComService,
			$this->archiveService,
			$this->userManager,
			$this->rootFolder,
			$this->urlGenerator,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function nodeWithOwner(?string $ownerUid, string $name = 'doc.pdf'): Node&MockObject {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn($name);
		$owner = null;
		if ($ownerUid !== null) {
			$owner = $this->createMock(IUser::class);
			$owner->method('getUID')->willReturn($ownerUid);
		}
		$node->method('getOwner')->willReturn($owner);
		return $node;
	}

	private function userWithEmail(string $uid, string $email): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn($uid . ' display');
		return $user;
	}

	public function testIgnoresNonFilesObjectType(): void {
		$this->infoProvider->expects($this->never())->method('isApprovalAppEnabled');
		$this->makeListener()->handle(new TagAssignedEvent('calendar', ['12'], [10]));
	}

	public function testIgnoresUnrelatedEvent(): void {
		$this->infoProvider->expects($this->never())->method('isApprovalAppEnabled');
		$this->makeListener()->handle(new Event());
	}

	public function testIgnoresWhenApprovalAppDisabled(): void {
		$infoProvider = $this->createMock(ApprovalInfoProvider::class);
		$infoProvider->method('isApprovalAppEnabled')->willReturn(false);
		$infoProvider->expects($this->never())->method('findRuleByTagId');
		$this->infoProvider = $infoProvider;
		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [10]));
	}

	public function testIgnoresUntrackedTag(): void {
		$this->infoProvider->expects($this->never())->method('findRuleByTagId');
		$this->registry->expects($this->never())->method('register');
		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [99]));
	}

	public function testPendingRegistersAndSendsCards(): void {
		$this->infoProvider->method('findRuleByTagId')->with(10)->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'pending',
		]);
		$this->infoProvider->method('findPendingRequesterUserId')->with(12, 3)->willReturn('alice');
		$this->infoProvider->method('resolveApproverUserIds')->with(3)->willReturn(['bob']);

		$bob = $this->userWithEmail('bob', 'bob@example.com');
		$alice = $this->userWithEmail('alice', 'alice@example.com');
		$this->userManager->method('get')->willReturnMap([['bob', $bob], ['alice', $alice]]);
		$this->weComService->method('getWeComUserIdByEmail')->with('bob@example.com')->willReturn('wcBob');
		$this->rootFolder->method('getById')->with(12)->willReturn([$this->nodeWithOwner('alice')]);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', ['fileid' => 12])
			->willReturn('https://nc/f/12');

		$this->registry->expects($this->once())->method('register')->with(12, 3, 'alice');
		$this->weComService->expects($this->once())->method('sendApprovalCards')
			->with(['wcBob'], 'alice display', 'doc.pdf', 12, 3, 'https://nc/f/12')
			->willReturn(['taskId' => 'approval_12_3_1', 'responseCode' => 'rc-send']);
		$this->registry->expects($this->once())->method('attachCardInfo')
			->with(12, 3, 'approval_12_3_1', 'rc-send');

		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [10]));
	}

	public function testPendingRegistersEvenWhenWeComDisabled(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPendingTagIds')->willReturn([10]);
		$settings->method('getApprovedTagIds')->willReturn([11]);
		$settings->method('getRejectedTagIds')->willReturn([12]);
		$settings->method('getWeComEnabled')->willReturn(false);
		$this->settings = $settings;

		$this->infoProvider->method('findRuleByTagId')->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'pending',
		]);
		$this->infoProvider->method('findPendingRequesterUserId')->willReturn('alice');

		$this->registry->expects($this->once())->method('register')->with(12, 3, 'alice');
		$this->weComService->expects($this->never())->method('sendApprovalCards');

		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [10]));
	}

	public function testPendingFallsBackToNodeOwnerAsRequester(): void {
		$this->infoProvider->method('findRuleByTagId')->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'pending',
		]);
		$this->infoProvider->method('findPendingRequesterUserId')->willReturn(null);
		$this->rootFolder->method('getById')->with(12)->willReturn([$this->nodeWithOwner('carol')]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPendingTagIds')->willReturn([10]);
		$settings->method('getApprovedTagIds')->willReturn([11]);
		$settings->method('getRejectedTagIds')->willReturn([12]);
		$settings->method('getWeComEnabled')->willReturn(false);
		$this->settings = $settings;

		$this->registry->expects($this->once())->method('register')->with(12, 3, 'carol');
		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [10]));
	}

	public function testPendingSkippedWhenRequesterUnknown(): void {
		$this->infoProvider->method('findRuleByTagId')->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'pending',
		]);
		$this->infoProvider->method('findPendingRequesterUserId')->willReturn(null);
		$this->rootFolder->method('getById')->willReturn([]);

		$this->registry->expects($this->never())->method('register');
		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [10]));
	}

	public function testPendingSkippedWhenTagIsNotRulePendingTag(): void {
		$this->infoProvider->method('findRuleByTagId')->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'approved',
		]);
		$this->registry->expects($this->never())->method('register');
		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [10]));
	}

	public function testApprovedArchivesUpdatesCardNotifiesAndDeletes(): void {
		$this->infoProvider->method('findRuleByTagId')->with(11)->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'approved',
		]);
		$this->registry->method('find')->with(12, 3)->willReturn([
			'id' => 1, 'file_id' => 12, 'rule_id' => 3, 'requester_user_id' => 'alice',
			'task_id' => 'approval_12_3_1', 'response_code' => 'rc-send', 'created_at' => 1,
		]);
		$entities = [['type' => Application::TYPE_USER, 'entityId' => 'bob']];
		$this->infoProvider->method('getApproverEntities')->with(3)->willReturn($entities);
		$this->infoProvider->method('findResolverUserId')->with(12, 3)->willReturn('bob');
		$bob = $this->userWithEmail('bob', 'bob@example.com');
		$this->userManager->method('get')->with('bob')->willReturn($bob);
		$this->rootFolder->method('getById')->with(12)->willReturn([$this->nodeWithOwner('alice')]);

		$this->archiveService->expects($this->once())->method('archiveShares')->with(12, 'alice', $entities);
		$this->weComService->expects($this->once())->method('replaceCardWithNotice')
			->with('rc-send', '审批已通过', 'doc.pdf 已批准，操作人：bob display');
		$this->weComService->expects($this->once())->method('sendNoticeToNcUser')
			->with('alice', 'doc.pdf 已批准，操作人：bob display');
		$this->registry->expects($this->once())->method('delete')->with(12, 3);

		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [11]));
	}

	public function testRejectedNotifiesButDoesNotArchive(): void {
		$this->infoProvider->method('findRuleByTagId')->with(12)->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'rejected',
		]);
		$this->registry->method('find')->willReturn([
			'id' => 1, 'file_id' => 12, 'rule_id' => 3, 'requester_user_id' => 'alice',
			'task_id' => 't', 'response_code' => 'rc-send', 'created_at' => 1,
		]);
		$this->infoProvider->method('findResolverUserId')->willReturn('bob');
		$bob = $this->userWithEmail('bob', 'bob@example.com');
		$this->userManager->method('get')->willReturn($bob);
		$this->rootFolder->method('getById')->willReturn([$this->nodeWithOwner('alice')]);

		$this->archiveService->expects($this->never())->method('archiveShares');
		$this->weComService->expects($this->once())->method('replaceCardWithNotice')
			->with('rc-send', '审批已拒绝', 'doc.pdf 已拒绝，操作人：bob display');
		$this->weComService->expects($this->once())->method('sendNoticeToNcUser')
			->with('alice', 'doc.pdf 已拒绝，操作人：bob display');
		$this->registry->expects($this->once())->method('delete')->with(12, 3);

		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [12]));
	}

	public function testApprovedWithoutRegistryRowFallsBackToNodeOwner(): void {
		$this->infoProvider->method('findRuleByTagId')->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'approved',
		]);
		$this->registry->method('find')->willReturn(null);
		$entities = [['type' => Application::TYPE_USER, 'entityId' => 'bob']];
		$this->infoProvider->method('getApproverEntities')->willReturn($entities);
		$this->infoProvider->method('findResolverUserId')->willReturn(null);
		$this->rootFolder->method('getById')->willReturn([$this->nodeWithOwner('carol')]);

		$this->archiveService->expects($this->once())->method('archiveShares')->with(12, 'carol', $entities);
		$this->weComService->expects($this->never())->method('replaceCardWithNotice');
		$this->weComService->expects($this->once())->method('sendNoticeToNcUser')
			->with('carol', 'doc.pdf 已批准');
		$this->registry->expects($this->once())->method('delete')->with(12, 3);

		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [11]));
	}

	public function testApprovedWithUnknownRuleArchivesUnfiltered(): void {
		$this->infoProvider->method('findRuleByTagId')->willReturn(null);
		$this->rootFolder->method('getById')->willReturn([$this->nodeWithOwner('carol')]);
		$this->registry->expects($this->never())->method('find');
		$this->registry->expects($this->never())->method('delete');
		$this->archiveService->expects($this->once())->method('archiveShares')->with(12, 'carol', null);

		$this->makeListener()->handle(new TagAssignedEvent('files', ['12'], [11]));
	}

	public function testDuplicateObjectsAndTagsProcessedOnce(): void {
		$this->infoProvider->method('findRuleByTagId')->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'pending',
		]);
		$this->infoProvider->method('findPendingRequesterUserId')->willReturn('alice');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPendingTagIds')->willReturn([10]);
		$settings->method('getApprovedTagIds')->willReturn([11]);
		$settings->method('getRejectedTagIds')->willReturn([12]);
		$settings->method('getWeComEnabled')->willReturn(false);
		$this->settings = $settings;

		$this->registry->expects($this->once())->method('register')->with(12, 3, 'alice');
		$this->makeListener()->handle(new TagAssignedEvent('files', ['12', '12'], [10, 10]));
	}

	public function testErrorOnOneObjectDoesNotStopOthers(): void {
		$this->infoProvider->method('findRuleByTagId')->willReturn([
			'id' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12, 'matched' => 'pending',
		]);
		$this->infoProvider->method('findPendingRequesterUserId')->willReturn('alice');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPendingTagIds')->willReturn([10]);
		$settings->method('getApprovedTagIds')->willReturn([11]);
		$settings->method('getRejectedTagIds')->willReturn([12]);
		$settings->method('getWeComEnabled')->willReturn(false);
		$this->settings = $settings;

		$calls = 0;
		$this->registry->expects($this->exactly(2))->method('register')
			->willReturnCallback(static function () use (&$calls): void {
				$calls++;
				if ($calls === 1) {
					throw new \RuntimeException('db gone');
				}
			});

		$this->makeListener()->handle(new TagAssignedEvent('files', ['12', '13'], [10]));
	}
}
