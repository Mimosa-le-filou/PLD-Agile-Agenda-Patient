<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Évaluation médicale d'un séjour (ressource HL7 ClinicalImpression, table ClinicalImpression) : faite par un
 * médecin, elle regroupe les fiches de suivi de chaque système (ClinicalObservation) et, pour une admission
 * après SMUR / SAMU, le bilan de déchocage (ShockRoomAssessment). Deux types : évaluation classique par
 * systèmes (KIND_STANDARD, figure 2 du cahier des charges) ou fiche de déchocage (KIND_SHOCK_ROOM, figure 3).
 *
 * Les données générales d'examen (poids, taille, IMC, surface corporelle) restent sur le séjour
 * (EpisodeOfCare) : seuls les champs propres à l'évaluation sont ici.
 *
 * Immuable. Accesseurs get*() : un par colonne, sans validation (elle appartient au service).
 */
final class ClinicalImpression
{
    /** Évaluation classique par systèmes (neurologique, cardiovasculaire…). */
    public const KIND_STANDARD = 'STANDARD';
    /** Fiche de déchocage : admission après une intervention du SMUR / SAMU. */
    public const KIND_SHOCK_ROOM = 'SHOCK_ROOM';

    /** Valeurs de la colonne ClinicalImpression.kind (CHECK chk_clinicalimpression_kind). */
    public const KINDS = [self::KIND_STANDARD, self::KIND_SHOCK_ROOM];

    /**
     * @param ClinicalObservation[] $observations fiches de suivi actives, une par système au plus
     */
    public function __construct(
        private ?string $idClinicalImpression,
        private int $idEpisodeOfCare,
        private string $idPractitioner,
        private string $kind,
        private DateTimeImmutable $evaluatedAt,
        private ?string $generalNote = null,
        private ?float $aggressionFactor = null,
        private ?int $restingEnergyExpenditure = null,
        private ?string $conclusion = null,
        private ?string $orientation = null,
        private ?DateTimeImmutable $recordedAt = null,
        private ?DateTimeImmutable $removedAt = null,
        private array $observations = [],
        private ?ShockRoomAssessment $shockRoomAssessment = null
    ) {
    }

    /** Null tant que l'évaluation n'est pas enregistrée. */
    public function getIdClinicalImpression(): ?string
    {
        return $this->idClinicalImpression;
    }

    /** Séjour évalué. */
    public function getIdEpisodeOfCare(): int
    {
        return $this->idEpisodeOfCare;
    }

    /** Médecin qui a réalisé l'évaluation (RG10). */
    public function getIdPractitioner(): string
    {
        return $this->idPractitioner;
    }

    /** KIND_STANDARD ou KIND_SHOCK_ROOM. */
    public function getKind(): string
    {
        return $this->kind;
    }

    /** Heure clinique de l'évaluation, saisie par le médecin. */
    public function getEvaluatedAt(): DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    /** « Évaluation générale (note) ». */
    public function getGeneralNote(): ?string
    {
        return $this->generalNote;
    }

    /** DER : niveau d'agression (facteur correctif, 1 = aucune agression). */
    public function getAggressionFactor(): ?float
    {
        return $this->aggressionFactor;
    }

    /** DER : dépense énergétique au repos, en kcal par jour. */
    public function getRestingEnergyExpenditure(): ?int
    {
        return $this->restingEnergyExpenditure;
    }

    /** Fin de l'évaluation et synthèse (figure 2.c) ou « Conclusion » du déchocage (figure 3.d). */
    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    /** Orientation du patient à l'issue de l'évaluation (« Conclusion et orientation », figure 3.d). */
    public function getOrientation(): ?string
    {
        return $this->orientation;
    }

    /** RG10 : heure de saisie, fixée par la base ; null tant que l'évaluation n'est pas enregistrée. */
    public function getRecordedAt(): ?DateTimeImmutable
    {
        return $this->recordedAt;
    }

    /** Suppression logique : null tant que l'évaluation est active. */
    public function getRemovedAt(): ?DateTimeImmutable
    {
        return $this->removedAt;
    }

    /** @return ClinicalObservation[] */
    public function getObservations(): array
    {
        return $this->observations;
    }

    /** Bilan de déchocage ; null pour une évaluation classique ou un bilan pas encore saisi. */
    public function getShockRoomAssessment(): ?ShockRoomAssessment
    {
        return $this->shockRoomAssessment;
    }

    public function isShockRoom(): bool
    {
        return $this->kind === self::KIND_SHOCK_ROOM;
    }

    public function isRemoved(): bool
    {
        return $this->removedAt !== null;
    }
}
