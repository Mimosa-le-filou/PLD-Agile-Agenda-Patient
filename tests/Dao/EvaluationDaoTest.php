<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\CarePlanDao;
use Aura\Dao\ClinicalImpressionDao;
use Aura\Dao\ClinicalObservationDao;
use Aura\Dao\EpisodeDiagnosisDao;
use Aura\Dao\ShockRoomAssessmentDao;
use Aura\Domain\CarePlan;
use Aura\Domain\ClinicalImpression;
use Aura\Domain\ClinicalObservation;
use Aura\Domain\EpisodeDiagnosis;
use Aura\Domain\ShockRoomAssessment;
use DateTimeImmutable;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * DAO des évaluations patient (sprint 1) : ClinicalImpressionDao, ClinicalObservationDao, ShockRoomAssessmentDao,
 * EpisodeDiagnosisDao. Insertion puis relecture, mise à jour, et isolation des lignes supprimées logiquement
 * (removedAt) : elles restent en base mais ne sont plus lues. Données de database/test.sql : séjour 1, praticien
 * PRA01, pathologies PTH01 à PTH03.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class EvaluationDaoTest extends TestCase
{
    private PDO $pdo;

    private ClinicalImpressionDao $impressions;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->impressions = new ClinicalImpressionDao($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testStandardImpressionIsSavedWithItsObservations(): void
    {
        $at = new DateTimeImmutable('2030-03-01 10:00:00');
        $impression = new ClinicalImpression(
            idClinicalImpression: null,
            idEpisodeOfCare: 1,
            idPractitioner: 'PRA01',
            kind: ClinicalImpression::KIND_STANDARD,
            evaluatedAt: $at,
            generalNote: 'Patient stable',
            aggressionFactor: 1.3,
            restingEnergyExpenditure: 1650,
            conclusion: 'Sepsis d\'origine urinaire',
            orientation: 'Maintien en réanimation',
            observations: [
                // Volontairement dans le désordre : la lecture suit l'ordre de l'écran.
                $this->observation(ClinicalObservation::CATEGORY_URORENAL, '1', null, $at),
                $this->observation(ClinicalObservation::CATEGORY_NEUROLOGICAL, 'A', 'G15, pupilles symétriques', $at),
                new ClinicalObservation(
                    null,
                    '',
                    ClinicalObservation::CATEGORY_CARDIOVASCULAR,
                    $at,
                    'SINUS',
                    'FC 80 bpm',
                    'ECG : sinusal régulier, 70 bpm'
                ),
            ]
        );

        $id = $this->impressions->insert($impression);
        $saved = $this->impressions->findById($id);

        self::assertNotNull($saved);
        self::assertSame($id, $saved->getIdClinicalImpression());
        self::assertSame(1, $saved->getIdEpisodeOfCare());
        self::assertSame('PRA01', $saved->getIdPractitioner());
        self::assertFalse($saved->isShockRoom());
        self::assertEquals($at, $saved->getEvaluatedAt());
        self::assertSame('Patient stable', $saved->getGeneralNote());
        self::assertSame(1.3, $saved->getAggressionFactor());
        self::assertSame(1650, $saved->getRestingEnergyExpenditure());
        self::assertSame('Maintien en réanimation', $saved->getOrientation());
        self::assertNotNull($saved->getRecordedAt(), 'heure de saisie remplie par la base (RG10)');
        self::assertNull($saved->getShockRoomAssessment());
        self::assertSame(
            ['NEUROLOGICAL', 'CARDIOVASCULAR', 'URORENAL'],
            array_map(static fn(ClinicalObservation $o) => $o->getCategory(), $saved->getObservations())
        );
        $cardio = $saved->getObservations()[1];
        self::assertSame($id, $cardio->getIdClinicalImpression(), 'fiche rattachée à l\'évaluation créée');
        self::assertSame('SINUS', $cardio->getSelectedValue());
        self::assertSame('ECG : sinusal régulier, 70 bpm', $cardio->getExamInterpretation());

        $ofEpisode = $this->impressions->findByEpisodeOfCare(1);
        self::assertContains($id, $this->impressionIds($ofEpisode));
        self::assertSame([], $this->impressions->findByEpisodeOfCare(999999), 'autre séjour : rien');
    }

    public function testShockRoomImpressionIsSavedWithItsAssessment(): void
    {
        $id = $this->impressions->insert($this->shockRoomImpression());
        $assessment = $this->impressions->findById($id)?->getShockRoomAssessment();

        self::assertNotNull($assessment);
        self::assertNotNull($assessment->getIdShockRoomAssessment());
        self::assertSame($id, $assessment->getIdClinicalImpression());
        self::assertSame('A', $assessment->getArrivalGrade());
        self::assertSame(9, $assessment->getGlasgowScore());
        self::assertSame(11.5, $assessment->getHemocue1());
        self::assertSame(2.5, $assessment->getCapillaryRefillSeconds());
        self::assertSame(1500, $assessment->getCrystalloidsVolumeMl());
        self::assertSame('3 orifices balistiques', $assessment->getLesionAssessment());
        self::assertSame(48, $assessment->getIgs2Score());
        self::assertNull($assessment->getOtherConsults());
    }

    public function testShockRoomAssessmentIsRefusedOnAStandardImpression(): void
    {
        $standard = new ClinicalImpression(
            null,
            1,
            'PRA01',
            ClinicalImpression::KIND_STANDARD,
            new DateTimeImmutable('2030-03-01 10:00:00'),
            shockRoomAssessment: new ShockRoomAssessment(null, '', glasgowScore: 15)
        );
        try {
            $this->impressions->insert($standard);
            self::fail('Un bilan de déchocage joint à une évaluation classique doit être refusé.');
        } catch (LogicException) {
        }

        $idStandard = $this->impressions->insert(
            new ClinicalImpression(
                null,
                1,
                'PRA01',
                ClinicalImpression::KIND_STANDARD,
                new DateTimeImmutable('2030-03-01 10:00:00')
            )
        );
        $this->expectException(LogicException::class);
        (new ShockRoomAssessmentDao($this->pdo))->insert(new ShockRoomAssessment(null, $idStandard, glasgowScore: 15));
    }

    public function testUpdatesRewriteTheImpressionItsObservationAndItsAssessment(): void
    {
        $id = $this->impressions->insert($this->shockRoomImpression());
        $saved = $this->impressions->findById($id);
        self::assertNotNull($saved);

        $later = new DateTimeImmutable('2030-03-01 12:00:00');
        self::assertTrue($this->impressions->update(new ClinicalImpression(
            $id,
            1,
            'PRA01',
            ClinicalImpression::KIND_SHOCK_ROOM,
            $later,
            conclusion: 'Polytraumatisé stabilisé'
        )));
        $observation = $saved->getObservations()[0];
        $observations = new ClinicalObservationDao($this->pdo);
        self::assertTrue($observations->update(new ClinicalObservation(
            $observation->getIdClinicalObservation(),
            $id,
            $observation->getCategory(),
            $later,
            'NIV',
            'Relais VNI'
        )));
        $assessment = $saved->getShockRoomAssessment();
        self::assertNotNull($assessment);
        $shockRoom = new ShockRoomAssessmentDao($this->pdo);
        $rewritten = new ShockRoomAssessment($assessment->getIdShockRoomAssessment(), $id, glasgowScore: 13);
        self::assertTrue($shockRoom->update($rewritten));
        self::assertTrue($shockRoom->update($rewritten), 'réécriture à l\'identique : la ligne existe toujours');

        $reread = $this->impressions->findById($id);
        self::assertNotNull($reread);
        self::assertEquals($later, $reread->getEvaluatedAt());
        self::assertSame('Polytraumatisé stabilisé', $reread->getConclusion());
        self::assertNull($reread->getGeneralNote(), 'tout l\'en-tête est réécrit');
        self::assertSame('NIV', $reread->getObservations()[0]->getSelectedValue());
        self::assertSame(13, $reread->getShockRoomAssessment()?->getGlasgowScore());
        self::assertNull($reread->getShockRoomAssessment()?->getArrivalGrade());
    }

    public function testRemovedImpressionStaysInTheDatabaseButIsNoLongerRead(): void
    {
        $id = $this->impressions->insert($this->shockRoomImpression());

        self::assertTrue($this->impressions->markRemoved($id));
        self::assertFalse($this->impressions->markRemoved($id), 'déjà retirée');

        self::assertNull($this->impressions->findById($id));
        self::assertNotContains($id, $this->impressionIds($this->impressions->findByEpisodeOfCare(1)));
        self::assertNull((new ShockRoomAssessmentDao($this->pdo))->findByClinicalImpression($id), 'le bilan suit');
        self::assertFalse($this->impressions->update(new ClinicalImpression(
            $id,
            1,
            'PRA01',
            ClinicalImpression::KIND_SHOCK_ROOM,
            new DateTimeImmutable('2030-03-02 08:00:00')
        )), 'une évaluation retirée ne se modifie plus');

        $stmt = $this->pdo->prepare('SELECT removedAt FROM ClinicalImpression WHERE idClinicalImpression = :id');
        $stmt->execute(['id' => $id]);
        self::assertNotNull($stmt->fetchColumn(), 'la ligne reste en base avec sa date de retrait (RG10)');
    }

    public function testRemovedObservationIsHiddenAndItsSystemCanBeFilledAgain(): void
    {
        $id = $this->impressions->insert($this->shockRoomImpression());
        $observations = new ClinicalObservationDao($this->pdo);
        $first = $observations->findByClinicalImpression($id)[0];
        $idFirst = (string) $first->getIdClinicalObservation();

        self::assertTrue($observations->markRemoved($idFirst));
        self::assertFalse($observations->markRemoved($idFirst), 'déjà retirée');
        self::assertNull($observations->findById($idFirst));
        self::assertSame([], $observations->findByClinicalImpression($id));
        self::assertFalse($observations->update($first), 'une fiche retirée ne se modifie plus');

        $idSecond = $observations->insert(new ClinicalObservation(
            null,
            $id,
            $first->getCategory(),
            new DateTimeImmutable('2030-03-01 11:00:00'),
            notes: 'Nouvelle fiche du même système'
        ));
        self::assertNotSame($idFirst, $idSecond);
        self::assertSame(
            [$idSecond],
            array_map(
                static fn(ClinicalObservation $o) => $o->getIdClinicalObservation(),
                $observations->findByClinicalImpression($id)
            )
        );
    }

    public function testDiagnosisIsSavedUpdatedAndRemovedLogically(): void
    {
        $idImpression = $this->impressions->insert($this->shockRoomImpression());
        $diagnoses = new EpisodeDiagnosisDao($this->pdo);
        $at = new DateTimeImmutable('2030-03-01 10:30:00');

        $id = $diagnoses->insert(new EpisodeDiagnosis(
            null,
            1,
            'PTH01',
            EpisodeDiagnosis::STATUS_SUSPECTED,
            $at,
            $idImpression,
            'Foyer à préciser'
        ));
        $saved = $diagnoses->findById($id);
        self::assertNotNull($saved);
        self::assertSame('PTH01', $saved->getIdPathology());
        self::assertSame($idImpression, $saved->getIdClinicalImpression());
        self::assertEquals($at, $saved->getDiagnosedAt());
        self::assertFalse($saved->isActive(), 'suspecté : pas encore retenu');
        self::assertSame([$id], $this->ids($diagnoses->findByClinicalImpression($idImpression)));
        self::assertContains($id, $this->ids($diagnoses->findByEpisodeOfCare(1)));

        self::assertTrue($diagnoses->updateStatus($id, EpisodeDiagnosis::STATUS_ACTIVE, 'Pyélonéphrite'));
        self::assertTrue($diagnoses->findById($id)?->isActive());

        self::assertTrue($diagnoses->markRemoved($id));
        self::assertFalse($diagnoses->markRemoved($id), 'déjà retiré');
        self::assertNull($diagnoses->findById($id));
        self::assertNotContains($id, $this->ids($diagnoses->findByEpisodeOfCare(1)));
        self::assertFalse($diagnoses->updateStatus($id, EpisodeDiagnosis::STATUS_RESOLVED, null));

        $again = $diagnoses->insert(new EpisodeDiagnosis(null, 1, 'PTH01', EpisodeDiagnosis::STATUS_ACTIVE, $at));
        self::assertContains($again, $this->ids($diagnoses->findByEpisodeOfCare(1)), 'pathologie ressaisie');
    }

    public function testPathologiesAreListedAlphabetically(): void
    {
        $labels = array_map(
            static fn($p) => $p->getLabel(),
            (new EpisodeDiagnosisDao($this->pdo))->pathologies()
        );

        self::assertContains('Sepsis et choc septique', $labels);
        $sorted = $labels;
        sort($sorted);
        self::assertSame($sorted, $labels);
    }

    public function testCarePlanKeepsTheImpressionItComesFrom(): void
    {
        $idImpression = $this->impressions->insert($this->shockRoomImpression());
        $carePlans = new CarePlanDao($this->pdo);

        $idCarePlan = $carePlans->insert(
            new CarePlan(null, 1, 'PRA01', 'MEDICAL', [], idClinicalImpression: $idImpression)
        );

        $saved = array_values(array_filter(
            $carePlans->findByEpisodeOfCare(1),
            static fn(CarePlan $c) => $c->getIdCarePlan() === $idCarePlan
        ));
        self::assertCount(1, $saved);
        self::assertSame($idImpression, $saved[0]->getIdClinicalImpression());
    }

    /** Fiche de déchocage complète : une fiche respiratoire et un bilan. */
    private function shockRoomImpression(): ClinicalImpression
    {
        $at = new DateTimeImmutable('2030-03-01 10:00:00');

        return new ClinicalImpression(
            idClinicalImpression: null,
            idEpisodeOfCare: 1,
            idPractitioner: 'PRA01',
            kind: ClinicalImpression::KIND_SHOCK_ROOM,
            evaluatedAt: $at,
            generalNote: 'Admission après SMUR',
            observations: [$this->observation(ClinicalObservation::CATEGORY_RESPIRATORY, 'INVASIVE', null, $at)],
            shockRoomAssessment: new ShockRoomAssessment(
                idShockRoomAssessment: null,
                idClinicalImpression: '',
                arrivalGrade: 'A',
                glasgowScore: 9,
                hemocue1: 11.5,
                crystalloidsVolumeMl: 1500,
                capillaryRefillSeconds: 2.5,
                lesionAssessment: '3 orifices balistiques',
                igs2Score: 48
            )
        );
    }

    private function observation(
        string $category,
        ?string $selectedValue,
        ?string $notes,
        DateTimeImmutable $at
    ): ClinicalObservation {
        return new ClinicalObservation(null, '', $category, $at, $selectedValue, $notes);
    }

    /**
     * @param ClinicalImpression[] $impressions
     * @return list<?string>
     */
    private function impressionIds(array $impressions): array
    {
        return array_map(static fn(ClinicalImpression $i) => $i->getIdClinicalImpression(), $impressions);
    }

    /**
     * @param EpisodeDiagnosis[] $diagnoses
     * @return list<?string>
     */
    private function ids(array $diagnoses): array
    {
        return array_map(static fn(EpisodeDiagnosis $d) => $d->getIdEpisodeDiagnosis(), $diagnoses);
    }
}
