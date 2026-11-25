<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\ClinicalImpression;
use DateTimeImmutable;
use LogicException;
use PDO;

/**
 * Accès aux évaluations médicales d'un séjour (table ClinicalImpression, ressource HL7 ClinicalImpression). Une
 * évaluation est lue avec ses fiches de suivi actives (ClinicalObservationDao) et, pour une fiche de déchocage,
 * son bilan (ShockRoomAssessmentDao) ; elle est enregistrée avec eux dans une seule transaction.
 *
 * Seules les évaluations actives (removedAt IS NULL) sont lues ; retirer une évaluation garde sa ligne, ses fiches
 * et son bilan (RG10).
 *
 * Ids VARCHAR(20) sans AUTO_INCREMENT, calculés par nextId() (MAX + 1, FOR UPDATE) dans la transaction d'écriture.
 */
class ClinicalImpressionDao
{
    private const SQL_DATE_FORMAT = 'Y-m-d H:i:s';

    private const COLUMNS = 'idClinicalImpression, idEpisodeOfCare, idPractitioner, kind, effectiveDateTime,
        description, aggressionFactor, restingEnergyExpenditure, researchProtocols, summary, paraclinicalProposal,
        therapeuticProposal, orientation, details, recordedAt, removedAt';

    private ClinicalObservationDao $observations;

    private ShockRoomAssessmentDao $shockRoomAssessments;

    public function __construct(private PDO $pdo)
    {
        $this->observations = new ClinicalObservationDao($pdo);
        $this->shockRoomAssessments = new ShockRoomAssessmentDao($pdo);
    }

