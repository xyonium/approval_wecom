<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Command;

use OCA\ApprovalWeCom\Service\SettingsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveExistingCommand extends Command {
	public function __construct(
		private IRootFolder $rootFolder,
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private IDBConnection $db,
		private SettingsService $settings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('approval_wecom:archive-existing')
			->setDescription('Archive approved shared files from a user\'s root directory')
			->addArgument('user-id', InputArgument::REQUIRED, 'The NC user ID (LDAP UUID)')
			->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'Tag name substring to match (case-insensitive)', 'approved')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'List files that would be moved without actually moving them');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user-id');
		$tagFilter = strtolower($input->getOption('tag'));
		$dryRun = $input->getOption('dry-run');

		$archiveFolder = $this->settings->getArchiveFolder();
		$subfolderMode = $this->settings->getArchiveSubfolder();

		$output->writeln("User:            $userId");
		$output->writeln("Tag filter:      *$tagFilter*");
		$output->writeln("Archive folder:  $archiveFolder");
		$output->writeln("Subfolder mode:  $subfolderMode (per-file, based on approval time → file mtime fallback)");
		$output->writeln($dryRun ? "Mode:            DRY RUN" : "Mode:            LIVE");
		$output->writeln('');

		// Collect all system tags matching the filter
		$allTags = $this->tagManager->getAllTags();
		$matchingTagIds = [];
		foreach ($allTags as $tag) {
			if (str_contains(strtolower($tag->getName()), $tagFilter)) {
				$matchingTagIds[] = $tag->getId();
				$output->writeln("  Matched tag: #{$tag->getId()} \"{$tag->getName()}\"");
			}
		}
		if ($matchingTagIds === []) {
			$output->writeln('<error>No system tags matching "' . $tagFilter . '" found.</error>');
			return 1;
		}
		$output->writeln('');

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$output->writeln('<error>Cannot open user folder: ' . $e->getMessage() . '</error>');
			return 1;
		}

		// Scan direct children of the user's root
		$candidates = [];
		foreach ($userFolder->getDirectoryListing() as $node) {
			if ($node->getName() === $archiveFolder) {
				continue;
			}
			if (!$node->isShared()) {
				continue;
			}
			$fileId = (string)$node->getId();
			$fileTags = $this->tagMapper->getTagIdsForObjects([$fileId], 'files');
			$nodeTagIds = $fileTags[$fileId] ?? [];
			$hit = array_intersect($matchingTagIds, $nodeTagIds);
			if ($hit === []) {
				continue;
			}
			$candidates[] = $node;
		}

		if ($candidates === []) {
			$output->writeln('No matching files found in root directory.');
			return 0;
		}

		$output->writeln(count($candidates) . ' file(s) to archive:');
		$output->writeln('');

		// Cache of created archive dirs keyed by subfolder string (e.g. "2026-09")
		$archiveDirs = [];

		$moved = 0;
		foreach ($candidates as $node) {
			$name = $node->getName();
			$fileId = $node->getId();

			// Determine the timestamp for subfolder placement
			$ts = $this->findApprovalTimestamp($fileId);
			$tsSource = 'approval_activity';
			if ($ts === null) {
				$ts = $node->getMTime();
				$tsSource = 'file mtime';
			}

			$sub = match ($subfolderMode) {
				'month' => date('Y-m', $ts),
				'year' => date('Y', $ts),
				default => null,
			};
			$targetPath = $sub !== null ? "$archiveFolder/$sub" : $archiveFolder;

			$output->write("  $name → /$targetPath  (" . date('Y-m-d', $ts) . " via $tsSource) ... ");

			if ($dryRun) {
				$output->writeln('would move');
			} else {
				try {
					$archiveDir = $this->getOrCreateArchiveDir($userFolder, $archiveFolder, $sub, $archiveDirs);
					$targetName = $this->uniqueName($archiveDir, $name);
					$node->move($archiveDir->getPath() . '/' . $targetName);
					$output->writeln("moved" . ($targetName !== $name ? " (as $targetName)" : ''));
					$moved++;
				} catch (\Throwable $e) {
					$output->writeln('<error>FAILED: ' . $e->getMessage() . '</error>');
				}
			}
		}

		$output->writeln('');
		$output->writeln($dryRun ? 'Dry run complete.' : "Done. $moved file(s) archived.");
		return 0;
	}

	/**
	 * Look up the approval timestamp from the approval app's activity table.
	 * Returns the unix timestamp of the most recent approved (state=2) activity
	 * row for this file, or null if not found.
	 */
	private function findApprovalTimestamp(int $fileId): ?int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('datetime')
				->from('approval_activity')
				->where($qb->expr()->eq('object_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('new_state', $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT)))
				->orderBy('datetime', 'DESC')
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			if ($row !== false && isset($row['datetime'])) {
				// datetime column is a DATETIME string in approval's schema
				$dt = new \DateTimeImmutable((string)$row['datetime']);
				return $dt->getTimestamp();
			}
		} catch (\Throwable) {
			// approval_activity table may not exist or have different schema
		}
		return null;
	}

	private function getOrCreateArchiveDir(Folder $userFolder, string $archiveFolder, ?string $sub, array &$cache): Folder {
		$key = $sub ?? '__root__';
		if (isset($cache[$key])) {
			return $cache[$key];
		}

		$segments = [$archiveFolder];
		if ($sub !== null) {
			$segments[] = $sub;
		}

		$dir = $userFolder;
		foreach ($segments as $segment) {
			if ($dir->nodeExists($segment)) {
				$next = $dir->get($segment);
				if (!$next instanceof Folder) {
					throw new \RuntimeException("\"$segment\" exists but is not a folder");
				}
				$dir = $next;
			} else {
				$dir = $dir->newFolder($segment);
			}
		}

		$cache[$key] = $dir;
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
