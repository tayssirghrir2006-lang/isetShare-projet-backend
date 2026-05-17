<?php

namespace App\Controller;

use App\Repository\DepartementRepository;
use App\Repository\IsetRepository;
use App\Repository\TypeDocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ReferentielController extends AbstractController
{
    // ══════════════════════════════════════════════════════════
    //  GET /api/isets
    //  Public — Liste de tous les ISET
    // ══════════════════════════════════════════════════════════
    #[Route('/isets', name: 'api_isets', methods: ['GET'])]
    public function isets(IsetRepository $repo): JsonResponse
    {
        $isets = $repo->findBy([], ['nom' => 'ASC']);

        return $this->json([
            'data'  => array_map(fn($i) => [
                'id'    => $i->getId(),
                'nom'   => $i->getNom(),
                'ville' => $i->getVille(),
            ], $isets),
            'total' => count($isets),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/departements
    //  Public — Liste de tous les départements
    // ══════════════════════════════════════════════════════════
    #[Route('/departements', name: 'api_departements', methods: ['GET'])]
    public function departements(DepartementRepository $repo): JsonResponse
    {
        $deps = $repo->findBy([], ['nom' => 'ASC']);

        return $this->json([
            'data'  => array_map(fn($d) => [
                'id'  => $d->getId(),
                'nom' => $d->getNom(),
            ], $deps),
            'total' => count($deps),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/types-documents
    //  Public — Liste de tous les types de documents
    // ══════════════════════════════════════════════════════════
    #[Route('/types-documents', name: 'api_types_documents', methods: ['GET'])]
    public function typesDocuments(TypeDocumentRepository $repo): JsonResponse
    {
        $types = $repo->findBy([], ['libelle' => 'ASC']);

        return $this->json([
            'data'  => array_map(fn($t) => [
                'id'      => $t->getId(),
                'libelle' => $t->getLibelle(),
            ], $types),
            'total' => count($types),
        ]);
    }
}