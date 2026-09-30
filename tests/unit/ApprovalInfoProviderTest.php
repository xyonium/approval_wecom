<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class ApprovalInfoProviderTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IGroupManager&MockObject $groupManager;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function makeProvider($db): ApprovalInfoProvider {
		return new ApprovalInfoProvider($db, $this->appManager, $this->groupManager, $this->logger);
	}

	public function testIsApprovalAppEnabled(): void {
		[$db] = $this->newQueryBuilderMock();
		$this->appManager->method('isEnabledForUser')->with('approval')->willReturn(true);
		$this->assertTrue($this->makeProvider($db)->isApprovalAppEnabled());
	}

	public function testFindRuleByTagIdMatchesPending(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([[
			'id' => '3', 'tag_pending' => '10', 'tag_approved' => '11', 'tag_rejected' => '12',
		]]));
		$rule = $this->makeProvider($db)->findRuleByTagId(10);
		$this->assertSame(3, $rule['id']);
		$this->assertSame('pending', $rule['matched']);
		$this->assertSame('10', $rule['tagPending']);
		$this->assertSame('11', $rule['tagApproved']);
		$this->assertSame('12', $rule['tagRejected']);
	}

	public function testFindRuleByTagIdMatchesApprovedAndRejected(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([
			['id' => '3', 'tag_pending' => '10', 'tag_approved' => '11', 'tag_rejected' => '12'],
			['id' => '3', 'tag_pending' => '10', 'tag_approved' => '11', 'tag_rejected' => '12'],
		]));
		$provider = $this->makeProvider($db);
		$this->assertSame('approved', $provider->findRuleByTagId(11)['matched']);
		$this->assertSame('rejected', $provider->findRuleByTagId(12)['matched']);
	}

	public function testFindRuleByTagIdReturnsNull(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([]));
		$this->assertNull($this->makeProvider($db)->findRuleByTagId(99));
	}

	public function testFindRuleById(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([[
			'id' => '7', 'tag_pending' => '20', 'tag_approved' => '21', 'tag_rejected' => '22',
		]]));
		$rule = $this->makeProvider($db)->findRuleById(7);
		$this->assertSame(7, $rule['id']);
		$this->assertSame('20', $rule['tagPending']);
		$this->assertArrayNotHasKey('matched', $rule);
	}

	public function testGetApproverEntities(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([
			['entity_type' => '0', 'entity_id' => 'alice'],
			['entity_type' => '1', 'entity_id' => 'admins'],
		]));
		$entities = $this->makeProvider($db)->getApproverEntities(3);
		$this->assertSame([
			['type' => Application::TYPE_USER, 'entityId' => 'alice'],
			['type' => Application::TYPE_GROUP, 'entityId' => 'admins'],
		], $entities);
	}

	public function testResolveApproverUserIdsExpandsGroupsAndSkipsCircles(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([
			['entity_type' => '0', 'entity_id' => 'alice'],
			['entity_type' => '1', 'entity_id' => 'admins'],
			['entity_type' => '1', 'entity_id' => 'ghost'],
			['entity_type' => '2', 'entity_id' => 'circle1'],
		]));

		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$carol = $this->createMock(IUser::class);
		$carol->method('getUID')->willReturn('carol');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$bob, $carol]);
		$this->groupManager->method('get')->willReturnMap([
			['admins', $group],
			['ghost', null],
		]);

		$userIds = $this->makeProvider($db)->resolveApproverUserIds(3);
		$this->assertSame(['alice', 'bob', 'carol'], $userIds);
	}

	public function testFindPendingRequesterUserId(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([['user_id' => 'alice']]));
		$this->assertSame('alice', $this->makeProvider($db)->findPendingRequesterUserId(12, 3));
	}

	public function testFindPendingRequesterUserIdReturnsNull(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([]));
		$this->assertNull($this->makeProvider($db)->findPendingRequesterUserId(12, 3));
	}

	public function testGetAllRuleTags(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([
			['id' => '3', 'tag_pending' => '10', 'tag_approved' => '11', 'tag_rejected' => '12'],
			['id' => '4', 'tag_pending' => '13', 'tag_approved' => '14', 'tag_rejected' => '15'],
		]));
		$this->assertSame([
			['ruleId' => 3, 'tagPending' => 10, 'tagApproved' => 11, 'tagRejected' => 12],
			['ruleId' => 4, 'tagPending' => 13, 'tagApproved' => 14, 'tagRejected' => 15],
		], $this->makeProvider($db)->getAllRuleTags());
	}
}
