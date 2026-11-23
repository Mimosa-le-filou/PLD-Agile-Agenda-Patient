<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\ShockRoomAssessment;
use LogicException;
use PDO;

/**
 * Accès aux bilans de déchocage (table ShockRoomAssessment, figure 3 du cahier des charges) : au plus un par
 * évaluation de type ClinicalImpression::KIND_SHOCK_ROOM (contrainte uq_shockroom_impression). Pas de suppression
 * propre : le bilan suit son évaluation (ClinicalImpressionDao::markRemoved()), et n'est lu que si celle-ci est
 * active.
 *
 * Ids VARCHAR(20) sans AUTO_INCREMENT, calculés par nextId() (MAX + 1, FOR UPDATE) dans la transaction d'écriture.
 */
class ShockRoomAssessmentDao
{
    private const COLUMNS = 'idShockRoomAssessment, idClinicalImpression,
        arrivalGrade, traumaType, traumaMechanism, originService, smurDurationMinutes,
        glasgowScore, hemocue1, hemocue2, smurTreatments, crystalloidsVolumeMl,
        shockRoomDurationMinutes, seniorDoctor, residentDoctor, medicalStudent, efastResult,
        leftPupil, rightPupil, capillaryRefillSeconds, lesionAssessment, paraclinicalWorkup,
        ctScanWorkup, otherImaging, abnormalLabResults, visceralConsult, orthopedicConsult,
        vascularConsult, otherConsults, igs2Score';

    public function __construct(private PDO $pdo)
    {
    }

