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
 * Entités et référentiels des évaluations patient (sprint 1), sans base : méthodes de lecture
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
        self::assertSame(['STANDARD', 'SHOCK_ROOM'], ClinicalImpression::KINDS, 'valeurs du CHECK');
    }

    public function testOnlyARetainedAndNotRemovedDiagnosisIsActive(): void
    {
        $at = new DateTimeImmutable('2030-03-01 10:00:00');

        $diagnosis = static fn(string $status, ?DateTimeImmutable $removedAt = null) =>
            new EpisodeDiagnosis(null, 1, 'PTH01', $status, $at, removedAt: $removedAt);

        self::assertTrue($diagnosis(EpisodeDiagnosis::STATUS_ACTIVE)->isActive());
        self::assertFalse($diagnosis(EpisodeDiagnosis::STATUS_SUSPECTED)->isActive());
        self::assertFalse($diagnosis(EpisodeDiagnosis::STATUS_RESOLVED)->isActive());
        self::assertFalse($diagnosis(EpisodeDiagnosis::STATUS_REFUTED)->isActive());
        self::assertFalse($diagnosis(EpisodeDiagnosis::STATUS_ACTIVE, $at)->isActive(), 'retiré');
    }

    public function testEverySystemHasANormalizedListThatFitsItsColumn(): void
    {
        self::assertSame(
            array_keys(ClinicalObservation::CATEGORIES),
            array_keys(ClinicalScales::OBSERVATION_LISTS),
            'une liste par système, dans l\'ordre de l\'écran'
        );
        foreach (ClinicalScales::OBSERVATION_LISTS as $category => $values) {
            self::assertNotEmpty($values, $category);
            foreach (array_keys($values) as $value) {
                self::assertLessThanOrEqual(100, mb_strlen((string) $value), 'ClinicalObservation.selectedValue');
            }
        }
        foreach (array_keys(ClinicalScales::ARRIVAL_GRADES) as $grade) {
            self::assertLessThanOrEqual(5, strlen($grade), 'ShockRoomAssessment.arrivalGrade VARCHAR(5)');
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
    }

    public function testShockRoomFieldsListEveryClinicalParameter(): void
    {
        $parameters = array_map(
            static fn($p) => $p->getName(),
            (new ReflectionClass(ShockRoomAssessment::class))->getConstructor()?->getParameters() ?? []
        );

        self::assertSame(
            array_merge(['idShockRoomAssessment', 'idClinicalImpression'], ShockRoomAssessment::FIELDS),
            $parameters
        );
    }
}
