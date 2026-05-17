<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\IsetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;


#[Route('/api')]
class AuthController extends AbstractController
{
    // ══════════════════════════════════════════════════════════
    //  POST /api/register
    //  Public — Inscription d'un nouvel étudiant
    // ══════════════════════════════════════════════════════════
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        IsetRepository $isetRepo,
        UserRepository $userRepo
    ): JsonResponse {

        $data = json_decode($request->getContent(), true);

        // ── Validation des champs obligatoires ──
        $required = ['email', 'password', 'nom', 'prenom', 'isetId'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->json(
                    ['error' => "Le champ '$field' est obligatoire."],
                    400
                );
            }
        }

        // ── Validation format email ──
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Format email invalide.'], 400);
        }

        // ── Vérifier que l'email n'est pas déjà utilisé ──
        if ($userRepo->findOneBy(['email' => $data['email']])) {
            return $this->json(['error' => 'Cet email est déjà utilisé.'], 409);
        }

        // ── Vérifier que l'ISET existe ──
        $iset = $isetRepo->find($data['isetId']);
        if (!$iset) {
            return $this->json(['error' => 'ISET introuvable.'], 404);
        }

        // ── Création de l'utilisateur ──
        $user = new User();
        $user->setEmail($data['email']);
        $user->setNom($data['nom']);
        $user->setPrenom($data['prenom']);
        $user->setIset($iset);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, $data['password']));

        $em->persist($user);
        $em->flush();

        return $this->json([
            'message' => 'Compte créé avec succès.',
            'user' => [
                'id'     => $user->getId(),
                'email'  => $user->getEmail(),
                'nom'    => $user->getNom(),
                'prenom' => $user->getPrenom(),
            ]
        ], 201);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/me
    //  Protégé — Retourne le profil de l'utilisateur connecté
    // ══════════════════════════════════════════════════════════
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'id'     => $user->getId(),
            'email'  => $user->getEmail(),
            'nom'    => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'roles'  => $user->getRoles(),
            'iset'   => [
                'id'  => $user->getIset()->getId(),
                'nom' => $user->getIset()->getNom(),
            ],
        ]);
    }
}