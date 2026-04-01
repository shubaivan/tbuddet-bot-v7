<?php

namespace App\Command;

use App\Entity\Enum\RoleEnum;
use App\Repository\RoleRepository;
use App\Repository\TelegramUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:assign-role',
    description: 'Assign a role to a Telegram user by phone number',
)]
class AssignRoleCommand extends Command
{
    public function __construct(
        private TelegramUserRepository $telegramUserRepository,
        private RoleRepository $roleRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Phone number (e.g. 380937542639)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role name (e.g. ROLE_MANAGER)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $phone = $input->getOption('phone');
        $roleName = $input->getOption('role');

        if (!$phone || !$roleName) {
            $io->error('Both --phone and --role are required.');
            return Command::FAILURE;
        }

        $roleEnum = RoleEnum::tryFrom($roleName);
        if (!$roleEnum) {
            $io->error(sprintf('Invalid role: %s. Available: %s', $roleName,
                implode(', ', array_map(fn($r) => $r->value, RoleEnum::cases()))));
            return Command::FAILURE;
        }

        $user = $this->telegramUserRepository->findOneBy(['phone_number' => $phone]);
        if (!$user) {
            $io->error(sprintf('No Telegram user found with phone: %s', $phone));
            $io->note('The user must start the Telegram bot first to be registered.');
            return Command::FAILURE;
        }

        $role = $this->roleRepository->getRoleByName($roleEnum);
        if (!$role) {
            $io->error(sprintf('Role %s not found in database.', $roleName));
            return Command::FAILURE;
        }

        $existingRoles = $user->getUserRoles()->map(fn($r) => $r->getName()->value)->toArray();
        if (in_array($roleName, $existingRoles, true)) {
            $io->warning(sprintf('User %s (%s) already has role %s',
                $user->getFirstName(), $phone, $roleName));
            return Command::SUCCESS;
        }

        $user->addUsersRole($role);
        $this->em->flush();

        $io->success(sprintf('Assigned %s to %s %s (%s)',
            $roleName, $user->getFirstName(), $user->getLastName() ?? '', $phone));

        return Command::SUCCESS;
    }
}
