<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contraintes essentielles de database/schema.sql, vérifiées par la base elle-même : chaque instruction
 * invalide est refusée avec le bon code d'erreur ET par la bonne contrainte (un refus pour une autre
 * raison ne compte pas). Deux instructions valides vérifient que les contraintes ne refusent pas trop.
 * Cas choisis parmi ceux de database/test_contraintes.sql (écrit par Didac Espin), puis ceux des tables des
 * évaluations patient (modèle v2 du sprint 2 : ClinicalImpression, ClinicalObservation, ShockRoomAssessment,
 * EpisodeDiagnosis).
 *
 * Codes MariaDB (SQLSTATE 23000) : 1062 doublon, 1048 colonne NULL, 1452 parent absent, 1451 parent
 * encore référencé, 4025 CHECK. Transaction annulée en tearDown ; ignoré sans DB_HOST/DB_NAME.
 */
final class SchemaConstraintsTest extends TestCase
{
    private const DUPLICATE = 1062;
    private const NOT_NULL = 1048;
    private const NO_PARENT = 1452;
    private const STILL_REFERENCED = 1451;
    private const CHECK = 4025;

    /** Patient et séjour « ZZ » réutilisés par plusieurs cas. */
    private const ZZ_PATIENT = "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', NULL, 'Zzcontrainte')";
    private const ZZ_EPISODE = "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService,
        episodeNumber, admissionDate) VALUES ('ZZE1', 'ZZP1', 'SRV01', 'ZZ-000001', '2030-01-01 08:00:00')";

    /** Évaluations « ZZ » (évaluations patient, modèle v2) sur le séjour 1 de database/test.sql. */
    private const ZZ_SHOCK_ROOM = "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare,
        idPractitioner, kind, effectiveDateTime) VALUES ('ZZCI1', '1', 'PRA01', 'SHOCK_ROOM', '2030-01-01 08:00:00')";
    private const ZZ_OBSERVATION = "INSERT INTO ClinicalObservation (idClinicalObservation, idClinicalImpression,
        category, note, effectiveDateTime) VALUES ('ZZCO1', 'ZZCI1', 'NEUROLOGICAL', 'G15', '2030-01-01 08:05:00')";
    private const ZZ_DIAGNOSIS = "INSERT INTO EpisodeDiagnosis (idEpisodeDiagnosis, idEpisodeOfCare, idPathology,
        recordedDate) VALUES ('ZZD1', '1', 'PTH01', '2030-01-01 09:00:00')";

    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Instructions invalides : [préparation, instruction refusée, code MariaDB, contrainte attendue
     * dans le message (insensible à la casse : MariaDB sous macOS met les noms de tables en minuscules)].
     *
     * @return array<string, array{list<string>, string, int, string}>
     */
    public static function invalidStatements(): array
    {
        return [
            'Patient : IPP en double (Didac)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', 555555, 'Zzmartin'), ('ZZP2',
                    555555, 'Zzdupont')",
                self::DUPLICATE,
                "for key 'ipp'",
            ],
            'Patient : IPP sous la borne basse (Didac)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP3', 10000, 'Zzjullien')",
                self::CHECK,
                'patient.ipp',
            ],
            'Patient : nom obligatoire' => [
                [],
                "INSERT INTO Patient (idPatient, lastName) VALUES ('ZZP5', NULL)",
                self::NOT_NULL,
                "'lastName'",
            ],
            'Séjour : numéro de séjour en double (Didac)' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE1', '1', 'SRV01', 'ZZ-100000', '2026-10-10 08:15:00'),
                        ('ZZE2', '1', 'SRV01', 'ZZ-100000', '2026-10-11 08:15:00')",
                self::DUPLICATE,
                'episodeNumber',
            ],
            'Séjour : patient inexistant (Didac)' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE3', 'ZZ_INCONNU', 'SRV01', 'ZZ-100000', '2026-10-10 08:15:00')",
                self::NO_PARENT,
                'fk_patient_episode',
            ],
            'Allergie : même substance deux fois pour un patient (Didac)' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance)
                 VALUES ('ZZA1', '1', 'ALG03'), ('ZZA2', '1', 'ALG03')",
                self::DUPLICATE,
                'uq_substance_patient',
            ],
            'Ordonnance : ancien mode « MEDICALE » (Didac, renommé MEDICAL)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC1', '1',
                    'PRA01', 'MEDICALE')",
                self::CHECK,
                'chk_careplan_mode',
            ],
            'Ordonnance : valideur sans date de validation (RG5)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES ('ZZC4', '1', 'PRA03', 'MEDICAL', 'PRA01', NULL)",
                self::CHECK,
                'chk_careplan_validation',
            ],
            'Ligne de soin : fin avant le début' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate)
                 VALUES ('ZZSR2', '1', 'TYP02', '2030-01-02 08:00:00', '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_servicerequest_dates',
            ],
            'Ligne de soin : arrêtée sans auteur (RG10)' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, stoppedAt)
                 VALUES ('ZZSR3', '1', 'TYP02', '2030-01-01 08:00:00', '2030-01-01 09:00:00')",
                self::CHECK,
                'chk_servicerequest_arret',
            ],
            'Soin planifié : rattaché aux deux lignes à la fois' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime)
                 VALUES ('ZZAP2', '1', '1', '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_appointment_ligne',
            ],
            'Soin planifié : deux fois la même heure sur une ligne de médicament' => [
                [],
                "INSERT INTO Appointment (idAppointment, idMedicationRequest, plannedTime) VALUES ('ZZAP4', '1',
                    '2026-10-14 06:00:00')",
                self::DUPLICATE,
                'uq_appointment_medication_time',
            ],
            'Équipe : rotation inconnue' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2030-01-01',
                    'SOIR', 'Zzéquipe')",
                self::CHECK,
                'chk_careteam_shift',
            ],
            'Équipe : même nom parmi les équipes actives de la rotation' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2026-10-14',
                    'MATIN', 'Équipe A')",
                self::DUPLICATE,
                'uq_careteam_name',
            ],
            'Évaluation : type inconnu (casse comprise)' => [
                [],
                "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                    effectiveDateTime) VALUES ('ZZCI2', '1', 'PRA01', 'standard', '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_clinicalimpression_kind',
            ],
            'Évaluation : séjour inexistant' => [
                [],
                "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                    effectiveDateTime) VALUES ('ZZCI2', 'ZZ_INCONNU', 'PRA01', 'STANDARD', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_episode_clinicalimpression',
            ],
            'Évaluation : facteur d\'agression nul' => [
                [],
                "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                    effectiveDateTime, aggressionFactor) VALUES ('ZZCI2', '1', 'PRA01', 'STANDARD',
                    '2030-01-01 08:00:00', 0)",
                self::CHECK,
                'chk_clinicalimpression_der',
            ],
            'Évaluation : deux bilans de déchocage actifs sur le séjour' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                    effectiveDateTime) VALUES ('ZZCI2', '1', 'PRA01', 'SHOCK_ROOM', '2030-01-02 08:00:00')",
                self::DUPLICATE,
                'uq_clinicalimpression_shockroom',
            ],
            'Évaluation : details qui n\'est pas du JSON' => [
                [],
                "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                    effectiveDateTime, details) VALUES ('ZZCI2', '1', 'PRA01', 'STANDARD', '2030-01-01 08:00:00',
                    'écho normale')",
                self::CHECK,
                'details',
            ],
            'Fiche de suivi : système inconnu' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO ClinicalObservation (idClinicalObservation, idClinicalImpression, category, note,
                    effectiveDateTime) VALUES ('ZZCO2', 'ZZCI1', 'DERMATOLOGICAL', 'RAS', '2030-01-01 08:05:00')",
                self::CHECK,
                'chk_clinicalobservation_category',
            ],
            'Fiche de suivi : deux fiches actives pour le même système' => [
                [self::ZZ_SHOCK_ROOM, self::ZZ_OBSERVATION],
                "INSERT INTO ClinicalObservation (idClinicalObservation, idClinicalImpression, category, note,
                    effectiveDateTime) VALUES ('ZZCO2', 'ZZCI1', 'NEUROLOGICAL', 'G14', '2030-01-01 09:00:00')",
                self::DUPLICATE,
                'uq_clinicalobservation_category',
            ],
            'Déchocage : Glasgow sous 3' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO ShockRoomAssessment (idShockRoomAssessment, idClinicalImpression, glasgowScore)
                 VALUES ('ZZSA1', 'ZZCI1', 2)",
                self::CHECK,
                'chk_shockroom_glasgow',
            ],
            'Déchocage : ISS au-delà de 75' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO ShockRoomAssessment (idShockRoomAssessment, idClinicalImpression, issScore)
                 VALUES ('ZZSA1', 'ZZCI1', 76)",
                self::CHECK,
                'chk_shockroom_iss',
            ],
            'Déchocage : volume de cristalloïdes négatif' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO ShockRoomAssessment (idShockRoomAssessment, idClinicalImpression, crystalloidsVolumeMl)
                 VALUES ('ZZSA1', 'ZZCI1', -1)",
                self::CHECK,
                'chk_shockroom_durations',
            ],
            'Déchocage : deux bilans pour une évaluation' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO ShockRoomAssessment (idShockRoomAssessment, idClinicalImpression)
                 VALUES ('ZZSA1', 'ZZCI1'), ('ZZSA2', 'ZZCI1')",
                self::DUPLICATE,
                'uq_shockroom_impression',
            ],
            'Diagnostic : statut clinique inconnu (ancien statut SUSPECTED)' => [
                [],
                "INSERT INTO EpisodeDiagnosis (idEpisodeDiagnosis, idEpisodeOfCare, idPathology, clinicalStatus)
                 VALUES ('ZZD2', '1', 'PTH01', 'SUSPECTED')",
                self::CHECK,
                'chk_episodediagnosis_clinical',
            ],
            'Diagnostic : statut de vérification inconnu' => [
                [],
                "INSERT INTO EpisodeDiagnosis (idEpisodeDiagnosis, idEpisodeOfCare, idPathology, verificationStatus)
                 VALUES ('ZZD2', '1', 'PTH01', 'ACTIVE')",
                self::CHECK,
                'chk_episodediagnosis_verification',
            ],
            'Diagnostic : même pathologie active deux fois sur le séjour' => [
                [self::ZZ_DIAGNOSIS],
                "INSERT INTO EpisodeDiagnosis (idEpisodeDiagnosis, idEpisodeOfCare, idPathology, verificationStatus,
                    recordedDate) VALUES ('ZZD2', '1', 'PTH01', 'CONFIRMED', '2030-01-02 09:00:00')",
                self::DUPLICATE,
                'uq_episodediagnosis_pathology',
            ],
            'Diagnostic : pathologie hors référentiel' => [
                [],
                "INSERT INTO EpisodeDiagnosis (idEpisodeDiagnosis, idEpisodeOfCare, idPathology, recordedDate)
                 VALUES ('ZZD2', '1', 'ZZ_INCONNUE', '2030-01-01 09:00:00')",
                self::NO_PARENT,
                'fk_pathology_episodediagnosis',
            ],
            'Ordonnance : évaluation inexistante' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idClinicalImpression)
                 VALUES ('ZZC5', '1', 'PRA01', 'MEDICAL', 'ZZ_INCONNUE')",
                self::NO_PARENT,
                'fk_clinicalimpression_careplan',
            ],
            'Suppression : évaluation qui a des fiches de suivi' => [
                [self::ZZ_SHOCK_ROOM, self::ZZ_OBSERVATION],
                "DELETE FROM ClinicalImpression WHERE idClinicalImpression = 'ZZCI1'",
                self::STILL_REFERENCED,
                'fk_clinicalimpression_observation',
            ],
            'Suppression : patient qui a un séjour' => [
                [self::ZZ_PATIENT, self::ZZ_EPISODE],
                "DELETE FROM Patient WHERE idPatient = 'ZZP1'",
                self::STILL_REFERENCED,
                'fk_patient_episode',
            ],
        ];
    }

    /** @param list<string> $setup */
    #[DataProvider('invalidStatements')]
    public function testInvalidStatementIsRejectedByTheRightConstraint(
        array $setup,
        string $sql,
        int $driverCode,
        string $constraint
    ): void {
        foreach ($setup as $statement) {
            $this->pdo->exec($statement);
        }

        try {
            $this->pdo->exec($sql);
            self::fail("L'instruction devait être refusée par la contrainte « $constraint ».");
        } catch (PDOException $e) {
            self::assertSame('23000', $e->errorInfo[0] ?? null, 'SQLSTATE : violation de contrainte d\'intégrité');
            self::assertSame($driverCode, $e->errorInfo[1] ?? null, 'code MariaDB : ' . $e->getMessage());
            self::assertStringContainsStringIgnoringCase(
                $constraint,
                $e->getMessage(),
                'refusée, mais pas par la contrainte attendue'
            );
        }
    }

    /**
     * Instructions valides de contrôle : [préparation, instruction, nombre de lignes touchées].
     *
     * @return array<string, array{list<string>, string, int}>
     */
    public static function validStatements(): array
    {
        return [
            'plusieurs patients sans IPP (admission en urgence)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', NULL, 'Zzun'), ('ZZP2', NULL,
                    'Zzdeux')",
                2,
            ],
            'nom d\'équipe réutilisé après retrait de l\'ancienne (suppression logique)' => [
                ["UPDATE CareTeam SET removedAt = '2026-10-14 09:00:00' WHERE idCareTeam = 1"],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2026-10-14',
                    'MATIN', 'Équipe A')",
                1,
            ],
            'fiche d\'un système ressaisie après retrait de la précédente (suppression logique)' => [
                [
                    self::ZZ_SHOCK_ROOM,
                    self::ZZ_OBSERVATION,
                    "UPDATE ClinicalObservation SET removedAt = '2030-01-01 08:30:00'
                     WHERE idClinicalObservation = 'ZZCO1'",
                ],
                "INSERT INTO ClinicalObservation (idClinicalObservation, idClinicalImpression, category, note,
                    effectiveDateTime) VALUES ('ZZCO2', 'ZZCI1', 'NEUROLOGICAL', 'G14', '2030-01-01 09:00:00')",
                1,
            ],
            'même système sur deux évaluations différentes' => [
                [
                    self::ZZ_SHOCK_ROOM,
                    self::ZZ_OBSERVATION,
                    "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                        effectiveDateTime) VALUES ('ZZCI2', '1', 'PRA01', 'STANDARD', '2030-01-02 08:00:00')",
                ],
                "INSERT INTO ClinicalObservation (idClinicalObservation, idClinicalImpression, category, note,
                    effectiveDateTime) VALUES ('ZZCO2', 'ZZCI2', 'NEUROLOGICAL', 'G15', '2030-01-02 08:05:00')",
                1,
            ],
            'pathologie rediagnostiquée après retrait du diagnostic' => [
                [
                    self::ZZ_DIAGNOSIS,
                    "UPDATE EpisodeDiagnosis SET removedAt = '2030-01-01 10:00:00' WHERE idEpisodeDiagnosis = 'ZZD1'",
                ],
                "INSERT INTO EpisodeDiagnosis (idEpisodeDiagnosis, idEpisodeOfCare, idPathology, clinicalStatus,
                    recordedDate) VALUES ('ZZD2', '1', 'PTH01', 'RESOLVED', '2030-01-02 09:00:00')",
                1,
            ],
            'bilan de déchocage aux bornes de Glasgow, d\'ISS et d\'IGS II' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO ShockRoomAssessment (idShockRoomAssessment, idClinicalImpression, glasgowScore,
                    issScore, igs2Score) VALUES ('ZZSA1', 'ZZCI1', 3, 75, 163)",
                1,
            ],
            'nouveau bilan de déchocage après retrait du précédent (shockRoomKey libéré)' => [
                [
                    self::ZZ_SHOCK_ROOM,
                    "UPDATE ClinicalImpression SET removedAt = '2030-01-01 10:00:00'
                     WHERE idClinicalImpression = 'ZZCI1'",
                ],
                "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner, kind,
                    effectiveDateTime) VALUES ('ZZCI2', '1', 'PRA01', 'SHOCK_ROOM', '2030-01-02 08:00:00')",
                1,
            ],
            'plusieurs évaluations classiques sur le même séjour' => [
                [
                    "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner,
                        effectiveDateTime) VALUES ('ZZCI2', '1', 'PRA01', '2030-01-02 08:00:00')",
                ],
                "INSERT INTO ClinicalImpression (idClinicalImpression, idEpisodeOfCare, idPractitioner,
                    effectiveDateTime) VALUES ('ZZCI3', '1', 'PRA01', '2030-01-03 08:00:00')",
                1,
            ],
            'ordonnance issue d\'une évaluation' => [
                [self::ZZ_SHOCK_ROOM],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idClinicalImpression)
                 VALUES ('ZZC5', '1', 'PRA01', 'MEDICAL', 'ZZCI1')",
                1,
            ],
        ];
    }

    /** @param list<string> $setup */
    #[DataProvider('validStatements')]
    public function testValidStatementIsAccepted(array $setup, string $sql, int $affectedRows): void
    {
        foreach ($setup as $statement) {
            $this->pdo->exec($statement);
        }

        self::assertSame($affectedRows, $this->pdo->exec($sql));
    }
}
