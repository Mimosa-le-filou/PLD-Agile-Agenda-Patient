<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\ClinicalImpressionDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Service\EvaluationRejected;
use Aura\Service\EvaluationService;
use Aura\Service\PatientRecordService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Parcours complet de création d'une évaluation classique (UC1) sur la vraie base : EvaluationService avec ses
 * DAO, transaction, trace AuditEvent, puis relecture dans l'historique du dossier (UC3). Données de
 * database/test.sql : séjour 1 en cours (admis le 10/10/2026), séjour 21 clos, médecin PRA01.
 *
 * Ignoré sans DB_HOST / DB_NAME ; tout est exécuté dans une transaction annulée à la fin (le service s'y
 * rattache).
 */
final class EvaluationIntegrationTest extends TestCase
{
    private PDO $pdo;

    private EvaluationService $service;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->service = new EvaluationService(
            new ClinicalImpressionDao($this->pdo),
            new AuditEventDao($this->pdo),
            new Transaction($this->pdo),
            new PatientRecordService(
                new EpisodeOfCareDao($this->pdo),
                new PatientDao($this->pdo),
                new AllergyIntoleranceDao($this->pdo),
                new SubstanceDao($this->pdo),
                new DateTimeImmutable('2026-10-14'),
                new ReferenceDataDao($this->pdo)
            ),
            new ReferenceDataDao($this->pdo),
            new DateTimeImmutable('2026-10-14 15:00:00')
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testEvaluationIsSavedTracedAndShownInTheEpisodeHistory(): void
    {
        $input = $this->service->emptyInput();
        $input['effectiveDateTime'] = '2026-10-14T14:00';
        $input['description'] = 'Zzintegration : réévaluation après réchauffement';
        $input['aggressionFactor'] = '1,3';
        $input['ventilationMode'] = 'VC';
        $input['mgSummary1'] = 'Zzintegration : lambeau prévu';
        $input['observations']['METABOLIC'] = ['value' => '', 'note' => 'Hypokaliémie', 'interpretation' => ''];
        $input['echocardiography'] = 'FEVG 55 %';
        $input['observations']['NEUROLOGICAL'] = ['value' => 'A', 'note' => 'G15', 'interpretation' => ''];
        $input['observations']['RESPIRATORY'] = ['value' => '', 'note' => '', 'interpretation' => 'Rx normale'];
        $input['idPractitioner'] = 'PRA01';
        $input['password'] = 'x';

        $id = $this->service->createStandard(1, $input);

        $history = $this->service->history(1);
        self::assertSame($id, $history[0]['id'] ?? null, 'la plus récente en tête de l\'historique du séjour');
        self::assertSame('Classique', $history[0]['kindLabel']);
        self::assertSame('14/10/2026 - 14:00', $history[0]['effectiveDateTime']);
        self::assertStringStartsWith('Dr ', $history[0]['practitioner']);
        self::assertSame(
            [
                'Examen et évaluation', 'Neurologique', 'Cardiovasculaire', 'Respiratoire et pulmonaire',
                'État métabolique', 'Conclusion',
            ],
            array_column($history[0]['sections'], 'title'),
            'ordre de l\'écran ; l\'échocardiographie seule ouvre le bloc cardiovasculaire'
        );

        // Patiente 1 de test.sql (F, née le 14/03/1958, 62,5 kg, 164 cm) : DER de base 1 239 kcal/j × 1,3.
        self::assertStringContainsString('1611 kcal/j', (string) $history[0]['der'], 'DER calculée et enregistrée');

        $events = (new AuditEventDao($this->pdo))->findByEntity(AuditEvent::ENTITY_CLINICAL_IMPRESSION, $id);
        self::assertCount(1, $events, 'une trace par création (RG10)');
        self::assertSame(EvaluationService::AUDIT_FIELD_CREATED, $events[0]->getField());
        self::assertStringContainsString('authentification factice', $events[0]->getAuthor());
        self::assertSame([], $this->service->history(2), 'l\'évaluation n\'apparaît pas dans un autre séjour');
    }

    public function testRefusedEvaluationWritesNothing(): void
    {
        $count = fn (): int => (int) $this->pdo->query('SELECT COUNT(*) FROM ClinicalImpression')->fetchColumn();
        $audits = fn (): int => (int) $this->pdo
            ->query("SELECT COUNT(*) FROM AuditEvent WHERE entity = 'ClinicalImpression'")->fetchColumn();
        $before = [$count(), $audits()];
        $input = ['description' => 'Zzintegration', 'idPractitioner' => 'PRA01', 'password' => 'x']
            + $this->service->emptyInput();

        foreach ([[21, 404], [999999, 404]] as [$idEpisodeOfCare, $status]) {
            try {
                $this->service->createStandard($idEpisodeOfCare, $input);
                self::fail('Séjour clos ou inexistant : refus attendu.');
            } catch (EvaluationRejected $e) {
                self::assertSame($status, $e->status);
            }
        }
        try {
            $this->service->createStandard(1, ['observations' => ['NEUROLOGICAL' => ['value' => 'ZZ']]] + $input);
            self::fail('Valeur hors liste : refus attendu.');
        } catch (EvaluationRejected $e) {
            self::assertSame(422, $e->status);
        }

        self::assertSame($before, [$count(), $audits()], 'ni évaluation ni trace');
    }
}