    /** Bilan d'une évaluation active, ou null (évaluation classique, retirée, ou bilan pas encore saisi). */
    public function findByClinicalImpression(string $idClinicalImpression): ?ShockRoomAssessment
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ShockRoomAssessment
             WHERE idClinicalImpression = :id
               AND idClinicalImpression IN (
                   SELECT idClinicalImpression FROM ClinicalImpression WHERE removedAt IS NULL
               )'
        );
        $stmt->execute(['id' => $idClinicalImpression]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /**
     * Enregistre le bilan et renvoie son identifiant. $idClinicalImpression remplace celui du bilan : c'est ainsi
     * que ClinicalImpressionDao::insert() rattache un bilan construit avant l'évaluation.
     *
     * L'évaluation doit exister, être active et de type SHOCK_ROOM : la condition est dans l'INSERT … SELECT
     * lui-même, si bien qu'aucune ligne n'est écrite sinon.
     *
     * @throws LogicException évaluation non désignée, absente, retirée ou classique (STANDARD)
     * @throws \PDOException contrainte refusée (bilan déjà saisi, Glasgow ou IGS II hors bornes) : rien n'est écrit
     */
    public function insert(ShockRoomAssessment $a, ?string $idClinicalImpression = null): string
    {
        $idImpression = $idClinicalImpression ?? $a->getIdClinicalImpression();
        if ($idImpression === '') {
            throw new LogicException('ShockRoomAssessmentDao::insert() requiert l\'identifiant de l\'évaluation.');
        }

        return (new Transaction($this->pdo))->run(function () use ($a, $idImpression): string {
            $id = (string) $this->nextId();
            $stmt = $this->pdo->prepare(
                'INSERT INTO ShockRoomAssessment (
                    idShockRoomAssessment, idClinicalImpression, arrivalGrade,
                    traumaType, traumaMechanism, originService,
                    smurDurationMinutes, glasgowScore, hemocue1, hemocue2,
                    smurTreatments, crystalloidsVolumeMl,
                    shockRoomDurationMinutes, seniorDoctor, residentDoctor,
                    medicalStudent, efastResult, leftPupil, rightPupil,
                    capillaryRefillSeconds, lesionAssessment,
                    paraclinicalWorkup, ctScanWorkup, otherImaging,
                    abnormalLabResults, visceralConsult, orthopedicConsult,
                    vascularConsult, otherConsults, igs2Score
                 )
                 SELECT
                    :id, c.idClinicalImpression, :arrivalGrade, :traumaType, :traumaMechanism,
                    :originService, :smurDurationMinutes, :glasgowScore, :hemocue1, :hemocue2,
                    :smurTreatments, :crystalloidsVolumeMl, :shockRoomDurationMinutes,
                    :seniorDoctor, :residentDoctor, :medicalStudent, :efastResult, :leftPupil,
                    :rightPupil, :capillaryRefillSeconds, :lesionAssessment,
                    :paraclinicalWorkup, :ctScanWorkup, :otherImaging, :abnormalLabResults,
                    :visceralConsult, :orthopedicConsult, :vascularConsult, :otherConsults,
                    :igs2Score
                 FROM ClinicalImpression c
                 WHERE c.idClinicalImpression = :idClinicalImpression
                   AND c.kind = \'SHOCK_ROOM\' AND c.removedAt IS NULL'
            );
            $stmt->execute(['id' => $id, 'idClinicalImpression' => $idImpression] + $this->values($a));
            if ($stmt->rowCount() !== 1) {
                throw new LogicException(
                    "Bilan de déchocage refusé : l'évaluation $idImpression est absente, retirée ou n'est pas "
                    . 'une fiche de déchocage.'
                );
            }

            return $id;
        });
    }

    /**
     * Met à jour tous les champs cliniques d'un bilan dont l'évaluation est active. Renvoie false si le bilan
     * n'existe pas ou si son évaluation a été retirée.
     *
     * @throws LogicException si le bilan n'a pas d'identifiant
     */
    public function update(ShockRoomAssessment $a): bool
    {
        $id = $a->getIdShockRoomAssessment();
        if ($id === null) {
            throw new LogicException('ShockRoomAssessmentDao::update() requiert un bilan enregistré (id non nul).');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ShockRoomAssessment SET
                     arrivalGrade = :arrivalGrade, traumaType = :traumaType,
                     traumaMechanism = :traumaMechanism, originService = :originService,
                     smurDurationMinutes = :smurDurationMinutes, glasgowScore = :glasgowScore,
                     hemocue1 = :hemocue1, hemocue2 = :hemocue2, smurTreatments = :smurTreatments,
                     crystalloidsVolumeMl = :crystalloidsVolumeMl,
                     shockRoomDurationMinutes = :shockRoomDurationMinutes,
                     seniorDoctor = :seniorDoctor, residentDoctor = :residentDoctor,
                     medicalStudent = :medicalStudent, efastResult = :efastResult,
                     leftPupil = :leftPupil, rightPupil = :rightPupil,
                     capillaryRefillSeconds = :capillaryRefillSeconds,
                     lesionAssessment = :lesionAssessment,
                     paraclinicalWorkup = :paraclinicalWorkup, ctScanWorkup = :ctScanWorkup,
                     otherImaging = :otherImaging, abnormalLabResults = :abnormalLabResults,
                     visceralConsult = :visceralConsult, orthopedicConsult = :orthopedicConsult,
                     vascularConsult = :vascularConsult, otherConsults = :otherConsults,
                     igs2Score = :igs2Score
             WHERE idShockRoomAssessment = :id
               AND idClinicalImpression IN (
                   SELECT idClinicalImpression FROM ClinicalImpression WHERE removedAt IS NULL
               )'
        );
        $stmt->execute(['id' => $id] + $this->values($a));

        // MariaDB ne compte pas une ligne réécrite à l'identique : on vérifie alors qu'elle est toujours lisible.
        return $stmt->rowCount() === 1 || $this->isReadable($id);
    }

    /** Le bilan existe et son évaluation est active. */
    private function isReadable(string $idShockRoomAssessment): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ShockRoomAssessment s
             JOIN ClinicalImpression c ON c.idClinicalImpression = s.idClinicalImpression
             WHERE s.idShockRoomAssessment = :id AND c.removedAt IS NULL'
        );
        $stmt->execute(['id' => $idShockRoomAssessment]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Valeurs des champs cliniques, une par colonne de ShockRoomAssessment::FIELDS.
     *
     * @return array<string, scalar|null>
     */
    private function values(ShockRoomAssessment $a): array
    {
        return [
            'arrivalGrade' => $a->getArrivalGrade(),
            'traumaType' => $a->getTraumaType(),
            'traumaMechanism' => $a->getTraumaMechanism(),
            'originService' => $a->getOriginService(),
            'smurDurationMinutes' => $a->getSmurDurationMinutes(),
            'glasgowScore' => $a->getGlasgowScore(),
            'hemocue1' => $a->getHemocue1(),
            'hemocue2' => $a->getHemocue2(),
            'smurTreatments' => $a->getSmurTreatments(),
            'crystalloidsVolumeMl' => $a->getCrystalloidsVolumeMl(),
            'shockRoomDurationMinutes' => $a->getShockRoomDurationMinutes(),
            'seniorDoctor' => $a->getSeniorDoctor(),
            'residentDoctor' => $a->getResidentDoctor(),
            'medicalStudent' => $a->getMedicalStudent(),
            'efastResult' => $a->getEfastResult(),
            'leftPupil' => $a->getLeftPupil(),
            'rightPupil' => $a->getRightPupil(),
            'capillaryRefillSeconds' => $a->getCapillaryRefillSeconds(),
            'lesionAssessment' => $a->getLesionAssessment(),
            'paraclinicalWorkup' => $a->getParaclinicalWorkup(),
            'ctScanWorkup' => $a->getCtScanWorkup(),
            'otherImaging' => $a->getOtherImaging(),
            'abnormalLabResults' => $a->getAbnormalLabResults(),
            'visceralConsult' => $a->getVisceralConsult(),
            'orthopedicConsult' => $a->getOrthopedicConsult(),
            'vascularConsult' => $a->getVascularConsult(),
            'otherConsults' => $a->getOtherConsults(),
            'igs2Score' => $a->getIgs2Score(),
        ];
    }

    /**
     * Plus grand identifiant + 1, table verrouillée (FOR UPDATE) jusqu'à la fin de la transaction.
     */
    private function nextId(): int
    {
        return (int) $this->pdo
            ->query('SELECT COALESCE(MAX(CAST(idShockRoomAssessment AS UNSIGNED)), 0) + 1
                     FROM ShockRoomAssessment FOR UPDATE')
            ->fetchColumn();
    }

    /** @param array<string, ?string> $r ligne aux colonnes de COLUMNS */
    private function map(array $r): ShockRoomAssessment
    {
        return new ShockRoomAssessment(
            idShockRoomAssessment: (string) $r['idShockRoomAssessment'],
            idClinicalImpression: (string) $r['idClinicalImpression'],
            arrivalGrade: $r['arrivalGrade'],
            traumaType: $r['traumaType'],
            traumaMechanism: $r['traumaMechanism'],
            originService: $r['originService'],
            smurDurationMinutes: $r['smurDurationMinutes'] !== null ? (int) $r['smurDurationMinutes'] : null,
            glasgowScore: $r['glasgowScore'] !== null ? (int) $r['glasgowScore'] : null,
            hemocue1: $r['hemocue1'] !== null ? (float) $r['hemocue1'] : null,
            hemocue2: $r['hemocue2'] !== null ? (float) $r['hemocue2'] : null,
            smurTreatments: $r['smurTreatments'],
            crystalloidsVolumeMl: $r['crystalloidsVolumeMl'] !== null ? (int) $r['crystalloidsVolumeMl'] : null,
            shockRoomDurationMinutes: $r['shockRoomDurationMinutes'] !== null
                ? (int) $r['shockRoomDurationMinutes']
                : null,
            seniorDoctor: $r['seniorDoctor'],
            residentDoctor: $r['residentDoctor'],
            medicalStudent: $r['medicalStudent'],
            efastResult: $r['efastResult'],
            leftPupil: $r['leftPupil'],
            rightPupil: $r['rightPupil'],
            capillaryRefillSeconds: $r['capillaryRefillSeconds'] !== null ? (float) $r['capillaryRefillSeconds'] : null,
            lesionAssessment: $r['lesionAssessment'],
            paraclinicalWorkup: $r['paraclinicalWorkup'],
            ctScanWorkup: $r['ctScanWorkup'],
            otherImaging: $r['otherImaging'],
            abnormalLabResults: $r['abnormalLabResults'],
            visceralConsult: $r['visceralConsult'],
            orthopedicConsult: $r['orthopedicConsult'],
            vascularConsult: $r['vascularConsult'],
            otherConsults: $r['otherConsults'],
            igs2Score: $r['igs2Score'] !== null ? (int) $r['igs2Score'] : null
        );
    }
}
