# Compte rendu : sprint 1, gestion des évaluations patient

Date : 23/11/2026. Branche : `evaluation`. Rien n'a été commité : toutes les modifications sont dans la copie de
travail, prêtes à relire.

## 1. Ce qui a servi de base

- `prompts/prompt.txt` : la consigne (lire le projet, réaliser le sprint 1, écrire ce compte rendu).
- `prompts/sprint1.txt` : les six tâches T1.1 à T1.6.
- `prompts/Cahier des charges concernant la gestion des évaluations patient.pdf` : écrans de l'évaluation classique
  (figure 2 : données générales, éléments par système, synthèse) et du déchocage (figure 3 : observation médicale,
  prise en charge SMUR, examen clinique, bilans, avis extérieurs, IGS II).
- `prompts/class_diagram.svg` : le diagramme de classes cible. Les entités, attributs et constantes ont été repris
  à l'identique (noms, types, multiplicités).
- `prompts/screenshot_…png` : UC1 « Créer une évaluation médicale classique ». Il décrit l'écran et le contrôleur.
  Ce sont les sprints suivants, mais le modèle a été fait pour les accueillir.
- Le dépôt : `README.md`, `docs/ARCHITECTURE.md`, `docs/TESTS.md`, `docs/REPRISE.md`, `database/`, `src/`, `tests/`.
  Les conventions déjà en place ont été suivies : ids `VARCHAR(20)` calculés par `MAX + 1 … FOR UPDATE`, suppression
  logique `removedAt` avec la colonne générée `activeKey`, entités immuables avec un accesseur par colonne, requêtes
  préparées, `Transaction::run()`, migrations rejouables, code en anglais HL7 et commentaires en français.

## 2. Réalisé, tâche par tâche

### T1.1 : base de données

`database/schema.sql` : quatre nouvelles tables, créées avant `CarePlan` (qui les référence) et supprimées dans le
bon ordre en tête du fichier.

| Table | Rôle (HL7 FHIR) | Contraintes clés |
| --- | --- | --- |
| `ClinicalImpression` | Évaluation médicale d'un séjour (ClinicalImpression) : médecin, type, heure clinique, note générale, DER (facteur d'agression, dépense énergétique au repos), conclusion, orientation, `recordedAt`, `removedAt` | `CHECK` sur le type (`STANDARD`, `SHOCK_ROOM`), facteur d'agression entre 1 et 3, DER > 0 ; clés étrangères vers séjour et praticien ; index (séjour, date) |
| `ClinicalObservation` | Fiche de suivi d'un système (Observation) : valeur de liste, notes, interprétation d'examen (ECG, écho, Rx) | `CHECK` sur les 7 systèmes, `CHECK` « fiche non vide », `activeKey` + `UNIQUE (évaluation, système, activeKey)` : une seule fiche active par système |
| `ShockRoomAssessment` | Bilan de déchocage : les 28 champs de la figure 3 | `UNIQUE (idClinicalImpression)` : au plus un bilan ; `CHECK` Glasgow 3 à 15, IGS II 0 à 163, durées et mesures ≥ 0 |
| `EpisodeDiagnosis` | Diagnostic du séjour (Encounter.diagnosis / Condition) : pathologie, statut, notes, date | `CHECK` statut (`ACTIVE`, `SUSPECTED`, `RESOLVED`, `REFUTED`), `activeKey` + `UNIQUE (séjour, pathologie, activeKey)` ; clés étrangères vers séjour, évaluation (facultative), `Pathology` |

Le lien `CarePlan.idClinicalImpression` (facultatif, clé étrangère) relie une ordonnance à l'évaluation dont elle
découle. Total : 24 tables et 37 clés étrangères (au lieu de 20 et 29).

`database/migrations/2026-11-18-evaluations-patient.sql` : `CREATE TABLE IF NOT EXISTS` pour les quatre tables,
`ADD COLUMN IF NOT EXISTS` et `FOREIGN KEY IF NOT EXISTS` pour `CarePlan`. Aucun `DROP`.

### T1.2 : documentation (`docs/ARCHITECTURE.md`)

