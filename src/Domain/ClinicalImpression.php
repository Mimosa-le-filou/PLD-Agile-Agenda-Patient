<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Évaluation médicale d'un séjour (ressource HL7 ClinicalImpression, table ClinicalImpression) : faite par un
 * médecin, elle regroupe les fiches de suivi de chaque système (ClinicalObservation) et, pour une admission
 * après SMUR / SAMU, le bilan de déchocage (ShockRoomAssessment). Deux types : évaluation classique par
 * systèmes (KIND_STANDARD, figure 2 du cahier des charges, UC1) ou bilan de déchocage (KIND_SHOCK_ROOM,
 * figure 3, UC2).
 *
 * Noms des attributs repris de FHIR ClinicalImpression (modèle de données v2, sprint 2) : effectiveDateTime
 * (heure clinique), description (« Évaluation générale (note) »), summary (synthèse). Les données générales
 * d'examen (poids, taille, IMC, surface corporelle) restent sur le séjour (EpisodeOfCare).
 *
 * Immuable. Accesseurs get*() : un par colonne, sans validation (elle appartient au service).
 */
final class ClinicalImpression
{
    /** Évaluation classique par systèmes (neurologique, cardiovasculaire…). */
    public const KIND_STANDARD = 'STANDARD';
    /** Bilan de déchocage : admission après une intervention du SMUR / SAMU. */
    public const KIND_SHOCK_ROOM = 'SHOCK_ROOM';

    /** Valeurs de la colonne ClinicalImpression.kind (CHECK chk_clinicalimpression_kind) => libellé affiché. */
    public const KINDS = [
        self::KIND_STANDARD => 'Classique',
        self::KIND_SHOCK_ROOM => 'Déchocage',
    ];

    /**
     * Clés admises dans la colonne JSON details (champs de l'écran du service sans colonne propre) => libellé :
     *  - echocardiography : l'écran cardiovasculaire a deux interprétations ; l'ECG va dans
     *    ClinicalObservation.interpretation, l'échocardiographie ici ;
     *  - ventilationMode : « VS/VC » (clé de ClinicalScales::VENTILATION_MODES) ;
     *  - initiallyCompletedBy : praticien qui a commencé le document (idPractitioner), distinct du médecin qui
     *    valide l'évaluation (idPractitioner de l'évaluation) ;
     *  - mgAnamnesis, mgSummary1, mgSummary2 : « MG - Anamnèse », « MG - Synthèse 1 » et « 2 » de la
     *    conclusion.
     */
    public const DETAIL_KEYS = [
        'echocardiography' => 'Interprétation de l\'échocardiographie',
        'ventilationMode' => 'VS/VC',
        'initiallyCompletedBy' => 'Document initialement complété par',
        'mgAnamnesis' => 'MG - Anamnèse',
        'mgSummary1' => 'MG - Synthèse 1',
        'mgSummary2' => 'MG - Synthèse 2',
    ];

    /**
     * @param array<string, string> $details compléments structurés (clés de DETAIL_KEYS), [] si aucun
     * @param ClinicalObservation[] $observations fiches de suivi actives, une par système au plus
     */
    public function __construct(
        private ?string $idClinicalImpression,
        private int $idEpisodeOfCare,
        private string $idPractitioner,
        private string $kind,
        private DateTimeImmutable $effectiveDateTime,
        private ?string $description = null,
        private ?float $aggressionFactor = null,
        private ?int $restingEnergyExpenditure = null,
        private ?string $researchProtocols = null,
        private ?string $summary = null,
        private ?string $paraclinicalProposal = null,
        private ?string $therapeuticProposal = null,
        private ?string $orientation = null,
        private array $details = [],
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
    public function getEffectiveDateTime(): DateTimeImmutable
    {
        return $this->effectiveDateTime;
    }

    /** « Évaluation générale (note) ». */
    public function getDescription(): ?string
    {
        return $this->description;
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

    /** Protocoles de recherche dans lesquels le patient est inclus. */
    public function getResearchProtocols(): ?string
    {
        return $this->researchProtocols;
    }

    /** Fin de l'évaluation et synthèse (figure 2.c) ou « Conclusion » du déchocage (figure 3.d). */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** Examens complémentaires proposés. */
    public function getParaclinicalProposal(): ?string
    {
        return $this->paraclinicalProposal;
    }

    /** Conduite thérapeutique proposée. */
    public function getTherapeuticProposal(): ?string
    {
        return $this->therapeuticProposal;
    }

    /** Orientation du patient à l'issue de l'évaluation (« Conclusion et orientation », figure 3.d). */
    public function getOrientation(): ?string
    {
        return $this->orientation;
    }

    /** @return array<string, string> compléments structurés (clés de DETAIL_KEYS) */
    public function getDetails(): array
    {
        return $this->details;
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
