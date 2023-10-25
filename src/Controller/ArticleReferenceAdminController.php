<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\ArticleReference;
use App\Service\UploadHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ArticleReferenceAdminController extends BaseController
{
    /**
     * @Route("/admin/article/{id}/references", name="admin_article_add_reference", methods={"POST"})
     * @IsGranted("MANAGE", subject="article")
     */
    public function uploadArticleReference(Article $article, Request $request, UploadHelper $uploaderHelper, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        /**@var UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('reference'); // name of the input in the form

        $violations = $validator->validate(
            $uploadedFile,
            [
                new NotBlank([
                    'message' => 'Please select a file to upload'
                ]),

                new File([
                    'maxSize' => '5M',
                    'mimeTypes' => [
                        'image/*',
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.openxmlformars-officedocument.spreadsheetml.sheet',
                        'application/vnd.openxmlformars-officedocument.presentationml.presentation',
                        'text/plain',
                        'application/vnd.ms-excel',
                    ]
                ])
            ]
        );

        if ($violations->count() > 0) {
            /** @var ConstraintVioldation $violation */
            $violation = $violations[0];
            $this->addFlash('error', $violation->getMessage());
        }


        $filename = $uploaderHelper->uploadArticleReference($uploadedFile);

        $articleReference = new ArticleReference($article);
        $articleReference->setFilename($filename);
        $articleReference->setOriginalFilename($uploadedFile->getClientOriginalName() ?? $filename);
        $articleReference->setMimeType($uploadedFile->getMimeType() ?? 'application/octet-stream');

        $entityManager->persist($articleReference);
        $entityManager->flush();

        return $this->redirectToRoute('admin_article_edit', [
            'id' => $article->getId()
        ]);
    }

    /**
     * @Route("/admin/article/references/{id}/download", name="admin_article_download_reference", methods={"GET"})
     */
    public function downloadArticleReference(ArticleReference $reference, UploadHelper $uploaderHelper): Response
    {
        $article = $reference->getArticle();

        $this->denyAccessUnlessGranted('MANAGE', $article);

        $response = new StreamedResponse(function () use ($reference, $uploaderHelper) { // Создается потоковый ответ для передачи файла пользователю
            $outputStream = fopen('php://output', 'wb'); // Открывается поток на вывод, где 'wb' означает запись в двоичном формате

            $fileStream = $uploaderHelper->readStream($reference->getFilePath(), false);  // Читается поток файла. Параметр false указывает, что файл не должен быть заблокирован при чтении

            stream_copy_to_stream($fileStream, $outputStream); // Копируется поток файла в поток вывода
        });

        $response->headers->set('Content-Type', $reference->getMimeType()); // Устанавливается заголовок 'Content-Type' с MIME-типом файла

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $reference->getOriginalFilename()
        ); // Создается заголовок 'Content-Disposition' для указания браузеру открыть диалоговое окно сохранения файла с оригинальным именем файла

        $response->headers->set('Content-Disposition', $disposition); // Устанавливается заголовок 'Content-Disposition'

        return $response;
    }
}