- Modèle de données (ER) : les quatre tables avec leurs colonnes et contraintes, la nouvelle colonne de `CarePlan`,
  les 8 nouvelles flèches de clé étrangère, le paragraphe « Lecture » et les notes sur `removedAt`, `activeKey` et
  `AuditEvent`.
- Diagramme de classes du domaine : `Pathology`, `ClinicalImpression`, `ClinicalObservation`, `ShockRoomAssessment`,
  `EpisodeDiagnosis`, les nouvelles constantes de `AuditEvent` et `ClinicalScales`, et les associations avec leurs
  multiplicités, comme dans `class_diagram.svg`.
- Diagramme des DAO : les quatre nouveaux DAO, avec un paragraphe sur leur fonctionnement.
- « Décisions de conception » : suppression logique étendue, nouvelle entrée « Évaluations patient ».
- Glossaire : 16 termes ajoutés à leur place alphabétique (AVPU, bilan de déchocage, déchocage, DER, diagnostic du
  séjour, eFAST, évaluation médicale, fiche de suivi, Glasgow, Hémocue, IGS II, KDIGO, pathologie, RASS, SMUR/SAMU,
  grade TRENAU).
- Ajouté hors sprint, pour garder la doc juste : `docs/TESTS.md` (nombre de tests mis à jour, ligne « Évaluations
  patient » dans les garanties).

### T1.3 : domaine PHP (`src/Domain/`)

Entités immuables PHP 8.4 (constructeur typé et promu, un accesseur `get…()` par colonne, sans validation, comme les
autres entités du dépôt) :

- `Pathology` : `idPathology`, `label`.
- `ClinicalImpression` : constantes `KIND_STANDARD`, `KIND_SHOCK_ROOM`, `KINDS` ; contient ses `observations` et son
  `shockRoomAssessment` ; méthodes `isShockRoom()` et `isRemoved()`.
