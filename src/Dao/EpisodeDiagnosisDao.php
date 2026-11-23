<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\EpisodeDiagnosis;
use Aura\Domain\Pathology;
use DateTimeImmutable;
use PDO;

/**
 * Accès aux diagnostics des séjours (table EpisodeDiagnosis, HL7 FHIR Encounter.diagnosis) et au référentiel des
 * pathologies qu'ils retiennent (table Pathology, lecture seule).
 *
 * Seuls les diagnostics actifs (removedAt IS NULL) sont lus ; retirer un diagnostic garde sa ligne (RG10) et
 * permet de ressaisir la même pathologie (colonne générée activeKey de uq_episodediagnosis_pathology).
 *
 * Ids VARCHAR(20) sans AUTO_INCREMENT, calculés par nextId() (MAX + 1, FOR UPDATE) dans la transaction d'écriture.
 */
class EpisodeDiagnosisDao
{
    private const SQL_DATE_FORMAT = 'Y-m-d H:i:s';

    private const COLUMNS = 'idEpisodeDiagnosis, idEpisodeOfCare, idClinicalImpression, idPathology, status, notes,
        diagnosedAt, removedAt';

    public function __construct(private PDO $pdo)
    {
    }

    /** Diagnostic actif, ou null s'il n'existe pas ou a été retiré. */
    public function findById(string $idEpisodeDiagnosis): ?EpisodeDiagnosis
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM EpisodeDiagnosis
             WHERE idEpisodeDiagnosis = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $idEpisodeDiagnosis]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /**
     * Diagnostics actifs d'un séjour, du plus récent au plus ancien.
     *
     * @return EpisodeDiagnosis[]
     */
    public function findByEpisodeOfCare(int $idEpisodeOfCare): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM EpisodeDiagnosis
             WHERE idEpisodeOfCare = :idEpisodeOfCare AND removedAt IS NULL
             ORDER BY diagnosedAt DESC, CAST(idEpisodeDiagnosis AS UNSIGNED) DESC'
        );
        $stmt->execute(['idEpisodeOfCare' => (string) $idEpisodeOfCare]);

        return array_map(fn(array $r) => $this->map($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Diagnostics actifs posés lors d'une évaluation, du plus récent au plus ancien.
     *
     * @return EpisodeDiagnosis[]
     */
    public function findByClinicalImpression(string $idClinicalImpression): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM EpisodeDiagnosis
             WHERE idClinicalImpression = :id AND removedAt IS NULL
             ORDER BY diagnosedAt DESC, CAST(idEpisodeDiagnosis AS UNSIGNED) DESC'
        );
        $stmt->execute(['id' => $idClinicalImpression]);

        return array_map(fn(array $r) => $this->map($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Référentiel des pathologies, par ordre alphabétique (liste déroulante du diagnostic).
     *
     * @return Pathology[]
     */
    public function pathologies(): array
    {
        $rows = $this->pdo->query('SELECT idPathology, label FROM Pathology ORDER BY label')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $r) => new Pathology((string) $r['idPathology'], (string) $r['label']),
            $rows
        );
    }

    /**
     * Enregistre un diagnostic et renvoie son identifiant.
     *
     * @throws \PDOException contrainte refusée (statut inconnu, pathologie déjà diagnostiquée sur le séjour,
     *                       séjour, évaluation ou pathologie absents) : rien n'est enregistré
     */
    public function insert(EpisodeDiagnosis $diagnosis): string
    {
        return (new Transaction($this->pdo))->run(function () use ($diagnosis): string {
            $id = (string) $this->nextId();
            $this->pdo->prepare(
                'INSERT INTO EpisodeDiagnosis (idEpisodeDiagnosis, idEpisodeOfCare, idClinicalImpression,
                                               idPathology, status, notes, diagnosedAt)
                 VALUES (:id, :idEpisodeOfCare, :idClinicalImpression, :idPathology, :status, :notes,
                         :diagnosedAt)'
            )->execute([
                'id' => $id,
                'idEpisodeOfCare' => (string) $diagnosis->getIdEpisodeOfCare(),
                'idClinicalImpression' => $diagnosis->getIdClinicalImpression(),
                'idPathology' => $diagnosis->getIdPathology(),
                'status' => $diagnosis->getStatus(),
                'notes' => $diagnosis->getNotes(),
                'diagnosedAt' => $diagnosis->getDiagnosedAt()->format(self::SQL_DATE_FORMAT),
            ]);

            return $id;
        });
    }

    /**
     * Change le statut et la note d'un diagnostic actif (suspecté puis retenu, retenu puis résolu…). Renvoie
     * false s'il n'existe pas ou a été retiré.
     *
     * @throws \PDOException statut inconnu (CHECK chk_episodediagnosis_status)
     */
    public function updateStatus(string $idEpisodeDiagnosis, string $status, ?string $notes): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeDiagnosis SET status = :status, notes = :notes
             WHERE idEpisodeDiagnosis = :id AND removedAt IS NULL'
        );
        $stmt->execute(['status' => $status, 'notes' => $notes, 'id' => $idEpisodeDiagnosis]);

        // MariaDB ne compte pas une ligne réécrite à l'identique : on vérifie alors qu'elle existe.
        return $stmt->rowCount() === 1 || $this->findById($idEpisodeDiagnosis) !== null;
    }

    /**
     * Retire un diagnostic (suppression logique, horloge de la base comme Patient.removedAt). Renvoie false s'il
     * n'existe pas ou est déjà retiré.
     */
    public function markRemoved(string $idEpisodeDiagnosis): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeDiagnosis SET removedAt = CURRENT_TIMESTAMP
             WHERE idEpisodeDiagnosis = :id AND removedAt IS NULL'
        );
        $stmt->execute(['id' => $idEpisodeDiagnosis]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Plus grand identifiant + 1, table verrouillée (FOR UPDATE) jusqu'à la fin de la transaction.
     */
    private function nextId(): int
    {
        return (int) $this->pdo
            ->query('SELECT COALESCE(MAX(CAST(idEpisodeDiagnosis AS UNSIGNED)), 0) + 1
                     FROM EpisodeDiagnosis FOR UPDATE')
            ->fetchColumn();
    }

    /** @param array<string, ?string> $r ligne aux colonnes de COLUMNS */
    private function map(array $r): EpisodeDiagnosis
    {
        return new EpisodeDiagnosis(
            idEpisodeDiagnosis: (string) $r['idEpisodeDiagnosis'],
            idEpisodeOfCare: (int) $r['idEpisodeOfCare'],
            idPathology: (string) $r['idPathology'],
            status: (string) $r['status'],
            diagnosedAt: new DateTimeImmutable((string) $r['diagnosedAt']),
            idClinicalImpression: $r['idClinicalImpression'],
            notes: $r['notes'],
            removedAt: $r['removedAt'] !== null ? new DateTimeImmutable($r['removedAt']) : null
        );
    }
}
