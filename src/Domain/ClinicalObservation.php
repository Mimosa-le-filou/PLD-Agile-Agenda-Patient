<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Fiche de suivi d'un système physiologique (ressource HL7 Observation, table ClinicalObservation) : un élément
 * d'une évaluation médicale (figure 2.b du cahier des charges). Chaque système a une valeur de liste normalisée
 * (« (LISTE) », ClinicalScales::OBSERVATION_LISTS), une note libre (« (NOTES) ») et, pour certains, une
 * interprétation d'examen : ECG et échocardiographie en cardiovasculaire, Rx thoracique en respiratoire.
 *
 * Une seule fiche active par système et par évaluation (contrainte uq_clinicalobservation_category).
 *
 * Immuable. Accesseurs get*() : un par colonne, sans validation (elle appartient au service).
 */
final class ClinicalObservation
{
    public const CATEGORY_SEDATION = 'SEDATION';
    public const CATEGORY_NEUROLOGICAL = 'NEUROLOGICAL';
    public const CATEGORY_CARDIOVASCULAR = 'CARDIOVASCULAR';
    public const CATEGORY_HEMODYNAMIC = 'HEMODYNAMIC';
    public const CATEGORY_RESPIRATORY = 'RESPIRATORY';
    public const CATEGORY_ABDOMINAL = 'ABDOMINAL';
    public const CATEGORY_URORENAL = 'URORENAL';

    /**
     * Systèmes dans l'ordre de l'écran (figure 2.b) : code (colonne ClinicalObservation.category, CHECK
     * chk_clinicalobservation_category) => libellé affiché. La sédation est rattachée au neurologique.
     */
    public const CATEGORIES = [
        self::CATEGORY_SEDATION => 'Sédation',
        self::CATEGORY_NEUROLOGICAL => 'Neurologique',
        self::CATEGORY_CARDIOVASCULAR => 'Cardiovasculaire',
        self::CATEGORY_HEMODYNAMIC => 'Hémodynamique',
        self::CATEGORY_RESPIRATORY => 'Respiratoire et pulmonaire',
        self::CATEGORY_ABDOMINAL => 'Abdominale',
        self::CATEGORY_URORENAL => 'Uro-rénale',
    ];

    public function __construct(
        private ?string $idClinicalObservation,
        private string $idClinicalImpression,
        private string $category,
        private DateTimeImmutable $observedAt,
        private ?string $selectedValue = null,
        private ?string $notes = null,
        private ?string $examInterpretation = null,
        private ?DateTimeImmutable $removedAt = null
    ) {
    }

    /** Null tant que la fiche n'est pas enregistrée. */
    public function getIdClinicalObservation(): ?string
    {
        return $this->idClinicalObservation;
    }

    /**
     * Évaluation de la fiche. Vide ('') pour une fiche construite avant son évaluation :
     * ClinicalImpressionDao::insert() l'enregistre avec l'identifiant de l'évaluation créée.
     */
    public function getIdClinicalImpression(): string
    {
        return $this->idClinicalImpression;
    }

    /** Une des clés de CATEGORIES. */
    public function getCategory(): string
    {
        return $this->category;
    }

    public function getObservedAt(): DateTimeImmutable
    {
        return $this->observedAt;
    }

    /** Valeur choisie dans la liste normalisée du système (clé de ClinicalScales::OBSERVATION_LISTS[category]). */
    public function getSelectedValue(): ?string
    {
        return $this->selectedValue;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /** Interprétation d'examen (ECG, échocardiographie, Rx thoracique). */
    public function getExamInterpretation(): ?string
    {
        return $this->examInterpretation;
    }

    /** Suppression logique : null tant que la fiche est active. */
    public function getRemovedAt(): ?DateTimeImmutable
    {
        return $this->removedAt;
    }
}
