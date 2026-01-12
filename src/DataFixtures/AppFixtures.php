<?php

namespace App\DataFixtures;

use App\Entity\ApplicableLaw;
use App\Entity\AutonomousCommunity;
use App\Entity\PublicBody;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Create Autonomous Communities
        $ccaa = $this->createAutonomousCommunities($manager);

        // Create Applicable Laws
        $laws = $this->createApplicableLaws($manager, $ccaa);

        // Create Public Bodies
        $this->createPublicBodies($manager, $ccaa);

        // Create Users
        $this->createUsers($manager);

        $manager->flush();
    }

    private function createAutonomousCommunities(ObjectManager $manager): array
    {
        $communities = [
            'AND' => 'Andalucía',
            'ARA' => 'Aragón',
            'AST' => 'Principado de Asturias',
            'BAL' => 'Illes Balears',
            'CAN' => 'Canarias',
            'CNT' => 'Cantabria',
            'CYL' => 'Castilla y León',
            'CLM' => 'Castilla-La Mancha',
            'CAT' => 'Cataluña',
            'CEU' => 'Ceuta',
            'VAL' => 'Comunitat Valenciana',
            'EXT' => 'Extremadura',
            'GAL' => 'Galicia',
            'MAD' => 'Comunidad de Madrid',
            'MEL' => 'Melilla',
            'MUR' => 'Región de Murcia',
            'NAV' => 'Comunidad Foral de Navarra',
            'PVA' => 'País Vasco',
            'RIO' => 'La Rioja',
        ];

        $ccaaEntities = [];
        foreach ($communities as $code => $name) {
            $ccaa = new AutonomousCommunity();
            $ccaa->setCode($code);
            $ccaa->setName($name);
            $manager->persist($ccaa);
            $ccaaEntities[$code] = $ccaa;
        }

        return $ccaaEntities;
    }

    private function createApplicableLaws(ObjectManager $manager, array $ccaa): array
    {
        $laws = [];

        // Ley estatal (Ley 19/2013) - Art. 20: 1 mes natural
        $stateLaw = new ApplicableLaw();
        $stateLaw->setName('Ley 19/2013, de 9 de diciembre, de transparencia');
        $stateLaw->setShortCode('LTAIPBG');
        $stateLaw->setResponseDeadlineValue(1);
        $stateLaw->setResponseDeadlineUnit(ApplicableLaw::UNIT_MONTHS);
        $stateLaw->setExtensionValue(1);
        $stateLaw->setExtensionUnit(ApplicableLaw::UNIT_MONTHS);
        $stateLaw->setMaxExtensions(1);
        $stateLaw->setComplaintDeadlineDays(30); // días hábiles para reclamación CTBG
        $stateLaw->setComplianceAfterComplaintDays(10);
        $stateLaw->setSilenceIsPositive(false);
        $stateLaw->setAutonomousCommunity(null);
        $manager->persist($stateLaw);
        $laws['estatal'] = $stateLaw;

        // Andalucía (Ley 1/2014) - Art. 36: 20 días hábiles
        $andLaw = new ApplicableLaw();
        $andLaw->setName('Ley 1/2014, de 24 de junio, de Transparencia Pública de Andalucía');
        $andLaw->setShortCode('LTA');
        $andLaw->setResponseDeadlineValue(20);
        $andLaw->setResponseDeadlineUnit(ApplicableLaw::UNIT_BUSINESS_DAYS);
        $andLaw->setExtensionValue(20);
        $andLaw->setExtensionUnit(ApplicableLaw::UNIT_BUSINESS_DAYS);
        $andLaw->setMaxExtensions(1);
        $andLaw->setComplaintDeadlineDays(30);
        $andLaw->setComplianceAfterComplaintDays(10);
        $andLaw->setSilenceIsPositive(false);
        $andLaw->setAutonomousCommunity($ccaa['AND']);
        $manager->persist($andLaw);
        $laws['AND'] = $andLaw;

        // Cataluña (Llei 19/2014) - Art. 33: 1 mes
        $catLaw = new ApplicableLaw();
        $catLaw->setName('Llei 19/2014, de 29 de desembre, de transparència de Catalunya');
        $catLaw->setShortCode('LTC');
        $catLaw->setResponseDeadlineValue(1);
        $catLaw->setResponseDeadlineUnit(ApplicableLaw::UNIT_MONTHS);
        $catLaw->setExtensionValue(1);
        $catLaw->setExtensionUnit(ApplicableLaw::UNIT_MONTHS);
        $catLaw->setMaxExtensions(1);
        $catLaw->setComplaintDeadlineDays(30);
        $catLaw->setComplianceAfterComplaintDays(15);
        $catLaw->setSilenceIsPositive(true); // Silencio positivo en Cataluña
        $catLaw->setAutonomousCommunity($ccaa['CAT']);
        $manager->persist($catLaw);
        $laws['CAT'] = $catLaw;

        // País Vasco (Ley 2/2016) - 1 mes
        $pvaLaw = new ApplicableLaw();
        $pvaLaw->setName('Ley 2/2016, de 7 de abril, de Instituciones Locales de Euskadi');
        $pvaLaw->setShortCode('LILE');
        $pvaLaw->setResponseDeadlineValue(1);
        $pvaLaw->setResponseDeadlineUnit(ApplicableLaw::UNIT_MONTHS);
        $pvaLaw->setExtensionValue(1);
        $pvaLaw->setExtensionUnit(ApplicableLaw::UNIT_MONTHS);
        $pvaLaw->setMaxExtensions(1);
        $pvaLaw->setComplaintDeadlineDays(30);
        $pvaLaw->setComplianceAfterComplaintDays(10);
        $pvaLaw->setSilenceIsPositive(false);
        $pvaLaw->setAutonomousCommunity($ccaa['PVA']);
        $manager->persist($pvaLaw);
        $laws['PVA'] = $pvaLaw;

        // Comunitat Valenciana (Ley 2/2015) - Art. 17: 1 mes
        $valLaw = new ApplicableLaw();
        $valLaw->setName('Ley 2/2015, de 2 de abril, de Transparencia de la Comunitat Valenciana');
        $valLaw->setShortCode('LTCV');
        $valLaw->setResponseDeadlineValue(1);
        $valLaw->setResponseDeadlineUnit(ApplicableLaw::UNIT_MONTHS);
        $valLaw->setExtensionValue(1);
        $valLaw->setExtensionUnit(ApplicableLaw::UNIT_MONTHS);
        $valLaw->setMaxExtensions(1);
        $valLaw->setComplaintDeadlineDays(30);
        $valLaw->setComplianceAfterComplaintDays(10);
        $valLaw->setSilenceIsPositive(false);
        $valLaw->setAutonomousCommunity($ccaa['VAL']);
        $manager->persist($valLaw);
        $laws['VAL'] = $valLaw;

        // Madrid (Ley 10/2019) - Art. 22: 1 mes
        $madLaw = new ApplicableLaw();
        $madLaw->setName('Ley 10/2019, de 10 de abril, de Transparencia de la Comunidad de Madrid');
        $madLaw->setShortCode('LTCM');
        $madLaw->setResponseDeadlineValue(1);
        $madLaw->setResponseDeadlineUnit(ApplicableLaw::UNIT_MONTHS);
        $madLaw->setExtensionValue(1);
        $madLaw->setExtensionUnit(ApplicableLaw::UNIT_MONTHS);
        $madLaw->setMaxExtensions(1);
        $madLaw->setComplaintDeadlineDays(30);
        $madLaw->setComplianceAfterComplaintDays(10);
        $madLaw->setSilenceIsPositive(false);
        $madLaw->setAutonomousCommunity($ccaa['MAD']);
        $manager->persist($madLaw);
        $laws['MAD'] = $madLaw;

        // Galicia (Ley 1/2016) - Art. 27: 1 mes
        $galLaw = new ApplicableLaw();
        $galLaw->setName('Ley 1/2016, de 18 de enero, de transparencia y buen gobierno de Galicia');
        $galLaw->setShortCode('LTBGG');
        $galLaw->setResponseDeadlineValue(1);
        $galLaw->setResponseDeadlineUnit(ApplicableLaw::UNIT_MONTHS);
        $galLaw->setExtensionValue(1);
        $galLaw->setExtensionUnit(ApplicableLaw::UNIT_MONTHS);
        $galLaw->setMaxExtensions(1);
        $galLaw->setComplaintDeadlineDays(30);
        $galLaw->setComplianceAfterComplaintDays(10);
        $galLaw->setSilenceIsPositive(false);
        $galLaw->setAutonomousCommunity($ccaa['GAL']);
        $manager->persist($galLaw);
        $laws['GAL'] = $galLaw;

        return $laws;
    }

    private function createPublicBodies(ObjectManager $manager, array $ccaa): void
    {
        $bodies = [
            // Ministerios (Estado)
            ['name' => 'Ministerio de la Presidencia, Justicia y Relaciones con las Cortes', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio de Hacienda', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio del Interior', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio de Defensa', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio de Asuntos Exteriores, Unión Europea y Cooperación', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio de Educación, Formación Profesional y Deportes', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio de Sanidad', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio de Transportes y Movilidad Sostenible', 'level' => 'state', 'ccaa' => null],
            ['name' => 'Ministerio para la Transición Ecológica y el Reto Demográfico', 'level' => 'state', 'ccaa' => null],
            // Juntas autonómicas
            ['name' => 'Junta de Andalucía', 'level' => 'autonomous', 'ccaa' => 'AND'],
            ['name' => 'Generalitat de Catalunya', 'level' => 'autonomous', 'ccaa' => 'CAT'],
            ['name' => 'Comunidad de Madrid', 'level' => 'autonomous', 'ccaa' => 'MAD'],
            ['name' => 'Generalitat Valenciana', 'level' => 'autonomous', 'ccaa' => 'VAL'],
            ['name' => 'Gobierno Vasco - Eusko Jaurlaritza', 'level' => 'autonomous', 'ccaa' => 'PVA'],
            ['name' => 'Xunta de Galicia', 'level' => 'autonomous', 'ccaa' => 'GAL'],
            // Ayuntamientos principales
            ['name' => 'Ayuntamiento de Madrid', 'level' => 'local', 'ccaa' => 'MAD'],
            ['name' => 'Ajuntament de Barcelona', 'level' => 'local', 'ccaa' => 'CAT'],
            ['name' => 'Ayuntamiento de Valencia', 'level' => 'local', 'ccaa' => 'VAL'],
            ['name' => 'Ayuntamiento de Sevilla', 'level' => 'local', 'ccaa' => 'AND'],
            ['name' => 'Ayuntamiento de Bilbao', 'level' => 'local', 'ccaa' => 'PVA'],
        ];

        foreach ($bodies as $bodyData) {
            $body = new PublicBody();
            $body->setName($bodyData['name']);
            $body->setLevel($bodyData['level']);
            $body->setAutonomousCommunity($bodyData['ccaa'] ? $ccaa[$bodyData['ccaa']] : null);
            $manager->persist($body);
        }
    }

    private function createUsers(ObjectManager $manager): void
    {
        // Admin user
        $admin = new User();
        $admin->setEmail('admin@vigia.es');
        $admin->setFirstName('Administrador');
        $admin->setLastName('Sistema');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsVerified(true);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);

        // Demo user
        $demo = new User();
        $demo->setEmail('demo@vigia.es');
        $demo->setFirstName('Usuario');
        $demo->setLastName('Demo');
        $demo->setRoles(['ROLE_USER']);
        $demo->setIsVerified(true);
        $demo->setPassword($this->passwordHasher->hashPassword($demo, 'demo123'));
        $manager->persist($demo);
    }
}
