<?php

namespace App\DataFixtures;

use App\Entity\Departement;
use App\Entity\Iset;
use App\Entity\TypeDocument;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        // ═══════════════════════════════════════════════
        //  1. ISET
        // ═══════════════════════════════════════════════
        $isetData = [
            ['nom' => 'Rades',       'ville' => 'Tunis'],
            ['nom' => 'Bizerte',     'ville' => 'Bizerte'],
            ['nom' => 'Charguia',    'ville' => 'Tunis'],
            ['nom' => 'Nabeul',      'ville' => 'Nabeul'],
            ['nom' => 'Zaghouan',    'ville' => 'Zaghouan'],
            ['nom' => "ISET'Com",    'ville' => 'Tunis'],
            ['nom' => 'Béja',        'ville' => 'Béja'],
            ['nom' => 'Jendouba',    'ville' => 'Jendouba'],
            ['nom' => 'Le Kef',      'ville' => 'Le Kef'],
            ['nom' => 'Siliana',     'ville' => 'Siliana'],
            ['nom' => 'Ksar Hellal', 'ville' => 'Monastir'],
            ['nom' => 'Mahdia',      'ville' => 'Mahdia'],
            ['nom' => 'Sousse',      'ville' => 'Sousse'],
            ['nom' => 'Sfax',        'ville' => 'Sfax'],
            ['nom' => 'Kairouan',    'ville' => 'Kairouan'],
            ['nom' => 'Kasserine',   'ville' => 'Kasserine'],
            ['nom' => 'Sidi Bouzid', 'ville' => 'Sidi Bouzid'],
            ['nom' => 'Gabès',       'ville' => 'Gabès'],
            ['nom' => 'Gafsa',       'ville' => 'Gafsa'],
            ['nom' => 'Djerba',      'ville' => 'Médenine'],
            ['nom' => 'Kébili',      'ville' => 'Kébili'],
            ['nom' => 'Tataouine',   'ville' => 'Tataouine'],
            ['nom' => 'Tozeur',      'ville' => 'Tozeur'],
            ['nom' => 'Médenine',    'ville' => 'Médenine'],
        ];

        $isets = []; // tableau de référence pour réutilisation

        foreach ($isetData as $data) {
            $iset = new Iset();
            $iset->setNom($data['nom']);
            $iset->setVille($data['ville']);
            $manager->persist($iset);

            // Stocker par nom pour y accéder facilement (ex: $isets['Rades'])
            $isets[$data['nom']] = $iset;
        }

        // ═══════════════════════════════════════════════
        //  2. DÉPARTEMENTS
        // ═══════════════════════════════════════════════
        $departementNames = [
            'Génie électrique',
            'Génie mécanique',
            'Génie civil',
            'Technologies de l\'informatique',
            'Informatique & multimédia',
            'Sciences économiques et gestion',
            'Techniques de commercialisation',
            'Maintenance industrielle',
            'Génie textile',
            'Télécommunications (ISET\'Com)',
        ];

        foreach ($departementNames as $nom) {
            $departement = new Departement();
            $departement->setNom($nom);
            $manager->persist($departement);
        }

        // ═══════════════════════════════════════════════
        //  3. TYPES DE DOCUMENTS
        //     Rapport de stage décliné en 3 sous-types
        //     pour respecter le cahier des charges
        // ═══════════════════════════════════════════════
        $typeDocumentNames = [
            'Devoirs de contrôle',
            'Examen',
            'Rapport de stage (initiation)',
            'Rapport de stage (perfectionnement)',
            'Rapport de stage (PFE)',
            'Cours',
            'Atelier',
            'TD',
        ];

        foreach ($typeDocumentNames as $libelle) {
            $type = new TypeDocument();
            $type->setLibelle($libelle);
            $manager->persist($type);
        }

        // ═══════════════════════════════════════════════
        //  4. COMPTE ADMINISTRATEUR
        // ═══════════════════════════════════════════════
        $admin = new User();
        $admin->setEmail('admin@iset.tn');
        $admin->setNom('admin');
        $admin->setPrenom('Système');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIset($isets['Rades']); // rattaché à ISET Rades
        $admin->setPassword(
            $this->hasher->hashPassword($admin, 'admin123')
        );

        $manager->persist($admin);

        // ═══════════════════════════════════════════════
        //  5. FLUSH — un seul appel à la fin (performance)
        // ═══════════════════════════════════════════════
        $manager->flush();

        // echo "\n Fixtures chargées avec succès :\n";
        // echo "   - " . count($isetData)          . " ISET\n";
        // echo "   - " . count($departementNames)   . " Départements\n";
        // echo "   - " . count($typeDocumentNames)  . " Types de documents\n";
        // echo "   - 1 Administrateur (admin@iset.tn)\n\n";
    }
}
