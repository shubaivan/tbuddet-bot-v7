<?php

namespace App\Controller;

use App\Controller\Request\User\Create\ConfirmEmailDto;
use App\Controller\Request\User\Create\ForgotPasswordDto;
use App\Controller\Request\User\Create\RegistrationUserDto;
use App\Controller\Request\User\Create\ResetPasswordDto;
use App\Entity\Enum\RoleEnum;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use App\Exception\Enum\AuthExceptionEnum;
use App\Exception\RegistrationException;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\ObjectHandler;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(path: 'api/v1/user')]
class SecurityController extends AbstractController
{
    #[Route(path: '/registration', name: 'user_registration', methods: [Request::METHOD_POST])]
    public function registration(
        ObjectHandler $objectHandler,
        Request $request,
        ValidatorInterface $validator,
        RoleRepository $roleRepository,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ): Response {
        /** @var RegistrationUserDto $registrationUserDto */
        $registrationUserDto = $objectHandler->handleRequestByObject(
            AuthExceptionEnum::REGISTRATION_EXCEPTION,
            RegistrationUserDto::class,
            $request
        );

        $user = new User();

        $role = $roleRepository->findOneBy(['name' => RoleEnum::USER]);
        if (!$role) {
            $role = new Role();
            $entityManager->persist($role);
        }
        $userRole = new UserRole();
        $userRole->setUser($user);
        $userRole->setRole($role);

        $user->addUserRole($userRole);

        $entityManager->persist($user);

        $user
            ->setEmail($registrationUserDto->getEmail())
            ->setFirstName($registrationUserDto->getFirstName())
            ->setLastName($registrationUserDto->getLastName())
            ->setPhone($registrationUserDto->getPhone())
            ->setIsEmailConfirmed(false)
        ;

        $errors = $validator->validate($user, null, [Constraint::DEFAULT_GROUP]);

        if (count($errors) > 0) {
            throw new RegistrationException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                implode("\n", array_map(
                    static fn($e) => $e->getMessage(),
                    iterator_to_array($errors))),
                new ValidationFailedException($user, $errors)
            );
        }

        $emailService->sendConfirmationEmail($user);

        $entityManager->flush();

        return $this->json([
            'message' => [
                'message' => 'Registration successful. Please check your email to confirm your account.'
            ],
            'entity' => $user
        ], Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [
                User::USER_OWN_REGISTRATION
            ]
        ]);
    }

    #[Route(path: '/confirm-email', name: 'user_confirm_email', methods: [Request::METHOD_POST])]
    public function confirmEmail(
        ObjectHandler $objectHandler,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenManagerInterface $refreshTokenManager,
        int $jwtTtl,
        int $jwtRefreshTtl,
    ): Response {
        /** @var ConfirmEmailDto $confirmEmailDto */
        $confirmEmailDto = $objectHandler->handleRequestByObject(
            AuthExceptionEnum::REGISTRATION_EXCEPTION,
            ConfirmEmailDto::class,
            $request
        );

        $user = $userRepository->findOneBy(['confirmationToken' => $confirmEmailDto->getToken()]);

        if (!$user) {
            throw new RegistrationException(
                Response::HTTP_BAD_REQUEST,
                'Invalid or expired confirmation token'
            );
        }

        if ($user->getConfirmationTokenExpiresAt() < new \DateTimeImmutable()) {
            throw new RegistrationException(
                Response::HTTP_BAD_REQUEST,
                'Confirmation token has expired. Please register again.'
            );
        }

        $password = $passwordHasher->hashPassword($user, $confirmEmailDto->getPassword());
        $user->setPassword($password);
        $user->setIsEmailConfirmed(true);
        $user->setConfirmationToken(null);
        $user->setConfirmationTokenExpiresAt(null);

        $entityManager->flush();

        // Auto-login: generate JWT tokens
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

    #[Route(path: '/forgot-password', name: 'user_forgot_password', methods: [Request::METHOD_POST])]
    public function forgotPassword(
        ObjectHandler $objectHandler,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ): Response {
        /** @var ForgotPasswordDto $dto */
        $dto = $objectHandler->handleRequestByObject(
            AuthExceptionEnum::REGISTRATION_EXCEPTION,
            ForgotPasswordDto::class,
            $request
        );

        $user = $userRepository->findOneBy(['email' => $dto->getEmail()]);

        // Always return success to prevent email enumeration
        if ($user && $user->isEmailConfirmed()) {
            $emailService->sendPasswordResetEmail($user);
            $entityManager->flush();
        }

        return $this->json([
            'message' => 'If an account with this email exists, a password reset link has been sent.'
        ], Response::HTTP_OK);
    }

    #[Route(path: '/reset-password', name: 'user_reset_password', methods: [Request::METHOD_POST])]
    public function resetPassword(
        ObjectHandler $objectHandler,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenManagerInterface $refreshTokenManager,
        int $jwtTtl,
        int $jwtRefreshTtl,
    ): Response {
        /** @var ResetPasswordDto $dto */
        $dto = $objectHandler->handleRequestByObject(
            AuthExceptionEnum::REGISTRATION_EXCEPTION,
            ResetPasswordDto::class,
            $request
        );

        $user = $userRepository->findOneBy(['resetPasswordToken' => $dto->getToken()]);

        if (!$user) {
            throw new RegistrationException(
                Response::HTTP_BAD_REQUEST,
                'Invalid or expired reset token'
            );
        }

        if ($user->getResetPasswordTokenExpiresAt() < new \DateTimeImmutable()) {
            throw new RegistrationException(
                Response::HTTP_BAD_REQUEST,
                'Reset token has expired. Please request a new one.'
            );
        }

        $password = $passwordHasher->hashPassword($user, $dto->getPassword());
        $user->setPassword($password);
        $user->setResetPasswordToken(null);
        $user->setResetPasswordTokenExpiresAt(null);

        $entityManager->flush();

        // Auto-login after password reset
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
