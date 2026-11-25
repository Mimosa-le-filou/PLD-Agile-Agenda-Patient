-- Migration (2026-11-25) : alignement des tables d'évaluation du sprint 1 sur le modèle de données du sprint 2
-- (noms HL7 FHIR, colonnes ajoutées, contraintes revues). À jouer APRÈS 2026-11-18-evaluations-patient.sql, sur
-- une base qui avait reçu la version du sprint 1 de ces tables ; sur une base où 2026-11-18 a été jouée dans sa
-- version actuelle, elle ne change rien. Une installation neuve charge database/schema.sql (qui contient déjà tout).
-- Rejouable : CHANGE COLUMN IF EXISTS, ADD COLUMN IF NOT EXISTS, DROP … IF EXISTS, ADD … IF NOT EXISTS (MariaDB).
-- Aucune table supprimée ; les colonnes retirées du modèle (fiche « non vide », lesionAssessment,
-- paraclinicalWorkup du bilan de déchocage) sont supprimées seulement si elles existent encore.
--   mariadb -u aura -p aura < database/migrations/2026-11-25-evaluations-modele-v2.sql

-- ClinicalImpression : noms HL7 (effectiveDateTime, description, summary), nouvelles colonnes, un seul bilan de
-- déchocage actif par séjour (shockRoomKey).
ALTER TABLE ClinicalImpression
    DROP CONSTRAINT IF EXISTS chk_clinicalimpression_aggression,
    DROP CONSTRAINT IF EXISTS chk_clinicalimpression_der,
    MODIFY COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'STANDARD',
    CHANGE COLUMN IF EXISTS evaluatedAt effectiveDateTime DATETIME NOT NULL,
    CHANGE COLUMN IF EXISTS generalNote description TEXT,
    MODIFY COLUMN aggressionFactor DECIMAL(4,2),
    ADD COLUMN IF NOT EXISTS researchProtocols VARCHAR(255) AFTER restingEnergyExpenditure,
    CHANGE COLUMN IF EXISTS conclusion summary TEXT,
    ADD COLUMN IF NOT EXISTS paraclinicalProposal TEXT AFTER summary,
    ADD COLUMN IF NOT EXISTS therapeuticProposal TEXT AFTER paraclinicalProposal,
    ADD COLUMN IF NOT EXISTS details JSON AFTER orientation,
    ADD COLUMN IF NOT EXISTS shockRoomKey TINYINT AS (IF(kind = 'SHOCK_ROOM' AND removedAt IS NULL, 1, NULL)) STORED
        AFTER removedAt;

ALTER TABLE ClinicalImpression
    ADD CONSTRAINT IF NOT EXISTS chk_clinicalimpression_der CHECK (
        (aggressionFactor IS NULL OR aggressionFactor > 0)
        AND (restingEnergyExpenditure IS NULL OR restingEnergyExpenditure > 0)
    ),
    ADD INDEX IF NOT EXISTS idx_clinicalimpression_episode (idEpisodeOfCare, effectiveDateTime),
    ADD CONSTRAINT uq_clinicalimpression_shockroom UNIQUE IF NOT EXISTS (idEpisodeOfCare, shockRoomKey);

-- ClinicalObservation : noms HL7 (valueCodeableConcept, note, interpretation, effectiveDateTime), systèmes
-- complémentaires, plus de CHECK « fiche non vide » (contrôlé par EvaluationService).
ALTER TABLE ClinicalObservation
    DROP CONSTRAINT IF EXISTS chk_clinicalobservation_category,
    DROP CONSTRAINT IF EXISTS chk_clinicalobservation_content,
    MODIFY COLUMN category VARCHAR(30) NOT NULL,
    CHANGE COLUMN IF EXISTS selectedValue valueCodeableConcept VARCHAR(200),
    CHANGE COLUMN IF EXISTS notes note TEXT,
    CHANGE COLUMN IF EXISTS examInterpretation interpretation TEXT,
    CHANGE COLUMN IF EXISTS observedAt effectiveDateTime DATETIME NOT NULL;

