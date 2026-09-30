<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\Service\ApprovalExecutor;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class ApprovalExecutorTest extends TestCase {
	private ApprovalInfoProvider&MockObject $infoProvider;
	private ISystemTagObjectMapper&MockObject $tagObjectMapper;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->infoProvider = $this->createMock(ApprovalInfoProvider::class);
		$this->tagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/** A duck-typed stand-in for OCA\Approval\Service\ApprovalService. */
	private function fakeApprovalService(?bool $result, ?\Throwable $throw = null): object {
		return new class($result, $throw) {
			/** @var list<array{method: string, args: array}> */
			public array $calls = [];

			public function __construct(private ?bool $result, private ?\Throwable $throw) {
			}

			public function getEtag(int $fileId): string {
				return 'etag-' . $fileId;
			}

			public function approve(int $fileId, ?string $userId, string $etag, string $message = ''): bool {
				$this->calls[] = ['method' => 'approve', 'args' => func_get_args()];
				if ($this->throw !== null) {
					throw $this->throw;
				}
				return $this->result;
			}

			public function reject(int $fileId, ?string $userId, string $etag, string $message = ''): bool {
				$this->calls[] = ['method' => 'reject', 'args' => func_get_args()];
				if ($this->throw !== null) {
					throw $this->throw;
				}
				return $this->result;
			}
		};
	}

	private function makeExecutor(?object $service) : ApprovalExecutor {
		[$db] = $this->newQueryBuilderMock();
		return new ApprovalExecutor(
			$this->infoProvider,
			$this->tagObjectMapper,
			$db,
			$this->logger,
			static fn (): ?object => $service,
		);
	}

	public function testExecuteUsesApprovalServiceWhenAvailable(): void {
		$service = $this->fakeApprovalService(true);
		$executor = $this->makeExecutor($service);

		$result = $executor->execute('approve', 12, 3, 'alice');

		$this->assertSame(['success' => true], $result);
		$this->assertSame('approve', $service->calls[0]['method']);
		$this->assertSame([12, 'alice', 'etag-12', 'via WeCom'], $service->calls[0]['args']);
	}

	public function testExecuteRejectPassesThrough(): void {
		$service = $this->fakeApprovalService(true);
		$executor = $this->makeExecutor($service);

		$this->assertSame(['success' => true], $executor->execute('reject', 12, 3, 'alice'));
		$this->assertSame('reject', $service->calls[0]['method']);
	}

	public function testExecuteReportsRefusal(): void {
		$executor = $this->makeExecutor($this->fakeApprovalService(false));
		$result = $executor->execute('approve', 12, 3, 'alice');
		$this->assertFalse($result['success']);
		$this->assertNotEmpty($result['error']);
	}

	public function testExecuteReportsException(): void {
		$executor = $this->makeExecutor($this->fakeApprovalService(null, new \RuntimeException('etag mismatch')));
		$result = $executor->execute('approve', 12, 3, 'alice');
		$this->assertFalse($result['success']);
		$this->assertSame('etag mismatch', $result['error']);
	}

	public function testInvalidAction(): void {
		$executor = $this->makeExecutor(null);
		$this->assertSame(['success' => false, 'error' => 'invalid action'], $executor->execute('dance', 12, 3, 'alice'));
	}

	public function testFallbackApproveWritesActivityAndSwapsTags(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('delete')->willReturn($qb);
		$qb->method('insert')->willReturn($qb);
		$qb->method('executeStatement')->willReturn(1);

		$this->infoProvider->method('findRuleById')->with(3)->willReturn([
			'id' => 3, 'tagPending' => '10', 'tagApproved' => '11', 'tagRejected' => '12',
		]);
		$this->infoProvider->method('resolveApproverUserIds')->with(3)->willReturn(['alice']);
		$this->tagObjectMapper->method('haveTag')->with('12', 'files', '10')->willReturn(true);
		$this->tagObjectMapper->expects($this->once())->method('assignTags')->with('12', 'files', '11');
		$this->tagObjectMapper->expects($this->once())->method('unassignTags')->with('12', 'files', '10');

		$executor = new ApprovalExecutor($this->infoProvider, $this->tagObjectMapper, $db, $this->logger, static fn () => null);
		$this->assertSame(['success' => true], $executor->execute('approve', 12, 3, 'alice'));
	}

	public function testFallbackRejectUsesRejectedTag(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('delete')->willReturn($qb);
		$qb->method('insert')->willReturn($qb);
		$qb->method('executeStatement')->willReturn(1);

		$this->infoProvider->method('findRuleById')->willReturn([
			'id' => 3, 'tagPending' => '10', 'tagApproved' => '11', 'tagRejected' => '12',
		]);
		$this->infoProvider->method('resolveApproverUserIds')->willReturn(['alice']);
		$this->tagObjectMapper->method('haveTag')->willReturn(true);
		$this->tagObjectMapper->expects($this->once())->method('assignTags')->with('12', 'files', '12');

		$executor = new ApprovalExecutor($this->infoProvider, $this->tagObjectMapper, $db, $this->logger, static fn () => null);
		$this->assertSame(['success' => true], $executor->execute('reject', 12, 3, 'alice'));
	}

	public function testFallbackRejectsUnauthorizedUser(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->expects($this->never())->method('insert');
		$this->infoProvider->method('findRuleById')->willReturn([
			'id' => 3, 'tagPending' => '10', 'tagApproved' => '11', 'tagRejected' => '12',
		]);
		$this->infoProvider->method('resolveApproverUserIds')->willReturn(['bob']);
		$this->tagObjectMapper->expects($this->never())->method('assignTags');

		$executor = new ApprovalExecutor($this->infoProvider, $this->tagObjectMapper, $db, $this->logger, static fn () => null);
		$result = $executor->execute('approve', 12, 3, 'alice');
		$this->assertFalse($result['success']);
	}

	public function testFallbackRejectsWhenNotPending(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->expects($this->never())->method('insert');
		$this->infoProvider->method('findRuleById')->willReturn([
			'id' => 3, 'tagPending' => '10', 'tagApproved' => '11', 'tagRejected' => '12',
		]);
		$this->infoProvider->method('resolveApproverUserIds')->willReturn(['alice']);
		$this->tagObjectMapper->method('haveTag')->willReturn(false);
		$this->tagObjectMapper->expects($this->never())->method('assignTags');

		$executor = new ApprovalExecutor($this->infoProvider, $this->tagObjectMapper, $db, $this->logger, static fn () => null);
		$this->assertFalse($executor->execute('approve', 12, 3, 'alice')['success']);
	}

	public function testFallbackRejectsUnknownRule(): void {
		[$db] = $this->newQueryBuilderMock();
		$this->infoProvider->method('findRuleById')->willReturn(null);

		$executor = new ApprovalExecutor($this->infoProvider, $this->tagObjectMapper, $db, $this->logger, static fn () => null);
		$result = $executor->execute('approve', 12, 3, 'alice');
		$this->assertFalse($result['success']);
		$this->assertSame('rule not found', $result['error']);
	}
}
