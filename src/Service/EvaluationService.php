<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AuditEventDao;
use Aura\Dao\ClinicalImpressionDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\ClinicalImpression;
use Aura\Domain\ClinicalObservation;
use Aura\Domain\ClinicalScales;
use DateTimeImmutable;

/**
 * Évaluations médicales d'un séjour (sprint 2, US-02 / UC1 « Créer une évaluation médicale classique par
 * systèmes ») : formulaire, validation, enregistrement et historique affiché dans le dossier du séjour.
 *
 * Règles :
 *  - validate() est pure (horloge injectée, aucune lecture en base) : champs obligatoires, valeurs des listes
 *    blanches de ClinicalScales, bornes du DER, longueurs des colonnes. Une erreur par champ, indexée par le nom
 *    du champ du formulaire (public/js/form.js l'affiche sous le champ) ;
 *  - le séjour doit exister et être en cours (E1, 404) ; l'évaluation ne peut pas précéder l'admission ;
 *  - AUTHENTIFICATION FACTICE, comme les parties du dossier (RecordSectionService) et les prescriptions : le
 *    médecin choisit son nom dans la liste des praticiens habilités (RG5) et saisit un mot de passe exigé mais
 *    NON vérifié ; un praticien hors liste est refusé (E2, 403) ;
 *  - tout ou rien : l'évaluation, ses fiches de suivi et la trace AuditEvent sont écrites dans une seule
 *    transaction (Transaction::run(), ids MAX + 1 FOR UPDATE dans les DAO) ; au moindre refus, rien n'est écrit.
 */
class EvaluationService
{
    /** Clé d'erreur hors champ (affichée près du bouton d'envoi). */
    public const FORM_ERROR = 'form';

    /** Format des champs datetime-local du formulaire. */
    public const INPUT_DATE_FORMAT = 'Y-m-d\TH:i';

    /** Longueur maximale des champs de texte libre (colonnes TEXT), pour garder des fiches lisibles. */
    public const TEXT_MAX_LENGTH = 4000;

    /** Longueur de la colonne VARCHAR researchProtocols (schema.sql). */
    public const RESEARCH_PROTOCOLS_MAX_LENGTH = 255;

    /**
     * Borne clinique du niveau d'agression (facteur correctif du DER), contrôlée par l'application (la base n'exige
     * que > 0) : de 1 (aucune agression) à 3 (borne haute volontairement large : brûlés graves).
     */
    public const AGGRESSION_FACTOR = ['min' => 1.0, 'max' => 3.0];

    /** Avance tolérée sur l'horloge du serveur pour l'heure de l'évaluation (montre du poste un peu en avance). */
    private const FUTURE_TOLERANCE = '+5 minutes';

    /** Pseudo-champ de la trace AuditEvent écrite à la création d'une évaluation. */
    public const AUDIT_FIELD_CREATED = 'creation';

    /**
     * Champs de texte libre de l'en-tête : nom du champ => longueur maximale. Ceux de
     * ClinicalImpression::DETAIL_KEYS vont dans la colonne JSON details, les autres dans leur colonne.
     */
    private const HEADER_TEXTS = [
        'researchProtocols' => self::RESEARCH_PROTOCOLS_MAX_LENGTH,
        'description' => self::TEXT_MAX_LENGTH,
        'echocardiography' => self::TEXT_MAX_LENGTH,
        'summary' => self::TEXT_MAX_LENGTH,
        'paraclinicalProposal' => self::TEXT_MAX_LENGTH,
        'therapeuticProposal' => self::TEXT_MAX_LENGTH,
        'mgAnamnesis' => self::TEXT_MAX_LENGTH,
        'mgSummary1' => self::TEXT_MAX_LENGTH,
        'mgSummary2' => self::TEXT_MAX_LENGTH,
    ];

    /** Champs de texte qui suffisent à faire une évaluation non vide (avec les fiches de suivi). */
    private const CONTENT_TEXTS = [
        'description', 'echocardiography', 'summary', 'paraclinicalProposal', 'therapeuticProposal',
        'mgAnamnesis', 'mgSummary1', 'mgSummary2',
    ];

