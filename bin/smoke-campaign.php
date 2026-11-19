<?php

// Smoke test for QR campaign tracking against the real LOCAL DB (never prod).
// Everything runs in one transaction that is rolled back at the end.
// Run with: php bin/smoke-campaign.php

require dirname(__DIR__).'/vendor/autoload_runtime.php';

use App\Entity\Campaign;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Kernel;
use App\Service\Campaign\BotDetector;
use App\Service\Campaign\CampaignStore;
use App\Service\Campaign\CampaignTracker;
use App\Service\Campaign\QrScanService;
use App\Service\Campaign\ScanContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
if (($_SERVER['APP_ENV'] ?? 'dev') === 'prod') {
    fwrite(STDERR, "refusing to run with APP_ENV=prod\n");
    exit(1);
}

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
assert($em instanceof EntityManagerInterface);
$db = $em->getConnection();

$fail = 0;
$check = static function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$what."\n";
    $fail += $ok ? 0 : 1;
};

$db->beginTransaction();
try {
    $campaign = (new Campaign())->setSlug('smoke'.random_int(1000, 9999))->setName('smoke')->setAttributionDays(60);
    $em->persist($campaign);
    $em->flush();
    $cid = (int) $campaign->getId();

    $store = new CampaignStore($db);
    $qr = new QrScanService($em->getRepository(Campaign::class), $store, new BotDetector(), new NullLogger(), 'https://artbeton.market');
    $phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) Safari/604.1';

    echo "── scan ──\n";
    $r = $qr->handle($campaign->getSlug(), new ScanContext('GET', '93.170.45.123', $phone, null, 'uk', null));
    $vid = $r->cookieVisitorId;
    $check(null !== $vid, 'visitor minted');
    $check(1 === (int) $db->fetchOne('SELECT count(*) FROM qr_scan WHERE campaign_id = ? AND visitor_id = ?', [$cid, $vid]), 'scan row with visitor');
    $qr->handle($campaign->getSlug(), new ScanContext('GET', '1.2.3.4', 'TelegramBot (like TwitterBot)', null, null, null));
    $check(1 === (int) $db->fetchOne('SELECT count(*) FROM qr_scan WHERE campaign_id = ? AND is_bot', [$cid]), 'bot row flagged');

    echo "── funnel ──\n";
    $requests = new RequestStack();
    $req = new Request();
    $req->headers->set('X-Abm-Visitor', (string) $vid);
    $requests->push($req);
    $tracker = new CampaignTracker($store, $requests, new NullLogger());

    $tracker->landed((string) $vid, $cid);
    $tracker->landed((string) $vid, $cid);
    $check(1 === (int) $db->fetchOne("SELECT count(*) FROM campaign_event WHERE visitor_id = ? AND type = 'landing'", [$vid]), 'landing recorded once');

    $user = $em->getRepository(User::class)->findOneBy([]);
    if (null === $user) {
        throw new RuntimeException('need at least one client_user locally');
    }
    $tracker->registered($user);
    $check((int) $user->getId() === (int) $db->fetchOne('SELECT user_id FROM campaign_visitor WHERE visitor_id = ?', [$vid]), 'visitor linked to account');
    $tracker->addedToCart($user);

    $order = (new UserOrder())->setTotalAmount(1000)->setClientUserId($user)->setDescription('smoke');
    $em->persist($order);
    $em->flush();
    $tracker->attributeOrder($order, $user);
    $row = $db->fetchAssociative('SELECT campaign_id, campaign_visitor_id, campaign_source FROM user_order WHERE id = ?', [$order->getId()]);
    $check($cid === (int) $row['campaign_id'] && $vid === $row['campaign_visitor_id'] && 'qr' === $row['campaign_source'], 'order snapshotted as qr');

    // another device, no header → still attributed through the linked account
    $requests->pop();
    $requests->push(new Request());
    $order2 = (new UserOrder())->setTotalAmount(500)->setClientUserId($user)->setDescription('smoke2');
    $em->persist($order2);
    $em->flush();
    $tracker->attributeOrder($order2, $user);
    $check($cid === (int) $db->fetchOne('SELECT campaign_id FROM user_order WHERE id = ?', [$order2->getId()]), 'second order via linked account');

    $types = $db->fetchFirstColumn('SELECT type FROM campaign_event WHERE campaign_id = ? ORDER BY id', [$cid]);
    $check(['landing', 'register', 'add_to_cart', 'order', 'order'] === $types, 'events: '.implode(',', $types));

    echo "── window ──\n";
    $db->executeStatement("UPDATE campaign_visitor SET first_seen_at = now() - interval '61 days' WHERE visitor_id = ?", [$vid]);
    $check(null === $store->findAttributableVisitor((string) $vid, new DateTimeImmutable()), 'outside 60-day window → not attributed');

    echo "── retention ──\n";
    $db->executeStatement("UPDATE qr_scan SET scanned_at = now() - interval '91 days' WHERE campaign_id = ?", [$cid]);
    $n = $store->anonymizeIpsBefore(new DateTimeImmutable('-90 days'), false);
    $check($n >= 2, "anonymized $n rows");
    $check('93.170.45.0' === $db->fetchOne('SELECT ip FROM qr_scan WHERE visitor_id = ? ORDER BY id LIMIT 1', [$vid]), 'IPv4 → /24');
} finally {
    $db->rollBack();
}

echo $fail ? "\n$fail FAILED\n" : "\nall good (rolled back)\n";
exit($fail ? 1 : 0);
