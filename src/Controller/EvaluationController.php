<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\Csrf;
use Aura\Core\View;
use Aura\Domain\ClinicalObservation;
use Aura\Service\EvaluationRejected;
use Aura\Service\EvaluationService;

/**
 * Évaluations médicales d'un séjour (sprint 2, UC1 « Créer une évaluation médicale classique ») :
 *  - GET /evaluations/new?idEpisodeOfCare=… : formulaire par systèmes, bandeau patient collant (RG2) ;
 *  - POST /evaluations/create?idEpisodeOfCare=… : enregistrement, envoyé en AJAX par form.js après la
 *    fenêtre d'authentification factice du médecin (evaluation-form.js).
 *
 * Même fonctionnement que les prescriptions (CarePlanController) : jeton CSRF obligatoire (403 sinon),
 * validation serveur avant toute écriture, réponses JSON pour form.js — 422 {ok: false, errors: {champ:
 * message}} sans perdre la saisie, 200 {ok: true, redirect} après succès ; sans JavaScript, formulaire
 * réaffiché en 422 ou redirection 303 (POST → redirect → GET). Séjour inexistant ou clos : 404 (E1) ;
 * praticien non habilité : 403 (E2).
 */
final class EvaluationController
{
    private const FORM_VIEW = 'evaluation/form';
    /** form.js : envoi AJAX ; form-sections.js : sections repliables et compteurs ; evaluation-form.js : fenêtre
     *  d'authentification factice et accès aux sections. */
    private const FORM_SCRIPTS = ['/js/form.js', '/js/form-sections.js', '/js/evaluation-form.js'];

    /**
     * Champs de l'en-tête lus dans le POST (texte), en plus des fiches observations[…]. La dépense énergétique au
     * repos n'en fait pas partie : elle est calculée par EvaluationService.
     */
    private const HEADER_FIELDS = [
        'effectiveDateTime',
        'initiallyCompletedBy',
        'aggressionFactor',
        'researchProtocols',
        'description',
        'echocardiography',
        'ventilationMode',
        'summary',
        'paraclinicalProposal',
        'therapeuticProposal',
        'mgAnamnesis',
        'mgSummary1',
        'mgSummary2',
        'idPractitioner',
    ];

    public function __construct(private EvaluationService $evaluationService)
    {
    }

    /** GET /evaluations/new?idEpisodeOfCare=… : formulaire vide (heure de l'évaluation : maintenant). */
    public function newForm(): void
    {
        $idEpisodeOfCare = $this->requestedEpisodeOfCare();
        if ($idEpisodeOfCare === null) {
            return;
        }

        $form = $this->evaluationService->form($idEpisodeOfCare);
        if ($form === null || !$form['isOpen']) {
            View::error(404, $form === null
                ? sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idEpisodeOfCare)
                : 'Ce séjour est clos : on ne peut plus y ajouter d\'évaluation.');
            return;
        }