    /**
     * Libellés des champs de l'en-tête, repris de l'écran du service (mail du 24/11/2026) : formulaire, messages
     * d'erreur, lecture seule.
     */
    public const LABELS = [
        'effectiveDateTime' => 'Date et heure de l\'évaluation',
        'initiallyCompletedBy' => 'Document initialement complété par',
        'aggressionFactor' => 'DER : niveau d\'agression (facteur correctif)',
        'restingEnergyExpenditure' => 'DER : dépense énergétique au repos',
        'researchProtocols' => 'Protocoles de recherche',
        'description' => 'Évaluation générale (note)',
        'echocardiography' => 'Interprétation de l\'échocardiographie',
        'ventilationMode' => 'VS/VC',
        'summary' => 'Synthèse clinique à l\'admission',
        'paraclinicalProposal' => 'Proposition d\'examens paracliniques',
        'therapeuticProposal' => 'Proposition thérapeutique',
        'mgAnamnesis' => 'MG - Anamnèse',
        'mgSummary1' => 'MG - Synthèse 1',
        'mgSummary2' => 'MG - Synthèse 2',
        'orientation' => 'Orientation',
    ];

    public const AUTH_ERROR_PRACTITIONER = 'Choisissez le médecin qui enregistre l\'évaluation.';
    public const AUTH_ERROR_PASSWORD = 'Saisissez le mot de passe du médecin.';
    public const AUTH_ERROR_FORBIDDEN = 'Ce praticien n\'est pas habilité à enregistrer une évaluation médicale.';

    public function __construct(
        private ClinicalImpressionDao $clinicalImpressionDao,
        private AuditEventDao $auditEventDao,
        private Transaction $transaction,
        private PatientRecordService $patientRecordService,
        private ReferenceDataDao $referenceDataDao,
        private DateTimeImmutable $now = new DateTimeImmutable()
    ) {
    }

    /**
     * Saisie vide du formulaire, dans la forme lue par le contrôleur : tous les champs à '', heure de
     * l'évaluation à maintenant. Sert de valeurs par défaut au formulaire et de modèle aux tests.
     *
     * @return array<string, mixed>
     */
    public function emptyInput(): array
    {
        $observations = [];
        foreach (ClinicalObservation::STANDARD_FORM as $category) {
            $observations[$category] = ['value' => '', 'note' => '', 'interpretation' => ''];
        }

        return ['effectiveDateTime' => $this->now->format(self::INPUT_DATE_FORMAT)]
            + array_fill_keys(array_keys(self::HEADER_TEXTS), '')
            + ['initiallyCompletedBy' => '', 'aggressionFactor' => '', 'ventilationMode' => '']
            + ['observations' => $observations, 'idPractitioner' => '', 'password' => ''];
    }

