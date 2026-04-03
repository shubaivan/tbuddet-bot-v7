<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GuideController extends AbstractController
{
    #[Route('/guide', name: 'app_guide', methods: ['GET'])]
    public function index(): Response
    {
        $guidePath = $this->getParameter('kernel.project_dir') . '/docs/guide/ADMIN_GUIDE_UA.md';
        $markdown = file_get_contents($guidePath);

        return $this->render('guide/index.html.twig', [
            'markdown' => $markdown,
        ]);
    }
}