        $this->showForm($form, [], '');
    }

    /**
     * POST /evaluations/create?idEpisodeOfCare=… : champs de l'en-tête, observations[CATEGORIE][value|note|
     * interpretation], idPractitioner et password (authentification FACTICE), csrf. Succès : retour au dossier
     * du séjour, section « Évaluations et suivi », avec le message d'enregistrement.
     */
    public function create(): void
    {
        $idEpisodeOfCare = $this->requestedEpisodeOfCare();
        if ($idEpisodeOfCare === null || !$this->checkCsrf()) {
            return;
        }

        $input = self::readInput($_POST);
        try {
            $id = $this->evaluationService->createStandard($idEpisodeOfCare, $input);
        } catch (EvaluationRejected $e) {
            $this->respondWithErrors($idEpisodeOfCare, $e, $input);
            return;
        }

        $this->redirect(sprintf('/sejour?id=%d&evaluation=%s#titre-evaluations', $idEpisodeOfCare, $id));
    }

    /**
     * Saisie envoyée par le formulaire : seuls les champs attendus sont gardés, une valeur non textuelle compte
     * comme vide. Le mot de passe est lu tel quel (jamais réaffiché ni tracé).
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function readInput(array $post): array
    {
        $input = [];
        foreach (self::HEADER_FIELDS as $field) {
            $input[$field] = self::text($post[$field] ?? null);
        }
        $posted = is_array($post['observations'] ?? null) ? $post['observations'] : [];
        $input['observations'] = [];
        foreach (ClinicalObservation::STANDARD_FORM as $category) {
            $values = is_array($posted[$category] ?? null) ? $posted[$category] : [];
            $input['observations'][$category] = [
                'value' => self::text($values['value'] ?? null),
                'note' => self::text($values['note'] ?? null),
                'interpretation' => self::text($values['interpretation'] ?? null),
            ];
        }
        $input['password'] = is_string($post['password'] ?? null) ? $post['password'] : '';

        return $input;
    }

    /**
     * Séjour demandé dans l'URL (?idEpisodeOfCare=…). Affiche l'erreur 400 et renvoie null s'il manque ou n'est
     * pas un entier positif.
     */
    private function requestedEpisodeOfCare(): ?int
    {
        $value = $_GET['idEpisodeOfCare'] ?? null;
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/', $value) !== 1) {
            View::error(400, 'Identifiant de séjour manquant ou invalide.');
            return null;
        }

        return (int) $value;
    }

    /** Jeton CSRF du formulaire (champ « csrf ») ; sinon 403 (JSON pour form.js) et false. */
    private function checkCsrf(): bool
    {
        if (Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            return true;
        }
        $message = 'Session expirée : rechargez la page puis réessayez.';
        if ($this->expectsJson()) {
            $this->respondJson(403, ['ok' => false, 'errors' => [EvaluationService::FORM_ERROR => $message]]);
        } else {
            View::error(403, $message);
        }

        return false;
    }

    /**
     * Refus : JSON pour form.js (code du refus, erreurs par champ). Sans JavaScript : formulaire réaffiché en 422
     * avec la saisie et les messages, ou page d'erreur (403, 404).
     *
     * @param array<string, mixed> $input
     */
    private function respondWithErrors(int $idEpisodeOfCare, EvaluationRejected $e, array $input): void
    {
        if ($this->expectsJson()) {
            $this->respondJson($e->status, ['ok' => false, 'errors' => $e->errors]);
            return;
        }
        $form = $e->status === EvaluationRejected::INVALID ? $this->evaluationService->form($idEpisodeOfCare) : null;
        if ($form === null) {
            View::error($e->status, $e->getMessage());
            return;
        }

        http_response_code(422);
        $input['password'] = '';
        $form['values'] = $input;
        $errors = $e->errors;
        $formError = $errors[EvaluationService::FORM_ERROR] ?? '';
        unset($errors[EvaluationService::FORM_ERROR]);
        $this->showForm($form, $errors, $formError);
    }

    /**
     * @param array<string, mixed> $form vue-modèle de EvaluationService::form()
     * @param array<string, string> $errors
     */
    private function showForm(array $form, array $errors, string $formError): void
    {
        View::page(
            self::FORM_VIEW,
            $form + ['errors' => $errors, 'formError' => $formError, 'csrfToken' => Csrf::token()],
            sprintf(
                'AURA - Nouvelle évaluation - %s %s, séjour %s',
                $form['banner']['lastName'],
                $form['banner']['firstName'],
                $form['banner']['episodeNumber']
            ),
            self::FORM_SCRIPTS
        );
    }

    /** Après un enregistrement : form.js suit « redirect » ; sans JavaScript, redirection 303. */
    private function redirect(string $destination): void
    {
        if ($this->expectsJson()) {
            $this->respondJson(200, ['ok' => true, 'redirect' => $destination]);
            return;
        }
        header('Location: ' . $destination, true, 303);
    }

    /** Valeur texte d'un champ, sans espaces autour ; chaîne vide si absente ou d'un autre type. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** Requête AJAX (form.js) : en-tête Accept: application/json ou X-Requested-With. */
    private function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return str_contains($accept, 'application/json')
            || strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
    }

    /** @param array<string, mixed> $body */
    private function respondJson(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