    /**
     * Contrôle pur d'une saisie (sans l'authentification, vérifiée par createStandard()) : renvoie les erreurs
     * indexées par nom de champ du formulaire ([] si tout est valide).
     *
     * Champs attendus (chaînes, '' = non renseigné ; voir emptyInput()) : effectiveDateTime (obligatoire,
     * AAAA-MM-JJTHH:MM, pas dans le futur), initiallyCompletedBy (identifiant de praticien, son existence est
     * vérifiée par createStandard()), aggressionFactor, ventilationMode, les textes de HEADER_TEXTS et
     * observations[CATEGORIE][value|note|interpretation] pour les systèmes de ClinicalObservation::STANDARD_FORM.
     * La dépense énergétique au repos n'est pas saisie : elle est calculée (createStandard()).
     * Une évaluation entièrement vide est refusée (erreur FORM_ERROR).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validate(array $input): array
    {
        $errors = [];

        $effective = self::parseDateTime(self::text($input, 'effectiveDateTime'));
        if (self::text($input, 'effectiveDateTime') === '') {
            $errors['effectiveDateTime'] = 'La date et l\'heure de l\'évaluation sont obligatoires.';
        } elseif ($effective === null) {
            $errors['effectiveDateTime'] = 'Date et heure invalides (format attendu : JJ/MM/AAAA HH:MM).';
        } elseif ($effective > $this->now->modify(self::FUTURE_TOLERANCE)) {
            $errors['effectiveDateTime'] = 'L\'évaluation ne peut pas être datée dans le futur.';
        }

        $factor = self::text($input, 'aggressionFactor');
        if ($factor !== '') {
            $value = self::parseDecimal($factor);
            if ($value === null) {
                $errors['aggressionFactor'] = 'Le niveau d\'agression doit être un nombre (ex. 1,3).';
            } elseif ($value < self::AGGRESSION_FACTOR['min'] || $value > self::AGGRESSION_FACTOR['max']) {
                $errors['aggressionFactor'] = sprintf(
                    'Le niveau d\'agression doit être compris entre %s et %s.',
                    self::frenchNumber(self::AGGRESSION_FACTOR['min']),
                    self::frenchNumber(self::AGGRESSION_FACTOR['max'])
                );
            }
        }

        $ventilation = self::text($input, 'ventilationMode');
        if ($ventilation !== '' && !isset(ClinicalScales::VENTILATION_MODES[$ventilation])) {
            $errors['ventilationMode'] = 'VS/VC : valeur hors de la liste.';
        }

        if (preg_match('/^[A-Za-z0-9_-]{0,20}$/', self::text($input, 'initiallyCompletedBy')) !== 1) {
            $errors['initiallyCompletedBy'] = 'Praticien inconnu.';
        }

        foreach (self::HEADER_TEXTS as $field => $max) {
            if (mb_strlen(self::text($input, $field)) > $max) {
                $errors[$field] = sprintf('%s : %d caractères au plus.', self::LABELS[$field], $max);
            }
        }

        $observations = self::observationsInput($input);
        foreach ($observations as $category => $values) {
            $name = static fn (string $part): string => "observations[$category][$part]";
            $label = ClinicalObservation::CATEGORIES[$category];
            if ($values['value'] !== '' && !isset(ClinicalScales::OBSERVATION_LISTS[$category][$values['value']])) {
                $errors[$name('value')] = sprintf('%s : valeur hors de la liste normalisée.', $label);
            }
            foreach (['note', 'interpretation'] as $part) {
                if (mb_strlen($values[$part]) > self::TEXT_MAX_LENGTH) {
                    $errors[$name($part)] = sprintf('%s : %d caractères au plus.', $label, self::TEXT_MAX_LENGTH);
                }
            }
            if ($values['interpretation'] !== '' && !isset(ClinicalObservation::INTERPRETATIONS[$category])) {
                $errors[$name('interpretation')] = sprintf('%s : pas d\'interprétation d\'examen.', $label);
            }
        }

        if (!self::hasContent($input, $observations)) {
            $errors[self::FORM_ERROR] = 'L\'évaluation est vide : renseignez au moins l\'évaluation générale, un '
                . 'système ou la conclusion.';
        }

        return $errors;
    }

    /**
     * UC1 : enregistre une évaluation classique (STANDARD) et ses fiches de suivi renseignées, au nom du médecin
     * authentifié (factice), et la trace dans AuditEvent ; renvoie l'identifiant de l'évaluation.
     *
     * @param array<string, mixed> $input saisie (voir validate()) avec idPractitioner et password
     * @throws EvaluationRejected 404 séjour inexistant ou clos, 403 praticien non habilité, 422 saisie invalide ;
     *                            rien n'est écrit
     */
    public function createStandard(int $idEpisodeOfCare, array $input): string
    {
        $record = $this->patientRecordService->record($idEpisodeOfCare);
        if ($record === null) {
            throw new EvaluationRejected(
                [self::FORM_ERROR => sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idEpisodeOfCare)],
                EvaluationRejected::NOT_FOUND
            );
        }
        if (!$record['episodeOfCare']['ongoing']) {
            throw new EvaluationRejected(
                [self::FORM_ERROR => 'Ce séjour est clos : on ne peut plus y ajouter d\'évaluation.'],
                EvaluationRejected::NOT_FOUND
            );
        }

        $doctors = $this->doctors();
        $idPractitioner = self::text($input, 'idPractitioner');
        if ($idPractitioner !== '' && !isset($doctors[$idPractitioner])) {
            throw new EvaluationRejected(
                [self::FORM_ERROR => self::AUTH_ERROR_FORBIDDEN],
                EvaluationRejected::FORBIDDEN
            );
        }

