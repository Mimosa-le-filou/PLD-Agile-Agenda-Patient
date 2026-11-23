-- Migration additive (2026-11-18) : gestion des évaluations patient, sprint 1 (T1.1). Tables ClinicalImpression
-- (évaluation médicale), ClinicalObservation (fiche de suivi d'un système), ShockRoomAssessment (bilan de
-- déchocage), EpisodeDiagnosis (diagnostic du séjour) et lien CarePlan.idClinicalImpression (ordonnance issue
-- d'une évaluation).
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql (qui contient déjà tout).
-- Rejouable : CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS (MariaDB). Aucun DROP.
-- Ordre : ClinicalImpression d'abord (les trois autres tables et CarePlan la référencent).
--   mariadb -u aura -p aura < database/migrations/2026-11-18-evaluations-patient.sql

CREATE TABLE IF NOT EXISTS ClinicalImpression( -- Évaluation médicale
    idClinicalImpression VARCHAR(20) PRIMARY KEY,
    idEpisodeOfCare VARCHAR(20) NOT NULL,
    -- Médecin qui a réalisé l'évaluation (authentification factice, RG10).
    idPractitioner VARCHAR(20) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    -- Heure clinique de l'évaluation, saisie par le médecin ; recordedAt est l'heure de saisie (base).
    evaluatedAt DATETIME NOT NULL,
    generalNote TEXT,
    aggressionFactor DECIMAL(3,2),
    restingEnergyExpenditure SMALLINT,
    conclusion TEXT,
    orientation VARCHAR(200),
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removedAt DATETIME NULL,

    CONSTRAINT chk_clinicalimpression_kind CHECK (BINARY kind IN ('STANDARD', 'SHOCK_ROOM')),
    -- Niveau d'agression (facteur correctif du DER) : 1 = aucune agression, borne haute large (brûlés graves).
    CONSTRAINT chk_clinicalimpression_aggression CHECK (aggressionFactor IS NULL OR aggressionFactor BETWEEN 1 AND 3),
    CONSTRAINT chk_clinicalimpression_der CHECK (restingEnergyExpenditure IS NULL OR restingEnergyExpenditure > 0),

    INDEX idx_clinicalimpression_episode (idEpisodeOfCare, evaluatedAt),
    CONSTRAINT fk_episode_clinicalimpression FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare),
    CONSTRAINT fk_practitioner_clinicalimpression FOREIGN KEY (idPractitioner) REFERENCES Practitioner(idPractitioner)
);

