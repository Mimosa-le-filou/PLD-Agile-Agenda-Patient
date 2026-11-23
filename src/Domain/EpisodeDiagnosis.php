<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Diagnostic d'un séjour (HL7 FHIR Encounter.diagnosis, statut inspiré de Condition.verificationStatus et
 * clinicalStatus ; table EpisodeDiagnosis) : une pathologie du référentiel retenue pour le séjour, éventuellement
 * issue d'une évaluation médicale. C'est par la pathologie qu'on rejoint un protocole de soin (PlanDefinition).
 *
 * Une pathologie n'a qu'un diagnostic actif par séjour (contrainte uq_episodediagnosis_pathology).
 *
 * Immuable. Accesseurs get*() : un par colonne, sans validation (elle appartient au service).
 */
final class EpisodeDiagnosis
{
    /** Diagnostic retenu, pathologie en cours. */
    public const STATUS_ACTIVE = 'ACTIVE';
    /** Diagnostic évoqué, non confirmé. */
    public const STATUS_SUSPECTED = 'SUSPECTED';
    /** Pathologie résolue pendant le séjour. */
    public const STATUS_RESOLVED = 'RESOLVED';
    /** Diagnostic écarté. */
    public const STATUS_REFUTED = 'REFUTED';

    /** Statuts (colonne EpisodeDiagnosis.status, CHECK chk_episodediagnosis_status) => libellé affiché. */
    public const STATUSES = [
        self::STATUS_ACTIVE => 'Retenu',
        self::STATUS_SUSPECTED => 'Suspecté',
        self::STATUS_RESOLVED => 'Résolu',
        self::STATUS_REFUTED => 'Écarté',
    ];

    public function __construct(
        private ?string $idEpisodeDiagnosis,
        private int $idEpisodeOfCare,
        private string $idPathology,
        private string $status,
        private DateTimeImmutable $diagnosedAt,
        private ?string $idClinicalImpression = null,
        private ?string $notes = null,
        private ?DateTimeImmutable $removedAt = null
    ) {
    }

    /** Null tant que le diagnostic n'est pas enregistré. */
    public function getIdEpisodeDiagnosis(): ?string
    {
        return $this->idEpisodeDiagnosis;
    }

    public function getIdEpisodeOfCare(): int
    {
        return $this->idEpisodeOfCare;
    }

    /** Évaluation d'où vient le diagnostic ; null = saisi hors évaluation. */
    public function getIdClinicalImpression(): ?string
    {
        return $this->idClinicalImpression;
    }

    public function getIdPathology(): string
    {
        return $this->idPathology;
    }

    /** Une des clés de STATUSES. */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getDiagnosedAt(): DateTimeImmutable
    {
        return $this->diagnosedAt;
    }

    /** Suppression logique : null tant que le diagnostic est actif. */
    public function getRemovedAt(): ?DateTimeImmutable
    {
        return $this->removedAt;
    }

    /** Pathologie en cours : diagnostic retenu et non retiré. */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->removedAt === null;
    }
}
