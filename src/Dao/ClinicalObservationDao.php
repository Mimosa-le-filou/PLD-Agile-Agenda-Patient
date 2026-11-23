<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\ClinicalObservation;
use DateTimeImmutable;
use LogicException;
use PDO;

/**
 * Accès aux fiches de suivi d'une évaluation (table ClinicalObservation, ressource HL7 Observation) : une fiche
 * par système physiologique (sédation, neurologique, cardiovasculaire…). Classes et colonnes portent les mêmes
 * noms : rien n'est traduit ici.
 *
 * Seules les fiches actives (removedAt IS NULL) sont lues ; retirer une fiche garde sa ligne (RG10) et libère la
 * place de son système (colonne générée activeKey de uq_clinicalobservation_category).
 *
 * Ids VARCHAR(20) sans AUTO_INCREMENT, calculés par nextId() (MAX + 1, FOR UPDATE) dans la transaction d'écriture.
 */
class ClinicalObservationDao
{
    private const SQL_DATE_FORMAT = 'Y-m-d H:i:s';

    private const COLUMNS = 'idClinicalObservation, idClinicalImpression, category, selectedValue, notes,
        examInterpretation, observedAt, removedAt';

    /** Ordre d'affichage des systèmes (celui de l'écran, ClinicalObservation::CATEGORIES). */
    private const CATEGORY_ORDER = "FIELD(category, 'SEDATION', 'NEUROLOGICAL', 'CARDIOVASCULAR', 'HEMODYNAMIC',
        'RESPIRATORY', 'ABDOMINAL', 'URORENAL')";

    public function __construct(private PDO $pdo)
    {
    }

    /** Fiche active, ou null si elle n'existe pas ou a été retirée. */
    public function findById(string $idClinicalObservation): ?ClinicalObservation
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ClinicalObservation
             WHERE idClinicalObservation = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $idClinicalObservation]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /**
     * Fiches actives d'une évaluation, dans l'ordre des systèmes de l'écran.
     *
     * @return ClinicalObservation[]
     */
    public function findByClinicalImpression(string $idClinicalImpression): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ClinicalObservation
             WHERE idClinicalImpression = :id AND removedAt IS NULL
             ORDER BY ' . self::CATEGORY_ORDER . ', CAST(idClinicalObservation AS UNSIGNED)'
        );
        $stmt->execute(['id' => $idClinicalImpression]);

        return array_map(fn(array $r) => $this->map($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Enregistre une fiche et renvoie son identifiant. $idClinicalImpression remplace celui de la fiche : c'est
     * ainsi que ClinicalImpressionDao::insert() rattache les fiches construites avant l'évaluation.
     *
     * @throws LogicException si aucune évaluation n'est désignée
     * @throws \PDOException contrainte refusée (système inconnu, fiche vide, système déjà renseigné, évaluation
     *                       absente) : rien n'est enregistré
     */
    public function insert(ClinicalObservation $observation, ?string $idClinicalImpression = null): string
    {
        $idImpression = $idClinicalImpression ?? $observation->getIdClinicalImpression();
        if ($idImpression === '') {
            throw new LogicException('ClinicalObservationDao::insert() requiert l\'identifiant de l\'évaluation.');
        }

        return (new Transaction($this->pdo))->run(function () use ($observation, $idImpression): string {
            $id = (string) $this->nextId();
            $this->pdo->prepare(
                'INSERT INTO ClinicalObservation (idClinicalObservation, idClinicalImpression, category,
                                                  selectedValue, notes, examInterpretation, observedAt)
                 VALUES (:id, :idClinicalImpression, :category, :selectedValue, :notes, :examInterpretation,
                         :observedAt)'
            )->execute([
                'id' => $id,
                'idClinicalImpression' => $idImpression,
                'category' => $observation->getCategory(),
                'selectedValue' => $observation->getSelectedValue(),
                'notes' => $observation->getNotes(),
                'examInterpretation' => $observation->getExamInterpretation(),
                'observedAt' => $observation->getObservedAt()->format(self::SQL_DATE_FORMAT),
            ]);

            return $id;
        });
    }

    /**
     * Met à jour le contenu d'une fiche active (liste, note, interprétation, heure d'observation). Le système et
     * l'évaluation ne changent pas. Renvoie false si la fiche n'existe pas ou a été retirée.
     *
     * @throws LogicException si la fiche n'a pas d'identifiant
     */
    public function update(ClinicalObservation $observation): bool
    {
        $id = $observation->getIdClinicalObservation();
        if ($id === null) {
            throw new LogicException('ClinicalObservationDao::update() requiert une fiche enregistrée (id non nul).');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ClinicalObservation
             SET selectedValue = :selectedValue, notes = :notes, examInterpretation = :examInterpretation,
                 observedAt = :observedAt
             WHERE idClinicalObservation = :id AND removedAt IS NULL'
        );
        $stmt->execute([
            'selectedValue' => $observation->getSelectedValue(),
            'notes' => $observation->getNotes(),
            'examInterpretation' => $observation->getExamInterpretation(),
            'observedAt' => $observation->getObservedAt()->format(self::SQL_DATE_FORMAT),
            'id' => $id,
        ]);

        // MariaDB ne compte pas une ligne réécrite à l'identique : on vérifie alors qu'elle existe.
        return $stmt->rowCount() === 1 || $this->findById($id) !== null;
    }

    /**
     * Retire une fiche (suppression logique, horloge de la base comme Patient.removedAt). Renvoie false si elle
     * n'existe pas ou est déjà retirée : la condition est dans la requête, deux retraits simultanés n'en font
     * qu'un.
     */
    public function markRemoved(string $idClinicalObservation): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ClinicalObservation SET removedAt = CURRENT_TIMESTAMP
             WHERE idClinicalObservation = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $idClinicalObservation]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Plus grand identifiant + 1, table verrouillée (FOR UPDATE) jusqu'à la fin de la transaction.
     */
    private function nextId(): int
    {
        return (int) $this->pdo
            ->query('SELECT COALESCE(MAX(CAST(idClinicalObservation AS UNSIGNED)), 0) + 1
                     FROM ClinicalObservation FOR UPDATE')
            ->fetchColumn();
    }

    /** @param array<string, ?string> $r ligne aux colonnes de COLUMNS */
    private function map(array $r): ClinicalObservation
    {
        return new ClinicalObservation(
            idClinicalObservation: (string) $r['idClinicalObservation'],
            idClinicalImpression: (string) $r['idClinicalImpression'],
            category: (string) $r['category'],
            observedAt: new DateTimeImmutable((string) $r['observedAt']),
            selectedValue: $r['selectedValue'],
            notes: $r['notes'],
            examInterpretation: $r['examInterpretation'],
            removedAt: self::date($r['removedAt'])
        );
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
