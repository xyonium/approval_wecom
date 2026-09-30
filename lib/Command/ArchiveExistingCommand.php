<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Command;

use OCA\ApprovalWeCom\Service\SettingsService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
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
		$subfolder = $this->settings->getArchiveSubfolder();
		$archivePath = $archiveFolder;
		$sub = match ($subfolder) {
			'month' => date('Y-m'),
			'year' => date('Y'),
			default => null,
		};
		if ($sub !== null) {
			$archivePath .= '/' . $sub;
		}

		$output->writeln("User:           $userId");
		$output->writeln("Tag filter:     *$tagFilter*");
		$output->writeln("Archive target: /$archivePath");
		$output->writeln($dryRun ? "Mode:           DRY RUN" : "Mode:           LIVE");
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
			// Skip the archive folder itself
			if ($node->getName() === $archiveFolder) {
				continue;
			}
			// Must be a shared mount (received share)
			if (!$node->isShared()) {
				continue;
			}
			// Check if this file has any of the matching tags
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

		// Ensure archive dir exists
		$archiveDir = null;
		if (!$dryRun) {
			$archiveDir = $userFolder;
			$segments = [$archiveFolder];
			if ($sub !== null) {
				$segments[] = $sub;
			}
			foreach ($segments as $segment) {
				if ($archiveDir->nodeExists($segment)) {
					$next = $archiveDir->get($segment);
					if (!$next instanceof Folder) {
						$output->writeln("<error>\"$segment\" exists but is not a folder.</error>");
						return 1;
					}
					$archiveDir = $next;
				} else {
					$archiveDir = $archiveDir->newFolder($segment);
				}
			}
		}

		$moved = 0;
		foreach ($candidates as $node) {
			$name = $node->getName();
			$output->write("  $name ... ");
			if ($dryRun) {
				$output->writeln('would move');
			} else {
				try {
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
		$output->writeln($dryRun ? 'Dry run complete.' : "Done. $moved file(s) archived to /$archivePath.");
		return 0;
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
