<?php

declare(strict_types=1);

namespace Aura\Tests\Http;

/**
 * UC1 de bout en bout (sprint 2) : bouton « Nouvelle évaluation » du dossier, formulaire avec bandeau patient
 * (RG2), refus 404 / 403 / 422 du POST /evaluations/create tel que l'envoie form.js, puis création et retour au
 * dossier, section « Évaluations et suivi ». Données de database/test.sql : séjour 1 en cours, séjour 21 clos.
 * Les évaluations créées (description « Zzhttpevaluation ») sont effacées dans un finally.
 */
final class EvaluationHttpTest extends HttpTestCase
{
    private const JSON = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];

    public function testFormIsReachableFromTheRecordWithThePatientBanner(): void
    {
        $record = $this->get('/sejour?id=1');
        self::assertStringContainsString('id="titre-evaluations"', $record->body, 'section du dossier');
        self::assertStringContainsString('href="/evaluations/new?idEpisodeOfCare=1"', $record->body);

        $form = $this->get('/evaluations/new?idEpisodeOfCare=1');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('patient-banner', $form->body, 'RG2 : bandeau patient');
        self::assertStringContainsString('SIMPSON', mb_strtoupper($form->body));
        self::assertStringContainsString('name="observations[URORENAL][value]"', $form->body, 'sept systèmes');
        self::assertStringContainsString(
            'name="observations[ABNORMAL_BIOLOGY][note]"',
            $form->body,
            'autres informations (mail du service)'
        );
        self::assertStringContainsString('name="mgAnamnesis"', $form->body, 'conclusion du service');
        self::assertStringNotContainsString('name="restingEnergyExpenditure"', $form->body, 'DER calculée');
        self::assertStringContainsString('/js/validation-fr.js', $form->body, 'messages natifs en français');
        self::assertStringContainsString('name="idPractitioner"', $form->body, 'authentification factice');

        self::assertSame(404, $this->get('/evaluations/new?idEpisodeOfCare=999999')->status, 'séjour inexistant');
        self::assertSame(404, $this->get('/evaluations/new?idEpisodeOfCare=21')->status, 'séjour clos');
        self::assertSame(400, $this->get('/evaluations/new?idEpisodeOfCare=abc')->status);
        self::assertStringNotContainsString(
            'href="/evaluations/new?idEpisodeOfCare=21"',
            $this->get('/sejour?id=21')->body,
            'pas de bouton sur un séjour clos'
        );
    }

    public function testCreationIsRefusedThenSavedAndShownInTheRecord(): void
    {
        $csrf = $this->csrfFrom($this->get('/evaluations/new?idEpisodeOfCare=1'));
        $input = [
            'effectiveDateTime' => '2026-10-14T14:00',
            'description' => 'Zzhttpevaluation : patient conscient',
            'observations' => ['NEUROLOGICAL' => ['value' => 'A', 'note' => 'G15']],
            'summary' => 'Zzhttpevaluation stable',
            'idPractitioner' => 'PRA01',
            'password' => 'x',
        ];
        $url = '/evaluations/create?idEpisodeOfCare=1';

        try {
            self::assertSame(403, $this->postForm($url, $input, self::JSON)->status, 'sans jeton CSRF');
            self::assertSame(
                403,
                $this->postForm($url, ['csrf' => $csrf, 'idPractitioner' => 'PRA04'] + $input, self::JSON)->status,
                'soignant sans droit de validation (RG5)'
            );
            self::assertSame(
                404,
                $this->postForm(
                    '/evaluations/create?idEpisodeOfCare=21',
                    ['csrf' => $csrf] + $input,
                    self::JSON
                )->status,
                'séjour clos'
            );
            $invalid = $this->postForm($url, ['csrf' => $csrf, 'password' => '', 'aggressionFactor' => '-1,3',
                'observations' => ['NEUROLOGICAL' => ['value' => 'HORS_LISTE']]] + $input, self::JSON);
            self::assertSame(422, $invalid->status);
            self::assertEqualsCanonicalizing(
                ['password', 'aggressionFactor', 'observations[NEUROLOGICAL][value]'],
                array_keys($invalid->json()['errors'] ?? []),
                'erreurs par champ pour form.js'
            );

            $saved = $this->postForm($url, ['csrf' => $csrf] + $input, self::JSON);
            self::assertSame(200, $saved->status, $saved->body);
            $redirect = (string) ($saved->json()['redirect'] ?? '');
            self::assertMatchesRegularExpression('#^/sejour\?id=1&evaluation=\d+\#titre-evaluations$#', $redirect);

            $record = $this->get(strstr($redirect, '#', true) ?: $redirect)->body;
            self::assertStringContainsString('Évaluation classique enregistrée le', $record, 'confirmation');
            self::assertStringContainsString('Zzhttpevaluation stable', $record, 'résumé dans l\'historique');
            self::assertStringContainsString('A : éveillé (alert)', $record, 'libellé de la liste normalisée');
        } finally {
            $this->deleteEvaluations();
        }
    }

    /** Efface les évaluations du test, leurs fiches et leurs traces (aucune transaction côté serveur). */
    private function deleteEvaluations(): void
    {
        $ids = $this->pdo()->query(
            "SELECT idClinicalImpression FROM ClinicalImpression WHERE description LIKE 'Zzhttpevaluation%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo()->prepare("DELETE FROM AuditEvent WHERE entity = 'ClinicalImpression' AND entityId = ?")
                ->execute([$id]);
            $this->pdo()->prepare('DELETE FROM ClinicalObservation WHERE idClinicalImpression = ?')->execute([$id]);
            $this->pdo()->prepare('DELETE FROM ClinicalImpression WHERE idClinicalImpression = ?')->execute([$id]);
        }
    }
}
