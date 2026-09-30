<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Service;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Moves the recipient-side share mount point of approval shares into a
 * date-organized archive folder inside each approver's own files.
 *
 * The requester's original file is never touched: only the share target
 * (where the share appears for the recipient) is changed.
 * Circle shares are not supported (skipped with a log line).
 */
class ArchiveService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private IGroupManager $groupManager,
		private SettingsService $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<array{type: int, entityId: string}> $approverEntities entities from ApprovalInfoProvider::getApproverEntities()
	 */
	public function archiveShares(int $fileId, string $requesterUserId, array $approverEntities): void {
		$nodes = $this->rootFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		if ($node === null) {
			$this->logger->warning('Archive: file ' . $fileId . ' not found, skipping', ['app' => Application::APP_ID]);
			return;
		}

		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP] as $shareType) {
			try {
				$shares = $this->shareManager->getSharesBy($requesterUserId, $shareType, $node);
			} catch (\Throwable $e) {
				$this->logger->warning('Archive: listing shares failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				continue;
			}
			foreach ($shares as $share) {
				try {
					if ($shareType === IShare::TYPE_USER) {
						$recipientId = $share->getSharedWith();
						if (!is_string($recipientId) || !$this->matchesEntity($approverEntities, Application::TYPE_USER, $recipientId)) {
							continue;
						}
						$this->moveShareForRecipient($share, $recipientId, $requesterUserId, $node->getName());
					} else {
						$groupId = $share->getSharedWith();
						if (!is_string($groupId) || !$this->matchesEntity($approverEntities, Application::TYPE_GROUP, $groupId)) {
							continue;
						}
						$this->moveGroupShareForMembers($share, $groupId, $requesterUserId, $node->getName());
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Archive: moving share failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		}
	}

	/** @param list<array{type: int, entityId: string}> $entities */
	private function matchesEntity(array $entities, int $type, string $entityId): bool {
		foreach ($entities as $entity) {
			if ($entity['type'] === $type && $entity['entityId'] === $entityId) {
				return true;
			}
		}
		return false;
	}

	private function moveGroupShareForMembers(IShare $share, string $groupId, string $requesterUserId, string $nodeName): void {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return;
		}
		foreach ($group->getUsers() as $user) {
			$memberId = $user->getUID();
			try {
				// per-member view of the group share, carrying that member's target
				$memberShare = $this->shareManager->getShareById($share->getId(), $memberId);
				$this->moveShareForRecipient($memberShare, $memberId, $requesterUserId, $nodeName);
			} catch (\Throwable $e) {
				$this->logger->warning('Archive: moving group share for member ' . $memberId . ' failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}

	private function moveShareForRecipient(IShare $share, string $recipientId, string $requesterUserId, string $nodeName): void {
		if ($recipientId === $requesterUserId) {
			return;
		}
		$archiveDir = $this->getOrCreateArchiveDir($recipientId);
		$name = $this->uniqueName($archiveDir, $nodeName);

		$target = $this->archiveRelativePath() . '/' . $name;
		$share->setTarget($target);
		$this->shareManager->moveShare($share, $recipientId);
	}

	/** Path of the archive dir relative to the recipient's files root, e.g. '/approval/2026-09'. */
	private function archiveRelativePath(): string {
		return '/' . implode('/', $this->archivePathSegments());
	}

	/** @return list<string> e.g. ['approval', '2026-09'] or ['approval'] */
	private function archivePathSegments(): array {
		$segments = [$this->settings->getArchiveFolder()];
		$sub = match ($this->settings->getArchiveSubfolder()) {
			'month' => date('Y-m'),
			'year' => date('Y'),
			default => null,
		};
		if ($sub !== null) {
			$segments[] = $sub;
		}
		return $segments;
	}

	private function getOrCreateArchiveDir(string $userId): Folder {
		$dir = $this->rootFolder->getUserFolder($userId);
		foreach ($this->archivePathSegments() as $segment) {
			if ($dir->nodeExists($segment)) {
				$next = $dir->get($segment);
				if (!$next instanceof Folder) {
					throw new \RuntimeException('Archive path segment "' . $segment . '" exists and is not a folder');
				}
				$dir = $next;
			} else {
				$dir = $dir->newFolder($segment);
			}
		}
		return $dir;
	}

	private function uniqueName(Folder $dir, string $nodeName): string {
		$name = $nodeName;
		$i = 1;
		while ($dir->nodeExists($name)) {
			$i++;
			$dot = strrpos($nodeName, '.');
			$name = $dot > 0
				? substr($nodeName, 0, $dot) . ' (' . $i . ')' . substr($nodeName, $dot)
				: $nodeName . ' (' . $i . ')';
		}
		return $name;
	}
}
