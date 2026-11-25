<?php

use Aura\Core\View;
use Aura\Domain\ClinicalObservation;
use Aura\Domain\ClinicalScales;
use Aura\Service\EvaluationService;

/**
 * Nouvelle évaluation médicale classique par systèmes (UC1) : champs et libellés de l'écran du service
 * (mail du 24/11/2026, prompt_tache2/mail) — « Examen et évaluation » (données générales calculées, DER),
 * sept systèmes, « Autres informations », « Conclusion ». Bandeau patient collant (RG2), menu du dossier à
 * gauche, sections repliables (<details>, compteur « n sur m renseignés ») et accès aux sections à droite.
 * « Valider l'évaluation » ouvre la fenêtre d'authentification factice du médecin (evaluation-form.js) ;
 * « Confirmer et enregistrer » envoie le formulaire en AJAX (form.js, POST /evaluations/create) : erreurs
 * 422 affichées sous les champs sans perdre la saisie. Sans JavaScript, le bloc d'authentification reste
 * visible en bas du formulaire et l'envoi est un POST classique. Rendue par
 * EvaluationController::newForm() et, sans JavaScript, réaffichée en 422 par create(). Scripts : form.js,
 * form-sections.js, evaluation-form.js (et validation-fr.js, chargé par le gabarit).
 *
 * @var array<string, mixed> $banner vue-modèle de PatientRecordService::presentBanner()
 * @var array<string, mixed> $episodeOfCare vue-modèle de PatientRecordService::presentEpisodeOfCare()
 * @var int $idEpisodeOfCare
 * @var array<string, string> $doctors médecins habilités : idPractitioner => « Dr Prénom Nom »
 * @var array<string, string> $practitioners tous les praticiens (« Document initialement complété par »)
 * @var array<string, array{value: ?string, reason: ?string}> $derived DerivedMeasuresService::present()
 * @var ?float $basalEnergyExpenditure DER de base (Harris et Benedict), null si non calculable
 * @var array<string, mixed> $values saisie (EvaluationService::emptyInput() ou saisie refusée)
 * @var array<string, string> $errors erreurs par nom de champ, [] par défaut
 * @var string $formError erreur hors champ, '' par défaut
 * @var string $csrfToken jeton CSRF de la session
 */
$errors = $errors ?? [];
$formError = $formError ?? '';
$recordUrl = '/sejour?id=' . $idEpisodeOfCare;
$value = static fn (string $name): string => is_string($values[$name] ?? null) ? $values[$name] : '';
$observation = static fn (string $category, string $part): string =>
    is_string($values['observations'][$category][$part] ?? null) ? $values['observations'][$category][$part] : '';
$textAttributes = ['rows' => '2', 'maxlength' => (string) EvaluationService::TEXT_MAX_LENGTH];

// Champ de l'en-tête (components/field) : libellé de EvaluationService::LABELS, valeur et erreur.
$headerField = static function (string $name, string $type, array $extra = []) use ($value, $errors): array {
    return [
        'name' => $name,
        'id' => 'evaluation-' . $name,
        'label' => EvaluationService::LABELS[$name],
        'type' => $type,
        'value' => $value($name),
        'error' => $errors[$name] ?? '',
    ] + $extra;
};
$longText = static fn (string $name) => $headerField($name, 'textarea', ['attributes' => $textAttributes]);