        $errors = $this->validate($input);
        $effective = self::parseDateTime(self::text($input, 'effectiveDateTime'));
        $admission = DateTimeImmutable::createFromFormat('!d/m/Y H:i', $record['episodeOfCare']['admissionDate']);
        $beforeAdmission = $effective !== null && $admission !== false && $effective < $admission;
        if (!isset($errors['effectiveDateTime']) && $beforeAdmission) {
            $errors['effectiveDateTime'] = sprintf(
                'L\'évaluation ne peut pas précéder l\'admission (%s).',
                $record['episodeOfCare']['admissionDate']
            );
        }
        $completedBy = self::text($input, 'initiallyCompletedBy');
        $unknownAuthor = $completedBy !== '' && !isset($this->practitioners()[$completedBy]);
        if (!isset($errors['initiallyCompletedBy']) && $unknownAuthor) {
            $errors['initiallyCompletedBy'] = 'Praticien inconnu.';
        }
        if ($idPractitioner === '') {
            $errors['idPractitioner'] = self::AUTH_ERROR_PRACTITIONER;
        }
        // Pas de trim : le mot de passe est lu tel quel ; seul « vide » est refusé (authentification factice).
        if (trim(is_string($input['password'] ?? null) ? $input['password'] : '') === '') {
            $errors['password'] = self::AUTH_ERROR_PASSWORD;
        }
        if ($errors !== [] || $effective === null) {
            throw new EvaluationRejected($errors);
        }

        $impression = $this->buildStandard(
            $idEpisodeOfCare,
            $idPractitioner,
            $effective,
            $input,
            self::basalEnergyExpenditure($record['measures'] ?? [])
        );
        $author = mb_substr($doctors[$idPractitioner] . ' (médecin, authentification factice)', 0, 100);

