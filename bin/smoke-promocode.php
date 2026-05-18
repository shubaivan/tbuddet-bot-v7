<?php

// Smoke test for the promocode feature against the real local DB.
// Exercises: create promocode → validate → redeem → assert ledger row + counter bumped + UNIQUE constraint protects against duplicate.
// Run with: php bin/smoke-promocode.php

require dirname(__DIR__) . '/vendor/autoload_runtime.php';

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Promocode;
use App\Entity\UserOrder;
use App\Kernel;
use App\Repository\PromocodeRedemptionRepository;
use App\Repository\PromocodeRepository;
use App\Repository\UserRepository;
use App\Service\Promocode\PromocodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();
$container = $kernel->getContainer();

$em = $container->get('doctrine.orm.entity_manager');
assert($em instanceof EntityManagerInterface);

$promocodeRepo = $em->getRepository(Promocode::class);
$redemptionRepo = $em->getRepository(\App\Entity\PromocodeRedemption::class);

// PromocodeService is private in DI — instantiate it manually with its
// (all-public) repository + EM dependencies. Same constructor signature.
$promocodeService = new PromocodeService($promocodeRepo, $redemptionRepo, $em);

echo "── Step 1: Find or create test user ──\n";
$userRepo = $em->getRepository(\App\Entity\User::class);
$testUser = $userRepo->findOneBy([]);
if ($testUser === null) {
    echo "  No User exists in DB — skipping user-scoped tests. Will use NULL user identity.\n";
} else {
    echo "  Using existing user id={$testUser->getId()} ({$testUser->getEmail()})\n";
}

echo "\n── Step 2: Clean up any prior test code ──\n";
$existing = $promocodeRepo->findActiveByCode('SMOKE-TEST');
if ($existing !== null) {
    $em->createQuery('DELETE FROM ' . \App\Entity\PromocodeRedemption::class . ' r WHERE r.promocode = :p')
       ->setParameter('p', $existing)->execute();
    $em->remove($existing);
    $em->flush();
    echo "  Deleted prior SMOKE-TEST code.\n";
}

echo "\n── Step 3: Create a 10% UAH test code ──\n";
$promocode = (new Promocode())
    ->setCode('SMOKE-TEST')
    ->setDiscountType(DiscountTypeEnum::PERCENT)
    ->setValue(10)
    ->setCurrency(CurrencyEnum::UAH)
    ->setMaxUses(5)
    ->setMaxUsesPerUser(1)
    ->setIsActive(true);
$em->persist($promocode);
$em->flush();
echo "  Created Promocode id={$promocode->getId()}, code=SMOKE-TEST, 10% UAH\n";

echo "\n── Step 4: validate() should succeed with 1000 UAH subtotal ──\n";
$result = $promocodeService->validate('SMOKE-TEST', 1000, CurrencyEnum::UAH, $testUser, null, null);
assert($result->ok === true, 'validate should succeed');
assert($result->discount === 100, "expected 100 discount, got {$result->discount}");
echo "  ✓ ok=true, discount=100 UAH\n";

echo "\n── Step 5: validate() should reject USD cart (currency mismatch) ──\n";
$result = $promocodeService->validate('SMOKE-TEST', 1000, CurrencyEnum::USD, $testUser, null, null);
assert($result->ok === false, 'validate should fail');
assert($result->error->value === 'currency_mismatch', "expected currency_mismatch, got {$result->error->value}");
echo "  ✓ ok=false, error=currency_mismatch\n";

echo "\n── Step 6: Create a dummy UserOrder and redeem ──\n";
$order = (new UserOrder())
    ->setSubtotalAmount(1000)
    ->setDiscountAmount(100)
    ->setTotalAmount(900)
    ->setPromocodeCodeUsed('SMOKE-TEST')
    ->setDescription('Smoke test order');
if ($testUser !== null) {
    $order->setClientUserId($testUser);
}
$em->persist($order);
$em->flush();
echo "  Created UserOrder id={$order->getId()}, subtotal=1000, discount=100, total=900\n";

$redeemed = $promocodeService->redeem($promocode, $order, $testUser, null, null, 100);
assert($redeemed === true, 'redeem should return true');
echo "  ✓ redeem returned true\n";

echo "\n── Step 7: Counter bumped to 1, ledger row present ──\n";
$em->refresh($promocode);
assert($promocode->getTimesUsed() === 1, "expected times_used=1, got {$promocode->getTimesUsed()}");
echo "  ✓ times_used = 1\n";

$redemptionRepo = $em->getRepository(\App\Entity\PromocodeRedemption::class);
$ledger = $redemptionRepo->findBy(['promocode' => $promocode]);
assert(count($ledger) === 1, "expected 1 ledger row, got " . count($ledger));
echo "  ✓ ledger has 1 row, discount_applied={$ledger[0]->getDiscountApplied()} {$ledger[0]->getCurrency()->value}\n";

echo "\n── Step 8: redeem() on SAME (promocode, order) should be idempotent (return false, no double-count) ──\n";
$redeemedAgain = $promocodeService->redeem($promocode, $order, $testUser, null, null, 100);
assert($redeemedAgain === false, 'second redeem should return false (UNIQUE caught it)');
$em->refresh($promocode);
assert($promocode->getTimesUsed() === 1, "counter should stay at 1, got {$promocode->getTimesUsed()}");
echo "  ✓ second redeem returned false; counter unchanged\n";

echo "\n── Step 9: validate() now reports USER_LIMIT_REACHED (max_uses_per_user=1) ──\n";
if ($testUser !== null) {
    $result = $promocodeService->validate('SMOKE-TEST', 1000, CurrencyEnum::UAH, $testUser, null, null);
    assert($result->ok === false, 'validate should now fail');
    assert($result->error->value === 'user_limit_reached', "expected user_limit_reached, got {$result->error->value}");
    echo "  ✓ ok=false, error=user_limit_reached\n";
} else {
    echo "  (skipped — no user available to test per-user limit)\n";
}

echo "\n── Step 10: Inactive code returns INACTIVE ──\n";
$promocode->setIsActive(false);
$em->flush();
$result = $promocodeService->validate('SMOKE-TEST', 1000, CurrencyEnum::UAH, null, null, null);
assert($result->ok === false);
assert($result->error->value === 'inactive', "expected inactive, got {$result->error->value}");
echo "  ✓ ok=false, error=inactive\n";

echo "\n── Step 11: Cleanup ──\n";
$em->createQuery('DELETE FROM ' . \App\Entity\PromocodeRedemption::class . ' r WHERE r.promocode = :p')
   ->setParameter('p', $promocode)->execute();
$em->remove($promocode);
$em->remove($order);
$em->flush();
echo "  Removed test data.\n";

echo "\n✅ All smoke checks passed.\n";