// Champs d'un système : liste normalisée (si le système en a une), notes, interprétation d'examen éventuelle.
$systemFields = static function (
    string $category,
    string $label,
    bool $withNotes = true
) use (
    $observation,
    $errors,
    $textAttributes
): array {
    $name = static fn (string $part): string => "observations[$category][$part]";
    $id = static fn (string $part): string => 'obs-' . strtolower($category) . '-' . $part;
    $fields = [];
    if (isset(ClinicalScales::OBSERVATION_LISTS[$category])) {
        $fields[] = [
            'name' => $name('value'), 'id' => $id('value'),
            'label' => $withNotes ? $label . ' (liste)' : $label, 'type' => 'select',
            'options' => ClinicalScales::OBSERVATION_LISTS[$category], 'empty' => 'Non renseigné',
            'value' => $observation($category, 'value'), 'error' => $errors[$name('value')] ?? '',
        ];
    }
    if ($withNotes) {
        $fields[] = [
            'name' => $name('note'), 'id' => $id('note'),
            'label' => isset(ClinicalScales::OBSERVATION_LISTS[$category]) ? $label . ' (notes)' : $label,
            'type' => 'textarea', 'attributes' => $textAttributes,
            'value' => $observation($category, 'note'), 'error' => $errors[$name('note')] ?? '',
        ];
    }
    if (isset(ClinicalObservation::INTERPRETATIONS[$category])) {
        $fields[] = [
            'name' => $name('interpretation'), 'id' => $id('interpretation'),
            'label' => ClinicalObservation::INTERPRETATIONS[$category], 'type' => 'textarea',
            'attributes' => $textAttributes,
            'value' => $observation($category, 'interpretation'), 'error' => $errors[$name('interpretation')] ?? '',
        ];
    }

    return $fields;
};

// Ligne calculée ou rappelée (lecture seule) : ['display' => libellé, 'text' => valeur, 'help' => …].
$display = static function (string $label, ?string $text, ?string $help = null, array $attributes = []): array {
    return ['display' => $label, 'text' => $text, 'help' => $help, 'attributes' => $attributes];
};
$derivedRow = static function (string $key, string $label, ?string $help = null) use ($derived, $display): array {
    $measure = $derived[$key] ?? ['value' => null, 'reason' => null];

    return $display(
        $label,
        $measure['value'],
        $measure['value'] === null && $measure['reason'] !== null
            ? 'Non calculable : ' . $measure['reason'] . '.'
            : $help
    );
};
$basal = $basalEnergyExpenditure;

