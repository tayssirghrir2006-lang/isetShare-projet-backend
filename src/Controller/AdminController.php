<?php

namespace App\Controller;

use App\Entity\Document;
use App\Repository\DepartementRepository;
use App\Repository\DocumentRepository;
use App\Repository\IsetRepository;
use App\Repository\TypeDocumentRepository;
use App\Service\VirusTotalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
class AdminController extends AbstractController
{
    // ══════════════════════════════════════════════════════════
    //  GET /api/admin/documents
    //  Admin — Liste tous les documents (filtrables par statut)
    // ══════════════════════════════════════════════════════════
    #[Route('/documents', name: 'admin_documents_list', methods: ['GET'])]
    public function listAll(Request $request, DocumentRepository $repo): JsonResponse
    {
        $statut   = $request->query->get('statut');
        $criteria = $statut ? ['statut' => $statut] : [];
        $docs     = $repo->findBy($criteria, ['dateDepot' => 'DESC']);

        return $this->json([
            'data'  => array_map(fn(Document $d) => $this->formatDocument($d), $docs),
            'total' => count($docs),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/admin/documents/en-attente
    //  Admin — Documents en attente uniquement
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/en-attente', name: 'admin_documents_pending', methods: ['GET'])]
    public function pending(DocumentRepository $repo): JsonResponse
    {
        $docs = $repo->findBy(
            ['statut' => Document::STATUS_EN_ATTENTE],
            ['dateDepot' => 'ASC']
        );

        return $this->json([
            'data'  => array_map(fn(Document $d) => $this->formatDocument($d), $docs),
            'total' => count($docs),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/admin/documents/{id}
    //  Admin — Visualiser les détails d'un document
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}', name: 'admin_document_show', methods: ['GET'])]
    public function show(Document $document): JsonResponse
    {
        return $this->json($this->formatDocument($document));
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/admin/documents/{id}/preview
    //  Admin — Visualiser le fichier inline (PDF/DOCX)
    //  Tous statuts autorisés
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/preview', name: 'admin_document_preview', methods: ['GET'])]
    public function preview(Document $document): BinaryFileResponse|JsonResponse
    {
        $filePath = $this->getParameter('uploads_directory') . '/' . $document->getFichier();

        if (!file_exists($filePath)) {
            return $this->json(['error' => 'Fichier introuvable sur le serveur.'], 404);
        }

        $mimeType = mime_content_type($filePath);

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $mimeType);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getFichier()
        );

        return $response;
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/admin/documents/{id}/download
    //  Admin — Télécharger n'importe quel document
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/download', name: 'admin_document_download', methods: ['GET'])]
    public function download(Document $document): BinaryFileResponse|JsonResponse
    {
        $filePath = $this->getParameter('uploads_directory') . '/' . $document->getFichier();

        if (!file_exists($filePath)) {
            return $this->json(['error' => 'Fichier introuvable sur le serveur.'], 404);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getTitre() . '.' . pathinfo($document->getFichier(), PATHINFO_EXTENSION)
        );

        return $response;
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/admin/documents/{id}/virustotal
    //  Admin — Analyser un document avec VirusTotal
    //  Disponible pour : en_attente uniquement
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/virustotal', name: 'admin_document_virustotal', methods: ['GET'])]
    public function scanVirustotal(
        Document $document,
        VirusTotalService $virusTotalService,
        EntityManagerInterface $em
    ): JsonResponse {

        // ── Seulement les documents en attente sont analysés ──
        if ($document->getStatut() !== Document::STATUS_EN_ATTENTE) {
            return $this->json([
                'error' => 'Seuls les documents en attente peuvent être analysés.',
                'statut_actuel' => $document->getStatut(),
            ], 400);
        }

        $filePath = $this->getParameter('uploads_directory') . '/' . $document->getFichier();

        if (!file_exists($filePath)) {
            return $this->json(['error' => 'Fichier introuvable sur le serveur.'], 404);
        }

        // ── Envoi à VirusTotal ──
        $rapport = $virusTotalService->scanFile($filePath);

        // ── Erreur de connexion VirusTotal ──
        if (isset($rapport['error'])) {
            return $this->json([
                'error'   => $rapport['error'],
                'conseil' => 'Vérifiez votre clé API VirusTotal et votre connexion internet.',
            ], 503);
        }

        // ── Retourner le rapport complet à l'admin ──
        return $this->json([
            'document' => [
                'id'    => $document->getId(),
                'titre' => $document->getTitre(),
                'statut'=> $document->getStatut(),
            ],
            'analyse'  => $rapport,
            'conseil'  => $rapport['isSafe']
                ? 'Le fichier est propre. Vous pouvez valider le document.'
                : 'Menace détectée. Il est recommandé de refuser ce document.',
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  POST /api/admin/documents
    //  Admin — Déposer un document (validé automatiquement)
    // ══════════════════════════════════════════════════════════
    #[Route('/documents', name: 'admin_document_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        IsetRepository $isetRepo,
        DepartementRepository $depRepo,
        TypeDocumentRepository $typeRepo,
        VirusTotalService $virusTotalService
    ): JsonResponse {

        $user = $this->getUser();
        $file = $request->files->get('fichier');

        if (!$file) {
            return $this->json(['error' => 'Le fichier est obligatoire.'], 400);
        }

        $allowedMimes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return $this->json(['error' => 'Seuls les fichiers PDF et DOCX sont acceptés.'], 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->json(['error' => 'Le fichier ne doit pas dépasser 10 Mo.'], 422);
        }

        $titre          = $request->request->get('titre');
        $niveau         = $request->request->get('niveau');
        $anneeScolaire  = $request->request->get('anneeScolaire');
        $isetId         = $request->request->get('isetId');
        $departementId  = $request->request->get('departementId');
        $typeDocumentId = $request->request->get('typeDocumentId');

        if (!$titre || !$niveau || !$anneeScolaire || !$isetId || !$departementId || !$typeDocumentId) {
            return $this->json(['error' => 'Tous les champs obligatoires doivent être remplis.'], 400);
        }

        $iset = $isetRepo->find($isetId);
        $dep  = $depRepo->find($departementId);
        $type = $typeRepo->find($typeDocumentId);

        if (!$iset || !$dep || !$type) {
            return $this->json(['error' => 'ISET, département ou type de document introuvable.'], 404);
        }

        // ── Sauvegarde temporaire pour scan antivirus ──
        $newFilename = uniqid('doc_') . '.' . $file->guessExtension();
        $uploadDir   = $this->getParameter('uploads_directory');
        $file->move($uploadDir, $newFilename);
        $filePath = $uploadDir . '/' . $newFilename;

        // ── Scan VirusTotal avant validation ──
        $rapport = $virusTotalService->scanFile($filePath);

        if (isset($rapport['error'])) {
            // Supprimer le fichier uploadé si le scan échoue
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return $this->json([
                'error'   => 'Scan antivirus échoué : ' . $rapport['error'],
                'conseil' => 'Vérifiez votre clé API VirusTotal.',
            ], 503);
        }

        // ── Fichier infecté → refus automatique ──
        if (!$rapport['isSafe']) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return $this->json([
                'error'   => 'Fichier refusé : menace détectée par VirusTotal.',
                'analyse' => $rapport,
            ], 422);
        }

        // ── Fichier propre → création du document ──
        $document = new Document();
        $document->setTitre($titre);
        $document->setDescription($request->request->get('description'));
        $document->setNiveau($niveau);
        $document->setMatiere($request->request->get('matiere'));
        $document->setAnneeScolaire($anneeScolaire);
        $document->setFichier($newFilename);
        $document->setAuteur($user);
        $document->setIset($iset);
        $document->setDepartement($dep);
        $document->setTypeDocument($type);

        // Admin → toujours validé automatiquement
        $document->setStatut(Document::STATUS_VALIDE);
        $document->setDateValidation(new \DateTimeImmutable());

        $em->persist($document);
        $em->flush();

        return $this->json([
            'message'  => 'Document déposé, scanné et validé automatiquement.',
            'id'       => $document->getId(),
            'statut'   => $document->getStatut(),
            'antivirus'=> [
                'statut'  => $rapport['statut'],
                'message' => $rapport['message'],
                'resume'  => $rapport['resume'],
            ],
        ], 201);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/admin/mes-documents
    //  Admin — Documents déposés par cet admin
    // ══════════════════════════════════════════════════════════
    #[Route('/mes-documents', name: 'admin_mes_documents', methods: ['GET'])]
    public function myDocuments(DocumentRepository $repo): JsonResponse
    {
        $docs = $repo->findBy(
            ['auteur' => $this->getUser()],
            ['dateDepot' => 'DESC']
        );

        return $this->json([
            'data'  => array_map(fn(Document $d) => $this->formatDocument($d), $docs),
            'total' => count($docs),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  PATCH /api/admin/documents/{id}/valider
    //  Transitions : en_attente → valide | archive → valide
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/valider', name: 'admin_document_valider', methods: ['PATCH'])]
    public function valider(Document $document, EntityManagerInterface $em): JsonResponse
    {
        if (!in_array($document->getStatut(), [
            Document::STATUS_EN_ATTENTE,
            Document::STATUS_ARCHIVE,
        ])) {
            return $this->json([
                'error'         => 'Seul un document en attente ou archivé peut être validé.',
                'statut_actuel' => $document->getStatut(),
            ], 400);
        }

        $document->setStatut(Document::STATUS_VALIDE);
        $document->setDateValidation(new \DateTimeImmutable());
        $em->flush();

        return $this->json([
            'message' => 'Document validé avec succès.',
            'statut'  => $document->getStatut(),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  PATCH /api/admin/documents/{id}/refuser
    //  Transitions : en_attente → refuse
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/refuser', name: 'admin_document_refuser', methods: ['PATCH'])]
    public function refuser(Document $document, EntityManagerInterface $em): JsonResponse
    {
        if ($document->getStatut() !== Document::STATUS_EN_ATTENTE) {
            return $this->json([
                'error'         => 'Seul un document en attente peut être refusé.',
                'statut_actuel' => $document->getStatut(),
            ], 400);
        }

        $document->setStatut(Document::STATUS_REFUSE);
        $em->flush();

        return $this->json([
            'message' => 'Document refusé.',
            'statut'  => $document->getStatut(),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  PATCH /api/admin/documents/{id}/archiver
    //  Transitions : valide → archive | refuse → archive
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/archiver', name: 'admin_document_archiver', methods: ['PATCH'])]
    public function archiver(Document $document, EntityManagerInterface $em): JsonResponse
    {
        if (!in_array($document->getStatut(), [
            Document::STATUS_VALIDE,
            Document::STATUS_REFUSE,
        ])) {
            return $this->json([
                'error'         => 'Seul un document validé ou refusé peut être archivé.',
                'statut_actuel' => $document->getStatut(),
            ], 400);
        }

        $document->setStatut(Document::STATUS_ARCHIVE);
        $em->flush();

        return $this->json([
            'message' => 'Document archivé.',
            'statut'  => $document->getStatut(),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  DELETE /api/admin/documents/{id}
    //  Autorisé : valide, refuse, archive (pas en_attente)
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}', name: 'admin_document_supprimer', methods: ['DELETE'])]
    public function supprimer(Document $document, EntityManagerInterface $em): JsonResponse
    {
        if ($document->getStatut() === Document::STATUS_EN_ATTENTE) {
            return $this->json([
                'error' => 'Un document en attente doit d\'abord être validé, refusé ou archivé avant suppression.',
            ], 400);
        }

        $filePath = $this->getParameter('uploads_directory') . '/' . $document->getFichier();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $em->remove($document);
        $em->flush();

        return $this->json(['message' => 'Document supprimé définitivement.']);
    }

    // ══════════════════════════════════════════════════════════
    //  Méthode privée — Formater un document
    // ══════════════════════════════════════════════════════════
    private function formatDocument(Document $d): array
    {
        return [
            'id'            => $d->getId(),
            'titre'         => $d->getTitre(),
            'description'   => $d->getDescription(),
            'niveau'        => $d->getNiveau(),
            'matiere'       => $d->getMatiere(),
            'anneeScolaire' => $d->getAnneeScolaire(),
            'statut'        => $d->getStatut(),
            'fichierUrl'    => '/uploads/documents/' . $d->getFichier(),
            'dateDepot'     => $d->getDateDepot()?->format('Y-m-d H:i:s'),
            'dateValidation'=> $d->getDateValidation()?->format('Y-m-d H:i:s'),
            'auteur'        => [
                'id'     => $d->getAuteur()->getId(),
                'nom'    => $d->getAuteur()->getNom(),
                'prenom' => $d->getAuteur()->getPrenom(),
                'email'  => $d->getAuteur()->getEmail(),
            ],
            'iset'          => [
                'id'  => $d->getIset()->getId(),
                'nom' => $d->getIset()->getNom(),
            ],
            'departement'   => [
                'id'  => $d->getDepartement()->getId(),
                'nom' => $d->getDepartement()->getNom(),
            ],
            'typeDocument'  => [
                'id'      => $d->getTypeDocument()->getId(),
                'libelle' => $d->getTypeDocument()->getLibelle(),
            ],
        ];
    }
}