    /** Évaluation active avec ses fiches et son bilan, ou null si elle n'existe pas ou a été retirée. */
    public function findById(string $idClinicalImpression): ?ClinicalImpression
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ClinicalImpression
             WHERE idClinicalImpression = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $idClinicalImpression]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /**
     * Évaluations actives d'un séjour, de la plus récente à la plus ancienne, avec leurs fiches et leur bilan.
     * Les évaluations des autres séjours du patient sont exclues.
     *
     * @return ClinicalImpression[]
     */
    public function findByEpisodeOfCare(int $idEpisodeOfCare): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ClinicalImpression
             WHERE idEpisodeOfCare = :idEpisodeOfCare AND removedAt IS NULL
             ORDER BY effectiveDateTime DESC, CAST(idClinicalImpression AS UNSIGNED) DESC'
        );
        $stmt->execute(['idEpisodeOfCare' => (string) $idEpisodeOfCare]);

        return array_map(fn(array $r) => $this->map($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Enregistre une évaluation avec ses fiches et son bilan de déchocage, et renvoie son identifiant. Tout est
     * écrit dans une transaction : si une fiche ou le bilan est refusé, rien n'est enregistré. L'heure de saisie
     * (recordedAt, RG10) est remplie par la base.
     *
     * @throws LogicException bilan de déchocage joint à une évaluation classique (STANDARD)
     * @throws \PDOException contrainte refusée (type inconnu, séjour ou praticien absent, fiche en double, second
     *                       bilan de déchocage actif sur le séjour…)
     */
    public function insert(ClinicalImpression $impression): string
    {
        if ($impression->getShockRoomAssessment() !== null && !$impression->isShockRoom()) {
            throw new LogicException('Un bilan de déchocage ne s\'enregistre qu\'avec une évaluation SHOCK_ROOM.');
        }

        return (new Transaction($this->pdo))->run(function () use ($impression): string {
            $id = (string) $this->nextId();
            $this->pdo->prepare(
                'INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                                                 effectiveDateTime, description, aggressionFactor,
                                                 restingEnergyExpenditure, researchProtocols, summary,
                                                 paraclinicalProposal, therapeuticProposal, orientation, details)
                 VALUES (:id, :idEpisodeOfCare, :idPractitioner, :kind, :effectiveDateTime, :description,
                         :aggressionFactor, :restingEnergyExpenditure, :researchProtocols, :summary,
                         :paraclinicalProposal, :therapeuticProposal, :orientation, :details)'
            )->execute([
                'id' => $id,
                'idEpisodeOfCare' => (string) $impression->getIdEpisodeOfCare(),
                'idPractitioner' => $impression->getIdPractitioner(),
                'kind' => $impression->getKind(),
            ] + $this->editableValues($impression));

            foreach ($impression->getObservations() as $observation) {
                $this->observations->insert($observation, $id);
            }
            $assessment = $impression->getShockRoomAssessment();
            if ($assessment !== null) {
                $this->shockRoomAssessments->insert($assessment, $id);
            }

            return $id;
        });
    }

    /**
     * Met à jour l'en-tête d'une évaluation active : heure clinique, évaluation générale, DER, protocoles,
     * synthèse, propositions, orientation et compléments (details). Le séjour, le médecin et le type ne changent
     * pas ; les fiches et le bilan ont leurs
     * propres méthodes (ClinicalObservationDao, ShockRoomAssessmentDao). Renvoie false si l'évaluation
     * n'existe pas ou a été retirée.
     *
     * @throws LogicException si l'évaluation n'a pas d'identifiant
     */
    public function update(ClinicalImpression $impression): bool
    {
        $id = $impression->getIdClinicalImpression();
        if ($id === null) {
            throw new LogicException('ClinicalImpressionDao::update() requiert une évaluation enregistrée.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ClinicalImpression
             SET effectiveDateTime = :effectiveDateTime, description = :description,
                 aggressionFactor = :aggressionFactor, restingEnergyExpenditure = :restingEnergyExpenditure,
                 researchProtocols = :researchProtocols, summary = :summary,
                 paraclinicalProposal = :paraclinicalProposal, therapeuticProposal = :therapeuticProposal,
                 orientation = :orientation, details = :details
             WHERE idClinicalImpression = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $id] + $this->editableValues($impression));

        // MariaDB ne compte pas une ligne réécrite à l'identique : on vérifie alors qu'elle existe.
        return $stmt->rowCount() === 1 || $this->isActive($id);
    }

    /**
     * Retire une évaluation (suppression logique, horloge de la base comme Patient.removedAt) : elle, ses fiches
     * et son bilan disparaissent des lectures mais restent en base. Renvoie false si elle n'existe pas ou est
     * déjà retirée.
     */
    public function markRemoved(string $idClinicalImpression): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ClinicalImpression SET removedAt = CURRENT_TIMESTAMP
             WHERE idClinicalImpression = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $idClinicalImpression]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Colonnes modifiables après la saisie (communes à insert() et update()).
     *
     * @return array<string, scalar|null>
     */
    private function editableValues(ClinicalImpression $impression): array
    {
        return [
            'effectiveDateTime' => $impression->getEffectiveDateTime()->format(self::SQL_DATE_FORMAT),
            'description' => $impression->getDescription(),
            'aggressionFactor' => $impression->getAggressionFactor(),
            'restingEnergyExpenditure' => $impression->getRestingEnergyExpenditure(),
            'researchProtocols' => $impression->getResearchProtocols(),
            'summary' => $impression->getSummary(),
            'paraclinicalProposal' => $impression->getParaclinicalProposal(),
            'therapeuticProposal' => $impression->getTherapeuticProposal(),
            'orientation' => $impression->getOrientation(),
            // Colonne JSON : NULL plutôt qu'un objet vide.
            'details' => $impression->getDetails() === []
                ? null
                : json_encode($impression->getDetails(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private function isActive(string $idClinicalImpression): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ClinicalImpression WHERE idClinicalImpression = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $idClinicalImpression]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Plus grand identifiant + 1, table verrouillée (FOR UPDATE) jusqu'à la fin de la transaction.
     */
    private function nextId(): int
    {
        return (int) $this->pdo
            ->query('SELECT COALESCE(MAX(CAST(idClinicalImpression AS UNSIGNED)), 0) + 1
                     FROM ClinicalImpression FOR UPDATE')
            ->fetchColumn();
    }

    /**
     * Colonne JSON details vers tableau : seules les clés connues (ClinicalImpression::DETAIL_KEYS) à valeur texte
     * sont gardées ; NULL ou JSON inattendu donnent [].
     *
     * @return array<string, string>
     */
    private static function decodeDetails(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_filter(
            array_intersect_key($decoded, ClinicalImpression::DETAIL_KEYS),
            static fn (mixed $value): bool => is_string($value)
        );
    }

    /** @param array<string, ?string> $r ligne aux colonnes de COLUMNS */
    private function map(array $r): ClinicalImpression
    {
        $id = (string) $r['idClinicalImpression'];

        return new ClinicalImpression(
            idClinicalImpression: $id,
            idEpisodeOfCare: (int) $r['idEpisodeOfCare'],
            idPractitioner: (string) $r['idPractitioner'],
            kind: (string) $r['kind'],
            effectiveDateTime: new DateTimeImmutable((string) $r['effectiveDateTime']),
            description: $r['description'],
            aggressionFactor: $r['aggressionFactor'] !== null ? (float) $r['aggressionFactor'] : null,
            restingEnergyExpenditure: $r['restingEnergyExpenditure'] !== null
                ? (int) $r['restingEnergyExpenditure']
                : null,
            researchProtocols: $r['researchProtocols'],
            summary: $r['summary'],
            paraclinicalProposal: $r['paraclinicalProposal'],
            therapeuticProposal: $r['therapeuticProposal'],
            orientation: $r['orientation'],
            details: self::decodeDetails($r['details']),
            recordedAt: new DateTimeImmutable((string) $r['recordedAt']),
            removedAt: $r['removedAt'] !== null ? new DateTimeImmutable($r['removedAt']) : null,
            observations: $this->observations->findByClinicalImpression($id),
            shockRoomAssessment: $r['kind'] === ClinicalImpression::KIND_SHOCK_ROOM
                ? $this->shockRoomAssessments->findByClinicalImpression($id)
                : null
        );
    }
}
