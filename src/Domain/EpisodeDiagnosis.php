<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Diagnostic d'un séjour (HL7 FHIR Encounter.diagnosis / Condition ; table EpisodeDiagnosis) : une pathologie du
 * référentiel pendant le séjour, éventuellement issue d'une évaluation médicale. Deux statuts, comme
 * Condition (modèle de données v2) : clinicalStatus (la pathologie est-elle en cours ?) et verificationStatus
 * (le diagnostic est-il établi ?). C'est par la pathologie qu'on rejoint un protocole de soin (PlanDefinition).
 *
 * Une pathologie n'a qu'un diagnostic actif par séjour (contrainte uq_episodediagnosis_pathology).
 *
 * Immuable. Accesseurs get*() : un par colonne, sans validation (elle appartient au service).
 */
final class EpisodeDiagnosis
{
    /** Pathologie en cours. */
    public const CLINICAL_ACTIVE = 'ACTIVE';
    /** Pathologie résolue pendant le séjour. */
    public const CLINICAL_RESOLVED = 'RESOLVED';

    /** clinicalStatus (CHECK chk_episodediagnosis_clinical) => libellé affiché. */
    public const CLINICAL_STATUSES = [
        self::CLINICAL_ACTIVE => 'En cours',
        self::CLINICAL_RESOLVED => 'Résolue',
    ];

    /** Diagnostic évoqué, à confirmer (valeur par défaut). */
    public const VERIFICATION_PROVISIONAL = 'PROVISIONAL';
    /** Diagnostic établi. */
    public const VERIFICATION_CONFIRMED = 'CONFIRMED';
    /** Diagnostic écarté. */
    public const VERIFICATION_REFUTED = 'REFUTED';

    /** verificationStatus (CHECK chk_episodediagnosis_verification) => libellé affiché. */
    public const VERIFICATION_STATUSES = [
        self::VERIFICATION_PROVISIONAL => 'Suspecté',
        self::VERIFICATION_CONFIRMED => 'Confirmé',
        self::VERIFICATION_REFUTED => 'Écarté',
    ];

    public function __construct(
        private ?string $idEpisodeDiagnosis,
        private int $idEpisodeOfCare,
        private string $idPathology,
        private DateTimeImmutable $recordedDate,
        private string $clinicalStatus = self::CLINICAL_ACTIVE,
        private string $verificationStatus = self::VERIFICATION_PROVISIONAL,
        private ?string $idClinicalImpression = null,
        private ?string $note = null,
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

    /** Une des clés de CLINICAL_STATUSES. */
    public function getClinicalStatus(): string
    {
        return $this->clinicalStatus;
    }

    /** Une des clés de VERIFICATION_STATUSES. */
    public function getVerificationStatus(): string
    {
        return $this->verificationStatus;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /** Date d'enregistrement du diagnostic. */
    public function getRecordedDate(): DateTimeImmutable
    {
        return $this->recordedDate;
    }

    /** Suppression logique : null tant que le diagnostic est actif. */
    public function getRemovedAt(): ?DateTimeImmutable
    {
        return $this->removedAt;
    }

    /** Pathologie en cours : non résolue, diagnostic non écarté et non retiré. */
    public function isActive(): bool
    {
        return $this->clinicalStatus === self::CLINICAL_ACTIVE
            && $this->verificationStatus !== self::VERIFICATION_REFUTED
            && $this->removedAt === null;
    }
}
