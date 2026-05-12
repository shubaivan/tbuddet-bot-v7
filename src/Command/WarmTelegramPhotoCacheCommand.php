<?php

namespace App\Command;

use App\Entity\Files;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pre-uploads every Files row to Telegram once so the bot never has to
 * upload-on-first-click. Sends each photo to a warmup chat, captures the
 * file_id Telegram returns, persists it, then deletes the warmup message.
 *
 * After this runs every Files row has telegram_file_id populated, so all
 * subsequent bot renders use the cached id (instant, no upload).
 */
#[AsCommand(
    name: 'app:telegram:warm-photo-cache',
    description: 'Pre-populate Telegram file_id for every Files row by uploading once to a warmup chat.',
)]
class WarmTelegramPhotoCacheCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('chat_id', InputArgument::REQUIRED, 'Telegram chat id to send warmup uploads to (your own user id works).')
            ->addOption('keep-messages', null, InputOption::VALUE_NONE, 'Skip deleting the warmup messages after capturing file_id.')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Cap how many rows to process this run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chatId = (int) $input->getArgument('chat_id');
        $keep = (bool) $input->getOption('keep-messages');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;

        $qb = $this->em->createQueryBuilder()
            ->select('f')
            ->from(Files::class, 'f')
            ->where('f.telegramFileId IS NULL')
            ->orderBy('f.id', 'ASC');
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        /** @var Files[] $rows */
        $rows = $qb->getQuery()->getResult();
        $io->writeln(sprintf('Warming %d Files rows.', count($rows)));

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $rel = $row->getPath();
            $disk = $this->projectDir . '/public/assets/default/' . $rel;
            if (!is_file($disk) || !is_readable($disk)) {
                $io->writeln(sprintf(' - skip #%d %s (disk file missing)', $row->getId(), $rel));
                $skipped++;
                continue;
            }

            try {
                $msg = $this->bot->sendPhoto(
                    chat_id: $chatId,
                    photo: InputFile::make($disk),
                    caption: 'warmup #' . $row->getId(),
                );
            } catch (\Throwable $e) {
                $io->writeln(sprintf(' - fail #%d %s: %s', $row->getId(), $rel, $e->getMessage()));
                $failed++;
                continue;
            }

            $sizes = $msg->photo ?? [];
            $largest = $sizes ? end($sizes) : null;
            $fileId = $largest->file_id ?? null;
            if (!$fileId) {
                $io->writeln(sprintf(' - fail #%d %s: no file_id in response', $row->getId(), $rel));
                $failed++;
                continue;
            }

            $row->setTelegramFileId($fileId);
            $this->em->flush();
            $ok++;

            if (!$keep && isset($msg->message_id)) {
                try {
                    $this->bot->deleteMessage($chatId, $msg->message_id);
                } catch (\Throwable $e) {
                    // best-effort cleanup
                }
            }
        }

        $io->success(sprintf('Done: cached=%d, skipped=%d, failed=%d', $ok, $skipped, $failed));

        return Command::SUCCESS;
    }
}
