<?php

namespace App\Repository;

use App\Entity\PublicBody;
use App\Entity\AutonomousCommunity;
use App\Entity\RegDestination;
use App\Service\Submission\RegAdministrationLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicBody>
 */
class PublicBodyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicBody::class);
    }

    /**
     * @return PublicBody[]
     */
    public function findByLevel(string $level): array
    {
        return $this->findBy(['level' => $level], ['name' => 'ASC']);
    }

    /**
     * @return PublicBody[]
     */
    public function findByAutonomousCommunity(AutonomousCommunity $community): array
    {
        return $this->createQueryBuilder('pb')
            ->where('pb.autonomousCommunity = :community')
            ->setParameter('community', $community)
            ->orderBy('pb.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PublicBody[]
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('pb')
            ->where('LOWER(pb.name) LIKE LOWER(:query)')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('pb.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuerpos enviables (Portal AGE por idAmb, o apuntados por una
     * RegDestination activa como submissionTarget), opcionalmente filtrados por
     * nivel de administración (clave UI de RegAdministrationLevel) y ámbito
     * (ministerio para `estado`, comunidad para el resto), más subcadena de
     * nombre.
     *
     * El nivel `estado` incluye además los cuerpos AGE, que no tienen
     * RegDestination. El resto de niveles excluyen AGE.
     *
     * @return PublicBody[]
     */
    public function searchSubmittable(
        string $query = '',
        ?string $nivel = null,
        ?string $ministerio = null,
        ?string $comunidad = null,
        int $limit = 20,
    ): array {
        $qb = $this->createQueryBuilder('pb')
            ->leftJoin(RegDestination::class, 'rd', 'WITH', 'rd.submissionTarget = pb AND rd.disabledAt IS NULL')
            ->groupBy('pb.id')
            ->orderBy('pb.name', 'ASC')
            ->setMaxResults($limit);

        $nivelValue = $nivel !== null ? RegAdministrationLevel::nivelFor($nivel) : null;

        if ($nivelValue === null) {
            // Sin nivel (o nivel desconocido): comportamiento anterior — REG ∪ AGE.
            $qb->where('pb.transparencyPortalAmbId IS NOT NULL OR rd.id IS NOT NULL');
        } else {
            // Rama REG: la unidad debe ser de ese nivel (y ámbito si procede).
            $regCond = 'rd.id IS NOT NULL AND rd.nivelAdministracion = :nivelValue';
            $qb->setParameter('nivelValue', $nivelValue);

            if ($comunidad !== null && $comunidad !== '') {
                $regCond .= ' AND rd.comunidad = :comunidad';
                $qb->setParameter('comunidad', $comunidad);
            }
            if ($ministerio !== null && $ministerio !== '') {
                // Raíz (rd.publicBody) identifica al ministerio.
                $regCond .= ' AND raiz.name = :ministerio';
                $qb->leftJoin('rd.publicBody', 'raiz');
                $qb->setParameter('ministerio', $ministerio);
            }

            if (RegAdministrationLevel::includesAgeBodies($nivel)) {
                // Rama AGE: cuerpos de Portal. Filtrados por ministerio = su propio nombre.
                $ageCond = 'pb.transparencyPortalAmbId IS NOT NULL';
                if ($ministerio !== null && $ministerio !== '') {
                    $ageCond .= ' AND pb.name = :ministerioAge';
                    $qb->setParameter('ministerioAge', $ministerio);
                }
                $qb->where(sprintf('(%s) OR (%s)', $regCond, $ageCond));
            } else {
                $qb->where($regCond);
            }
        }

        if ($query !== '') {
            $qb->andWhere('LOWER(pb.name) LIKE LOWER(:query)')
                ->setParameter('query', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByNameLike(string $name): ?PublicBody
    {
        return $this->createQueryBuilder('pb')
            ->where('pb.name LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByNameInsensitive(string $name): ?PublicBody
    {
        return $this->createQueryBuilder('pb')
            ->where('LOWER(pb.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Like findOneByNameInsensitive but returns null when the lookup is
     * ambiguous (more than one row with that exact name). Used by the REG
     * importer to avoid attaching a DIR3 code to the wrong duplicate when
     * the curated catalogue still carries near-identical variants.
     */
    public function findUniqueByNameInsensitive(string $name): ?PublicBody
    {
        $rows = $this->createQueryBuilder('pb')
            ->where('LOWER(pb.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * @return PublicBody[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Comunidades distintas presentes en destinos REG activos del nivel dado
     * (clave UI). Alimenta el desplegable del paso 2 para niveles territoriales.
     *
     * @return list<string>
     */
    public function findComunidadesForNivel(string $nivel): array
    {
        $nivelValue = RegAdministrationLevel::nivelFor($nivel);
        if ($nivelValue === null) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT rd.comunidad')
            ->from(RegDestination::class, 'rd')
            ->where('rd.disabledAt IS NULL')
            ->andWhere('rd.nivelAdministracion = :nivelValue')
            ->andWhere('rd.comunidad IS NOT NULL')
            ->setParameter('nivelValue', $nivelValue)
            ->orderBy('rd.comunidad', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => $r['comunidad'], $rows);
    }

    /**
     * Ministerios para el paso 2 del nivel "estado": nombres de las Raíces de
     * destinos REG estatales ∪ nombres de cuerpos AGE. Distintos y ordenados.
     *
     * @return list<string>
     */
    public function findEstadoMinistries(): array
    {
        $em = $this->getEntityManager();
        $estado = RegAdministrationLevel::nivelFor('estado');

        $regRows = $em->createQueryBuilder()
            ->select('DISTINCT raiz.name AS name')
            ->from(RegDestination::class, 'rd')
            ->join('rd.publicBody', 'raiz')
            ->where('rd.disabledAt IS NULL')
            ->andWhere('rd.nivelAdministracion = :estado')
            ->setParameter('estado', $estado)
            ->getQuery()
            ->getScalarResult();

        $ageRows = $this->createQueryBuilder('pb')
            ->select('DISTINCT pb.name AS name')
            ->where('pb.transparencyPortalAmbId IS NOT NULL')
            ->getQuery()
            ->getScalarResult();

        $names = array_merge(
            array_map(static fn (array $r) => $r['name'], $regRows),
            array_map(static fn (array $r) => $r['name'], $ageRows),
        );
        $names = array_values(array_unique($names));
        sort($names, SORT_LOCALE_STRING);

        return $names;
    }
}
