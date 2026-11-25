<?php

declare(strict_types=1);

namespace Aura\Tests\Domain;

use Aura\Domain\ClinicalImpression;
use Aura\Domain\ClinicalObservation;
use Aura\Domain\ClinicalScales;
use Aura\Domain\EpisodeDiagnosis;
use Aura\Domain\ShockRoomAssessment;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Entités et référentiels des évaluations patient (modèle v2, sprint 2), sans base : méthodes de lecture
 * (isShockRoom, isRemoved, isActive) et cohérence des catalogues avec le schéma SQL.
 */
final class ClinicalImpressionTest extends TestCase
{
    public function testKindAndRemovalAreReadFromTheColumns(): void
    {
        $at = new DateTimeImmutable('2030-03-01 10:00:00');
        $standard = new ClinicalImpression(null, 1, 'PRA01', ClinicalImpression::KIND_STANDARD, $at);
        $shockRoom = new ClinicalImpression(
            '7',
            1,
            'PRA01',
            ClinicalImpression::KIND_SHOCK_ROOM,
            $at,
            removedAt: $at
        );

        self::assertFalse($standard->isShockRoom());
        self::assertFalse($standard->isRemoved());
        self::assertTrue($shockRoom->isShockRoom());
        self::assertTrue($shockRoom->isRemoved());
        self::assertSame(['STANDARD', 'SHOCK_ROOM'], array_keys(ClinicalImpression::KINDS), 'valeurs du CHECK');
    }

    public function testOnlyAnOngoingNotRefutedAndNotRemovedDiagnosisIsActive(): void
    {
        $at = new DateTimeImmutable('2030-03-01 10:00:00');

        $diagnosis = static fn(string $clinical, string $verification, ?DateTimeImmutable $removedAt = null) =>
            new EpisodeDiagnosis(null, 1, 'PTH01', $at, $clinical, $verification, removedAt: $removedAt);

        self::assertTrue($diagnosis('ACTIVE', 'CONFIRMED')->isActive());
        self::assertTrue($diagnosis('ACTIVE', 'PROVISIONAL')->isActive(), 'suspecté, en cours');
        self::assertFalse($diagnosis('ACTIVE', 'REFUTED')->isActive(), 'écarté');
        self::assertFalse($diagnosis('RESOLVED', 'CONFIRMED')->isActive(), 'résolu');
        self::assertFalse($diagnosis('ACTIVE', 'CONFIRMED', $at)->isActive(), 'retiré');
        self::assertSame(['ACTIVE', 'RESOLVED'], array_keys(EpisodeDiagnosis::CLINICAL_STATUSES), 'CHECK');
        self::assertSame(
            ['PROVISIONAL', 'CONFIRMED', 'REFUTED'],
            array_keys(EpisodeDiagnosis::VERIFICATION_STATUSES),
            'CHECK'
        );
    }

    public function testEverySystemHasANormalizedListThatFitsItsColumn(): void
    {
        self::assertSame(
            array_values(array_diff(ClinicalObservation::STANDARD_FORM, ClinicalObservation::OTHER_INFORMATION)),
            array_keys(ClinicalScales::OBSERVATION_LISTS),
            'une liste par système physiologique, dans l\'ordre de l\'écran ; aucune pour les autres informations'
        );
        self::assertSame(
            ClinicalObservation::STANDARD_FORM,
            array_keys(ClinicalObservation::CATEGORIES),
            'toutes les catégories du modèle sont saisies par l\'évaluation classique, dans l\'ordre de l\'écran'
        );
        foreach (array_keys(ClinicalScales::VENTILATION_MODES) as $mode) {
            self::assertLessThanOrEqual(2, strlen($mode), 'VS / VC');
        }
        foreach (ClinicalScales::OBSERVATION_LISTS as $category => $values) {
            self::assertNotEmpty($values, $category);
            foreach (array_keys($values) as $value) {
                self::assertLessThanOrEqual(200, mb_strlen((string) $value), 'valueCodeableConcept VARCHAR(200)');
            }
        }
        foreach (array_keys(ClinicalObservation::CATEGORIES) as $category) {
            self::assertLessThanOrEqual(30, strlen($category), 'ClinicalObservation.category VARCHAR(30)');
        }
        foreach (array_keys(ClinicalScales::ARRIVAL_GRADES) as $grade) {
            self::assertLessThanOrEqual(50, strlen($grade), 'ShockRoomAssessment.arrivalGrade VARCHAR(50)');
        }
    }

    public function testScoreBoundsMatchTheirComponents(): void
    {
        $min = 0;
        $max = 0;
        foreach (ClinicalScales::GLASGOW_COMPONENTS as $levels) {
            $min += min(array_keys($levels));
            $max += max(array_keys($levels));
        }

        self::assertSame(['min' => $min, 'max' => $max], ClinicalScales::GLASGOW, 'Glasgow : 3 à 15');
        self::assertSame(['min' => 0, 'max' => 163], ClinicalScales::IGS2);
        self::assertSame(['min' => 0, 'max' => 75], ClinicalScales::ISS, 'ISS : 3 × 5², bornes du CHECK');
    }

    public function testShockRoomFieldsListEveryClinicalParameter(): void
    {
        $parameters = array_map(
            static fn($p) => $p->getName(),
            (new ReflectionClass(ShockRoomAssessment::class))->getConstructor()?->getParameters() ?? []
        );

        self::assertSame(
            array_merge(['idShockRoomAssessment', 'idClinicalImpression'], array_keys(ShockRoomAssessment::FIELDS)),
            $parameters
        );
    }
}
