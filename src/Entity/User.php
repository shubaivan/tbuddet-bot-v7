<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\Enum\RoleEnum;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\Ignore;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks()]
#[UniqueEntity(['email'])]
#[UniqueEntity(['phone'])]
#[ORM\Table(name: 'client_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use CreatedUpdatedAtAwareTrait;

    public const USER_DEFAULT_GROUP = 'user_default';
    public const USER_ME_GROUP = 'user_me';
    public const USER_PERSONAL_DATA_GROUP = 'user_personal_data';
    public const USER_OWN_REGISTRATION = 'user_own_registration';
    const GROUP_ADMIN_CREATE_USER = "group_admin_create_user";
    private static array $USER_CREATE = ['firstName', 'lastName', 'phone', 'email', 'userRoles',];
    private static array $USER_UPDATE = ['firstName', 'lastName', 'phone', 'email',];
    private static array $USER_INVITE = ['firstName', 'lastName', 'email',];

    #[Groups([self::USER_OWN_REGISTRATION, self::USER_ME_GROUP, self::USER_DEFAULT_GROUP, self::USER_PERSONAL_DATA_GROUP, UserOrder::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $id;

    #[Groups([self::USER_OWN_REGISTRATION, self::USER_ME_GROUP, self::USER_DEFAULT_GROUP, self::USER_PERSONAL_DATA_GROUP, UserOrder::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(name: 'uuid', type: 'string', length: 255, unique: true, nullable: false)]
    private string $uuid;

    #[Assert\Email]
    #[Assert\NotNull(message: 'Email is required')]
    #[Assert\NotBlank(message: 'Email should not be blank')]
    #[Groups([self::USER_OWN_REGISTRATION, self::USER_DEFAULT_GROUP, self::USER_PERSONAL_DATA_GROUP, self::USER_ME_GROUP, UserOrder::PROTECTED_ORDER_VIEW_GROUP])]
    #[ORM\Column(name: 'email', type: 'string', length: 255, unique: true, nullable: false)]
    private string $email;

    #[Assert\NotNull(message: 'First name is required')]
    #[Assert\NotBlank(message: 'First name should not be blank')]
    #[Assert\Length(min: 2, max: 255, minMessage: 'First name cannot be less than {{ limit }} characters',
        maxMessage: 'First name cannot be longer than {{ limit }} characters')]
    #[Groups([self::USER_OWN_REGISTRATION, User::USER_ME_GROUP, self::USER_DEFAULT_GROUP, self::USER_PERSONAL_DATA_GROUP])]
    #[ORM\Column(name: 'first_name', type: 'string', length: 255, nullable: false)]
    private string $firstName;

    #[Assert\NotNull(message: 'Last name is required')]
    #[Assert\NotBlank(message: 'Last name should not be blank')]
    #[Assert\Length(min: 2, max: 255, minMessage: 'Last name cannot be less than {{ limit }} characters',
        maxMessage: 'Last name cannot be longer than {{ limit }} characters')]
    #[Groups([self::USER_OWN_REGISTRATION, User::USER_ME_GROUP, self::USER_DEFAULT_GROUP, self::USER_PERSONAL_DATA_GROUP])]
    #[ORM\Column(name: 'last_name', type: 'string', length: 255, nullable: false)]
    private string $lastName;

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 12, minMessage: 'Phone cannot be less than {{ limit }} characters',
        maxMessage: 'Phone cannot be longer than {{ limit }} characters')]
    #[Assert\Regex(pattern: "/^[0-9]*$/", message: "Please use number only")]
    #[Groups([User::USER_ME_GROUP, self::USER_DEFAULT_GROUP, self::USER_PERSONAL_DATA_GROUP, self::USER_OWN_REGISTRATION])]
    #[ORM\Column(name: 'phone', type: 'string', length: 255, unique: true, nullable: true)]
    private string $phone;

    #[ORM\OneToMany(targetEntity: UserRole::class, mappedBy: 'user', cascade: ['persist'])]
    private Collection|PersistentCollection|ArrayCollection $userRoles;

    #[Ignore]
    #[ORM\Column(name: 'password', type: 'string', length: 255, nullable: false)]
    private string $password;

    #[ORM\OneToMany(targetEntity: UserOrder::class, mappedBy: 'telegram_user_id', cascade: ["persist"])]
    private Collection $orders;

    #[ORM\OneToMany(targetEntity: UserOrder::class, mappedBy: 'client_user_id', cascade: ["persist"])]
    private Collection $client_orders;

    /** One Customer has One Cart. */
    #[ORM\OneToOne(targetEntity: ShoppingCart::class, mappedBy: 'user')]
    private ShoppingCart|null $shoppingCart = null;

    /** One User has One Merge. */
    #[ORM\OneToOne(targetEntity: UserMerge::class, mappedBy: 'user')]
    private UserMerge|null $merge = null;

    public function __construct()
    {
        $this->uuid = (Uuid::v7())->jsonSerialize();
        $this->userRoles = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->client_orders = new ArrayCollection();
    }

    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function setOrders(Collection $orders): self
    {
        $this->orders = $orders;

        return $this;
    }

    public function getClientOrders(): Collection
    {
        return $this->client_orders;
    }

    public function setClientOrders(Collection $client_orders): User
    {
        $this->client_orders = $client_orders;

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): User
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    #[Groups([self::USER_DEFAULT_GROUP, self::USER_PERSONAL_DATA_GROUP,])]
    public function getRoles(): array
    {
        $data = [];
        foreach ($this->getUserRoles() as $userRole) {
            /** @var UserRole $userRole */
            $data[] = $userRole->getRole()->getName()->value;
        }

        return $data;
    }

    /**
     * @return Collection|UserRole
     */
    public function getUserRoles(): Collection|UserRole
    {
        return $this->userRoles;
    }

    public function addUserRole(UserRole $role): self
    {
        if (!$this->userRoles->contains($role)) {
            $this->userRoles->add($role);
        }

        return $this;
    }

    public function setUserRoles(Collection $collection): self
    {
        $this->userRoles = $collection;

        return $this;
    }

    public function removeUserRole(UserRole $role): self
    {
        if ($this->userRoles->contains($role)) {
            $this->userRoles->removeElement($role);
        }

        return $this;
    }

    public function hasRole(RoleEnum $roleEnum): int
    {
        return $this->userRoles->filter(function (UserRole $role) use ($roleEnum) {
            return $role->getRole()->getName() == $roleEnum;
        })->count();
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public static function getUserCreateProperties(): array
    {
        return self::$USER_CREATE;
    }

    public static function getUserUpdateProperties(): array
    {
        return self::$USER_UPDATE;
    }

    public static function getUserInviteProperties(): array
    {
        return self::$USER_INVITE;
    }

    public function getShoppingCart(): ?ShoppingCart
    {
        return $this->shoppingCart;
    }

    public function setShoppingCart(?ShoppingCart $shoppingCart): User
    {
        $this->shoppingCart = $shoppingCart;

        return $this;
    }

    public function getMerge(): ?UserMerge
    {
        return $this->merge;
    }

    public function setMerge(?UserMerge $merge): User
    {
        $this->merge = $merge;

        return $this;
    }
}