ALTER TABLE ClinicalObservation
    ADD CONSTRAINT IF NOT EXISTS chk_clinicalobservation_category CHECK (BINARY category IN (
        'SEDATION', 'NEUROLOGICAL', 'CARDIOVASCULAR', 'HEMODYNAMIC', 'RESPIRATORY', 'ABDOMINAL', 'URORENAL',
        'CUTANEOUS', 'OSTEOARTICULAR', 'METABOLIC', 'INFECTIOUS', 'BMR_SCREENING', 'ABNORMAL_BIOLOGY'
    ));

-- ShockRoomAssessment : champs du modèle v2 (constantes initiales, remplissage, imagerie, produits sanguins, ISS,
-- avis neurochirurgical, bilan lésionnel JSON) ; noms de l'équipe et des bilans revus.
ALTER TABLE ShockRoomAssessment
    DROP CONSTRAINT IF EXISTS chk_shockroom_glasgow,
    DROP CONSTRAINT IF EXISTS chk_shockroom_igs2,
    DROP CONSTRAINT IF EXISTS chk_shockroom_iss,
    DROP CONSTRAINT IF EXISTS chk_shockroom_durations,
    DROP CONSTRAINT IF EXISTS chk_shockroom_measures,
    MODIFY COLUMN arrivalGrade VARCHAR(50),
    ADD COLUMN IF NOT EXISTS antithromboticTreatment VARCHAR(200) AFTER originService,
    ADD COLUMN IF NOT EXISTS initialSystolicBp SMALLINT AFTER smurDurationMinutes,
    ADD COLUMN IF NOT EXISTS initialRespiratoryRate SMALLINT AFTER initialSystolicBp,
    ADD COLUMN IF NOT EXISTS colloidsVolumeMl SMALLINT AFTER crystalloidsVolumeMl,
    ADD COLUMN IF NOT EXISTS mannitol20VolumeMl SMALLINT AFTER colloidsVolumeMl,
    ADD COLUMN IF NOT EXISTS aminesType VARCHAR(100) AFTER mannitol20VolumeMl,
    CHANGE COLUMN IF EXISTS seniorDoctor seniorIntensivist VARCHAR(100),
    CHANGE COLUMN IF EXISTS residentDoctor icuResident VARCHAR(100),
    ADD COLUMN IF NOT EXISTS clinicalExamNote TEXT AFTER capillaryRefillSeconds,
    DROP COLUMN IF EXISTS lesionAssessment,
    DROP COLUMN IF EXISTS paraclinicalWorkup,
    CHANGE COLUMN IF EXISTS ctScanWorkup ctScanAssessment TEXT,
    CHANGE COLUMN IF EXISTS abnormalLabResults abnormalBiology TEXT,
    MODIFY COLUMN igs2Score TINYINT UNSIGNED;

