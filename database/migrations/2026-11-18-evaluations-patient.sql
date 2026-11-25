-- Migration additive (2026-11-18) : gestion des évaluations patient, sprint 1 (T1.1). Tables ClinicalImpression
-- (évaluation médicale), ClinicalObservation (fiche de suivi d'un système), ShockRoomAssessment (bilan de
-- déchocage), EpisodeDiagnosis (diagnostic du séjour) et lien CarePlan.idClinicalImpression (ordonnance issue
-- d'une évaluation).
-- Mise à jour du 2026-11-25 (sprint 2) : tables alignées sur le modèle de données v2. Une base qui avait déjà
-- reçu la version du sprint 1 joue ensuite 2026-11-25-evaluations-modele-v2.sql.
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql (qui contient déjà tout).
-- Rejouable : CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS (MariaDB). Aucun DROP.
-- Ordre : ClinicalImpression d'abord (les trois autres tables et CarePlan la référencent).
--   mariadb -u aura -p aura < database/migrations/2026-11-18-evaluations-patient.sql

-- Évaluation médicale d'un séjour (ressource HL7 ClinicalImpression, cahier des charges « gestion des évaluations
-- patient », sprint 1 du 2026-11-18, aligné au sprint 2 du 2026-11-25 sur le modèle de données fourni) :
-- l'évaluation faite par un médecin regroupe les fiches de suivi de chaque système (ClinicalObservation) et, pour
-- une admission après SMUR / SAMU, le bilan de déchocage (ShockRoomAssessment). kind : STANDARD (évaluation
-- classique par systèmes, figure 2) ou SHOCK_ROOM (bilan de déchocage, figure 3). Noms des colonnes repris de HL7
-- FHIR ClinicalImpression : effectiveDateTime (heure clinique, saisie par le médecin ; recordedAt est l'heure de
-- saisie, fixée par la base), description (« Évaluation générale (note) »), summary (synthèse). Les données
-- générales (poids, taille, IMC…) restent sur EpisodeOfCare. DER : facteur d'agression et dépense énergétique au
-- repos en kcal/j. details : compléments structurés sans colonne propre (JSON, ex. interprétation de
-- l'échocardiographie, cf. Aura\Domain\ClinicalImpression::DETAIL_KEYS).
-- shockRoomKey : un seul bilan de déchocage actif par séjour (UC2) ; retirer le bilan (removedAt) libère la place.
-- Suppression logique (removedAt) : une évaluation retirée garde sa ligne et ses fiches (RG10) ; les DAO filtrent
-- removedAt IS NULL.
CREATE TABLE IF NOT EXISTS ClinicalImpression( -- Évaluation médicale
    idClinicalImpression VARCHAR(20) PRIMARY KEY,
    idEpisodeOfCare VARCHAR(20) NOT NULL,
    -- Médecin qui a réalisé l'évaluation (authentification factice, RG10).
    idPractitioner VARCHAR(20) NOT NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'STANDARD',
    effectiveDateTime DATETIME NOT NULL,
    description TEXT,
    aggressionFactor DECIMAL(4,2),
    restingEnergyExpenditure SMALLINT,
    researchProtocols VARCHAR(255),
    summary TEXT,
    paraclinicalProposal TEXT,
    therapeuticProposal TEXT,
    orientation VARCHAR(200),
    details JSON,
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removedAt DATETIME NULL,
    shockRoomKey TINYINT AS (IF(kind = 'SHOCK_ROOM' AND removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT uq_clinicalimpression_shockroom UNIQUE (idEpisodeOfCare, shockRoomKey),
    CONSTRAINT chk_clinicalimpression_kind CHECK (BINARY kind IN ('STANDARD', 'SHOCK_ROOM')),
    -- Bornes de cohérence seulement ; les bornes cliniques sont contrôlées par EvaluationService.
    CONSTRAINT chk_clinicalimpression_der CHECK (
        (aggressionFactor IS NULL OR aggressionFactor > 0)
        AND (restingEnergyExpenditure IS NULL OR restingEnergyExpenditure > 0)
    ),

    INDEX idx_clinicalimpression_episode (idEpisodeOfCare, effectiveDateTime),
    CONSTRAINT fk_episode_clinicalimpression FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare),
    CONSTRAINT fk_practitioner_clinicalimpression FOREIGN KEY (idPractitioner) REFERENCES Practitioner(idPractitioner)
);

-- Fiche de suivi d'un système physiologique (ressource HL7 Observation) : une par élément d'évaluation de la
-- figure 2.b. category : les sept systèmes de l'écran (SEDATION, NEUROLOGICAL, CARDIOVASCULAR, HEMODYNAMIC,
-- RESPIRATORY, ABDOMINAL, URORENAL) puis les volets complémentaires du modèle (CUTANEOUS, OSTEOARTICULAR,
-- METABOLIC, INFECTIOUS, BMR_SCREENING, ABNORMAL_BIOLOGY). valueCodeableConcept : valeur de la liste normalisée du
-- système (« (LISTE) », Aura\Domain\ClinicalScales::OBSERVATION_LISTS, contrôlée par l'application) ; note :
-- « (NOTES) » ; interpretation : interprétation d'examen du système (ECG en cardiovasculaire, Rx thoracique en
-- respiratoire). activeKey : une seule fiche active par système et par évaluation ; une fiche retirée (removedAt)
-- libère la place.
CREATE TABLE IF NOT EXISTS ClinicalObservation( -- Fiche de suivi d'un système
    idClinicalObservation VARCHAR(20) PRIMARY KEY,
    idClinicalImpression VARCHAR(20) NOT NULL,
    category VARCHAR(30) NOT NULL,
    valueCodeableConcept VARCHAR(200),
    note TEXT,
    interpretation TEXT,
    effectiveDateTime DATETIME NOT NULL,
    removedAt DATETIME NULL,
    activeKey TINYINT AS (IF(removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT chk_clinicalobservation_category CHECK (BINARY category IN (
        'SEDATION', 'NEUROLOGICAL', 'CARDIOVASCULAR', 'HEMODYNAMIC', 'RESPIRATORY', 'ABDOMINAL', 'URORENAL',
        'CUTANEOUS', 'OSTEOARTICULAR', 'METABOLIC', 'INFECTIOUS', 'BMR_SCREENING', 'ABNORMAL_BIOLOGY'
    )),
    CONSTRAINT uq_clinicalobservation_category UNIQUE (idClinicalImpression, category, activeKey),
    CONSTRAINT fk_clinicalimpression_observation FOREIGN KEY (idClinicalImpression)
        REFERENCES ClinicalImpression(idClinicalImpression)
);

-- Bilan de déchocage (figure 3) d'une évaluation de type SHOCK_ROOM : prise en charge SMUR / SAMU (3.a), équipe et
-- examen clinique au déchocage (3.b), bilans et produits sanguins (3.c), avis extérieurs, ISS et IGS II (3.d). Au
-- plus un par évaluation (UNIQUE) ; le type SHOCK_ROOM de l'évaluation est vérifié par
-- ShockRoomAssessmentDao::insert(). Pas de removedAt : le bilan suit son évaluation. Bornes officielles : Glasgow
-- 3 à 15 (Teasdale et Jennett, 1974), ISS 0 à 75 (Baker, 1974) ; IGS II ≥ 0 (borne haute 163, Le Gall 1993,
-- contrôlée par l'application ; TINYINT UNSIGNED pour contenir 163). lesionDetails : bilan lésionnel structuré
-- (JSON).
CREATE TABLE IF NOT EXISTS ShockRoomAssessment( -- Bilan de déchocage
    idShockRoomAssessment VARCHAR(20) PRIMARY KEY,
    idClinicalImpression VARCHAR(20) NOT NULL,
    -- 3.a Observation médicale : grade TRENAU à l'arrivée (A, B, C, ClinicalScales::ARRIVAL_GRADES).
    arrivalGrade VARCHAR(50),
    traumaType VARCHAR(100),
    traumaMechanism VARCHAR(200),
    originService VARCHAR(100),
    antithromboticTreatment VARCHAR(200),
    -- 3.a Prise en charge SMUR.
    smurDurationMinutes SMALLINT,
    initialSystolicBp SMALLINT,
    initialRespiratoryRate SMALLINT,
    glasgowScore TINYINT,
    -- Hémocue (hémoglobine capillaire) en g/dL.
    hemocue1 DECIMAL(4,1),
    hemocue2 DECIMAL(4,1),
    smurTreatments TEXT,
    crystalloidsVolumeMl SMALLINT,
    colloidsVolumeMl SMALLINT,
    mannitol20VolumeMl SMALLINT,
    aminesType VARCHAR(100),
    -- 3.b Équipe et examen clinique au déchocage.
    shockRoomDurationMinutes SMALLINT,
    seniorIntensivist VARCHAR(100),
    icuResident VARCHAR(100),
    medicalStudent VARCHAR(100),
    leftPupil VARCHAR(100),
    rightPupil VARCHAR(100),
    capillaryRefillSeconds DECIMAL(3,1),
    clinicalExamNote TEXT,
    -- 3.b / 3.c Premiers bilans, imagerie et produits sanguins.
    efastResult VARCHAR(200),
    transcranialDoppler VARCHAR(200),
    chestXray VARCHAR(200),
    pelvisXray VARCHAR(200),
    rbcUnits SMALLINT,
    ffpUnits SMALLINT,
    otherBloodProducts VARCHAR(200),
    ctScanAssessment TEXT,
    otherImaging TEXT,
    rotemResult TEXT,
    abnormalBiology TEXT,
    cutaneousWounds TEXT,
    -- 3.d Gravité lésionnelle, avis extérieurs, puis score IGS II.
    issScore SMALLINT,
    visceralConsult TEXT,
    orthopedicConsult TEXT,
    neurosurgicalConsult TEXT,
    vascularConsult TEXT,
    otherConsults TEXT,
    lesionDetails JSON,
    igs2Score TINYINT UNSIGNED,

    CONSTRAINT chk_shockroom_glasgow CHECK (glasgowScore IS NULL OR glasgowScore BETWEEN 3 AND 15),
    CONSTRAINT chk_shockroom_igs2 CHECK (igs2Score IS NULL OR igs2Score >= 0),
    CONSTRAINT chk_shockroom_iss CHECK (issScore IS NULL OR issScore BETWEEN 0 AND 75),
    CONSTRAINT chk_shockroom_durations CHECK (
        (smurDurationMinutes IS NULL OR smurDurationMinutes >= 0)
        AND (shockRoomDurationMinutes IS NULL OR shockRoomDurationMinutes >= 0)
        AND (crystalloidsVolumeMl IS NULL OR crystalloidsVolumeMl >= 0)
    ),
    CONSTRAINT uq_shockroom_impression UNIQUE (idClinicalImpression),
    CONSTRAINT fk_clinicalimpression_shockroom FOREIGN KEY (idClinicalImpression)
        REFERENCES ClinicalImpression(idClinicalImpression)
);

-- Diagnostic d'un séjour (Encounter.diagnosis / Condition en HL7 FHIR) : une pathologie du référentiel Pathology
-- pendant le séjour, éventuellement issue d'une évaluation (idClinicalImpression). Deux statuts, comme
-- Condition : clinicalStatus (ACTIVE, RESOLVED : la pathologie est-elle en cours ?) et verificationStatus
-- (PROVISIONAL, CONFIRMED, REFUTED : le diagnostic est-il établi ?). recordedDate : date d'enregistrement.
-- C'est par la pathologie que l'on rejoint un protocole (PlanDefinition.idPathology).
-- activeKey : une pathologie n'a qu'un diagnostic actif par séjour ; retirer le diagnostic (removedAt) permet
-- de le ressaisir.
CREATE TABLE IF NOT EXISTS EpisodeDiagnosis( -- Diagnostic du séjour
    idEpisodeDiagnosis VARCHAR(20) PRIMARY KEY,
    idEpisodeOfCare VARCHAR(20) NOT NULL,
    idClinicalImpression VARCHAR(20),
    idPathology VARCHAR(20) NOT NULL,
    clinicalStatus VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    verificationStatus VARCHAR(20) NOT NULL DEFAULT 'PROVISIONAL',
    note TEXT,
    recordedDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removedAt DATETIME NULL,
    activeKey TINYINT AS (IF(removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT chk_episodediagnosis_clinical CHECK (BINARY clinicalStatus IN ('ACTIVE', 'RESOLVED')),
    CONSTRAINT chk_episodediagnosis_verification CHECK (
        BINARY verificationStatus IN ('PROVISIONAL', 'CONFIRMED', 'REFUTED')
    ),
    CONSTRAINT uq_episodediagnosis_pathology UNIQUE (idEpisodeOfCare, idPathology, activeKey),
    CONSTRAINT fk_episode_episodediagnosis FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare),
    CONSTRAINT fk_clinicalimpression_episodediagnosis FOREIGN KEY (idClinicalImpression)
        REFERENCES ClinicalImpression(idClinicalImpression),
    CONSTRAINT fk_pathology_episodediagnosis FOREIGN KEY (idPathology) REFERENCES Pathology(idPathology)
);

ALTER TABLE CarePlan
    ADD COLUMN IF NOT EXISTS idClinicalImpression VARCHAR(20) NULL;

ALTER TABLE CarePlan
    ADD CONSTRAINT fk_clinicalimpression_careplan
        FOREIGN KEY IF NOT EXISTS (idClinicalImpression) REFERENCES ClinicalImpression(idClinicalImpression);
