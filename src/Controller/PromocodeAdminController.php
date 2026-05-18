<?php

namespace App\Controller;

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Promocode;
use App\Entity\TelegramUser;
use App\Entity\User;
use App\Repository\PromocodeRedemptionRepository;
use App\Repository\PromocodeRepository;
use App\Repository\TelegramUserRepository;
use App\Repository\UserRepository;
use App\Service\Promocode\PromocodeCodeGenerator;
use App\Service\Promocode\PromocodeDeliveryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin CRUD for promocodes. Mirrors the simple server-rendered table + form
 * pattern used by /admin/categories rather than the DataTable JS used by
 * /admin/products — DataTable is overkill for a few-row resource.
 */
#[IsGranted('ROLE_MANAGER')]
class PromocodeAdminController extends AbstractController
{
    #[Route('/admin/promocodes/help', name: 'app_admin_promocodes_help', methods: ['GET'])]
    public function help(): Response
    {
        return $this->render('admin/promocodes/help.html.twig');
    }

    #[Route('/admin/promocodes', name: 'app_admin_promocodes', methods: ['GET'])]
    public function index(PromocodeRepository $repository): Response
    {
        $promocodes = $repository->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/promocodes/index.html.twig', [
            'promocodes' => $promocodes,
        ]);
    }

    #[Route('/admin/promocodes/new', name: 'app_admin_promocodes_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        TelegramUserRepository $telegramUserRepository,
        PromocodeCodeGenerator $codeGenerator,
        PromocodeDeliveryService $deliveryService,
    ): Response {
        if ($request->isMethod('POST')) {
            $promocode = new Promocode();
            $this->populateFromRequest(
                $promocode,
                $request,
                $userRepository,
                $telegramUserRepository,
                $codeGenerator,
            );

            // Stamp the admin who created the code (for audit / who-issued-it columns).
            $currentUser = $this->getUser();
            if ($currentUser instanceof User) {
                $promocode->setCreatedByAdmin($currentUser);
            }

            $em->persist($promocode);
            $em->flush();

            if ($request->request->get('send_now') === '1') {
                $deliveryService->deliver($promocode);
            }

            return $this->redirectToRoute('app_admin_promocodes');
        }

        return $this->render('admin/promocodes/form.html.twig', [
            'promocode' => null,
            'suggestedCode' => $codeGenerator->generate(),
        ]);
    }

    #[Route('/admin/promocodes/{id}/edit', name: 'app_admin_promocodes_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        PromocodeRepository $repository,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        TelegramUserRepository $telegramUserRepository,
        PromocodeCodeGenerator $codeGenerator,
    ): Response {
        $promocode = $repository->find($id);
        if ($promocode === null) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->populateFromRequest(
                $promocode,
                $request,
                $userRepository,
                $telegramUserRepository,
                $codeGenerator,
            );
            $em->flush();

            return $this->redirectToRoute('app_admin_promocodes');
        }

        return $this->render('admin/promocodes/form.html.twig', [
            'promocode' => $promocode,
            'suggestedCode' => null,
        ]);
    }

    #[Route('/admin/promocodes/{id}/toggle', name: 'app_admin_promocodes_toggle', methods: ['POST'])]
    public function toggle(int $id, PromocodeRepository $repository, EntityManagerInterface $em): RedirectResponse
    {
        $promocode = $repository->find($id);
        if ($promocode === null) {
            throw $this->createNotFoundException();
        }
        $promocode->setIsActive(!$promocode->isActive());
        $em->flush();

        return $this->redirectToRoute('app_admin_promocodes');
    }

    #[Route('/admin/promocodes/{id}/issue', name: 'app_admin_promocodes_issue', methods: ['POST'])]
    public function issue(
        int $id,
        PromocodeRepository $repository,
        PromocodeDeliveryService $deliveryService,
    ): RedirectResponse {
        $promocode = $repository->find($id);
        if ($promocode === null) {
            throw $this->createNotFoundException();
        }

        $channel = $deliveryService->deliver($promocode);
        $this->addFlash(
            $channel !== null ? 'success' : 'warning',
            $channel !== null
                ? sprintf('Промокод %s надіслано через %s', $promocode->getCode(), $channel)
                : sprintf('Не вдалося надіслати %s — у клієнта немає Telegram/email', $promocode->getCode()),
        );

        return $this->redirectToRoute('app_admin_promocodes');
    }

    #[Route('/admin/promocodes/{id}/redemptions', name: 'app_admin_promocodes_redemptions', methods: ['GET'])]
    public function redemptions(
        int $id,
        PromocodeRepository $repository,
        PromocodeRedemptionRepository $redemptionRepository,
    ): Response {
        $promocode = $repository->find($id);
        if ($promocode === null) {
            throw $this->createNotFoundException();
        }

        $redemptions = $redemptionRepository->findBy(
            ['promocode' => $promocode],
            ['redeemedAt' => 'DESC'],
        );

        return $this->render('admin/promocodes/redemptions.html.twig', [
            'promocode' => $promocode,
            'redemptions' => $redemptions,
        ]);
    }

    /**
     * Autocomplete for the assignee picker — returns top 20 users matching $q
     * across both User (web client) and TelegramUser (bot) tables.
     */
    #[Route('/admin/promocodes/search-users', name: 'app_admin_promocodes_search_users', methods: ['GET'], options: ['expose' => true])]
    public function searchUsers(
        Request $request,
        UserRepository $userRepository,
        TelegramUserRepository $telegramUserRepository,
    ): JsonResponse {
        $q = trim((string) $request->query->get('q', ''));
        $results = [];

        if ($q !== '') {
            $like = '%' . strtolower($q) . '%';

            $users = $userRepository->createQueryBuilder('u')
                ->where('LOWER(u.email) LIKE :q')
                ->orWhere('LOWER(u.firstName) LIKE :q')
                ->orWhere('LOWER(u.lastName) LIKE :q')
                ->orWhere('LOWER(u.phone) LIKE :q')
                ->setParameter('q', $like)
                ->setMaxResults(10)
                ->getQuery()
                ->getResult();
            foreach ($users as $u) {
                /** @var User $u */
                $results[] = [
                    'kind' => 'user',
                    'id' => $u->getId(),
                    'label' => sprintf(
                        '%s %s — %s',
                        $u->getFirstName() ?? '',
                        $u->getLastName() ?? '',
                        $u->getEmail() ?? $u->getPhone() ?? '—',
                    ),
                ];
            }

            $tgUsers = $telegramUserRepository->createQueryBuilder('t')
                ->where('LOWER(t.first_name) LIKE :q')
                ->orWhere('LOWER(t.last_name) LIKE :q')
                ->orWhere('LOWER(t.username) LIKE :q')
                ->orWhere('LOWER(t.phone_number) LIKE :q')
                ->setParameter('q', $like)
                ->setMaxResults(10)
                ->getQuery()
                ->getResult();
            foreach ($tgUsers as $t) {
                /** @var TelegramUser $t */
                $results[] = [
                    'kind' => 'telegram',
                    'id' => $t->getId(),
                    'label' => sprintf(
                        'TG @%s %s — %s',
                        $t->getUsername() ?? '?',
                        trim(($t->getFirstName() ?? '') . ' ' . ($t->getLastName() ?? '')),
                        $t->getPhoneNumber() ?? '—',
                    ),
                ];
            }
        }

        return $this->json(['results' => $results]);
    }

    private function populateFromRequest(
        Promocode $promocode,
        Request $request,
        UserRepository $userRepository,
        TelegramUserRepository $telegramUserRepository,
        PromocodeCodeGenerator $codeGenerator,
    ): void {
        $code = trim((string) $request->request->get('code', ''));
        if ($code === '') {
            $code = $codeGenerator->generate();
        }
        $promocode->setCode($code);

        $type = DiscountTypeEnum::from((string) $request->request->get('discount_type', DiscountTypeEnum::PERCENT->value));
        $promocode->setDiscountType($type);
        $promocode->setValue((int) $request->request->get('value', 0));

        $promocode->setCurrency(CurrencyEnum::from((string) $request->request->get('currency', CurrencyEnum::UAH->value)));

        $validFrom = $request->request->get('valid_from');
        $promocode->setValidFrom($validFrom ? new \DateTime($validFrom) : null);
        $validTo = $request->request->get('valid_to');
        $promocode->setValidTo($validTo ? new \DateTime($validTo) : null);

        $maxUses = $request->request->get('max_uses');
        $promocode->setMaxUses($maxUses === '' || $maxUses === null ? null : (int) $maxUses);

        $maxUsesPerUser = $request->request->get('max_uses_per_user');
        $promocode->setMaxUsesPerUser(
            $maxUsesPerUser === '' || $maxUsesPerUser === null ? null : (int) $maxUsesPerUser,
        );

        $minOrderAmount = $request->request->get('min_order_amount');
        $promocode->setMinOrderAmount(
            $minOrderAmount === '' || $minOrderAmount === null ? null : (int) $minOrderAmount,
        );

        $promocode->setIsActive((bool) $request->request->get('is_active', false));

        // Assignment: at most one of (assigned_user_id, assigned_telegram_user_id) — UI enforces this.
        $assignedKind = $request->request->get('assigned_kind'); // 'user' | 'telegram' | ''
        $assignedId = $request->request->get('assigned_id');
        $promocode->setAssignedUser(null);
        $promocode->setAssignedTelegramUser(null);
        if ($assignedKind === 'user' && $assignedId) {
            $promocode->setAssignedUser($userRepository->find((int) $assignedId));
        } elseif ($assignedKind === 'telegram' && $assignedId) {
            $promocode->setAssignedTelegramUser($telegramUserRepository->find((int) $assignedId));
        }
    }
}
