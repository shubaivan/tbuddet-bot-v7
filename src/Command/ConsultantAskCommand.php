<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SupportChat\ChatAssistantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Поставити консультанту питання з консолі (перевірка промпту/каталогу після змін).
 * Нічого не шле в Telegram і не створює діалогів. Повертає 1, якщо у відповіді
 * згадано стороннього виробника.
 */
#[AsCommand(name: 'app:consultant:ask', description: 'Ask the AI consultant a question from the console (no Telegram, no dialog)')]
final class ConsultantAskCommand extends Command
{
    public function __construct(private readonly ChatAssistantService $assistant)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('question', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Питання (кілька — окремими аргументами, кожне в новому діалозі)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bad = false;
        foreach ((array) $input->getArgument('question') as $q) {
            $r = $this->assistant->reply([['role' => 'user', 'content' => (string) $q]], ['name' => 'Тест', 'phone' => '+380000000000']);
            $output->writeln('== '.$q);
            $output->writeln($r['reply']);
            $output->writeln(\sprintf('[operator=%s in=%d out=%d]', $r['suggestOperator'] ? 'yes' : 'no', $r['usage']['input'], $r['usage']['output']));
            $output->writeln('');
            $bad = $bad || 1 === preg_match('/буддетал|zavodbuddetal/iu', $r['reply']);
        }

        return $bad ? Command::FAILURE : Command::SUCCESS;
    }
}
