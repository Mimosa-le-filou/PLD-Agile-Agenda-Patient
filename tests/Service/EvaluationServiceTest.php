<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\ClinicalImpressionDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\ClinicalImpression;
use Aura\Domain\ClinicalObservation;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\Patient;
use Aura\Domain\Practitioner;
use Aura\Service\EvaluationRejected;
use Aura\Service\EvaluationService;
use Aura\Service\PatientRecordService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Évaluation médicale classique (sprint 2, UC1, champs de l'écran du service du 24/11/2026), sans base :
 * validate() est pure (horloge injectée) et createStandard() est testé avec des DAO doublés — refus 404 / 403 /
 * 422 sans aucune écriture, et création complète (évaluation, fiches renseignées seulement, compléments JSON, DER
 * calculée, trace AuditEvent au nom du médecin, dans la transaction).
 */
final class EvaluationServiceTest extends TestCase
{
    private const NOW = '2026-10-14 15:00:00';

    public function testNominalInputIsAccepted(): void
    {
        self::assertSame([], $this->service()->validate($this->nominalInput()));
    }

    public function testDateIsRequiredValidAndNotInTheFuture(): void
    {
        $service = $this->service();

        self::assertArrayHasKey(
            'effectiveDateTime',
            $service->validate(['effectiveDateTime' => ''] + $this->nominalInput()),
            'obligatoire'
        );
        self::assertArrayHasKey(
            'effectiveDateTime',
            $service->validate(['effectiveDateTime' => '2026-02-30T10:00'] + $this->nominalInput()),
            'date inexistante'
        );
        self::assertArrayHasKey(
            'effectiveDateTime',
            $service->validate(['effectiveDateTime' => '2026-10-14T16:00'] + $this->nominalInput()),
            'une heure dans le futur'
        );
        self::assertSame(
            [],
            $service->validate(['effectiveDateTime' => '2026-10-14T15:04'] + $this->nominalInput()),
            'quelques minutes d\'avance tolérées (horloge du poste)'
        );
    }

    public function testValueOutsideTheNormalizedListIsRejected(): void
    {
        $input = $this->nominalInput();
        $input['observations'][ClinicalObservation::CATEGORY_NEUROLOGICAL]['value'] = 'GCS15';

        $errors = $this->service()->validate($input);

        self::assertSame(['observations[NEUROLOGICAL][value]'], array_keys($errors), 'erreur sous le bon champ');
    }

    public function testAggressionFactorVentilationModeAndAuthorAreChecked(): void
    {
        $service = $this->service();

        self::assertArrayHasKey(
            'aggressionFactor',
            $service->validate(['aggressionFactor' => '0,8'] + $this->nominalInput()),
            'sous 1'
        );
        self::assertArrayHasKey(
            'aggressionFactor',
            $service->validate(['aggressionFactor' => '-1,3'] + $this->nominalInput()),
            'négatif'
        );
        self::assertArrayHasKey(
            'aggressionFactor',
            $service->validate(['aggressionFactor' => 'élevé'] + $this->nominalInput())
        );
        self::assertSame([], $service->validate(['aggressionFactor' => '1,30'] + $this->nominalInput()), 'virgule');
        self::assertArrayHasKey(
            'ventilationMode',
            $service->validate(['ventilationMode' => 'VNI'] + $this->nominalInput()),
            'VS/VC : liste blanche'
        );
        self::assertArrayHasKey(
            'initiallyCompletedBy',
            $service->validate(['initiallyCompletedBy' => '<script>'] + $this->nominalInput())
        );
    }

    public function testOtherInformationIsFreeTextOnlyAndSedationCanBeNone(): void
    {
        $input = $this->nominalInput();
        $input['observations'][ClinicalObservation::CATEGORY_SEDATION]['value'] = 'NONE';
        $input['observations'][ClinicalObservation::CATEGORY_CUTANEOUS]['note'] = 'Pansement au scarpa gauche';
        self::assertSame([], $this->service()->validate($input), 'sédation « Non » et note cutanéo-muqueuse');

        $input['observations'][ClinicalObservation::CATEGORY_BMR_SCREENING]['value'] = 'POSITIVE';
        self::assertArrayHasKey(
            'observations[BMR_SCREENING][value]',
            $this->service()->validate($input),
            'pas de liste pour les « Autres informations »'
        );
    }

    public function testAnEmptyEvaluationAndTooLongTextsAreRejected(): void
    {
        $service = $this->service();

        $errors = $service->validate($service->emptyInput());
        self::assertSame([EvaluationService::FORM_ERROR], array_keys($errors), 'heure seule : évaluation vide');

        $long = ['researchProtocols' => str_repeat('x', EvaluationService::RESEARCH_PROTOCOLS_MAX_LENGTH + 1)]
            + $this->nominalInput();
        self::assertArrayHasKey('researchProtocols', $service->validate($long), 'VARCHAR(255)');

        $input = $this->nominalInput();
        $input['observations'][ClinicalObservation::CATEGORY_ABDOMINAL]['interpretation'] = 'Rx ASP';
        self::assertArrayHasKey(
            'observations[ABDOMINAL][interpretation]',
            $service->validate($input),
            'pas d\'interprétation d\'examen pour ce système'
        );
    }

    public function testUnknownOrClosedEpisodeIsNotFoundAndNothingIsWritten(): void
    {
        foreach ([null, new DateTimeImmutable('2026-10-14 12:00:00')] as $discharge) {
            $dao = $this->createMock(ClinicalImpressionDao::class);
            $dao->expects(self::never())->method('insert');
            try {
                $this->service($dao, episode: $discharge === null ? null : $this->episode($discharge))
                    ->createStandard(1, $this->nominalInput());
                self::fail('Séjour inexistant ou clos : refus attendu.');
            } catch (EvaluationRejected $e) {
                self::assertSame(404, $e->status);
            }
        }
    }

    public function testPractitionerWithoutValidationRightIsForbidden(): void
    {
        $dao = $this->createMock(ClinicalImpressionDao::class);
        $dao->expects(self::never())->method('insert');

        try {
            $this->service($dao)->createStandard(1, ['idPractitioner' => 'PRA04'] + $this->nominalInput());
            self::fail('Un soignant (sans droit RG5) ne peut pas enregistrer une évaluation.');
        } catch (EvaluationRejected $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testUnknownInitialAuthorIsRefused(): void
    {
        try {
            $this->service()->createStandard(1, ['initiallyCompletedBy' => 'PRA99'] + $this->nominalInput());
            self::fail('Praticien inconnu : refus attendu.');
        } catch (EvaluationRejected $e) {
            self::assertSame(422, $e->status);
            self::assertSame(['initiallyCompletedBy'], array_keys($e->errors));
        }
    }

    public function testMissingAuthenticationAndDateBeforeAdmissionAreFieldErrors(): void
    {
        $dao = $this->createMock(ClinicalImpressionDao::class);
        $dao->expects(self::never())->method('insert');

        try {
            $this->service($dao)->createStandard(1, [
                'idPractitioner' => '',
                'password' => '   ',
                'effectiveDateTime' => '2026-10-09T08:00',
            ] + $this->nominalInput());
            self::fail('Saisie refusée attendue.');
        } catch (EvaluationRejected $e) {
            self::assertSame(422, $e->status);
            self::assertEqualsCanonicalizing(
                ['effectiveDateTime', 'idPractitioner', 'password'],
                array_keys($e->errors),
                'avant l\'admission du 10/10/2026, médecin et mot de passe manquants'
            );
        }
    }

    public function testStandardEvaluationIsSavedWithItsFilledObservationsAndTraced(): void
    {
        $saved = null;
        $dao = $this->createMock(ClinicalImpressionDao::class);
        $dao->expects(self::once())->method('insert')
            ->willReturnCallback(static function (ClinicalImpression $impression) use (&$saved): string {
                $saved = $impression;
                return '42';
            });
        $traced = null;
        $audit = $this->createMock(AuditEventDao::class);
        $audit->expects(self::once())->method('insert')
            ->willReturnCallback(static function (AuditEvent $event) use (&$traced): int {
                $traced = $event;
                return 1;
            });

        $id = $this->service($dao, $audit)->createStandard(1, $this->nominalInput());

        self::assertSame('42', $id);
        self::assertInstanceOf(ClinicalImpression::class, $saved);
        self::assertSame(ClinicalImpression::KIND_STANDARD, $saved->getKind());
        self::assertSame('PRA01', $saved->getIdPractitioner(), 'médecin authentifié (factice)');
        self::assertEquals(new DateTimeImmutable('2026-10-14 14:00'), $saved->getEffectiveDateTime());
        self::assertSame(1.3, $saved->getAggressionFactor());
        // Harris et Benedict révisée, femme de 68 ans, 62,5 kg, 164 cm : 1 239,16 kcal/j × 1,3 = 1 611 kcal/j.
        self::assertSame(1611, $saved->getRestingEnergyExpenditure(), 'DER calculée, pas saisie');
        self::assertSame([
            'echocardiography' => 'FEVG 55 %',
            'ventilationMode' => 'VS',
            'initiallyCompletedBy' => 'PRA03',
            'mgAnamnesis' => 'Rupture AFS sur plaie radique',
        ], $saved->getDetails(), 'champs sans colonne propre dans details (JSON)');
        self::assertNull($saved->getOrientation(), 'pas d\'orientation dans l\'évaluation classique');
        self::assertSame(
            ['NEUROLOGICAL', 'CARDIOVASCULAR', 'CUTANEOUS'],
            array_map(static fn (ClinicalObservation $o): string => $o->getCategory(), $saved->getObservations()),
            'seuls les systèmes renseignés deviennent des fiches'
        );
        self::assertNull($saved->getObservations()[0]->getInterpretation(), 'champ vide : NULL');

        self::assertInstanceOf(AuditEvent::class, $traced);
        self::assertSame(AuditEvent::ENTITY_CLINICAL_IMPRESSION, $traced->getEntity());
        self::assertSame('42', $traced->getEntityId());
        self::assertSame(EvaluationService::AUDIT_FIELD_CREATED, $traced->getField());
        self::assertStringContainsString('Dr Claire Morel', $traced->getAuthor());
        self::assertStringContainsString('authentification factice', $traced->getAuthor());
        self::assertStringNotContainsString('secret', (string) $traced->getDetail(), 'mot de passe jamais tracé');
    }

    public function testPresentListsOnlyFilledSectionsWithListLabels(): void
    {
        $at = new DateTimeImmutable('2026-10-14 14:00');
        $view = EvaluationService::present(new ClinicalImpression(
            '7',
            1,
            'PRA01',
            ClinicalImpression::KIND_STANDARD,
            $at,
            details: ['echocardiography' => 'FEVG 55 %', 'ventilationMode' => 'VS', 'mgSummary1' => 'Lambeau'],
            recordedAt: $at,
            observations: [new ClinicalObservation('1', '7', ClinicalObservation::CATEGORY_HEMODYNAMIC, $at, 'STABLE')]
        ), ['PRA01' => 'Dr Claire Morel']);

        self::assertSame('Dr Claire Morel', $view['practitioner']);
        self::assertSame('Hémodynamique stable', $view['headline'], 'libellé de la liste, pas le code');
        $respiratory = $view['sections'][3]['lines'] ?? [];
        self::assertSame('VS : ventilation spontanée', $respiratory[0]['value'] ?? null, 'VS/VC en tête');
        self::assertSame(
            ['Examen et évaluation', 'Cardiovasculaire', 'Hémodynamique', 'Respiratoire et pulmonaire', 'Conclusion'],
            array_column($view['sections'], 'title'),
            'ordre de l\'écran ; échocardiographie et VS/VC affichés même sans fiche de leur système'
        );
    }

    /** Saisie complète et valide, telle que la lit EvaluationController::readInput(). */
    private function nominalInput(): array
    {
        $input = $this->service()->emptyInput();
        $input['effectiveDateTime'] = '2026-10-14T14:00';
        $input['description'] = 'Patient conscient, coopérant.';
        $input['initiallyCompletedBy'] = 'PRA03';
        $input['aggressionFactor'] = '1.3';
        $input['echocardiography'] = 'FEVG 55 %';
        $input['ventilationMode'] = 'VS';
        $input['observations'][ClinicalObservation::CATEGORY_CUTANEOUS] = [
            'value' => '', 'note' => 'Pansement et packing au scarpa gauche', 'interpretation' => '',
        ];
        $input['observations'][ClinicalObservation::CATEGORY_NEUROLOGICAL] = [
            'value' => 'A', 'note' => 'G15, pupilles symétriques', 'interpretation' => '',
        ];
        $input['observations'][ClinicalObservation::CATEGORY_CARDIOVASCULAR] = [
            'value' => 'SINUS', 'note' => '', 'interpretation' => 'Sinusal régulier, 70 bpm',
        ];
        $input['summary'] = 'Stable.';
        $input['mgAnamnesis'] = 'Rupture AFS sur plaie radique';
        $input['idPractitioner'] = 'PRA01';
        $input['password'] = 'secret';

        return $input;
    }

    private function episode(?DateTimeImmutable $discharge = null): EpisodeOfCare
    {
        return new EpisodeOfCare(
            1,
            1,
            '2026-000101',
            new DateTimeImmutable('2026-10-10 08:15:00'),
            $discharge,
            weight: 62.5,
            height: 164.0
        );
    }

    /** Service sur des DAO doublés ; séjour 1 en cours (admis le 10/10/2026) sauf $episode null passé exprès. */
    private function service(
        ?ClinicalImpressionDao $dao = null,
        ?AuditEventDao $audit = null,
        EpisodeOfCare|null|false $episode = false
    ): EvaluationService {
        $episodes = $this->createStub(EpisodeOfCareDao::class);
        $episodes->method('findById')->willReturn($episode === false ? $this->episode() : $episode);
        $episodes->method('findByPatient')->willReturn([]);
        $patients = $this->createStub(PatientDao::class);
        $patients->method('findById')
            ->willReturn(new Patient(1, '100000001', 'Simpson', 'Marge', new DateTimeImmutable('1958-03-14'), 'F'));
        $allergies = $this->createStub(AllergyIntoleranceDao::class);
        $allergies->method('findByPatient')->willReturn([]);
        $substances = $this->createStub(SubstanceDao::class);
        $substances->method('findAll')->willReturn([]);
        $references = $this->createStub(ReferenceDataDao::class);
        $references->method('doctors')->willReturn(['PRA01' => 'Dr Claire Morel', 'PRA02' => 'Dr Paul Martin']);
        $references->method('practitioners')->willReturn([
            'PRA01' => new Practitioner('PRA01', 'Morel', 'Claire', 'Médecin réanimateur', true),
            'PRA03' => new Practitioner('PRA03', 'Girard', 'Thomas', 'Interne', false),
        ]);
        $references->method('healthcareServices')->willReturn([]);
        $transaction = $this->createStub(Transaction::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $treatment): mixed => $treatment());

        return new EvaluationService(
            $dao ?? $this->createStub(ClinicalImpressionDao::class),
            $audit ?? $this->createStub(AuditEventDao::class),
            $transaction,
            new PatientRecordService(
                $episodes,
                $patients,
                $allergies,
                $substances,
                new DateTimeImmutable('2026-10-14'),
                $references
            ),
            $references,
            new DateTimeImmutable(self::NOW)
        );
    }
}
