<?php


namespace App\Controller;

use App\Entity\AttachmentFilesInterface;
use App\Entity\Files;
use App\Entity\Product;
use App\Repository\FilesRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

class AttachmentFileController extends AbstractController
{
    public function __construct(
        private Environment $twig,
        private FilesRepository $filesRepository,
        private ProductRepository $productRepository
    )
    {
    }

    #[Route(path: '/admin/api/attachment_files/template', options: ["expose" => true])]
    public function getAttachmentFilesTemplateAction(Request $request)
    {
        return $this->json(['template' => $this->twig->render('partial/_attachment_files.html.twig', [])]);
    }

    #[Route(path: '/admin/api/attachment_file', options: ["expose" => true], methods: ['POST'])]
    public function postAttachmentFileAction(Request $request)
    {
        /** @var UploadedFile[] $files */
        $files = $request->files->get('files');

        if ($request->get('id')) {
            $repo = $this->getModelRepo($request);
            if (!$repo) {
                throw new \Exception('entity was not matched');
            }
            /** @var AttachmentFilesInterface $parentEntity */
            $parentEntity = $repo->findOneBy(['id' => $request->get('id')]);
            if (!$parentEntity) {
                throw new \Exception('entity not found');
            }
        }

        $response = [];
        foreach ($files as $file) {
            if ('blob' === $file->getClientOriginalName()) {
                continue;
            }
            $entityFile = new Files();
            $entityFile
                ->setPath($file);
            if ($request->get('id')) {
                $check = $parentEntity->checkFileExist($file->getClientOriginalName());
                if ($check) {
                    throw new \Exception('exist file');
                }
            }

            if ($request->get('caption')) {
                $entityFile
                    ->setDescription($request->get('caption'));
            }

            $this->filesRepository->save($entityFile);
            $response[] = $entityFile;
        }

        return $this->json($response);
    }

    #[Route(path: '/admin/api/attachment_files/list', options: ["expose" => true])]
    public function getAttachmentFilesListAction(Request $request)
    {
        $repo = $this->getModelRepo($request);
        if (!$repo) {
            throw new \Exception('entity was not matched');
        }
        /** @var AttachmentFilesInterface $parentEntity */
        $parentEntity = $repo->findOneBy(['id' => $request->get('id')]);
        if (!$parentEntity) {
            throw new \Exception('entity not found');
        }

        return $this->json($parentEntity->getFiles()->getValues());
    }

    #[Route(path: '/admin/api/attachment_file/{id}', options: ["expose" => true], methods: ['DELETE'])]
    public function deleteAttachmentFileAction(Files $file)
    {
        $this->filesRepository->remove($file);

        return $this->json(['status' => 'success']);
    }

    /**
     * @param Request $request
     * @return ProductRepository|bool
     */
    private function getModelRepo(Request $request): ProductRepository|bool
    {
        switch ($request->get('entity')) {
            case Product::class:
                $repo = $this->filesRepository;
                break;
            default:
                $repo = false;
        }

        return $repo;
    }
}