$sections = [
    ['id' => 'general', 'title' => 'Examen et évaluation', 'items' => [
        $headerField('effectiveDateTime', 'datetime-local', [
            'required' => true,
            'help' => 'Heure clinique de l\'examen ; l\'heure de saisie est enregistrée à part.',
        ]),
        $headerField('initiallyCompletedBy', 'select', ['options' => $practitioners, 'empty' => 'Non renseigné']),
        $display('Poids (admission)', $banner['weight'] ?? null),
        $display('Taille', $banner['height'] ?? null),
        $display('Date de naissance', $banner['birthDate'] ?? null),
        $display('Âge', $banner['age'] !== null ? $banner['age'] . ' ans' : null),
        ['heading' => 'Données générales'],
        $derivedRow('bodySurfaceArea', 'Surface corporelle', 'Formule de Mosteller.'),
        $derivedRow(
            'bmi',
            'Indice de masse corporelle (IMC)',
            'Pour mémoire : homme entre 20 et 25, femme entre 19 et 24, obésité si > 30.'
        ),
        $derivedRow('predictedBodyWeight', 'Poids théorique'),
        $derivedRow('tidalVolume', 'Vt théorique (6 à 8 ml/kg de poids théorique)'),
        $headerField('aggressionFactor', 'text', [
            'help' => sprintf(
                'Facteur correctif de %s (aucune agression) à %s.',
                EvaluationService::AGGRESSION_FACTOR['min'],
                EvaluationService::AGGRESSION_FACTOR['max']
            ),
            'attributes' => [
                'inputmode' => 'decimal', 'placeholder' => '1,3', 'maxlength' => '4', 'autocomplete' => 'off',
                'pattern' => '[0-9]([.,][0-9]{1,2})?',
                'data-pattern-message' => 'Saisissez un nombre décimal, par exemple 1,3.',
                'data-der-factor' => '',
            ],
        ]),
        $display(
            EvaluationService::LABELS['restingEnergyExpenditure'],
            null,
            $basal === null
                ? 'Non calculable : poids, taille, sexe ou âge manquant.'
                : 'DER de base (Harris et Benedict) × niveau d\'agression.',
            ['data-der-output' => '', 'data-der-basal' => $basal === null ? '' : (string) round($basal, 1)]
        ),
        $display('Précautions', $episodeOfCare['precautions'] ?? null),
        $headerField('researchProtocols', 'text', ['attributes' => [
            'maxlength' => (string) EvaluationService::RESEARCH_PROTOCOLS_MAX_LENGTH,
        ]]),
        $longText('description'),
    ]],
    ['id' => 'neurological', 'title' => 'Neurologique', 'items' => array_merge(
        $systemFields(ClinicalObservation::CATEGORY_SEDATION, 'Sédation', withNotes: false),
        $systemFields(ClinicalObservation::CATEGORY_NEUROLOGICAL, 'Évaluation neurologique')
    )],
    ['id' => 'cardiovascular', 'title' => 'Cardiovasculaire', 'items' => array_merge(
        $systemFields(ClinicalObservation::CATEGORY_CARDIOVASCULAR, 'Évaluation cardiovasculaire'),
        [$longText('echocardiography')]
    )],
    ['id' => 'hemodynamic', 'title' => 'Hémodynamique',
        'items' => $systemFields(ClinicalObservation::CATEGORY_HEMODYNAMIC, 'Évaluation hémodynamique')],
    ['id' => 'respiratory', 'title' => 'Respiratoire et pulmonaire', 'items' => array_merge(
        [$headerField('ventilationMode', 'select', [
            'options' => ClinicalScales::VENTILATION_MODES, 'empty' => 'Non renseigné',
        ])],
        $systemFields(ClinicalObservation::CATEGORY_RESPIRATORY, 'Évaluation pulmonaire')
    )],
    ['id' => 'abdominal', 'title' => 'Abdominale',
        'items' => $systemFields(ClinicalObservation::CATEGORY_ABDOMINAL, 'Évaluation abdominale')],
    ['id' => 'urorenal', 'title' => 'Uro-rénal',
        'items' => $systemFields(ClinicalObservation::CATEGORY_URORENAL, 'Évaluation uro-rénale')],
    ['id' => 'other', 'title' => 'Autres informations', 'items' => array_merge(
        $systemFields(ClinicalObservation::CATEGORY_CUTANEOUS, 'Évaluation cutanéo-muqueuse (notes)'),
        $systemFields(ClinicalObservation::CATEGORY_OSTEOARTICULAR, 'Évaluation ostéo-articulaire (notes)'),
        $systemFields(ClinicalObservation::CATEGORY_METABOLIC, 'Évaluation état métabolique (notes)'),
        $systemFields(ClinicalObservation::CATEGORY_INFECTIOUS, 'Évaluation état infectieux (notes)'),
        $systemFields(ClinicalObservation::CATEGORY_BMR_SCREENING, 'Dépistage BMR'),
        $systemFields(ClinicalObservation::CATEGORY_ABNORMAL_BIOLOGY, 'Marqueurs biologiques anormaux')
    )],
    ['id' => 'conclusion', 'title' => 'Conclusion', 'items' => [
        $longText('summary'),
        $longText('paraclinicalProposal'),
        $longText('therapeuticProposal'),
        $longText('mgAnamnesis'),
        $longText('mgSummary1'),
        $longText('mgSummary2'),
    ]],
];
// Compteur « n sur m renseignés » sur les seuls champs saisis (mis à jour ensuite par form-sections.js) et
// ouverture : la première section, et toute section déjà renseignée ou en erreur.
foreach ($sections as $i => $section) {
    $fields = array_filter($section['items'], static fn (array $item): bool => isset($item['name']));
    $filled = count(array_filter($fields, static fn (array $f): bool => trim((string) $f['value']) !== ''));
    $failing = array_filter($fields, static fn (array $f): bool => $f['error'] !== '');
    $sections[$i]['filled'] = $filled;
    $sections[$i]['total'] = count($fields);
    $sections[$i]['open'] = $i === 0 || $filled > 0 || $failing !== [];
}
?>
<?= View::render('components/patient_banner', ['banner' => $banner]) ?>
<?= View::render('components/breadcrumb', ['items' => [
    ['label' => 'Patients', 'href' => '/patients'],
    ['label' => 'Séjour n° ' . $banner['episodeNumber'], 'href' => $recordUrl],
    ['label' => 'Évaluations et suivi', 'href' => $recordUrl . '#titre-evaluations'],
    ['label' => 'Nouvelle évaluation'],
]]) ?>

