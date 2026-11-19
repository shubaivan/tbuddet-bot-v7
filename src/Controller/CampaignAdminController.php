<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Repository\CampaignRepository;
use App\Repository\PromocodeRepository;
use App\Service\Campaign\CampaignStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * QR / offline campaigns: list with the funnel, per-campaign report (daily,
 * every scan with time/IP/UA and what the visitor did next, attributed orders),
 * create/edit and an on/off switch.
 */
#[IsGranted('ROLE_MANAGER')]
class CampaignAdminController extends AbstractController
{
    public function __construct(private readonly string $frontendUrl)
    {
    }

    #[Route('/admin/campaigns', name: 'app_admin_campaigns', methods: ['GET'])]
    public function index(CampaignRepository $campaigns, CampaignStats $stats): Response
    {
        $list = $campaigns->findBy([], ['id' => 'DESC']);

        return $this->render('admin/campaigns/index.html.twig', [
            'campaigns' => $list,
            'funnel' => $stats->funnel(array_map(static fn (Campaign $c): int => (int) $c->getId(), $list)),
            'qrBase' => $this->qrBase(),
        ]);
    }

    #[Route('/admin/campaigns/new', name: 'app_admin_campaigns_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, CampaignRepository $campaigns, PromocodeRepository $promocodes): Response
    {
        return $this->form(new Campaign(), $request, $em, $campaigns, $promocodes);
    }

    #[Route('/admin/campaigns/{id}/edit', name: 'app_admin_campaigns_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $em, CampaignRepository $campaigns, PromocodeRepository $promocodes): Response
    {
        $campaign = $campaigns->find($id) ?? throw $this->createNotFoundException();

        return $this->form($campaign, $request, $em, $campaigns, $promocodes);
    }

    #[Route('/admin/campaigns/{id}', name: 'app_admin_campaigns_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request, CampaignRepository $campaigns, CampaignStats $stats): Response
    {
        $campaign = $campaigns->find($id) ?? throw $this->createNotFoundException();
        $withBots = $request->query->getBoolean('bots');

        return $this->render('admin/campaigns/show.html.twig', [
            'campaign' => $campaign,
            'funnel' => $stats->funnel([$id])[$id],
            'daily' => $stats->daily($id),
            'scans' => $stats->scans($id, $withBots),
            'orders' => $stats->orders($id),
            'withBots' => $withBots,
            'qrBase' => $this->qrBase(),
        ]);
    }

    #[Route('/admin/campaigns/{id}/toggle', name: 'app_admin_campaigns_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, CampaignRepository $campaigns, EntityManagerInterface $em): RedirectResponse
    {
        $campaign = $campaigns->find($id) ?? throw $this->createNotFoundException();
        $campaign->setIsActive(!$campaign->isActive());
        $em->flush();
        $this->addFlash('success', sprintf(
            'Кампанію «%s» %s.',
            $campaign->getSlug(),
            $campaign->isActive() ? 'увімкнено' : 'вимкнено: QR веде на головну без промокоду, скани далі записуються',
        ));

        return $this->redirectToRoute('app_admin_campaigns');
    }

    private function form(Campaign $campaign, Request $request, EntityManagerInterface $em, CampaignRepository $campaigns, PromocodeRepository $promocodes): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $slug = Campaign::normalizeSlug((string) $request->request->get('slug', ''));
            if (null === $campaign->getId()) {
                if (!Campaign::isValidSlug($slug)) {
                    $errors[] = 'Ідентифікатор: 1–32 символи, латиниця a-z, цифри, «-» або «_».';
                } elseif (null !== $campaigns->findBySlug($slug)) {
                    $errors[] = 'Кампанія з таким ідентифікатором уже є.';
                } else {
                    $campaign->setSlug($slug);
                }
            }
            $campaign->setName((string) $request->request->get('name', ''));
            $campaign->setTargetPath((string) $request->request->get('target_path', '/uk/'));
            $campaign->setAttributionDays((int) $request->request->get('attribution_days', 60));
            $campaign->setIsActive($request->request->getBoolean('is_active'));
            $promocodeId = (int) $request->request->get('promocode_id', 0);
            $campaign->setPromocode($promocodeId > 0 ? $promocodes->find($promocodeId) : null);

            if ([] === $errors) {
                $em->persist($campaign);
                $em->flush();
                $this->addFlash('success', sprintf('Збережено. QR-посилання: %s/q/%s', $this->qrBase(), $campaign->getSlug()));

                return $this->redirectToRoute('app_admin_campaigns_show', ['id' => $campaign->getId()]);
            }
        }

        return $this->render('admin/campaigns/form.html.twig', [
            'campaign' => $campaign,
            'promocodes' => $promocodes->findBy(['purpose' => \App\Entity\Enum\PromocodePurposeEnum::GENERIC], ['id' => 'DESC']),
            'errors' => $errors,
            'qrBase' => $this->qrBase(),
        ]);
    }

    private function qrBase(): string
    {
        $base = rtrim($this->frontendUrl, '/');

        return '' === $base || str_contains($base, '!ChangeMe!') ? 'https://artbeton.market' : $base;
    }
}
