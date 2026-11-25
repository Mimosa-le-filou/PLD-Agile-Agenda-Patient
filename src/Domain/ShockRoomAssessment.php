<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Bilan de déchocage (figure 3 du cahier des charges, table ShockRoomAssessment) d'une évaluation de type
 * ClinicalImpression::KIND_SHOCK_ROOM : observation médicale à l'arrivée et prise en charge SMUR (3.a), équipe et
 * examen clinique au déchocage (3.b), bilans, imagerie et produits sanguins (3.c), avis extérieurs, ISS et IGS II
 * (3.d). Au plus un par évaluation (contrainte uq_shockroom_impression). Pas de suppression propre : il suit son
 * évaluation. Colonnes du modèle de données v2 (sprint 2, 2026-11-25).
 *
 * Tous les champs cliniques sont facultatifs : la fiche se complète au fil de la prise en charge.
 *
 * Immuable. Accesseurs get*() : un par colonne, sans validation (elle appartient au service).
 */
final class ShockRoomAssessment
{
    /**
     * Champs cliniques du bilan, dans l'ordre de l'écran et de la table : nom de colonne (et de paramètre du
     * constructeur) => type PHP (int, float, string, ou array pour une colonne JSON). Sert au DAO pour lire et
     * écrire chaque colonne.
     */
    public const FIELDS = [
        'arrivalGrade' => 'string',
        'traumaType' => 'string',
        'traumaMechanism' => 'string',
        'originService' => 'string',
        'antithromboticTreatment' => 'string',
        'smurDurationMinutes' => 'int',
        'initialSystolicBp' => 'int',
        'initialRespiratoryRate' => 'int',
        'glasgowScore' => 'int',
        'hemocue1' => 'float',
        'hemocue2' => 'float',
        'smurTreatments' => 'string',
        'crystalloidsVolumeMl' => 'int',
        'colloidsVolumeMl' => 'int',
        'mannitol20VolumeMl' => 'int',
        'aminesType' => 'string',
        'shockRoomDurationMinutes' => 'int',
        'seniorIntensivist' => 'string',
        'icuResident' => 'string',
        'medicalStudent' => 'string',
        'leftPupil' => 'string',
        'rightPupil' => 'string',
        'capillaryRefillSeconds' => 'float',
        'clinicalExamNote' => 'string',
        'efastResult' => 'string',
        'transcranialDoppler' => 'string',
        'chestXray' => 'string',
        'pelvisXray' => 'string',
        'rbcUnits' => 'int',
        'ffpUnits' => 'int',
        'otherBloodProducts' => 'string',
        'ctScanAssessment' => 'string',
        'otherImaging' => 'string',
        'rotemResult' => 'string',
        'abnormalBiology' => 'string',
        'cutaneousWounds' => 'string',
        'issScore' => 'int',
        'visceralConsult' => 'string',
        'orthopedicConsult' => 'string',
        'neurosurgicalConsult' => 'string',
        'vascularConsult' => 'string',
        'otherConsults' => 'string',
        'lesionDetails' => 'array',
        'igs2Score' => 'int',
    ];