        return $this->transaction->run(function () use ($impression, $author): string {
            $id = $this->clinicalImpressionDao->insert($impression);
            $categories = array_map(
                static fn (ClinicalObservation $o): string => ClinicalObservation::CATEGORIES[$o->getCategory()],
                $impression->getObservations()
            );
            $this->auditEventDao->insert(new AuditEvent(
                idAuditEvent: null,
                entity: AuditEvent::ENTITY_CLINICAL_IMPRESSION,
                entityId: $id,
                field: self::AUDIT_FIELD_CREATED,
                oldValue: null,
                newValue: ClinicalImpression::KIND_STANDARD,
                author: $author,
                detail: mb_substr(sprintf(
                    'Évaluation classique du %s, séjour %d : %s',
                    $impression->getEffectiveDateTime()->format('d/m/Y H:i'),
                    $impression->getIdEpisodeOfCare(),
                    $categories === [] ? 'aucune fiche de suivi' : implode(', ', $categories)
                ), 0, 200)
            ));

            return $id;
        });
    }

    /**
     * Médecins habilités (RG5) proposés dans la fenêtre d'authentification factice : idPractitioner =>
     * « Dr Prénom Nom ».
     *
     * @return array<string, string>
     */
    public function doctors(): array
    {
        return $this->referenceDataDao->doctors();
    }

    /**
     * Tous les praticiens, pour « Document initialement complété par » (un interne ou un externe peut avoir
     * commencé le document) : idPractitioner => « Dr Prénom Nom » pour un médecin, « Prénom Nom (rôle) »
     * sinon.
     *
     * @return array<string, string>
     */
    public function practitioners(): array
    {
        $list = [];
        foreach ($this->referenceDataDao->practitioners() as $id => $practitioner) {
            $role = $practitioner->getRole();
            $list[(string) $id] = $practitioner->canValidate()
                ? $practitioner->getDoctorName()
                : $practitioner->getFullName() . ($role !== null ? ' (' . $role . ')' : '');
        }

        return $list;
    }

    /**
     * Vue-modèle du formulaire de création (GET /evaluations/new) : bandeau (RG2), séjour, médecins et praticiens,
     * données générales calculées (surface corporelle, IMC, poids et Vt théoriques, DER de base), saisie par
     * défaut. null si le séjour n'existe pas.
     *
     * @return array<string, mixed>|null
     */
    public function form(int $idEpisodeOfCare): ?array
    {
        $record = $this->patientRecordService->record($idEpisodeOfCare);
        if ($record === null) {
            return null;
        }
        $measures = $record['measures'] ?? [];

        return [
            'banner' => $record['banner'],
            'episodeOfCare' => $record['episodeOfCare'],
            'idEpisodeOfCare' => $idEpisodeOfCare,
            'isOpen' => $record['episodeOfCare']['ongoing'],
            'doctors' => $this->doctors(),
            'practitioners' => $this->practitioners(),
            'derived' => DerivedMeasuresService::present(
                $measures['weightKg'] ?? null,
                $measures['heightCm'] ?? null,
                $measures['sex'] ?? null
            ),
            'basalEnergyExpenditure' => self::basalEnergyExpenditure($measures),
            'values' => $this->emptyInput(),
        ];
    }

    /**
     * UC3 : évaluations actives du séjour, de la plus récente à la plus ancienne, prêtes à afficher dans le
     * dossier (lecture seule).
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $idEpisodeOfCare): array
    {
        $names = $this->practitioners();

        return array_map(
            static fn (ClinicalImpression $impression): array => self::present($impression, $names),
            $this->clinicalImpressionDao->findByEpisodeOfCare($idEpisodeOfCare)
        );
    }

    /**
     * Vue-modèle d'une évaluation pour l'historique du dossier : en-tête (type, date, médecin, résumé) et
     * sections en lecture seule (informations générales, un bloc par système renseigné, synthèse). Pure.
     *
     * @param array<string, string> $practitionerNames idPractitioner => « Dr Prénom Nom »
     * @return array<string, mixed>
     */
    public static function present(ClinicalImpression $impression, array $practitionerNames = []): array
    {
        $kind = $impression->getKind();
        $practitioner = $practitionerNames[$impression->getIdPractitioner()] ?? $impression->getIdPractitioner();

        $details = $impression->getDetails();
        $completedBy = $details['initiallyCompletedBy'] ?? null;
        $general = self::lines([
            'Observation' => $impression->getEffectiveDateTime()->format('d/m/Y à H:i'),
            'Saisie' => $impression->getRecordedAt()?->format('d/m/Y à H:i'),
            self::LABELS['initiallyCompletedBy'] => $completedBy === null
                ? null
                : ($practitionerNames[$completedBy] ?? $completedBy),
            'DER' => self::der($impression),
            self::LABELS['researchProtocols'] => $impression->getResearchProtocols(),
            self::LABELS['description'] => $impression->getDescription(),
        ]);

        $sections = [['title' => 'Examen et évaluation', 'lines' => $general]];
        $headline = [];
        $byCategory = [];
        foreach ($impression->getObservations() as $observation) {
            $byCategory[$observation->getCategory()] = $observation;
        }
        $echocardiography = $details['echocardiography'] ?? null;
        $ventilation = $details['ventilationMode'] ?? null;
        // Ordre de l'écran ; l'échocardiographie suit le cardiovasculaire et VS/VC ouvre le respiratoire, même sans
        // fiche de ces systèmes.
        foreach (array_keys(ClinicalObservation::CATEGORIES) as $category) {
            $observation = $byCategory[$category] ?? null;
            $lines = [];
            if ($observation !== null) {
                $value = $observation->getValueCodeableConcept();
                $valueLabel = $value === null ? null : (ClinicalScales::OBSERVATION_LISTS[$category][$value] ?? $value);
                if ($valueLabel !== null) {
                    $headline[] = $valueLabel;
                }
                $lines = self::lines([
                    'Évaluation' => $valueLabel,
                    'Notes' => $observation->getNote(),
                    ClinicalObservation::INTERPRETATIONS[$category] ?? 'Interprétation'
                        => $observation->getInterpretation(),
                ]);
            }
            if ($category === ClinicalObservation::CATEGORY_CARDIOVASCULAR) {
                $lines = array_merge($lines, self::lines([
                    ClinicalImpression::DETAIL_KEYS['echocardiography'] => $echocardiography,
                ]));
            }
            if ($category === ClinicalObservation::CATEGORY_RESPIRATORY) {
                $lines = array_merge(self::lines([
                    self::LABELS['ventilationMode'] => $ventilation === null
                        ? null
                        : (ClinicalScales::VENTILATION_MODES[$ventilation] ?? $ventilation),
                ]), $lines);
            }
            if ($observation !== null || $lines !== []) {
                $sections[] = ['title' => ClinicalObservation::CATEGORIES[$category], 'lines' => $lines];
            }
        }

        $conclusion = self::lines([
            self::LABELS['summary'] => $impression->getSummary(),
            self::LABELS['paraclinicalProposal'] => $impression->getParaclinicalProposal(),
            self::LABELS['therapeuticProposal'] => $impression->getTherapeuticProposal(),
            self::LABELS['mgAnamnesis'] => $details['mgAnamnesis'] ?? null,
            self::LABELS['mgSummary1'] => $details['mgSummary1'] ?? null,
            self::LABELS['mgSummary2'] => $details['mgSummary2'] ?? null,
            // Orientation : saisie par le bilan de déchocage, pas par l'évaluation classique.
            self::LABELS['orientation'] => $impression->getOrientation(),
        ]);
        if ($conclusion !== []) {
            $sections[] = ['title' => 'Conclusion', 'lines' => $conclusion];
        }
        $igs2 = $impression->getShockRoomAssessment()?->getIgs2Score();

        return [
            'id' => (string) $impression->getIdClinicalImpression(),
            'kind' => $kind,
            'kindLabel' => ClinicalImpression::KINDS[$kind] ?? $kind,
            'title' => $impression->isShockRoom() ? 'Bilan de déchocage' : 'Évaluation médicale par systèmes',
            'effectiveDateTime' => $impression->getEffectiveDateTime()->format('d/m/Y - H:i'),
            'recordedAt' => $impression->getRecordedAt()?->format('d/m/Y à H:i'),
            'practitioner' => $practitioner,
            // Résumé d'une ligne : la synthèse, sinon les valeurs de liste des systèmes renseignés.
            'headline' => $impression->getSummary() ?? ($headline === [] ? null : implode(' · ', $headline)),
            'der' => self::der($impression),
            'igs2' => $igs2,
            'sections' => $sections,
        ];
    }

    /**
     * Construit l'évaluation (non enregistrée) à partir d'une saisie déjà validée : seules les fiches
     * renseignées sont gardées ; les champs vides valent null. La dépense énergétique au repos est la DER de base
     * (Harris et Benedict) multipliée par le niveau d'agression ; null si l'un des deux manque.
     *
     * @param array<string, mixed> $input
     */
    private function buildStandard(
        int $idEpisodeOfCare,
        string $idPractitioner,
        DateTimeImmutable $effective,
        array $input,
        ?float $basalEnergyExpenditure
    ): ClinicalImpression {
        $observations = [];
        foreach (self::observationsInput($input) as $category => $values) {
            if ($values['value'] === '' && $values['note'] === '' && $values['interpretation'] === '') {
                continue;
            }
            $observations[] = new ClinicalObservation(
                idClinicalObservation: null,
                idClinicalImpression: '',
                category: $category,
                effectiveDateTime: $effective,
                valueCodeableConcept: self::nullable($values['value']),
                note: self::nullable($values['note']),
                interpretation: self::nullable($values['interpretation'])
            );
        }
        $details = [];
        foreach (array_keys(ClinicalImpression::DETAIL_KEYS) as $key) {
            if (self::text($input, $key) !== '') {
                $details[$key] = self::text($input, $key);
            }
        }
        $factor = self::parseDecimal(self::text($input, 'aggressionFactor'));
        $factor = $factor === null ? null : round($factor, 2);

        return new ClinicalImpression(
            idClinicalImpression: null,
            idEpisodeOfCare: $idEpisodeOfCare,
            idPractitioner: $idPractitioner,
            kind: ClinicalImpression::KIND_STANDARD,
            effectiveDateTime: $effective,
            description: self::nullable(self::text($input, 'description')),
            aggressionFactor: $factor,
            restingEnergyExpenditure: $factor === null || $basalEnergyExpenditure === null
                ? null
                : (int) round($basalEnergyExpenditure * $factor),
            researchProtocols: self::nullable(self::text($input, 'researchProtocols')),
            summary: self::nullable(self::text($input, 'summary')),
            paraclinicalProposal: self::nullable(self::text($input, 'paraclinicalProposal')),
            therapeuticProposal: self::nullable(self::text($input, 'therapeuticProposal')),
            details: $details,
            observations: $observations
        );
    }

    /**
     * Fiches saisies, une par système de l'évaluation classique, valeurs nettoyées ('' si absentes). Les
     * systèmes inconnus envoyés par le client sont ignorés.
     *
     * @param array<string, mixed> $input
     * @return array<string, array{value: string, note: string, interpretation: string}>
     */
    private static function observationsInput(array $input): array
    {
        $posted = is_array($input['observations'] ?? null) ? $input['observations'] : [];
        $observations = [];
        foreach (ClinicalObservation::STANDARD_FORM as $category) {
            $values = is_array($posted[$category] ?? null) ? $posted[$category] : [];
            $observations[$category] = [
                'value' => self::text($values, 'value'),
                'note' => self::text($values, 'note'),
                'interpretation' => self::text($values, 'interpretation'),
            ];
        }

        return $observations;
    }

    /**
     * Au moins un élément clinique : évaluation générale, échocardiographie, une fiche de suivi ou un champ de la
     * conclusion. L'heure, le DER, VS/VC et l'auteur du document seuls ne font pas une évaluation.
     *
     * @param array<string, mixed> $input
     * @param array<string, array{value: string, note: string, interpretation: string}> $observations
     */
    private static function hasContent(array $input, array $observations): bool
    {
        foreach (self::CONTENT_TEXTS as $f) {
            if (self::text($input, $f) !== '') {
                return true;
            }
        }
        foreach ($observations as $values) {
            if (implode('', $values) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * DER de base (kcal/j) à partir des mesures du séjour (PatientRecordService::record()['measures']) ; null si
     * une donnée manque.
     *
     * @param array<string, mixed> $measures
     */
    public static function basalEnergyExpenditure(array $measures): ?float
    {
        return DerivedMeasuresService::basalEnergyExpenditure(
            is_string($measures['sex'] ?? null) ? $measures['sex'] : null,
            is_int($measures['age'] ?? null) ? $measures['age'] : null,
            is_float($measures['weightKg'] ?? null) ? $measures['weightKg'] : null,
            is_float($measures['heightCm'] ?? null) ? $measures['heightCm'] : null
        );
    }

    /** « facteur d'agression 1,3 · 1650 kcal/j » ; null si le DER n'est pas renseigné. */
    private static function der(ClinicalImpression $impression): ?string
    {
        $parts = [];
        if ($impression->getAggressionFactor() !== null) {
            $parts[] = 'facteur d\'agression ' . self::frenchNumber($impression->getAggressionFactor());
        }
        if ($impression->getRestingEnergyExpenditure() !== null) {
            $parts[] = $impression->getRestingEnergyExpenditure() . ' kcal/j';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Lignes « libellé : valeur » non vides, dans l'ordre donné.
     *
     * @param array<string, ?string> $values
     * @return list<array{label: string, value: string}>
     */
    private static function lines(array $values): array
    {
        $lines = [];
        foreach ($values as $label => $value) {
            if ($value !== null && trim($value) !== '') {
                $lines[] = ['label' => (string) $label, 'value' => $value];
            }
        }

        return $lines;
    }

    /** « 2026-10-14T14:00 » (champ datetime-local) vers une date ; null si invalide. */
    public static function parseDateTime(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::INPUT_DATE_FORMAT, $value);
        if ($date === false || $date->format(self::INPUT_DATE_FORMAT) !== $value || (int) $date->format('Y') < 2000) {
            return null;
        }

        return $date;
    }

    /** Nombre décimal saisi à la française ou non (« 1,3 », « 1.30 ») ; null si ce n'en est pas un. */
    private static function parseDecimal(string $value): ?float
    {
        $normalized = str_replace(',', '.', $value);

        return preg_match('/^-?\d{1,3}(\.\d{1,2})?$/', $normalized) === 1 ? (float) $normalized : null;
    }

    private static function frenchNumber(float $value): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
    }

    /**
     * Valeur texte d'un champ, sans espaces autour ; '' si absente ou d'un autre type.
     *
     * @param array<mixed> $input
     */
    private static function text(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
