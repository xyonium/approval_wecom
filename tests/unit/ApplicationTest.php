<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;

final class ApplicationTest extends TestCase {
	public function testConstants(): void {
		$this->assertSame('approval_wecom', Application::APP_ID);
		$this->assertSame(2, Application::STATE_APPROVED);
		$this->assertSame(3, Application::STATE_REJECTED);
	}
}
