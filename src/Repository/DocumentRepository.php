<?php

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    // ══════════════════════════════════════════════════════════
    //  Documents validés avec filtres + pagination
    // ══════════════════════════════════════════════════════════
    public function findValidatedWithFilters(array $filters, int $page = 1, int $limit = 12): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.statut = :statut')
            ->setParameter('statut', Document::STATUS_VALIDE)
            ->orderBy('d.dateDepot', 'DESC');

        $this->applyFilters($qb, $filters);

        return $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // ══════════════════════════════════════════════════════════
    //  Compter les documents validés (pour la pagination)
    // ══════════════════════════════════════════════════════════
    public function countValidatedWithFilters(array $filters): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.statut = :statut')
            ->setParameter('statut', Document::STATUS_VALIDE);

        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // ══════════════════════════════════════════════════════════
    //  Méthode privée — Appliquer les filtres au QueryBuilder
    // ══════════════════════════════════════════════════════════
    private function applyFilters($qb, array $filters): void
    {
        if (!empty($filters['iset'])) {
            $qb->andWhere('d.iset = :iset')
               ->setParameter('iset', $filters['iset']);
        }

        if (!empty($filters['departement'])) {
            $qb->andWhere('d.departement = :departement')
               ->setParameter('departement', $filters['departement']);
        }

        if (!empty($filters['typeDocument'])) {
            $qb->andWhere('d.typeDocument = :typeDocument')
               ->setParameter('typeDocument', $filters['typeDocument']);
        }

        if (!empty($filters['niveau'])) {
            $qb->andWhere('d.niveau = :niveau')
               ->setParameter('niveau', $filters['niveau']);
        }

        if (!empty($filters['anneeScolaire'])) {
            $qb->andWhere('d.anneeScolaire = :anneeScolaire')
               ->setParameter('anneeScolaire', $filters['anneeScolaire']);
        }
    }
}