    /**
     * @param array<int|string, mixed> $lesionDetails bilan lésionnel structuré (JSON), [] si non saisi
     */
    public function __construct(
        private ?string $idShockRoomAssessment,
        private string $idClinicalImpression,
        private ?string $arrivalGrade = null,
        private ?string $traumaType = null,
        private ?string $traumaMechanism = null,
        private ?string $originService = null,
        private ?string $antithromboticTreatment = null,
        private ?int $smurDurationMinutes = null,
        private ?int $initialSystolicBp = null,
        private ?int $initialRespiratoryRate = null,
        private ?int $glasgowScore = null,
        private ?float $hemocue1 = null,
        private ?float $hemocue2 = null,
        private ?string $smurTreatments = null,
        private ?int $crystalloidsVolumeMl = null,
        private ?int $colloidsVolumeMl = null,
        private ?int $mannitol20VolumeMl = null,
        private ?string $aminesType = null,
        private ?int $shockRoomDurationMinutes = null,
        private ?string $seniorIntensivist = null,
        private ?string $icuResident = null,
        private ?string $medicalStudent = null,
        private ?string $leftPupil = null,
        private ?string $rightPupil = null,
        private ?float $capillaryRefillSeconds = null,
        private ?string $clinicalExamNote = null,
        private ?string $efastResult = null,
        private ?string $transcranialDoppler = null,
        private ?string $chestXray = null,
        private ?string $pelvisXray = null,
        private ?int $rbcUnits = null,
        private ?int $ffpUnits = null,
        private ?string $otherBloodProducts = null,
        private ?string $ctScanAssessment = null,
        private ?string $otherImaging = null,
        private ?string $rotemResult = null,
        private ?string $abnormalBiology = null,
        private ?string $cutaneousWounds = null,
        private ?int $issScore = null,
        private ?string $visceralConsult = null,
        private ?string $orthopedicConsult = null,
        private ?string $neurosurgicalConsult = null,
        private ?string $vascularConsult = null,
        private ?string $otherConsults = null,
        private array $lesionDetails = [],
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

    /** Traitement antithrombotique en cours (anticoagulant, antiagrégant). */
    public function getAntithromboticTreatment(): ?string
    {
        return $this->antithromboticTreatment;
    }

    /** Durée de la prise en charge SMUR, en minutes. */
    public function getSmurDurationMinutes(): ?int
    {
        return $this->smurDurationMinutes;
    }

    /** Pression artérielle systolique initiale (SMUR), en mmHg. */
    public function getInitialSystolicBp(): ?int
    {
        return $this->initialSystolicBp;
    }

    /** Fréquence respiratoire initiale (SMUR), en cycles par minute. */
    public function getInitialRespiratoryRate(): ?int
    {
        return $this->initialRespiratoryRate;
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

    /** Volume de cristalloïdes administré, en mL. */
    public function getCrystalloidsVolumeMl(): ?int
    {
        return $this->crystalloidsVolumeMl;
    }

    /** Volume de colloïdes administré, en mL. */
    public function getColloidsVolumeMl(): ?int
    {
        return $this->colloidsVolumeMl;
    }

    /** Volume de mannitol 20 % administré, en mL. */
    public function getMannitol20VolumeMl(): ?int
    {
        return $this->mannitol20VolumeMl;
    }

    /** Amines (catécholamines) administrées. */
    public function getAminesType(): ?string
    {
        return $this->aminesType;
    }

    /** Durée du passage en salle de déchocage, en minutes. */
    public function getShockRoomDurationMinutes(): ?int
    {
        return $this->shockRoomDurationMinutes;
    }

    /** Réanimateur sénior présent. */
    public function getSeniorIntensivist(): ?string
    {
        return $this->seniorIntensivist;
    }

    /** Interne de réanimation présent. */
    public function getIcuResident(): ?string
    {
        return $this->icuResident;
    }

    /** Externe présent. */
    public function getMedicalStudent(): ?string
    {
        return $this->medicalStudent;
    }

    /** Pupille gauche (taille, réactivité). */
    public function getLeftPupil(): ?string
    {
        return $this->leftPupil;
    }

    /** Pupille droite (taille, réactivité). */
    public function getRightPupil(): ?string
    {
        return $this->rightPupil;
    }

    /** Temps de recoloration cutanée (TRC), en secondes. */
    public function getCapillaryRefillSeconds(): ?float
    {
        return $this->capillaryRefillSeconds;
    }

    /** Examen clinique au déchocage (texte libre). */
    public function getClinicalExamNote(): ?string
    {
        return $this->clinicalExamNote;
    }

    /** Résultat de l'échographie eFAST. */
    public function getEfastResult(): ?string
    {
        return $this->efastResult;
    }

    /** Doppler transcrânien. */
    public function getTranscranialDoppler(): ?string
    {
        return $this->transcranialDoppler;
    }

    /** Radiographie thoracique. */
    public function getChestXray(): ?string
    {
        return $this->chestXray;
    }

    /** Radiographie du bassin. */
    public function getPelvisXray(): ?string
    {
        return $this->pelvisXray;
    }

    /** Culots globulaires (CGR) transfusés, en unités. */
    public function getRbcUnits(): ?int
    {
        return $this->rbcUnits;
    }

    /** Plasmas frais congelés (PFC) transfusés, en unités. */
    public function getFfpUnits(): ?int
    {
        return $this->ffpUnits;
    }

    /** Autres produits sanguins (plaquettes, fibrinogène…). */
    public function getOtherBloodProducts(): ?string
    {
        return $this->otherBloodProducts;
    }

    /** Bilan scannographique. */
    public function getCtScanAssessment(): ?string
    {
        return $this->ctScanAssessment;
    }

    /** Autre imagerie. */
    public function getOtherImaging(): ?string
    {
        return $this->otherImaging;
    }

    /** Résultat du ROTEM (thromboélastométrie). */
    public function getRotemResult(): ?string
    {
        return $this->rotemResult;
    }

    /** Biologie anormale. */
    public function getAbnormalBiology(): ?string
    {
        return $this->abnormalBiology;
    }

    /** Plaies cutanées. */
    public function getCutaneousWounds(): ?string
    {
        return $this->cutaneousWounds;
    }

    /** Injury Severity Score, 0 à 75 (ClinicalScales::ISS). */
    public function getIssScore(): ?int
    {
        return $this->issScore;
    }

    /** Avis de chirurgie viscérale. */
    public function getVisceralConsult(): ?string
    {
        return $this->visceralConsult;
    }

    /** Avis orthopédique. */
    public function getOrthopedicConsult(): ?string
    {
        return $this->orthopedicConsult;
    }

    /** Avis neurochirurgical. */
    public function getNeurosurgicalConsult(): ?string
    {
        return $this->neurosurgicalConsult;
    }

    /** Avis de chirurgie vasculaire. */
    public function getVascularConsult(): ?string
    {
        return $this->vascularConsult;
    }

    /** Autres avis. */
    public function getOtherConsults(): ?string
    {
        return $this->otherConsults;
    }

    /**
     * Bilan lésionnel structuré (colonne JSON) : liste de lésions, [] si non saisi.
     *
     * @return array<int|string, mixed>
     */
    public function getLesionDetails(): array
    {
        return $this->lesionDetails;
    }

    /** IGS II, 0 à 163 (ClinicalScales::IGS2). */
    public function getIgs2Score(): ?int
    {
        return $this->igs2Score;
    }
}
