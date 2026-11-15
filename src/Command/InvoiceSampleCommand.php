<?php

declare(strict_types=1);

namespace App\Command;

use App\Invoice\InvoiceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:invoice:sample',
    description: 'Check seller requisites and write a «ЗРАЗОК» invoice PDF with dummy data',
)]
class InvoiceSampleCommand extends Command
{
    public function __construct(private readonly InvoiceService $invoiceService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('output', InputArgument::OPTIONAL, 'Where to write the sample PDF', 'var/invoice-sample.pdf');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $problems = $this->invoiceService->requisitesProblems();
        if ($problems === []) {
            $io->success('Реквізити продавця заповнені — оплата за рахунком доступна на checkout.');
        } else {
            $io->warning(array_merge(['Оплата за рахунком ВИМКНЕНА, бо:'], $problems));
        }

        $path = (string) $input->getArgument('output');
        file_put_contents($path, $this->invoiceService->renderSamplePdf());
        $io->writeln(sprintf('Зразок PDF (тестові дані, позначено «ЗРАЗОК»): %s', $path));

        return Command::SUCCESS;
    }
}
