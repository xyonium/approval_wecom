<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\BackgroundJob;

use OCA\ApprovalWeCom\Service\RequestRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Sweeps orphaned approval_wecom_requests rows: requests whose tags were
 * removed manually or whose files were deleted never see a resolution event,
 * so their rows would pile up otherwise. Runs daily, deletes rows older
 * than 30 days.
 */
class CleanupRequestsJob extends TimedJob {
	private const MAX_AGE = 30 * 86400;

	public function __construct(
		ITimeFactory $time,
		private RequestRegistry $registry,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		try {
			$this->registry->deleteOlderThan($this->time->getTime() - self::MAX_AGE);
		} catch (\Throwable) {
			// a failed sweep must not fail the cron run
		}
	}
}