<div class="record-layout">
<?= View::render('components/episode_menu', [
    'primary' => [
        'href' => '/agenda-patient?id=' . $idEpisodeOfCare,
        'label' => 'Agenda du jour',
        'icon' => 'calendar',
    ],
    'items' => [
        ['href' => $recordUrl . '#dossier-resume', 'label' => 'Dossier'],
        ['href' => $recordUrl . '#titre-allergies', 'label' => 'Allergies'],
        ['href' => $recordUrl . '#titre-dossier-complet', 'label' => 'Dossier complet'],
        ['href' => $recordUrl . '#titre-evaluations', 'label' => 'Évaluations et suivi', 'current' => true],
        ['href' => $recordUrl . '#titre-sejours', 'label' => 'Séjours du patient'],
        ['href' => $recordUrl . '#titre-prescriptions', 'label' => 'Prescriptions en cours'],
    ],
]) ?>

<section class="evaluation-form">
    <div class="page-header">
        <h1 class="form-title">Nouvelle évaluation médicale</h1>
        <p class="page-subtitle">Évaluation classique · observations structurées par systèmes</p>
    </div>

    <form id="evaluation-form" class="evaluation-form__form" method="post" data-ajax data-evaluation-form
          action="/evaluations/create?idEpisodeOfCare=<?= View::e($idEpisodeOfCare) ?>">
        <input type="hidden" name="csrf" value="<?= View::e($csrfToken) ?>">

        <?php foreach ($sections as $number => $section) : ?>
            <details class="form-section evaluation-section" id="section-<?= View::e($section['id']) ?>"
                     data-form-section<?= $section['open'] ? ' open' : '' ?>>
                <summary>
                    <span class="form-section__title">
                        <span class="evaluation-section__number"><?= sprintf('%02d', $number + 1) ?></span>
                        <?= View::e($section['title']) ?>
                    </span>
                    <span class="form-section__count" data-section-count
                          data-total="<?= View::e($section['total']) ?>">
                        <?= View::e($section['filled']) ?> sur <?= View::e($section['total']) ?>
                        renseigné<?= $section['filled'] > 1 ? 's' : '' ?>
                    </span>
                </summary>
                <div class="form-section__body">
                    <?php foreach ($section['items'] as $item) : ?>
                        <?php if (isset($item['heading'])) : ?>
                            <h3 class="evaluation-section__heading"><?= View::e($item['heading']) ?></h3>
                        <?php elseif (isset($item['display'])) : ?>
                            <?php /* Donnée du séjour ou valeur calculée : affichée, jamais saisie ici. */ ?>
                            <div class="evaluation-computed">
                                <span class="evaluation-computed__label"><?= View::e($item['display']) ?></span>
                                <span class="evaluation-computed__value"<?php
                                foreach ($item['attributes'] as $attribute => $attributeValue) {
                                    echo ' ' . View::e($attribute) . '="' . View::e($attributeValue) . '"';
                                } ?>><?= $item['text'] !== null
                                    ? View::e($item['text'])
                                    : '<span class="not-recorded">Non renseigné</span>' ?></span>
                                <?php if ($item['help'] !== null) : ?>
                                    <small class="evaluation-computed__help"><?= View::e($item['help']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <?= View::render('components/field', ['field' => $item]) ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($section['id'] === 'general') : ?>
                        <p class="field-help">
                            Poids, taille, date de naissance et précautions se modifient depuis le dossier du séjour.
                        </p>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>

        <?php /* Authentification FACTICE du médecin : sans JavaScript, bloc visible en bas du formulaire ;
                 avec, evaluation-form.js le place dans une fenêtre modale ouverte par « Valider
                 l'évaluation ». */ ?>
        <fieldset class="evaluation-auth" id="evaluation-auth" data-evaluation-auth>
            <legend class="evaluation-auth__title" id="evaluation-auth-title">Enregistrer l'évaluation</legend>
            <p class="evaluation-auth__summary">Vérifiez le patient et identifiez le praticien responsable.</p>
            <p class="evaluation-auth__patient">
                <strong><?= View::e($banner['lastName'] . ' ' . $banner['firstName']) ?></strong>
                · Séjour <?= View::e($banner['episodeNumber']) ?><br>
                Évaluation classique · <span data-evaluation-date><?= View::e(
                    EvaluationService::parseDateTime($value('effectiveDateTime'))?->format('d/m/Y à H:i') ?? ''
                ) ?></span>
            </p>
            <?= View::render('components/field', ['field' => [
                'name' => 'idPractitioner', 'id' => 'evaluation-practitioner', 'label' => 'Praticien',
                'type' => 'select', 'options' => $doctors, 'empty' => 'Choisir le médecin', 'required' => true,
                'value' => $value('idPractitioner'), 'error' => $errors['idPractitioner'] ?? '',
            ]]) ?>
            <?= View::render('components/field', ['field' => [
                'name' => 'password', 'id' => 'evaluation-password', 'label' => 'Mot de passe', 'type' => 'password',
                'required' => true, 'value' => '', 'error' => $errors['password'] ?? '',
                'attributes' => ['autocomplete' => 'off'],
            ]]) ?>
            <p class="auth-dialog__notice">
                <?= View::render('components/icon', ['name' => 'info']) ?>
                Authentification de démonstration : le mot de passe n'est pas vérifié. L'enregistrement conserve
                la date de saisie et trace l'action au nom du médecin.
            </p>
            <div class="auth-dialog__actions" data-evaluation-auth-actions hidden>
                <button type="button" class="button button--secondary" data-auth-cancel>Revenir à la saisie</button>
                <button type="submit" class="button">Confirmer et enregistrer</button>
            </div>
        </fieldset>

        <div class="form-actions evaluation-form__actions">
            <a class="button button--secondary"
               href="<?= View::e($recordUrl) ?>#titre-evaluations">Retour au dossier</a>
            <span class="evaluation-form__hint">Validation médicale requise</span>
            <button type="submit" data-evaluation-validate>Valider l'évaluation</button>
            <?php if ($formError !== '') : ?>
                <p class="form-error" role="alert"><?= View::e($formError) ?></p>
            <?php endif; ?>
        </div>
    </form>
</section>

<aside class="record-aside evaluation-toc" aria-labelledby="evaluation-toc-title">
    <h2 class="record-subtitle" id="evaluation-toc-title">Évaluation classique</h2>
    <p class="evaluation-toc__help">Accès aux sections</p>
    <ol class="evaluation-toc__list">
        <?php foreach ($sections as $section) : ?>
            <li>
                <a href="#section-<?= View::e($section['id']) ?>" data-open-section><?=
                    View::e($section['title']) ?></a>
            </li>
        <?php endforeach; ?>
    </ol>
    <p class="evaluation-toc__help">Les sections peuvent être repliées. L'identité et les allergies restent
        affichées pendant la saisie.</p>
    <p class="evaluation-toc__help"><span class="req">*</span> Champ obligatoire</p>
</aside>
</div>
