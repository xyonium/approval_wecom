<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Service\ArchiveService;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class ArchiveServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private IManager&MockObject $shareManager;
	private IGroupManager&MockObject $groupManager;
	private SettingsService&MockObject $settings;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function makeService(): ArchiveService {
		return new ArchiveService(
			$this->rootFolder,
			$this->shareManager,
			$this->groupManager,
			$this->settings,
			$this->logger,
		);
	}

	private function fileNode(string $name = 'doc.pdf'): Node&MockObject {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn($name);
		return $node;
	}

	private function userShare(string $sharedWith): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getSharedWith')->willReturn($sharedWith);
		$share->method('getId')->willReturn('share-' . $sharedWith);
		return $share;
	}

	/** Folder mock whose nodeExists/get/newFolder create the requested child mocks recursively. */
	private function folderChain(array &$createdFolders): IUserFolder&MockObject {
		$factory = null;
		$factory = function (bool $isRoot) use (&$factory, &$createdFolders) {
			$folder = $isRoot ? $this->createMock(IUserFolder::class) : $this->createMock(Folder::class);
			$children = [];
			$folder->method('nodeExists')->willReturnCallback(static fn (string $name): bool => isset($children[$name]));
			$folder->method('get')->willReturnCallback(static function (string $name) use (&$children) {
				return $children[$name] ?? throw new \LogicException('unexpected get ' . $name);
			});
			$folder->method('newFolder')->willReturnCallback(static function (string $name) use (&$children, &$factory, &$createdFolders) {
				$child = $factory(false);
				$children[$name] = $child;
				$createdFolders[] = $name;
				return $child;
			});
			return $folder;
		};
		return $factory(true);
	}

	public function testUserShareIsMovedIntoMonthlyArchiveFolder(): void {
		$node = $this->fileNode('doc.pdf');
		$this->rootFolder->method('getById')->with(12)->willReturn([$node]);
		$this->settings->method('getArchiveFolder')->willReturn('approval');
		$this->settings->method('getArchiveSubfolder')->willReturn('month');

		$shareAlice = $this->userShare('alice');
		$shareBob = $this->userShare('bob'); // not an approver of the rule
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			static fn (string $userId, int $type, ?Node $path = null): array =>
				$type === IShare::TYPE_USER ? [$shareAlice, $shareBob] : []
		);

		$created = [];
		$userFolder = $this->folderChain($created);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$expectedPath = '/approval/' . date('Y-m') . '/doc.pdf';
		$shareAlice->expects($this->once())->method('setTarget')->with($expectedPath);
		$shareBob->expects($this->never())->method('setTarget');
		$this->shareManager->expects($this->once())->method('moveShare')->with($shareAlice, 'alice');

		$this->makeService()->archiveShares(12, 'requester', [
			['type' => Application::TYPE_USER, 'entityId' => 'alice'],
		]);
	}

	public function testSubfolderModesNoneAndYear(): void {
		$node = $this->fileNode('doc.pdf');
		$this->rootFolder->method('getById')->willReturn([$node]);

		foreach ([['none', '/approval/doc.pdf'], ['year', '/approval/' . date('Y') . '/doc.pdf']] as [$mode, $expected]) {
			$share = $this->userShare('alice');
			$this->shareManager = $this->createMock(IManager::class);
			$this->shareManager->method('getSharesBy')->willReturnCallback(
				static fn (string $userId, int $type, ?Node $path = null): array =>
					$type === IShare::TYPE_USER ? [$share] : []
			);
			$this->settings = $this->createMock(SettingsService::class);
			$this->settings->method('getArchiveFolder')->willReturn('approval');
			$this->settings->method('getArchiveSubfolder')->willReturn($mode);

			$created = [];
			$this->rootFolder = $this->createMock(IRootFolder::class);
			$this->rootFolder->method('getById')->willReturn([$node]);
			$this->rootFolder->method('getUserFolder')->willReturn($this->folderChain($created));

			$share->expects($this->once())->method('setTarget')->with($expected);
			$this->makeService()->archiveShares(12, 'requester', [
				['type' => Application::TYPE_USER, 'entityId' => 'alice'],
			]);
		}
	}

	public function testDuplicateNameGetsNumericSuffix(): void {
		$node = $this->fileNode('doc.pdf');
		$this->rootFolder->method('getById')->willReturn([$node]);
		$this->settings->method('getArchiveFolder')->willReturn('approval');
		$this->settings->method('getArchiveSubfolder')->willReturn('none');

		$share = $this->userShare('alice');
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			static fn (string $userId, int $type, ?Node $path = null): array =>
				$type === IShare::TYPE_USER ? [$share] : []
		);

		// 'approval' exists; inside it 'doc.pdf' exists, 'doc (2).pdf' does not
		$archiveFolder = $this->createMock(Folder::class);
		$archiveFolder->method('nodeExists')->willReturnCallback(
			static fn (string $name): bool => $name === 'doc.pdf'
		);
		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('nodeExists')->with('approval')->willReturn(true);
		$userFolder->method('get')->with('approval')->willReturn($archiveFolder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$share->expects($this->once())->method('setTarget')->with('/approval/doc (2).pdf');
		$this->makeService()->archiveShares(12, 'requester', [
			['type' => Application::TYPE_USER, 'entityId' => 'alice'],
		]);
	}

	public function testGroupShareIsMovedPerMember(): void {
		$node = $this->fileNode('doc.pdf');
		$this->rootFolder->method('getById')->willReturn([$node]);
		$this->settings->method('getArchiveFolder')->willReturn('approval');
		$this->settings->method('getArchiveSubfolder')->willReturn('none');

		$groupShare = $this->createMock(IShare::class);
		$groupShare->method('getSharedWith')->willReturn('admins');
		$groupShare->method('getId')->willReturn('55');
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			static fn (string $userId, int $type, ?Node $path = null): array =>
				$type === IShare::TYPE_GROUP ? [$groupShare] : []
		);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$alice, $bob]);
		$this->groupManager->method('get')->with('admins')->willReturn($group);

		$memberShareAlice = $this->userShare('alice');
		$memberShareBob = $this->userShare('bob');
		$this->shareManager->method('getShareById')->willReturnMap([
			['55', 'alice', true, $memberShareAlice],
			['55', 'bob', true, $memberShareBob],
		]);

		$created = [];
		$this->rootFolder->method('getUserFolder')->willReturn($this->folderChain($created));

		$memberShareAlice->expects($this->once())->method('setTarget')->with('/approval/doc.pdf');
		$memberShareBob->expects($this->once())->method('setTarget')->with('/approval/doc.pdf');
		$this->shareManager->expects($this->exactly(2))->method('moveShare');

		$this->makeService()->archiveShares(12, 'requester', [
			['type' => Application::TYPE_GROUP, 'entityId' => 'admins'],
		]);
	}

	public function testShareOwnedByRequesterIsSkipped(): void {
		$node = $this->fileNode();
		$this->rootFolder->method('getById')->willReturn([$node]);
		$this->settings->method('getArchiveFolder')->willReturn('approval');
		$this->settings->method('getArchiveSubfolder')->willReturn('none');

		$share = $this->userShare('requester');
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			static fn (string $userId, int $type, ?Node $path = null): array =>
				$type === IShare::TYPE_USER ? [$share] : []
		);
		$this->shareManager->expects($this->never())->method('moveShare');
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->makeService()->archiveShares(12, 'requester', [
			['type' => Application::TYPE_USER, 'entityId' => 'requester'],
		]);
	}

	public function testRecipientErrorDoesNotStopOtherRecipients(): void {
		$node = $this->fileNode();
		$this->rootFolder->method('getById')->willReturn([$node]);
		$this->settings->method('getArchiveFolder')->willReturn('approval');
		$this->settings->method('getArchiveSubfolder')->willReturn('none');

		$shareAlice = $this->userShare('alice');
		$shareBob = $this->userShare('bob');
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			static fn (string $userId, int $type, ?Node $path = null): array =>
				$type === IShare::TYPE_USER ? [$shareAlice, $shareBob] : []
		);

		$this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $userId) use (&$created) {
			if ($userId === 'alice') {
				throw new \OCP\Files\NotPermittedException('no home');
			}
			$created = [];
			return $this->folderChain($created);
		});

		$shareBob->expects($this->once())->method('setTarget')->with('/approval/doc.pdf');
		$this->shareManager->expects($this->once())->method('moveShare')->with($shareBob, 'bob');

		$this->makeService()->archiveShares(12, 'requester', [
			['type' => Application::TYPE_USER, 'entityId' => 'alice'],
			['type' => Application::TYPE_USER, 'entityId' => 'bob'],
		]);
	}

	public function testUnknownFileDoesNothing(): void {
		$this->rootFolder->method('getById')->willReturn([]);
		$this->shareManager->expects($this->never())->method('getSharesBy');
		$this->makeService()->archiveShares(99, 'requester', [['type' => 0, 'entityId' => 'alice']]);
	}

	public function testNullEntitiesMovesAllRequesterShares(): void {
		$node = $this->fileNode('doc.pdf');
		$this->rootFolder->method('getById')->willReturn([$node]);
		$this->settings->method('getArchiveFolder')->willReturn('approval');
		$this->settings->method('getArchiveSubfolder')->willReturn('none');

		$shareAlice = $this->userShare('alice');
		$shareBob = $this->userShare('bob');
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			static fn (string $userId, int $type, ?Node $path = null): array =>
				$type === IShare::TYPE_USER ? [$shareAlice, $shareBob] : []
		);

		$created = [];
		$this->rootFolder->method('getUserFolder')->willReturn($this->folderChain($created));

		$shareAlice->expects($this->once())->method('setTarget')->with('/approval/doc.pdf');
		$shareBob->expects($this->once())->method('setTarget')->with('/approval/doc.pdf');
		$this->shareManager->expects($this->exactly(2))->method('moveShare');

		$this->makeService()->archiveShares(12, 'requester', null);
	}
}
