<?php

namespace App\Controller;

use App\Entity\Document;
use App\Repository\DepartementRepository;
use App\Repository\DocumentRepository;
use App\Repository\IsetRepository;
use App\Repository\TypeDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class DocumentController extends AbstractController
{
    // ══════════════════════════════════════════════════════════
    //  GET /api/documents
    //  Protégé — Liste des documents VALIDÉS avec filtres
    //  Accessible : User + Admin
    // ══════════════════════════════════════════════════════════
    #[Route('/documents', name: 'document_list', methods: ['GET'])]
    public function list(Request $request, DocumentRepository $repo): JsonResponse
    {
        $filters = [
            'iset'          => $request->query->get('isetId'),
            'departement'   => $request->query->get('departementId'),
            'typeDocument'  => $request->query->get('typeDocumentId'),
            'niveau'        => $request->query->get('niveau'),
            'anneeScolaire' => $request->query->get('anneeScolaire'),
        ];

        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = 12;

        $documents = $repo->findValidatedWithFilters($filters, $page, $limit);
        $total     = $repo->countValidatedWithFilters($filters);

        return $this->json([
            'data'       => array_map(fn(Document $d) => $this->formatDocument($d), $documents),
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  POST /api/documents
    //  Protégé — Déposer un document
    //  - User  → statut : en_attente
    //  - Admin → statut : valide (automatiquement)
    // ══════════════════════════════════════════════════════════
    #[Route('/documents', name: 'document_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        IsetRepository $isetRepo,
        DepartementRepository $depRepo,
        TypeDocumentRepository $typeRepo
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

        $newFilename = uniqid('doc_') . '.' . $file->guessExtension();
        $file->move($this->getParameter('uploads_directory'), $newFilename);

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

        // ── Admin → validé automatiquement | User → en attente ──
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            $document->setStatut(Document::STATUS_VALIDE);
            $document->setDateValidation(new \DateTimeImmutable());
            $message = 'Document déposé et validé automatiquement.';
        } else {
            $document->setStatut(Document::STATUS_EN_ATTENTE);
            $message = 'Document soumis avec succès. En attente de validation.';
        }

        $em->persist($document);
        $em->flush();

        return $this->json([
            'message' => $message,
            'id'      => $document->getId(),
            'statut'  => $document->getStatut(),
        ], 201);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/documents/mes-documents
    //  Protégé — Documents déposés par l'utilisateur connecté
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/mes-documents', name: 'document_mine', methods: ['GET'])]
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
    //  GET /api/documents/{id}
    //  Protégé — Visualiser un document (avant téléchargement)
    //  User  → documents VALIDÉS uniquement
    //  Admin → tous les statuts (pour validation)
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}', name: 'document_show', methods: ['GET'])]
    public function show(Document $document): JsonResponse
    {
        $user = $this->getUser();

        if (
            !in_array('ROLE_ADMIN', $user->getRoles()) &&
            $document->getStatut() !== Document::STATUS_VALIDE
        ) {
            return $this->json(['error' => 'Document non disponible.'], 403);
        }

        return $this->json($this->formatDocument($document));
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/documents/{id}/download
    //  Protégé — Télécharger un document
    //  User  → documents VALIDÉS uniquement
    //  Admin → tous les documents
    // ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/download', name: 'document_download', methods: ['GET'])]
    public function download(Document $document): BinaryFileResponse|JsonResponse
    {
        $user = $this->getUser();

        if (
            !in_array('ROLE_ADMIN', $user->getRoles()) &&
            $document->getStatut() !== Document::STATUS_VALIDE
        ) {
            return $this->json(['error' => 'Ce document n\'est pas disponible au téléchargement.'], 403);
        }

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
//  GET /api/documents/{id}/preview
//  Protégé — Visualiser le fichier dans le navigateur
//  User  → documents VALIDÉS uniquement
//  Admin → tous les statuts
// ══════════════════════════════════════════════════════════
    #[Route('/documents/{id}/preview', name: 'document_preview', methods: ['GET'])]
    public function preview(Document $document): BinaryFileResponse|JsonResponse
    {
        $user = $this->getUser();

        // User : visualisation limitée aux documents validés
        if (
            !in_array('ROLE_ADMIN', $user->getRoles()) &&
            $document->getStatut() !== Document::STATUS_VALIDE
        ) {
            return $this->json(['error' => 'Document non disponible.'], 403);
        }

        $filePath = $this->getParameter('uploads_directory') . '/' . $document->getFichier();

        if (!file_exists($filePath)) {
            return $this->json(['error' => 'Fichier introuvable sur le serveur.'], 404);
        }

        // Détecter le type MIME réel du fichier
        $mimeType = mime_content_type($filePath);

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $mimeType);

        // ── INLINE = affichage dans le navigateur (pas de téléchargement forcé) ──
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getFichier()
        );

        return $response;
    }

    // ══════════════════════════════════════════════════════════
    //  Méthode privée — Formater un document en tableau
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