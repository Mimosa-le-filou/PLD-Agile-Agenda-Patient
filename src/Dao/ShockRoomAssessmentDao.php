<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\ShockRoomAssessment;
use LogicException;
use PDO;

/**
 * Accès aux bilans de déchocage (table ShockRoomAssessment, figure 3 du cahier des charges, modèle v2) : au plus
 * un par
 * évaluation de type ClinicalImpression::KIND_SHOCK_ROOM (contrainte uq_shockroom_impression). Pas de suppression
 * propre : le bilan suit son évaluation (ClinicalImpressionDao::markRemoved()), et n'est lu que si celle-ci est
 * active.
 *
 * Ids VARCHAR(20) sans AUTO_INCREMENT, calculés par nextId() (MAX + 1, FOR UPDATE) dans la transaction d'écriture.
 */
class ShockRoomAssessmentDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Bilan d'une évaluation active, ou null (évaluation classique, retirée, ou bilan pas encore saisi). */
    public function findByClinicalImpression(string $idClinicalImpression): ?ShockRoomAssessment
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM ShockRoomAssessment
             WHERE idClinicalImpression = :id
               AND idClinicalImpression IN (
                   SELECT idClinicalImpression FROM ClinicalImpression WHERE removedAt IS NULL
               )'
        );
        $stmt->execute(['id' => $idClinicalImpression]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /**
     * Enregistre le bilan et renvoie son identifiant. $idClinicalImpression remplace celui du bilan : c'est ainsi
     * que ClinicalImpressionDao::insert() rattache un bilan construit avant l'évaluation.
     *
     * L'évaluation doit exister, être active et de type SHOCK_ROOM : la condition est dans l'INSERT … SELECT
     * lui-même, si bien qu'aucune ligne n'est écrite sinon.
     *
     * @throws LogicException évaluation non désignée, absente, retirée ou classique (STANDARD)
     * @throws \PDOException contrainte refusée (bilan déjà saisi, Glasgow ou IGS II hors bornes) : rien n'est écrit
     */
    public function insert(ShockRoomAssessment $a, ?string $idClinicalImpression = null): string
    {
        $idImpression = $idClinicalImpression ?? $a->getIdClinicalImpression();
        if ($idImpression === '') {
            throw new LogicException('ShockRoomAssessmentDao::insert() requiert l\'identifiant de l\'évaluation.');
        }

        return (new Transaction($this->pdo))->run(function () use ($a, $idImpression): string {
            $id = (string) $this->nextId();
            $fields = array_keys(ShockRoomAssessment::FIELDS);
            $stmt = $this->pdo->prepare(
                'INSERT INTO ShockRoomAssessment (idShockRoomAssessment, idClinicalImpression, '
                . implode(', ', $fields) . ')
                 SELECT :id, c.idClinicalImpression, :' . implode(', :', $fields) . '
                 FROM ClinicalImpression c
                 WHERE c.idClinicalImpression = :idClinicalImpression
                   AND c.kind = \'SHOCK_ROOM\' AND c.removedAt IS NULL'
            );
            $stmt->execute(['id' => $id, 'idClinicalImpression' => $idImpression] + $this->values($a));
            if ($stmt->rowCount() !== 1) {
                throw new LogicException(
                    "Bilan de déchocage refusé : l'évaluation $idImpression est absente, retirée ou n'est pas "
                    . 'une fiche de déchocage.'
                );
            }

            return $id;
        });
    }

    /**
     * Met à jour tous les champs cliniques d'un bilan dont l'évaluation est active. Renvoie false si le bilan
     * n'existe pas ou si son évaluation a été retirée.
     *
     * @throws LogicException si le bilan n'a pas d'identifiant
     */
    public function update(ShockRoomAssessment $a): bool
    {
        $id = $a->getIdShockRoomAssessment();
        if ($id === null) {
            throw new LogicException('ShockRoomAssessmentDao::update() requiert un bilan enregistré (id non nul).');
        }

        $assignments = array_map(
            static fn (string $field): string => "$field = :$field",
            array_keys(ShockRoomAssessment::FIELDS)
        );
        $stmt = $this->pdo->prepare(
            'UPDATE ShockRoomAssessment SET ' . implode(', ', $assignments) . '
             WHERE idShockRoomAssessment = :id
               AND idClinicalImpression IN (
                   SELECT idClinicalImpression FROM ClinicalImpression WHERE removedAt IS NULL
               )'
        );
        $stmt->execute(['id' => $id] + $this->values($a));

        // MariaDB ne compte pas une ligne réécrite à l'identique : on vérifie alors qu'elle est toujours lisible.
        return $stmt->rowCount() === 1 || $this->isReadable($id);
    }

    /** Le bilan existe et son évaluation est active. */
    private function isReadable(string $idShockRoomAssessment): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ShockRoomAssessment s
             JOIN ClinicalImpression c ON c.idClinicalImpression = s.idClinicalImpression
             WHERE s.idShockRoomAssessment = :id AND c.removedAt IS NULL'
        );
        $stmt->execute(['id' => $idShockRoomAssessment]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Valeurs des champs cliniques, une par colonne de ShockRoomAssessment::FIELDS (accesseur get…() du même
     * nom) ; le bilan lésionnel (array) est écrit en JSON, NULL s'il est vide.
     *
     * @return array<string, scalar|null>
     */
    private function values(ShockRoomAssessment $a): array
    {
        $values = [];
        foreach (ShockRoomAssessment::FIELDS as $field => $type) {
            $value = $a->{'get' . ucfirst($field)}();
            if ($type === 'array') {
                $value = $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /** Colonnes lues : identifiants puis champs cliniques (noms tirés d'une constante, jamais de la requête). */
    private static function columns(): string
    {
        return 'idShockRoomAssessment, idClinicalImpression, ' . implode(', ', array_keys(ShockRoomAssessment::FIELDS));
    }

    /**
     * Plus grand identifiant + 1, table verrouillée (FOR UPDATE) jusqu'à la fin de la transaction.
     */
    private function nextId(): int
    {
        return (int) $this->pdo
            ->query('SELECT COALESCE(MAX(CAST(idShockRoomAssessment AS UNSIGNED)), 0) + 1
                     FROM ShockRoomAssessment FOR UPDATE')
            ->fetchColumn();
    }

    /** @param array<string, ?string> $r ligne aux colonnes de columns() */
    private function map(array $r): ShockRoomAssessment
    {
        $arguments = [];
        foreach (ShockRoomAssessment::FIELDS as $field => $type) {
            $value = $r[$field];
            $arguments[$field] = match (true) {
                $type === 'array' => is_array($decoded = json_decode((string) $value, true)) ? $decoded : [],
                $value === null => null,
                $type === 'int' => (int) $value,
                $type === 'float' => (float) $value,
                default => $value,
            };
        }

        return new ShockRoomAssessment(
            (string) $r['idShockRoomAssessment'],
            (string) $r['idClinicalImpression'],
            ...$arguments
        );
    }
}
