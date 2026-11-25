<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Fiche de suivi d'un système physiologique (ressource HL7 Observation, table ClinicalObservation) : un élément
 * d'une évaluation médicale (figure 2.b du cahier des charges). Chaque système a une valeur de liste normalisée
 * (« (LISTE) », valueCodeableConcept, ClinicalScales::OBSERVATION_LISTS), une note libre (« (NOTES) », note) et,
 * pour certains, une interprétation d'examen (interpretation) : ECG en cardiovasculaire, Rx thoracique en
 * respiratoire.
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
    public const CATEGORY_CUTANEOUS = 'CUTANEOUS';
    public const CATEGORY_OSTEOARTICULAR = 'OSTEOARTICULAR';
    public const CATEGORY_METABOLIC = 'METABOLIC';
    public const CATEGORY_INFECTIOUS = 'INFECTIOUS';
    public const CATEGORY_BMR_SCREENING = 'BMR_SCREENING';
    public const CATEGORY_ABNORMAL_BIOLOGY = 'ABNORMAL_BIOLOGY';

    /**
     * Systèmes dans l'ordre de l'écran : code (colonne ClinicalObservation.category, CHECK
     * chk_clinicalobservation_category) => libellé affiché. Les sept premiers sont ceux de la figure 2.b ; les
     * suivants sont les « Autres informations » de l'écran du service (mail du 24/11/2026).
     */
    public const CATEGORIES = [
        self::CATEGORY_SEDATION => 'Sédation',
        self::CATEGORY_NEUROLOGICAL => 'Neurologique',
        self::CATEGORY_CARDIOVASCULAR => 'Cardiovasculaire',
        self::CATEGORY_HEMODYNAMIC => 'Hémodynamique',
        self::CATEGORY_RESPIRATORY => 'Respiratoire et pulmonaire',
        self::CATEGORY_ABDOMINAL => 'Abdominale',
        self::CATEGORY_URORENAL => 'Uro-rénale',
        self::CATEGORY_CUTANEOUS => 'Cutanéo-muqueuse',
        self::CATEGORY_OSTEOARTICULAR => 'Ostéo-articulaire',
        self::CATEGORY_METABOLIC => 'État métabolique',
        self::CATEGORY_INFECTIOUS => 'État infectieux',
        self::CATEGORY_BMR_SCREENING => 'Dépistage BMR',
        self::CATEGORY_ABNORMAL_BIOLOGY => 'Marqueurs biologiques anormaux',
    ];

    /**
     * Systèmes saisis dans l'évaluation classique (UC1), dans l'ordre de l'écran : les sept systèmes physiologiques
     * (liste normalisée et notes), puis les six « Autres informations » (notes seulement).
     */
    public const STANDARD_FORM = [
        self::CATEGORY_SEDATION,
        self::CATEGORY_NEUROLOGICAL,
        self::CATEGORY_CARDIOVASCULAR,
        self::CATEGORY_HEMODYNAMIC,
        self::CATEGORY_RESPIRATORY,
        self::CATEGORY_ABDOMINAL,
        self::CATEGORY_URORENAL,
        self::CATEGORY_CUTANEOUS,
        self::CATEGORY_OSTEOARTICULAR,
        self::CATEGORY_METABOLIC,
        self::CATEGORY_INFECTIOUS,
        self::CATEGORY_BMR_SCREENING,
        self::CATEGORY_ABNORMAL_BIOLOGY,
    ];

    /** « Autres informations » de l'évaluation classique : texte libre seulement, sans liste normalisée. */
    public const OTHER_INFORMATION = [
        self::CATEGORY_CUTANEOUS,
        self::CATEGORY_OSTEOARTICULAR,
        self::CATEGORY_METABOLIC,
        self::CATEGORY_INFECTIOUS,
        self::CATEGORY_BMR_SCREENING,
        self::CATEGORY_ABNORMAL_BIOLOGY,
    ];

    /**
     * Libellé de l'interprétation d'examen, pour les systèmes qui en ont une (figure 2.b). L'échocardiographie,
     * seconde interprétation du cardiovasculaire, est dans ClinicalImpression::DETAIL_KEYS.
     */
    public const INTERPRETATIONS = [
        self::CATEGORY_CARDIOVASCULAR => 'Interprétation de l\'ECG',
        self::CATEGORY_RESPIRATORY => 'Interprétation Rx thoracique',
    ];

    public function __construct(
        private ?string $idClinicalObservation,
        private string $idClinicalImpression,
        private string $category,
        private DateTimeImmutable $effectiveDateTime,
        private ?string $valueCodeableConcept = null,
        private ?string $note = null,
        private ?string $interpretation = null,
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

    /** Heure de l'observation (celle de l'évaluation à la création). */
    public function getEffectiveDateTime(): DateTimeImmutable
    {
        return $this->effectiveDateTime;
    }

    /** Valeur choisie dans la liste normalisée du système (clé de ClinicalScales::OBSERVATION_LISTS[category]). */
    public function getValueCodeableConcept(): ?string
    {
        return $this->valueCodeableConcept;
    }

    /** « (NOTES) » du système. */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /** Interprétation d'examen (ECG, Rx thoracique). */
    public function getInterpretation(): ?string
    {
        return $this->interpretation;
    }

    /** Suppression logique : null tant que la fiche est active. */
    public function getRemovedAt(): ?DateTimeImmutable
    {
        return $this->removedAt;
    }
}
