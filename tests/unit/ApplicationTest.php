<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Listener\TagAssignmentListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\SystemTag\TagAssignedEvent;

final class ApplicationTest extends TestCase {
	public function testConstants(): void {
		$this->assertSame('approval_wecom', Application::APP_ID);
		$this->assertSame(2, Application::STATE_APPROVED);
		$this->assertSame(3, Application::STATE_REJECTED);
	}

	public function testRegisterWiresTagAssignmentListener(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerEventListener')
			->with(TagAssignedEvent::class, TagAssignmentListener::class);

		// App's constructor needs the server DI container; register() does not
		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);
	}
}
