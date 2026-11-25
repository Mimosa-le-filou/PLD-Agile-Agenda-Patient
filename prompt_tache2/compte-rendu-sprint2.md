# Compte rendu : sprint 2, créer une évaluation médicale classique (UC1)

Date : 25/11/2026. Branche : `evaluation`. Rien n'a été commité : toutes les modifications sont dans la copie de
travail, prêtes à relire.

Ce compte rendu intègre les **changements de dernière minute** (section 4) : les champs de l'évaluation suivent
désormais l'écran du service reçu par mail (`prompt_tache2/mail`), et les messages d'erreur du navigateur sont en
français.

## 1. Ce qui a servi de base

- `prompt_tache2/prompt.txt` : la consigne (implémenter les tâches T2.x, mettre à jour les tâches T1.x selon les
  changements, ajouter la sous-section « Évaluations » au dossier, appeler l'authentification médecin existante à
  l'enregistrement, écrire ce compte rendu).
- `screenshot_20261125_085106-region.png` : le backlog. US-01 (T1.1 à T1.6, faites au sprint 1) et US-02 (T2.1 à
  T2.6, ce sprint).
- `screenshot_20261125_091426-region.png` : la maquette en 4 écrans. 01 Dossier « Évaluations et suivi », 02
  « Nouvelle évaluation médicale », 03 « Validation médicale » (praticien et mot de passe), 04 « Évaluation
  enregistrée ».
- `spec_CU.pdf` : la fiche de UC1 (scénario nominal, erreurs E1 404, E2 403, E3 422, postconditions) et la matrice
  de traçabilité (routes `GET /evaluations/new`, `POST /evaluations/create`, test `EvaluationHttpTest`).
- `Modèle de données.png` : le **modèle de données v2**. Il diffère du sprint 1 : c'est l'origine de la mise à jour
  des tâches T1.x (section 2). `Class_diagram.png` est la même figure en basse résolution (illisible). J'ai donc
  travaillé à partir du modèle de données.
- `prompts/compte-rendu-sprint1.md` et `prompts/Cahier des charges…pdf` (figure 2 : champs de l'évaluation
  classique).
- `prompt_tache2/mail/` (3 captures du 24/11/2026) : l'écran d'évaluation classique du SI du service, champ par
  champ (« Examen et évaluation », systèmes, « Autres informations », « Conclusion »). Il fait foi pour les champs du
  formulaire (section 4).

## 2. Mise à jour des tâches T1.x (écarts entre le sprint 1 et le modèle v2)

### T1.1 : base de données

`database/schema.sql` : les quatre tables sont réécrites selon le modèle v2.

| Table | Changements |
| --- | --- |
| `ClinicalImpression` | Noms HL7 : `evaluatedAt` → `effectiveDateTime`, `generalNote` → `description`, `conclusion` → `summary`. Ajouts : `researchProtocols`, `paraclinicalProposal`, `therapeuticProposal`, `details JSON`. `kind DEFAULT 'STANDARD'`, `aggressionFactor DECIMAL(4,2)`. Colonne générée `shockRoomKey` + `UNIQUE (idEpisodeOfCare, shockRoomKey)` : un seul bilan de déchocage actif par séjour (UC2). Le CHECK « facteur entre 1 et 3 » devient « > 0 », comme dans le modèle (la borne 1 à 3 est contrôlée par le service). |
| `ClinicalObservation` | `selectedValue` → `valueCodeableConcept VARCHAR(200)`, `notes` → `note`, `examInterpretation` → `interpretation`, `observedAt` → `effectiveDateTime`, `category VARCHAR(30)`. 6 systèmes ajoutés au CHECK : `CUTANEOUS`, `OSTEOARTICULAR`, `METABOLIC`, `INFECTIOUS`, `BMR_SCREENING`, `ABNORMAL_BIOLOGY`. Le CHECK « fiche non vide » est retiré (absent du modèle) : c'est le service qui le contrôle. |
| `ShockRoomAssessment` | 45 champs au lieu de 28. Ajouts : traitement antithrombotique, PAS et FR initiales, colloïdes, mannitol, amines, examen clinique, Doppler transcrânien, Rx thorax et bassin, CGR, PFC, autres produits sanguins, ROTEM, plaies cutanées, ISS, avis neurochirurgical, `lesionDetails JSON`. Renommés : `seniorIntensivist`, `icuResident`, `ctScanAssessment`, `abnormalBiology`. Retirés : `lesionAssessment`, `paraclinicalWorkup`. CHECK ISS 0 à 75 ajouté. CHECK IGS II réduit à `>= 0`. |
| `EpisodeDiagnosis` | `status` est remplacé par deux statuts HL7 Condition : `clinicalStatus` (ACTIVE, RESOLVED, défaut ACTIVE) et `verificationStatus` (PROVISIONAL, CONFIRMED, REFUTED, défaut PROVISIONAL). `notes` → `note`, `diagnosedAt` → `recordedDate DEFAULT CURRENT_TIMESTAMP`. |
| `CarePlan` | Inchangé (`idClinicalImpression` était déjà là). |

Migrations :

- `2026-11-18-evaluations-patient.sql` : réalignée sur le modèle v2. Une base qui n'a pas encore les tables les
  crée directement dans leur version finale.
- `2026-11-25-evaluations-modele-v2.sql` (nouvelle) : convertit une base qui avait reçu la version du sprint 1.
  Elle est rejouable (`CHANGE COLUMN IF EXISTS`, `ADD … IF NOT EXISTS`, `DROP … IF EXISTS`) et conserve les données
  (colonnes renommées et non recréées). Les anciens statuts de diagnostic sont convertis : SUSPECTED → ACTIVE /
  PROVISIONAL, REFUTED → ACTIVE / REFUTED.

### T1.2 : documentation

- `docs/ARCHITECTURE.md` : diagramme ER des quatre tables, paragraphe « Lecture » et notes (`shockRoomKey`, JSON).
  Diagramme de classes du domaine (entités v2, `ClinicalScales::ISS`), DAO (`updateStatus`), services
  (`EvaluationService`) et contrôleurs (`EvaluationController`). Routes `/evaluations/*`. Décision de conception
  « Évaluations, modèle v2 et UC1 ». Glossaire : ISS ajouté ; évaluation, fiche de suivi, diagnostic et bilan de
  déchocage mis à jour.
- `docs/TESTS.md` : décompte des tests (300) et nouvelle ligne de garanties « UC1 ». `README.md` et
  `docs/REPRISE.md` : nouvelles routes.

### T1.3 et T1.4 : domaine PHP

- `ClinicalImpression` : attributs v2, `KINDS` devient `code => libellé` (« Classique », « Déchocage »),
  `DETAIL_KEYS` (clés admises dans `details`).
- `ClinicalObservation` : 13 catégories, plus `STANDARD_FORM` (les 13 saisies par l'UC1 : 7 systèmes et 6
  « Autres informations »), `OTHER_INFORMATION` et `INTERPRETATIONS` (ECG, Rx thoracique).
- `ShockRoomAssessment` : les 45 champs. `FIELDS` donne maintenant `colonne => type` (int, float, string, array),
  ce qui permet au DAO de construire ses requêtes.
- `EpisodeDiagnosis` : constantes `CLINICAL_*` et `VERIFICATION_*`. `isActive()` signifie « en cours, non écarté, non
  retiré ».
- `ClinicalScales` : ajout de `ISS` (Baker et al., J Trauma 1974) et `VENTILATION_MODES` (VS/VC) ; sédation
  « Non » ajoutée devant les niveaux RASS ; liste pulmonaire de travail (section 4) ; documentation mise à jour.

### T1.5 : DAO

Les quatre DAO utilisent les colonnes v2. `ClinicalImpressionDao` encode et décode `details` (seules les clés
connues sont relues). `ShockRoomAssessmentDao` génère son `INSERT … SELECT`, son `UPDATE` et sa lecture à partir de
`ShockRoomAssessment::FIELDS`. Le JSON `lesionDetails` y est géré. `EpisodeDiagnosisDao::updateStatus()` prend les
deux statuts.

### T1.6 : tests

- `SchemaConstraintsTest` : colonnes v2. Nouveaux cas refusés : second bilan de déchocage actif sur le séjour,
  `details` non JSON, facteur d'agression nul, ISS > 75, cristalloïdes < 0, statut clinique et statut de
  vérification inconnus. Nouveaux cas acceptés : nouveau bilan après retrait, plusieurs évaluations classiques, bornes
  ISS 75 et IGS II 163.
- `EvaluationDaoTest` : colonnes v2, JSON relu, IGS II 150 (au-delà de 127, d'où `TINYINT UNSIGNED`), deux statuts
  du diagnostic, et nouveau test « un seul bilan de déchocage actif par séjour, libéré par le retrait ».
- `ClinicalImpressionTest` : catégories et listes cohérentes avec `STANDARD_FORM` et les longueurs des colonnes,
  bornes ISS.

## 3. Sprint 2 : tâches T2.1 à T2.6

### T2.1 : service métier `src/Service/EvaluationService.php`

- `validate(array $input)` est **pure** (l'horloge est injectée). Elle renvoie les erreurs indexées par nom de champ
  du formulaire, par exemple `observations[NEUROLOGICAL][value]`. Contrôles :
  - date et heure obligatoires, au format valide, pas dans le futur (5 minutes de tolérance) ;
  - valeurs des listes blanches de `ClinicalScales` (systèmes, VS/VC ; aucune liste pour les autres informations) ;
  - facteur d'agression de 1 à 3 (virgule acceptée ; négatif refusé) ;
  - « Document initialement complété par » : identifiant de praticien (son existence est vérifiée à
    l'enregistrement) ;
  - longueurs (`TEXT` 4 000 caractères, protocoles 255) ;
  - interprétation d'examen seulement pour l'ECG et la Rx ;
  - évaluation vide refusée.
- `createStandard($idEpisodeOfCare, $input)` :
  - séjour inexistant ou clos : 404 (E1) ;
  - praticien hors liste des médecins habilités RG5 : 403 (E2) ;
  - saisie invalide, évaluation antérieure à l'admission, praticien initial inconnu, médecin ou mot de passe
    manquant : 422 (E3) ;
  - la DER est **calculée** : DER de base (Harris et Benedict) × niveau d'agression ;
  - sinon, dans **une seule** `Transaction::run()` : `ClinicalImpressionDao::insert()` (évaluation et fiches
    renseignées seulement, ids MAX + 1 FOR UPDATE), puis `AuditEventDao::insert()`. La trace a pour entité
    `ClinicalImpression`, pour champ `creation`, et pour auteur « Dr … (médecin, authentification factice) », avec un
    détail sur les systèmes saisis.

  Les refus sont des `EvaluationRejected` (`src/Service/EvaluationRejected.php`) qui portent les erreurs et le code
  HTTP. Rien n'est écrit.
- `form()`, `history()`, `present()` : vues-modèles du formulaire et de l'historique du dossier (UC3, lecture
  seule).

### T2.2 : câblage `public/index.php`

La closure `$evaluationService` construit les DAO, le service, la transaction et `PatientRecordService` par
injection. Routes ajoutées : `GET /evaluations/new` et `POST /evaluations/create`. Le contrôleur du dossier reçoit
aussi le service pour afficher la section.

### T2.3 : contrôleur `src/Controller/EvaluationController.php`

- `newForm()` : 400 si l'identifiant est mal formé, 404 si le séjour est inexistant ou clos.
- `create()` :
  - contrôle `Csrf::check()` (403) ;
  - extraction de `$_POST` en liste blanche (`readInput()`) ;
  - appel du service ;
  - JSON 422 ou 403/404 pour `form.js`, `{ok: true, redirect}` (200) après succès, vers
    `/sejour?id=…&evaluation=…#titre-evaluations`.

  Sans JavaScript, le formulaire est réaffiché en 422 avec la saisie (le mot de passe n'est jamais réaffiché), et le
  succès renvoie une redirection 303.

### T2.4 : vues

- **Dossier du séjour** (`pages/episode/record.php`) : entrée « Évaluations et suivi » dans le menu figé et nouvelle
  section (`components/evaluation_history.php`), comme sur les maquettes 01 et 04 :
  - bouton « + Nouvelle évaluation » (séjour en cours seulement ; sinon « Séjour en cours requis ») ;
  - message « Évaluation classique enregistrée le … par Dr … » ;
  - liste du plus récent au plus ancien : badge du type, date, médecin, DER, résumé ;
  - chaque évaluation se déplie en lecture seule : examen et évaluation, un bloc par système renseigné (autres
    informations comprises), conclusion ;
  - état vide explicite, note « Seules les évaluations non supprimées… ».
- **Formulaire** (`pages/evaluation/form.php`), maquette 02 :
  - bandeau patient collant (RG2), fil d'Ariane, menu du dossier (entrée courante en surbrillance) ;
  - 9 sections `<details>` numérotées avec leur compteur « n sur m renseignés », dans l'ordre et avec les libellés
    de l'écran du service (détail en section 4) ;
  - colonne de droite « Accès aux sections » et barre d'actions « Retour au dossier » / « Valider l'évaluation ».
- Le backlog indique `src/View/evaluation/form.php`. J'ai suivi la convention du dépôt : `src/View/pages/…` pour les
  pages, `src/View/components/…` pour les composants.
- `components/episode_menu.php` (option `current`), `components/icon.php` (icônes `plus`, `clipboard-list`) et
  `public/css/style.css` (styles des cartes, du formulaire et de la fenêtre, avec les jetons de couleur : le thème
  sombre suit).

### T2.5 : authentification factice et AJAX (`public/js/evaluation-form.js`)

- J'ai réutilisé l'authentification existante : même règle (médecin de la liste des habilités RG5, mot de passe
  exigé mais **non vérifié**), même liste (`ReferenceDataDao::doctors()`), même libellé d'auteur
  (« … (médecin, authentification factice) »), mêmes styles `auth-dialog`.
- « Valider l'évaluation » vérifie d'abord les contrôles natifs, puis ouvre la fenêtre « Enregistrer l'évaluation »
  (maquette 03), qui rappelle le patient, le séjour et la date. « Confirmer et enregistrer » envoie le formulaire par
  `form.js` (AJAX, jeton CSRF).
- En cas de 422, les erreurs s'affichent sous les champs **sans perdre la saisie**. La fenêtre reste ouverte si
  l'erreur porte sur le médecin ou le mot de passe ; sinon elle se ferme et `form-sections.js` ouvre la section
  fautive.
- La fenêtre est construite à partir d'un `fieldset` placé dans le formulaire. Sans JavaScript, ce bloc reste
  visible en bas de page et l'envoi se fait en POST classique.

### T2.6 : tests

| Fichier | Contenu |
| --- | --- |
| `tests/Service/EvaluationServiceTest.php` (12 tests, sans base) | cas nominal ; date obligatoire, invalide, future ; valeur hors liste ; facteur d'agression hors bornes ou négatif ; VS/VC hors liste ; sédation « Non » ; pas de liste pour les autres informations ; praticien initial inconnu ; évaluation vide ; longueurs ; 404, 403, 422 sans aucune écriture ; création complète (seules les fiches renseignées, `details` JSON, DER calculée 1 611 kcal/j, pas d'orientation, trace AuditEvent, mot de passe jamais tracé) ; présentation (ordre de l'écran, VS/VC, conclusion) |
| `tests/Service/EvaluationIntegrationTest.php` (2 tests, base) | parcours complet sur MariaDB : création, DER calculée à partir de la patiente de `test.sql`, trace AuditEvent, relecture dans l'historique ; refus sans aucune écriture |
| `tests/Http/EvaluationHttpTest.php` (2 tests, HTTP) | bouton du dossier, formulaire avec bandeau RG2, autres informations, MG, pas de champ DER saisi, script des messages français, 404 et 400 ; POST : 403 sans CSRF, 403 soignant, 404 séjour clos, 422 avec erreurs par champ, 200 et redirection, message et résumé dans le dossier |

## 4. Changements de dernière minute

### 4.1 Champs de l'évaluation selon l'écran du service (`prompt_tache2/mail`)

Le formulaire reprend l'ordre et les libellés des trois captures :

| Section | Champs | Avant |
| --- | --- | --- |
| 01 Examen et évaluation | date et heure*, **Document initialement complété par** (tous les praticiens), poids, taille, **date de naissance**, âge ; « Données générales » calculées : **surface corporelle** (Mosteller), **IMC** avec le repère « pour mémoire » de l'écran, **poids théorique**, **Vt théorique** ; niveau d'agression ; **DER calculée** (non saisie) ; précautions ; **protocoles de recherche** (déplacés ici) ; évaluation générale | DER saisie, protocoles en fin de formulaire, pas de données calculées ni d'auteur initial |
| 02 Neurologique | sédation (**liste seule**, avec « Non »), évaluation neurologique (liste, notes) | sédation avec notes, sans « Non » |
| 03 Cardiovasculaire | liste, notes, ECG, échocardiographie | inchangé |
| 04 Hémodynamique | liste, notes | inchangé |
| 05 Respiratoire et pulmonaire | **VS/VC** (champ à part), **évaluation pulmonaire** (liste, notes), Rx thoracique | une seule liste mêlant VS/VC et oxygénation |
| 06 Abdominale, 07 Uro-rénal | liste, notes | inchangé |
| 08 **Autres informations** (nouvelle) | cutanéo-muqueuse, ostéo-articulaire, état métabolique, état infectieux, dépistage BMR, marqueurs biologiques anormaux (texte libre) | absente |
| 09 **Conclusion** | synthèse clinique à l'admission, proposition d'examens paracliniques, proposition thérapeutique, **MG - Anamnèse**, **MG - Synthèse 1**, **MG - Synthèse 2** | « Synthèse et orientation » avec orientation |

Conséquences dans le code :

- Les six « Autres informations » sont **exactement** les six catégories ajoutées par le modèle v2. Le mail confirme
  donc `ABNORMAL_BIOLOGY` (« Marqueurs biologiques anormaux »), le nom que j'avais deviné. Aucune modification de
  base n'est nécessaire.
- Les champs sans colonne propre vont dans la colonne JSON `details` : `ventilationMode`, `initiallyCompletedBy`,
  `mgAnamnesis`, `mgSummary1`, `mgSummary2`, en plus de `echocardiography`. Liste blanche :
  `ClinicalImpression::DETAIL_KEYS`.
- **DER** : `DerivedMeasuresService::basalEnergyExpenditure()`, équation de Harris et Benedict révisée (Roza et
  Shizgal, Am J Clin Nutr 1984, sourcée comme les autres formules), × niveau d'agression. Elle utilise le sexe, l'âge,
  le poids et la taille du séjour. `PatientRecordService::record()` fournit maintenant ces mesures brutes. Le
  formulaire affiche le résultat en direct pendant la saisie du facteur. Le serveur refait le calcul et l'enregistre
  dans `restingEnergyExpenditure`. Si une donnée manque, l'écran affiche « Non calculable » avec le motif.
- L'**orientation** quitte l'évaluation classique (absente de l'écran). La colonne reste pour le bilan de déchocage,
  où l'écran la montre (« Orientation post déchocage »).
- Le contrôle « DER négative » du sprint précédent n'a plus lieu d'être (plus de saisie). La règle E3 de la spec
  reste couverte par le niveau d'agression (négatif ou hors 1 à 3 → 422).

### 4.2 Messages d'erreur en français

- Les messages « Please fill out this field », « Please enter a number »… venaient du **navigateur** (contrôles
  natifs `required`, `type="number"`, `min`, `max`, `step`, `pattern`, `type="email"`), qui parle sa propre langue
  quelle que soit celle de la page. Les messages du serveur (422) étaient déjà en français.
- Nouveau `public/js/validation-fr.js`, chargé **sur toutes les pages** par `layouts/main.php`. Il remplace chaque
  message natif par un texte français : champ obligatoire, choix dans une liste, nombre ou date invalide, bornes,
  multiple du pas, longueur, adresse e-mail, format. Un champ peut donner son propre message de format
  (`data-pattern-message`). Le message est retiré dès que la saisie change. Ce qui est accepté ne change pas.
- Le niveau d'agression n'est plus un champ `number`. Firefox en anglais refusait « 1,3 » (« Please enter a
  number »). C'est désormais un champ texte décimal qui accepte la virgule et le point, avec le message « Saisissez
  un nombre décimal, par exemple 1,3. ».
- Vérifié dans Firefox (mode headless, interface en anglais) en lisant `validationMessage` de chaque champ : tous
  les messages sortent en français. Le sélecteur de date et d'heure (`datetime-local`) affiche encore son format
  selon la **langue du navigateur** (11/25/2026 AM sur un poste en anglais). Ce n'est pas un message d'erreur, et
  il apparaît en français sur un poste configuré en français.

### 4.3 Captures non utilisées

L'archive `transfer_14272528_files_551384ed.zip` contient, en plus des trois captures du dossier `mail`, cinq
captures du **bilan de déchocage** (UC2, sprint suivant). Je ne les ai pas utilisées. Elles serviront à l'UC2 : le
modèle v2 a déjà leurs champs. **Attention** : l'une d'elles montre en clair un identifiant et un mot de passe
d'accès à un outil de l'hôpital. Mieux vaut ne pas versionner cette archive.

## 5. Vérifications effectuées

Comme au sprint 1, l'accès à votre base locale n'a pas été utilisé. J'ai lancé une instance MariaDB jetable (13.0,
port 33099, dossier scratch de la session), arrêtée à la fin. La CI utilise MariaDB 11.8.

| Vérification | Résultat |
| --- | --- |
| `schema.sql` + `test.sql` (+ `demo-today.sql`) sur une base vide | OK |
| Chemin A : schéma d'avant le sprint 1 + `test.sql`, `2026-11-18` (nouvelle) ×2, puis `2026-11-25` ×2 | OK, `SHOW CREATE TABLE` identique à `schema.sql` (4 tables + `CarePlan`) |
| Chemin B : schéma d'avant le sprint 1 + `2026-11-18` **version sprint 1** + données, puis `2026-11-25` ×2 | OK, structure identique à `schema.sql`, données conservées (statut SUSPECTED converti) |
| PHPUnit, tout (`aura` + `http`) sur la base de test | **OK (300 tests, 3 544 assertions)** (278 avant ce sprint) |
| PHPUnit sans base | OK, 129 tests ignorés comme prévu |
| `php -l` sur `src`, `tests`, `public` | aucune erreur |
| `composer cs` (PSR-12) | 0 erreur, 26 avertissements, tous antérieurs (même nombre qu'avant). Aucun dans les fichiers créés. |
| Parcours manuel sur serveur PHP local | formulaire, fenêtre, 403, 422, création, retour au dossier avec le message : OK. Captures Firefox (mode headless) comparées aux maquettes 02, 03, 04 et aux captures du mail (DER affichée 1 611 kcal/j pour un facteur 1,3). |
| Messages natifs du navigateur | Firefox en anglais : obligatoire, liste, format décimal, maximum, pas, e-mail → messages français (section 4.2) |

## 6. Choix de conception et points à faire valider

1. **Catégorie `ABNORM…`** : son nom est coupé dans `Modèle de données.png`. J'ai retenu `ABNORMAL_BIOLOGY`.
   **Confirmé** par le mail (« Marqueurs biologiques anormaux »).
2. **IGS II en `TINYINT UNSIGNED`** : le modèle indique `TINYINT`, qui s'arrête à 127, alors que l'IGS II va jusqu'à
   163. La version non signée (0 à 255) respecte le type du modèle et contient 163. La borne 163 est contrôlée par
   l'application.
3. **Échocardiographie** : l'écran a deux interprétations cardiovasculaires et la fiche n'a qu'une colonne
   `interpretation`. L'ECG va dans la fiche, l'échocardiographie dans `ClinicalImpression.details`
   (`{"echocardiography": …}`). Cela utilise la colonne JSON du modèle sans en inventer une autre.
4. **DER** : la formule (Harris et Benedict révisée × niveau d'agression) est mon choix ; le mail montre seulement
   que la DER est calculée. Le niveau d'agression est borné de 1 à 3 par le service (la base n'exige que `> 0`).
   **Formule et bornes à valider par le service.**
5. **Deux praticiens** : « Document initialement complété par » (mail, maquette 02) est enregistré dans `details`.
   Il peut s'agir de n'importe quel praticien, par exemple un interne. Le **médecin qui valide** (fenêtre, maquette
   03) reste l'auteur de l'évaluation (`idPractitioner`, RG10) et de la trace.
6. **Poids, taille, date de naissance, précautions** : l'écran du service les présente comme modifiables. Je les ai
   laissés **en lecture seule** dans l'évaluation : ce sont des données du patient et du séjour, déjà modifiables (et
   tracées) depuis le dossier. Les ressaisir ici créerait une seconde source de vérité. **À valider.**
7. **Statuts de diagnostic** : la migration convertit SUSPECTED et REFUTED. ACTIVE et RESOLVED gardent la
   vérification PROVISIONAL par défaut : c'est au médecin de les confirmer.
8. **« + Observation déchocage »** (maquettes 01 et 04) : c'est l'UC2, prévue à un sprint suivant. Le bouton n'est
   pas affiché, pour ne pas mener à une page inexistante.
9. **Liste « Évaluation pulmonaire »** : l'écran ne donne pas ses valeurs. J'ai proposé une liste de travail
   (auscultation normale, encombrement, crépitants, sibilants, condensation, épanchement pleural, pneumothorax), à
   faire valider comme les autres listes sans échelle. « Dépistage BMR » et les autres informations sont en texte
   libre faute de liste.
10. **« MG »** dans « MG - Anamnèse / Synthèse » : j'ai gardé le libellé de l'écran tel quel, sans l'interpréter.

## 7. Hors périmètre et suite proposée

- UC2 (bilan de déchocage : formulaire 4 blocs, 409/422 si un bilan actif existe déjà), UC4 et UC7 (modification
  avec concurrence optimiste et trace par champ), UC5 et UC8 (suppression logique). Le modèle, les DAO et la
  contrainte `shockRoomKey` sont prêts.
- L'affichage détaillé d'un bilan de déchocage dans l'historique (UC6) se limite pour l'instant à l'IGS II.
- `test.sql` n'a pas été modifié : les tests créent et effacent leurs propres évaluations.

À signaler : `config/config.example.php` apparaît modifié dans la copie de travail (`127.0.0.1` → `localhost`, mot de
passe `mot-de-passe`). Je n'ai pas fait cette modification et je n'y ai pas touché : à vérifier avant un commit.

## 8. Fichiers

Créés :

- `database/migrations/2026-11-25-evaluations-modele-v2.sql`
- `src/Service/EvaluationService.php`, `src/Service/EvaluationRejected.php`
- `src/Controller/EvaluationController.php`
- `src/View/pages/evaluation/form.php`, `src/View/components/evaluation_history.php`
- `public/js/evaluation-form.js`, `public/js/validation-fr.js`
- `tests/Service/EvaluationServiceTest.php`, `tests/Service/EvaluationIntegrationTest.php`,
  `tests/Http/EvaluationHttpTest.php`
- `prompt_tache2/compte-rendu-sprint2.md` (ce fichier)

Modifiés :

- `database/schema.sql`, `database/migrations/2026-11-18-evaluations-patient.sql`
- `src/Domain/ClinicalImpression.php`, `ClinicalObservation.php`, `ShockRoomAssessment.php`,
  `EpisodeDiagnosis.php`, `ClinicalScales.php`
- `src/Dao/ClinicalImpressionDao.php`, `ClinicalObservationDao.php`, `ShockRoomAssessmentDao.php`,
  `EpisodeDiagnosisDao.php`
- `src/Service/DerivedMeasuresService.php` (DER de base), `src/Service/PatientRecordService.php` (mesures brutes)
- `src/Controller/EpisodeOfCareController.php`, `public/index.php`, `src/View/layouts/main.php`
- `src/View/pages/episode/record.php`, `src/View/components/episode_menu.php`, `src/View/components/icon.php`
- `public/css/style.css`
- `tests/Dao/SchemaConstraintsTest.php`, `tests/Dao/EvaluationDaoTest.php`, `tests/Domain/ClinicalImpressionTest.php`
- `docs/ARCHITECTURE.md`, `docs/TESTS.md`, `docs/REPRISE.md`, `README.md`
