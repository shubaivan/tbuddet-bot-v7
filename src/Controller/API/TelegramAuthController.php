<?php

namespace App\Controller\API;

use App\Authenticator\Exception\AuthenticationException;
use App\Authenticator\TelegramLoginValidator;
use App\Entity\Enum\RoleEnum;
use App\Entity\Role;
use App\Entity\TelegramUser;
use App\Entity\User;
use App\Entity\UserMerge;
use App\Entity\UserRole;
use App\Repository\RoleRepository;
use App\Repository\TelegramUserRepository;
use App\Repository\UserMergeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/auth')]
class TelegramAuthController extends AbstractController
{
    #[Route(path: '/telegram', name: 'api_auth_telegram', methods: [Request::METHOD_POST])]
    public function loginWithTelegram(
        Request $request,
        TelegramLoginValidator $validator,
        TelegramUserRepository $telegramUserRepository,
        UserMergeRepository $userMergeRepository,
        RoleRepository $roleRepository,
        EntityManagerInterface $em,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenManagerInterface $refreshTokenManager,
        int $jwtTtl,
        int $jwtRefreshTtl,
    ): Response {
        $payload = json_decode($request->getContent(), true) ?? [];

        // Telegram Login Widget sends numeric `id`; the validator expects strings
        // for the HMAC since serialize() does "key=value" concatenation.
        $normalized = array_map(fn($v) => is_scalar($v) ? (string) $v : $v, $payload);

        try {
            $validator->validate($normalized);
        } catch (AuthenticationException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        $telegramId = (string) $payload['id'];

        $telegramUser = $telegramUserRepository->getByTelegramId($telegramId);
        if (!$telegramUser) {
            $telegramUser = new TelegramUser();
            $telegramUser->setTelegramId($telegramId);
        }

        // Refresh display fields from the latest widget payload.
        if (!empty($payload['first_name'])) {
            $telegramUser->setFirstName($payload['first_name']);
        }
        if (!empty($payload['last_name'])) {
            $telegramUser->setLastName($payload['last_name']);
        }
        if (!empty($payload['username'])) {
            $telegramUser->setUsername($payload['username']);
        }
        if (!empty($payload['auth_date'])) {
            $telegramUser->setAuthDate((int) $payload['auth_date']);
        }
        $em->persist($telegramUser);
        $em->flush();

        $merge = $userMergeRepository->getByTelegramUser($telegramUser);
        $user = $merge?->getUser();

        if (!$user) {
            $user = new User();
            $user
                ->setEmail(sprintf('tg-%s@telegram.local', $telegramId))
                ->setFirstName($payload['first_name'] ?? ($payload['username'] ?? 'Telegram user'))
                ->setLastName($payload['last_name'] ?? null)
                ->setIsEmailConfirmed(false);

            $role = $roleRepository->findOneBy(['name' => RoleEnum::USER]);
            if (!$role) {
                $role = new Role();
                $em->persist($role);
            }
            $userRole = new UserRole();
            $userRole->setUser($user);
            $userRole->setRole($role);
            $user->addUserRole($userRole);

            $em->persist($user);
            $em->flush();

            $merge = new UserMerge();
            $merge->setTelegramUser($telegramUser);
            $merge->setUser($user);
            $em->persist($merge);
            $em->flush();
        }

        $token = $jwtManager->create($user);

        $refreshToken = $refreshTokenManager->create();
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setRefreshToken();
        $refreshToken->setValid((new \DateTime())->modify('+' . $jwtRefreshTtl . ' seconds'));
        $refreshTokenManager->save($refreshToken);

        return $this->json([
            'token' => $token,
            'refresh_token' => $refreshToken->getRefreshToken(),
            'token_expiration' => time() + $jwtTtl,
            'refresh_token_expiration' => time() + $jwtRefreshTtl,
        ], Response::HTTP_OK);
    }
}
