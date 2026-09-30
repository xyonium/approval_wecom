<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\BackgroundJob\CleanupRequestsJob;
use OCA\ApprovalWeCom\Service\RequestRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;

final class CleanupRequestsJobTest extends TestCase {
	private RequestRegistry&MockObject $registry;
	private ITimeFactory&MockObject $timeFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = $this->createMock(RequestRegistry::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
	}

	private function makeJob(): CleanupRequestsJob {
		// Job::run() is protected; expose it for the test
		return new class($this->timeFactory, $this->registry) extends CleanupRequestsJob {
			public function runPublic(mixed $argument): void {
				$this->run($argument);
			}
		};
	}

	public function testIntervalIsDaily(): void {
		$this->assertSame(86400, $this->makeJob()->getInterval());
	}

	public function testRunDeletesRowsOlderThan30Days(): void {
		$this->timeFactory->method('getTime')->willReturn(1_800_000_000);
		$this->registry->expects($this->once())->method('deleteOlderThan')
			->with(1_800_000_000 - 30 * 86400)
			->willReturn(2);

		$this->makeJob()->runPublic(null);
	}

	public function testRunDoesNotThrowWhenRegistryFails(): void {
		$this->timeFactory->method('getTime')->willReturn(1_800_000_000);
		$this->registry->method('deleteOlderThan')->willThrowException(new \RuntimeException('db gone'));

		$this->makeJob()->runPublic(null);
		$this->addToAssertionCount(1); // no exception escaped
	}
}