-- Colonnes ajoutées à leur place dans l'ordre du modèle (après la première ALTER, qui a créé leurs voisines).
ALTER TABLE ShockRoomAssessment
    MODIFY COLUMN efastResult VARCHAR(200) AFTER clinicalExamNote,
    ADD COLUMN IF NOT EXISTS transcranialDoppler VARCHAR(200) AFTER efastResult,
    ADD COLUMN IF NOT EXISTS chestXray VARCHAR(200) AFTER transcranialDoppler,
    ADD COLUMN IF NOT EXISTS pelvisXray VARCHAR(200) AFTER chestXray,
    ADD COLUMN IF NOT EXISTS rbcUnits SMALLINT AFTER pelvisXray,
    ADD COLUMN IF NOT EXISTS ffpUnits SMALLINT AFTER rbcUnits,
    ADD COLUMN IF NOT EXISTS otherBloodProducts VARCHAR(200) AFTER ffpUnits,
    MODIFY COLUMN ctScanAssessment TEXT AFTER otherBloodProducts,
    MODIFY COLUMN otherImaging TEXT AFTER ctScanAssessment,
    ADD COLUMN IF NOT EXISTS rotemResult TEXT AFTER otherImaging,
    MODIFY COLUMN abnormalBiology TEXT AFTER rotemResult,
    ADD COLUMN IF NOT EXISTS cutaneousWounds TEXT AFTER abnormalBiology,
    ADD COLUMN IF NOT EXISTS issScore SMALLINT AFTER cutaneousWounds,
    MODIFY COLUMN visceralConsult TEXT AFTER issScore,
    MODIFY COLUMN orthopedicConsult TEXT AFTER visceralConsult,
    ADD COLUMN IF NOT EXISTS neurosurgicalConsult TEXT AFTER orthopedicConsult,
    MODIFY COLUMN vascularConsult TEXT AFTER neurosurgicalConsult,
    MODIFY COLUMN otherConsults TEXT AFTER vascularConsult,
    ADD COLUMN IF NOT EXISTS lesionDetails JSON AFTER otherConsults,
    MODIFY COLUMN igs2Score TINYINT UNSIGNED AFTER lesionDetails;

-- Contraintes recréées dans l'ordre de schema.sql.
ALTER TABLE ShockRoomAssessment
    ADD CONSTRAINT IF NOT EXISTS chk_shockroom_glasgow CHECK (glasgowScore IS NULL OR glasgowScore BETWEEN 3 AND 15),
    ADD CONSTRAINT IF NOT EXISTS chk_shockroom_igs2 CHECK (igs2Score IS NULL OR igs2Score >= 0),
    ADD CONSTRAINT IF NOT EXISTS chk_shockroom_iss CHECK (issScore IS NULL OR issScore BETWEEN 0 AND 75),
    ADD CONSTRAINT IF NOT EXISTS chk_shockroom_durations CHECK (
        (smurDurationMinutes IS NULL OR smurDurationMinutes >= 0)
        AND (shockRoomDurationMinutes IS NULL OR shockRoomDurationMinutes >= 0)
        AND (crystalloidsVolumeMl IS NULL OR crystalloidsVolumeMl >= 0)
    );

-- EpisodeDiagnosis : statuts HL7 Condition (clinicalStatus + verificationStatus), note, recordedDate. Les deux
-- anciens statuts qui n'existent plus sont répartis : SUSPECTED → ACTIVE / PROVISIONAL, REFUTED → ACTIVE / REFUTED.
-- ACTIVE et RESOLVED restent tels quels (vérification PROVISIONAL par défaut, à confirmer par le médecin).
ALTER TABLE EpisodeDiagnosis
    DROP CONSTRAINT IF EXISTS chk_episodediagnosis_status,
    ADD COLUMN IF NOT EXISTS verificationStatus VARCHAR(20) NOT NULL DEFAULT 'PROVISIONAL' AFTER idPathology,
    CHANGE COLUMN IF EXISTS status clinicalStatus VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    CHANGE COLUMN IF EXISTS notes note TEXT,
    CHANGE COLUMN IF EXISTS diagnosedAt recordedDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE EpisodeDiagnosis
SET verificationStatus = IF(BINARY clinicalStatus = 'REFUTED', 'REFUTED', 'PROVISIONAL'),
    clinicalStatus = 'ACTIVE'
WHERE BINARY clinicalStatus IN ('SUSPECTED', 'REFUTED');

ALTER TABLE EpisodeDiagnosis
    MODIFY COLUMN clinicalStatus VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' AFTER idPathology,
    ADD CONSTRAINT IF NOT EXISTS chk_episodediagnosis_clinical CHECK (BINARY clinicalStatus IN ('ACTIVE', 'RESOLVED')),
    ADD CONSTRAINT IF NOT EXISTS chk_episodediagnosis_verification CHECK (
        BINARY verificationStatus IN ('PROVISIONAL', 'CONFIRMED', 'REFUTED')
    );
