<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Bilan de déchocage (figure 3 du cahier des charges, table ShockRoomAssessment) d'une évaluation de type
 * ClinicalImpression::KIND_SHOCK_ROOM : observation médicale à l'arrivée et prise en charge SMUR (3.a), équipe et
 * examen clinique au déchocage (3.b), bilans (3.c), avis extérieurs et score IGS II (3.d). Au plus un par
 * évaluation (contrainte uq_shockroom_impression). Pas de suppression propre : il suit son évaluation.
 *
 * Tous les champs cliniques sont facultatifs : la fiche se complète au fil de la prise en charge.
 *
 * Immuable. Accesseurs get*() : un par colonne, sans validation (elle appartient au service).
 */
final class ShockRoomAssessment
{
    /** Champs cliniques du bilan, dans l'ordre de l'écran (noms de colonnes et de paramètres du constructeur). */
    public const FIELDS = [
        'arrivalGrade',
        'traumaType',
        'traumaMechanism',
        'originService',
        'smurDurationMinutes',
        'glasgowScore',
        'hemocue1',
        'hemocue2',
        'smurTreatments',
        'crystalloidsVolumeMl',
        'shockRoomDurationMinutes',
        'seniorDoctor',
        'residentDoctor',
        'medicalStudent',
        'efastResult',
        'leftPupil',
        'rightPupil',
        'capillaryRefillSeconds',
        'lesionAssessment',
        'paraclinicalWorkup',
        'ctScanWorkup',
        'otherImaging',
        'abnormalLabResults',
        'visceralConsult',
        'orthopedicConsult',
        'vascularConsult',
        'otherConsults',
        'igs2Score',
    ];

    public function __construct(
        private ?string $idShockRoomAssessment,
        private string $idClinicalImpression,
        private ?string $arrivalGrade = null,
        private ?string $traumaType = null,
        private ?string $traumaMechanism = null,
        private ?string $originService = null,
        private ?int $smurDurationMinutes = null,
        private ?int $glasgowScore = null,
        private ?float $hemocue1 = null,
        private ?float $hemocue2 = null,
        private ?string $smurTreatments = null,
        private ?int $crystalloidsVolumeMl = null,
        private ?int $shockRoomDurationMinutes = null,
        private ?string $seniorDoctor = null,
        private ?string $residentDoctor = null,
        private ?string $medicalStudent = null,
        private ?string $efastResult = null,
        private ?string $leftPupil = null,
        private ?string $rightPupil = null,
        private ?float $capillaryRefillSeconds = null,
        private ?string $lesionAssessment = null,
        private ?string $paraclinicalWorkup = null,
        private ?string $ctScanWorkup = null,
        private ?string $otherImaging = null,
        private ?string $abnormalLabResults = null,
        private ?string $visceralConsult = null,
        private ?string $orthopedicConsult = null,
        private ?string $vascularConsult = null,
        private ?string $otherConsults = null,
        private ?int $igs2Score = null
    ) {
    }

    /** Null tant que le bilan n'est pas enregistré. */
    public function getIdShockRoomAssessment(): ?string
    {
        return $this->idShockRoomAssessment;
    }

    /**
     * Évaluation du bilan. Vide ('') pour un bilan construit avant son évaluation :
     * ClinicalImpressionDao::insert() l'enregistre avec l'identifiant de l'évaluation créée.
     */
    public function getIdClinicalImpression(): string
    {
        return $this->idClinicalImpression;
    }

    /** Grade TRENAU à l'arrivée (clé de ClinicalScales::ARRIVAL_GRADES). */
    public function getArrivalGrade(): ?string
    {
        return $this->arrivalGrade;
    }

    /** Type de traumatisme. */
    public function getTraumaType(): ?string
    {
        return $this->traumaType;
    }

    /** Mécanisme du traumatisme. */
    public function getTraumaMechanism(): ?string
    {
        return $this->traumaMechanism;
    }

    /** Service de provenance. */
    public function getOriginService(): ?string
    {
        return $this->originService;
    }

    /** Durée de la prise en charge SMUR, en minutes. */
    public function getSmurDurationMinutes(): ?int
    {
        return $this->smurDurationMinutes;
    }

    /** Score de Glasgow, 3 à 15 (ClinicalScales::GLASGOW). */
    public function getGlasgowScore(): ?int
    {
        return $this->glasgowScore;
    }

    /** Hémocue n° 1 (hémoglobine capillaire), en g/dL. */
    public function getHemocue1(): ?float
    {
        return $this->hemocue1;
    }

    /** Hémocue n° 2, en g/dL. */
    public function getHemocue2(): ?float
    {
        return $this->hemocue2;
    }

    /** Traitements administrés par le SMUR. */
    public function getSmurTreatments(): ?string
    {
        return $this->smurTreatments;
    }

    /** Quantité de cristalloïdes reçue, en mL. */
    public function getCrystalloidsVolumeMl(): ?int
    {
        return $this->crystalloidsVolumeMl;
    }

    /** Durée de la prise en charge au déchocage, en minutes. */
    public function getShockRoomDurationMinutes(): ?int
    {
        return $this->shockRoomDurationMinutes;
    }

    /** Réanimateur sénior. */
    public function getSeniorDoctor(): ?string
    {
        return $this->seniorDoctor;
    }

    /** Interne de réanimation. */
    public function getResidentDoctor(): ?string
    {
        return $this->residentDoctor;
    }

    /** Externe présent au déchocage. */
    public function getMedicalStudent(): ?string
    {
        return $this->medicalStudent;
    }

    /** Résultat de l'eFAST (échographie de débrouillage du traumatisé). */
    public function getEfastResult(): ?string
    {
        return $this->efastResult;
    }

    /** Pupille gauche. */
    public function getLeftPupil(): ?string
    {
        return $this->leftPupil;
    }

    /** Pupille droite. */
    public function getRightPupil(): ?string
    {
        return $this->rightPupil;
    }

    /** Temps de recoloration cutanée, en secondes. */
    public function getCapillaryRefillSeconds(): ?float
    {
        return $this->capillaryRefillSeconds;
    }

    /** Bilan lésionnel complet. */
    public function getLesionAssessment(): ?string
    {
        return $this->lesionAssessment;
    }

    /** Bilan paraclinique. */
    public function getParaclinicalWorkup(): ?string
    {
        return $this->paraclinicalWorkup;
    }

    /** Bilan scanographique. */
    public function getCtScanWorkup(): ?string
    {
        return $this->ctScanWorkup;
    }

    /** Imagerie autre. */
    public function getOtherImaging(): ?string
    {
        return $this->otherImaging;
    }

    /** Bilans biologiques anormaux. */
    public function getAbnormalLabResults(): ?string
    {
        return $this->abnormalLabResults;
    }

    /** Avis viscéral. */
    public function getVisceralConsult(): ?string
    {
        return $this->visceralConsult;
    }

    /** Avis orthopédique. */
    public function getOrthopedicConsult(): ?string
    {
        return $this->orthopedicConsult;
    }

    /** Avis vasculaire. */
    public function getVascularConsult(): ?string
    {
        return $this->vascularConsult;
    }

    /** Avis autres. */
    public function getOtherConsults(): ?string
    {
        return $this->otherConsults;
    }

    /** Score IGS II, 0 à 163 (ClinicalScales::IGS2). */
    public function getIgs2Score(): ?int
    {
        return $this->igs2Score;
    }
}
