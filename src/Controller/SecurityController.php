<?php

namespace App\Controller;

use App\Controller\Request\User\Create\RegistrationUserDto;
use App\Entity\Enum\RoleEnum;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use App\Exception\Enum\AuthExceptionEnum;
use App\Exception\RegistrationException;
use App\Repository\RoleRepository;
use App\Service\ObjectHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        RoleRepository $roleRepository,
        EntityManagerInterface $entityManager
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

        $password = $passwordHasher->hashPassword(
            $user,
            $registrationUserDto->getPassword()
        );

        $user->setPassword($password);

        $user
            ->setEmail($registrationUserDto->getEmail())
            ->setFirstName($registrationUserDto->getFirstName())
            ->setLastName($registrationUserDto->getLastName())
            ->setPhone($registrationUserDto->getPhone())
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

        $entityManager->flush();

        return $this->json([
            'message' => [
                'message' => 'User registered'
            ],
            'entity' => $user
        ], Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [
                User::USER_OWN_REGISTRATION
            ]
        ]);
    }
}