-- Fiche de suivi d'un système physiologique (ressource HL7 Observation) : une par élément d'évaluation de la
-- figure 2.b. category : SEDATION, NEUROLOGICAL, CARDIOVASCULAR, HEMODYNAMIC, RESPIRATORY, ABDOMINAL, URORENAL.
-- selectedValue : valeur de la liste normalisée du système (« (LISTE) », Aura\Domain\ClinicalScales::
-- OBSERVATION_LISTS, contrôlée par l'application) ; notes : « (NOTES) » ; examInterpretation : interprétation
-- d'examen du système (ECG et échocardiographie en cardiovasculaire, Rx thoracique en respiratoire).
-- activeKey : une seule fiche active par système et par évaluation ; une fiche retirée (removedAt) libère la place.
CREATE TABLE IF NOT EXISTS ClinicalObservation( -- Fiche de suivi d'un système
    idClinicalObservation VARCHAR(20) PRIMARY KEY,
    idClinicalImpression VARCHAR(20) NOT NULL,
    category VARCHAR(20) NOT NULL,
    selectedValue VARCHAR(100),
    notes TEXT,
    examInterpretation TEXT,
    observedAt DATETIME NOT NULL,
    removedAt DATETIME NULL,
    activeKey TINYINT AS (IF(removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT chk_clinicalobservation_category CHECK (BINARY category IN (
        'SEDATION', 'NEUROLOGICAL', 'CARDIOVASCULAR', 'HEMODYNAMIC', 'RESPIRATORY', 'ABDOMINAL', 'URORENAL'
    )),
    -- Une fiche vide n'a pas de sens : au moins la liste, la note ou l'interprétation.
    CONSTRAINT chk_clinicalobservation_content CHECK (
        selectedValue IS NOT NULL OR notes IS NOT NULL OR examInterpretation IS NOT NULL
    ),
    CONSTRAINT uq_clinicalobservation_category UNIQUE (idClinicalImpression, category, activeKey),
    CONSTRAINT fk_clinicalimpression_observation FOREIGN KEY (idClinicalImpression)
        REFERENCES ClinicalImpression(idClinicalImpression)
);

-- Bilan de déchocage (figure 3) d'une évaluation de type SHOCK_ROOM : prise en charge SMUR / SAMU, examen
-- clinique au déchocage, bilans, avis extérieurs, score IGS II. Au plus un par évaluation (UNIQUE) ; le type
-- SHOCK_ROOM de l'évaluation est vérifié par ShockRoomAssessmentDao::insert(). Pas de removedAt : le bilan suit
-- son évaluation. Bornes officielles : Glasgow 3 à 15 (Teasdale et Jennett, 1974), IGS II 0 à 163 (Le Gall, 1993).
CREATE TABLE IF NOT EXISTS ShockRoomAssessment( -- Bilan de déchocage
    idShockRoomAssessment VARCHAR(20) PRIMARY KEY,
    idClinicalImpression VARCHAR(20) NOT NULL,
    -- 3.a Observation médicale : grade TRENAU à l'arrivée (A, B, C, ClinicalScales::ARRIVAL_GRADES).
    arrivalGrade VARCHAR(5),
    traumaType VARCHAR(100),
    traumaMechanism VARCHAR(200),
    originService VARCHAR(100),
    -- 3.a Prise en charge SMUR.
    smurDurationMinutes SMALLINT,
    glasgowScore TINYINT,
    -- Hémocue (hémoglobine capillaire) en g/dL.
    hemocue1 DECIMAL(4,1),
    hemocue2 DECIMAL(4,1),
    smurTreatments TEXT,
    crystalloidsVolumeMl SMALLINT,
    -- 3.b Équipe et examen clinique au déchocage.
    shockRoomDurationMinutes SMALLINT,
    seniorDoctor VARCHAR(100),
    residentDoctor VARCHAR(100),
    medicalStudent VARCHAR(100),
    efastResult TEXT,
    leftPupil VARCHAR(100),
    rightPupil VARCHAR(100),
    capillaryRefillSeconds DECIMAL(3,1),
    -- 3.b / 3.c Bilans.
    lesionAssessment TEXT,
    paraclinicalWorkup TEXT,
    ctScanWorkup TEXT,
    otherImaging TEXT,
    abnormalLabResults TEXT,
    -- 3.d Avis extérieurs, puis score IGS II.
    visceralConsult TEXT,
    orthopedicConsult TEXT,
    vascularConsult TEXT,
    otherConsults TEXT,
    igs2Score SMALLINT,

    CONSTRAINT chk_shockroom_glasgow CHECK (glasgowScore IS NULL OR glasgowScore BETWEEN 3 AND 15),
    CONSTRAINT chk_shockroom_igs2 CHECK (igs2Score IS NULL OR igs2Score BETWEEN 0 AND 163),
    CONSTRAINT chk_shockroom_durations CHECK (
        (smurDurationMinutes IS NULL OR smurDurationMinutes >= 0)
        AND (shockRoomDurationMinutes IS NULL OR shockRoomDurationMinutes >= 0)
    ),
    CONSTRAINT chk_shockroom_measures CHECK (
        (hemocue1 IS NULL OR hemocue1 >= 0) AND (hemocue2 IS NULL OR hemocue2 >= 0)
        AND (crystalloidsVolumeMl IS NULL OR crystalloidsVolumeMl >= 0)
        AND (capillaryRefillSeconds IS NULL OR capillaryRefillSeconds >= 0)
    ),
    CONSTRAINT uq_shockroom_impression UNIQUE (idClinicalImpression),
    CONSTRAINT fk_clinicalimpression_shockroom FOREIGN KEY (idClinicalImpression)
        REFERENCES ClinicalImpression(idClinicalImpression)
);

-- Diagnostic d'un séjour (Encounter.diagnosis / Condition en HL7 FHIR) : une pathologie du référentiel Pathology
-- retenue, suspectée, résolue ou réfutée pendant le séjour, éventuellement issue d'une évaluation
-- (idClinicalImpression). C'est par la pathologie que l'on rejoint un protocole (PlanDefinition.idPathology).
-- activeKey : une pathologie n'a qu'un diagnostic actif par séjour ; retirer le diagnostic (removedAt) permet
-- de le ressaisir.
CREATE TABLE IF NOT EXISTS EpisodeDiagnosis( -- Diagnostic du séjour
    idEpisodeDiagnosis VARCHAR(20) PRIMARY KEY,
    idEpisodeOfCare VARCHAR(20) NOT NULL,
    idClinicalImpression VARCHAR(20),
    idPathology VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL,
    notes TEXT,
    diagnosedAt DATETIME NOT NULL,
    removedAt DATETIME NULL,
    activeKey TINYINT AS (IF(removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT chk_episodediagnosis_status CHECK (BINARY status IN ('ACTIVE', 'SUSPECTED', 'RESOLVED', 'REFUTED')),
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