- `ClinicalObservation` : constantes `CATEGORY_*` (7 systèmes) et `CATEGORIES` (code ⇒ libellé, dans l'ordre de
  l'écran).
- `ShockRoomAssessment` : les 28 champs cliniques, tous facultatifs (la fiche se remplit au fil de la prise en
  charge), et la constante `FIELDS` qui en donne la liste.
- `EpisodeDiagnosis` : constantes `STATUS_*` et `STATUSES` (code ⇒ libellé) ; `isActive()` (diagnostic retenu et non
  retiré).
- `CarePlan` : nouveau paramètre facultatif `idClinicalImpression`, ajouté en dernier pour ne casser aucun appel
  existant.

### T1.4 : référentiels PHP

`ClinicalScales.php`. Chaque échelle cite sa source, comme l'exige la règle du dépôt (« aucune échelle inventée ») :

- `GLASGOW` (3 à 15) et `GLASGOW_COMPONENTS` (E1 à 4, V1 à 5, M1 à 6) : Teasdale et Jennett, Lancet 1974.
- `IGS2` (0 à 163) : Le Gall et al., JAMA 1993.
- `ARRIVAL_GRADES` (A, B, C) : grades du réseau TRENAU, Bouzat et al., Crit Care 2015.
- `OBSERVATION_LISTS` : une liste normalisée par système. Sources : sédation RASS (Sessler 2002), neurologique AVPU
  (McNarry 2004), hémodynamique selon les quatre mécanismes de choc (Vincent et De Backer, NEJM 2013), uro-rénal
  selon les stades KDIGO (2012).

`AuditEvent.php` : `ENTITY_CLINICAL_IMPRESSION` et `ENTITY_EPISODE_DIAGNOSIS`. Ces noms tiennent dans la colonne
`entity VARCHAR(20)`.

### T1.5 : accès aux données (`src/Dao/`)

Les quatre DAO utilisent des requêtes préparées PDO. Ils calculent leurs ids `VARCHAR(20)` par
`MAX(CAST(id AS UNSIGNED)) + 1 … FOR UPDATE` dans la transaction d'écriture et filtrent `removedAt IS NULL` à la
lecture.

- `ClinicalImpressionDao` : `findById`, `findByEpisodeOfCare` (plus récente d'abord), `insert` (enregistre
  l'évaluation, ses fiches et son bilan dans une seule transaction), `update` (en-tête), `markRemoved`. Il refuse un
  bilan de déchocage joint à une évaluation `STANDARD`.
- `ClinicalObservationDao` : `findById`, `findByClinicalImpression` (dans l'ordre des systèmes de l'écran), `insert`,
  `update`, `markRemoved`.
- `ShockRoomAssessmentDao` : `findByClinicalImpression`, `insert`, `update`. L'`insert` est un `INSERT … SELECT` qui
  vérifie dans la même requête que l'évaluation existe, est active et est de type `SHOCK_ROOM` ; sinon il lève une
  `LogicException` et rien n'est écrit. Le bilan n'a pas de `removedAt` : il disparaît des lectures avec son
  évaluation.
- `EpisodeDiagnosisDao` : `findById`, `findByEpisodeOfCare`, `findByClinicalImpression`, `pathologies()` (référentiel
  `Pathology` trié, pour la future liste déroulante), `insert`, `updateStatus`, `markRemoved`.
- `CarePlanDao` : lit et écrit `idClinicalImpression`.

À savoir : MariaDB ne compte pas une ligne mise à jour avec des valeurs identiques. Les méthodes `update` vérifient
donc l'existence de la ligne quand le nombre de lignes modifiées vaut 0. Sinon, une réécriture à l'identique serait
signalée à tort comme « introuvable ».

### T1.6 : tests automatisés (`tests/`)

- `tests/Dao/SchemaConstraintsTest.php` : 19 cas ajoutés. 14 instructions invalides doivent être refusées avec le bon
  code d'erreur et par la bonne contrainte : type d'évaluation inconnu (casse comprise), séjour inexistant, facteur
  d'agression < 1, système inconnu, fiche vide, deux fiches actives pour un même système, Glasgow < 3, IGS II > 163,
  deux bilans, statut de diagnostic inconnu, pathologie en double, pathologie hors référentiel, ordonnance liée à une
  évaluation inexistante, suppression physique d'une évaluation qui a des fiches. 5 instructions valides doivent
  passer : fiche ressaisie après retrait, même système sur deux évaluations, pathologie ressaisie après retrait,
  bornes Glasgow 3 et IGS II 163, ordonnance liée à une évaluation.
- `tests/Dao/EvaluationDaoTest.php` (nouveau, 9 tests) : insertion puis relecture d'une évaluation classique avec
  ses fiches (ordre de l'écran, rattachement à l'évaluation créée), d'une fiche de déchocage avec son bilan ; refus
  du bilan sur une évaluation classique ; mises à jour (y compris une réécriture à l'identique) ; isolation des
  lignes retirées (évaluation, fiche, diagnostic : la ligne reste en base mais n'est plus lue ni modifiable) ;
  ressaisie d'un système ou d'une pathologie après retrait ; cycle de vie d'un diagnostic ; référentiel des
  pathologies ; ordonnance reliée à son évaluation.
- `tests/Domain/ClinicalImpressionTest.php` (nouveau, 5 tests, sans base) : `isShockRoom`, `isRemoved`, `isActive` ;
  une liste normalisée par système ; valeurs compatibles avec la taille des colonnes ; bornes de Glasgow égales à la
  somme de ses composantes ; `ShockRoomAssessment::FIELDS` identique aux paramètres du constructeur.

## 3. Vérifications effectuées

L'accès direct à votre base locale `aura` a été refusé. Pour tout tester sans y toucher, j'ai lancé une instance
MariaDB jetable (port 33099, données dans le dossier scratch de la session) et je l'ai arrêtée à la fin. Elle est
en version 13.0, alors que la CI utilise la 11.8. Seule la CI confirmera donc le résultat en 11.8.

| Vérification | Résultat |
| --- | --- |
| `schema.sql`, puis `test.sql` et `demo-today.sql`, chargés sur une base vide | OK |
| Migration jouée deux fois de suite sur le schéma d'avant le sprint (`git show HEAD:database/schema.sql`) + `test.sql` | OK, puis `SHOW CREATE TABLE` identique à `schema.sql` pour les 4 tables et `CarePlan` |
| PHPUnit, tout (`aura` + `http`) sur la base de test | **OK (278 tests, 3 386 assertions)** (225 + 20 avant le sprint) |
| PHPUnit sans base (`DB_HOST` absent) | OK, les tests sur base sont ignorés comme prévu |
| `php -l` sur `src` et `tests` | aucune erreur |
| `composer cs` (PSR-12) | 0 erreur ; aucun avertissement dans les fichiers créés ou modifiés (les 26 avertissements restants existaient avant) |
| Diagrammes Mermaid | non rendus en local (pas d'outil Mermaid sur la machine) ; même syntaxe que les diagrammes existants. À vérifier à l'affichage sur GitHub. |

## 4. Choix de conception et points à faire valider

1. **Données générales d'examen non dupliquées** (poids, taille, IMC, surface corporelle, figure 2.a) : elles restent
   sur `EpisodeOfCare` et dans `DerivedMeasuresService`, comme dans le diagramme fourni.
2. **Interprétation d'examen unique par fiche** (`examInterpretation`) : l'écran cardiovasculaire a deux zones (ECG,
   échocardiographie) et le diagramme fourni n'a qu'un attribut. Je l'ai respecté. Si le service veut deux champs
   séparés, il faudra une colonne de plus.
3. **Listes sans échelle de référence** (rythme ECG, support ventilatoire « VS/VC », examen abdominal) : ce sont des
   termes cliniques usuels, signalés « à faire valider par le service » dans `ClinicalScales`, comme les données de
   démonstration.
4. **Bornes choisies pour la cohérence des données, sans source** : facteur d'agression de 1 à 3 (borne haute
   volontairement large), DER > 0, durées et mesures ≥ 0. Glasgow et IGS II ont, eux, leurs bornes officielles.
5. **Type `SHOCK_ROOM` du bilan** : il est vérifié par le DAO et non par une clé étrangère composite, une
   construction plus fragile en MariaDB (clé étrangère sur colonne générée).
6. **UC1 et `Evaluation`** : la capture parle d'une table `Evaluation`. J'ai retenu le nom HL7 `ClinicalImpression` du
   diagramme de classes fourni, conformément à la convention de nommage du dépôt.

## 5. Hors périmètre du sprint 1 (suite proposée)

Rien n'utilise encore ces DAO côté application. Il reste à faire :

- un service avec validation pure (champs obligatoires, valeurs de `ClinicalScales`, longueurs), la transaction et
  la trace `AuditEvent` au nom du médecin ;
- les routes et le contrôleur (`/sejour/evaluations/…`), avec CSRF (403), erreurs 422 en JSON et authentification
  factice ;
- les vues : formulaire en sections `<details>`, bandeau patient (RG2) et bouton « Nouvelle évaluation » sur
  `pages/episode/record.php` (UC1) ;
- la fiche de déchocage et l'écran des diagnostics.

Données de démonstration : `test.sql` n'a pas été modifié. Les tests créent leurs propres évaluations dans une
transaction annulée.

## 6. Fichiers

Créés :

- `database/migrations/2026-11-18-evaluations-patient.sql`
- `src/Domain/Pathology.php`, `ClinicalImpression.php`, `ClinicalObservation.php`, `ShockRoomAssessment.php`,
  `EpisodeDiagnosis.php`
- `src/Dao/ClinicalImpressionDao.php`, `ClinicalObservationDao.php`, `ShockRoomAssessmentDao.php`,
  `EpisodeDiagnosisDao.php`
- `tests/Dao/EvaluationDaoTest.php`, `tests/Domain/ClinicalImpressionTest.php`
- `prompts/compte-rendu-sprint1.md` (ce fichier)

Modifiés :

- `database/schema.sql`
- `src/Domain/ClinicalScales.php`, `src/Domain/AuditEvent.php`, `src/Domain/CarePlan.php`
- `src/Dao/CarePlanDao.php`
- `tests/Dao/SchemaConstraintsTest.php`
- `docs/ARCHITECTURE.md`, `docs/TESTS.md`
