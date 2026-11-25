<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Échelles cliniques proposées à l'admission (scores et état antérieur) et à l'évaluation médicale
 * (listes normalisées par système, Glasgow, IGS II, grade de déchocage), avec leurs valeurs
 * officielles. La valeur stockée est la clé (colonne VARCHAR de EpisodeOfCare, ClinicalObservation ou
 * ShockRoomAssessment), le libellé est celui affiché dans les listes déroulantes et le dossier.
 *
 * Chaque échelle cite sa source (règle du brief : aucune échelle inventée). Les libellés sont
 * des traductions abrégées ; en cas de doute, la source fait foi.
 */
final class ClinicalScales
{
    /**
     * ASA Physical Status Classification System (American Society of Anesthesiologists,
     * statement approuvé le 15/10/2014, amendé le 13/12/2020).
     * Source : https://www.asahq.org/standards-and-practice-parameters/
     *          statement-on-asa-physical-status-classification-system
     * Reproduction consultée : https://www.scientificspine.com/scores/asa-classification
     */
    public const ASA = [
        'I' => 'ASA I : patient en bonne santé',
        'II' => 'ASA II : maladie systémique légère',
        'III' => 'ASA III : maladie systémique sévère',
        'IV' => 'ASA IV : maladie systémique sévère, menace vitale constante',
        'V' => 'ASA V : patient moribond, survie improbable sans l\'intervention',
        'VI' => 'ASA VI : mort cérébrale déclarée, prélèvement d\'organes',
    ];

    /**
     * Classification fonctionnelle NYHA (New York Heart Association), classes I à IV.
     * Source : https://www.heart.org/en/health-topics/heart-failure/what-is-heart-failure/classes-of-heart-failure
     * Reproduction consultée : https://www.healio.com/cardiology/learn-the-heart/cardiology-review/topic-reviews/new-york-heart-association-nyha-functional-class
     */
    public const NYHA = [
        'I' => 'NYHA I : aucune limitation de l\'activité physique',
        'II' => 'NYHA II : symptômes à l\'effort modéré',
        'III' => 'NYHA III : symptômes à l\'effort minime',
        'IV' => 'NYHA IV : symptômes au repos',
    ];

    /**
     * Échelle de Mac Cabe (état de santé antérieur, pronostic défini dans les trois mois
     * précédant l'entrée en réanimation). Items 0, 1, 2 selon le guide IGS II / Mac Cabe /
     * Knaus de la SRLF (2011).
     * Source : https://www.srlf.org/wp-content/uploads/2015/11/2011_IGSII_Mac_Cabe_Knaus.doc
     * Copie PDF consultée : https://sofia.medicalistes.fr/spip/IMG/pdf/IGSII-_Mac_Cabe-Knaus_2011_.pdf
     */
    public const MCCABE = [
        '0' => '0 : pas de maladie mortelle',
        '1' => '1 : maladie mortelle à 5 ans',
        '2' => '2 : maladie mortelle à 1 an',
    ];

    /**
     * État fonctionnel antérieur de Knaus (activité et suivi médical dans les six mois précédant
     * l'admission), classes A à D. Même source SRLF que l'échelle de Mac Cabe.
     * Source : https://www.srlf.org/wp-content/uploads/2015/11/2011_IGSII_Mac_Cabe_Knaus.doc
     */
    public const KNAUS = [
        'A' => 'A : santé normale',
        'B' => 'B : limitation modérée (traitement chronique)',
        'C' => 'C : limitation importante (handicap à l\'effort, traitement anticancéreux, hémodialyse)',
        'D' => 'D : patient grabataire, restriction majeure',
    ];

    /**
     * Groupes iso-ressources de la grille AGGIR, GIR 1 (dépendance la plus forte) à GIR 6
     * (autonome). L'échelle officielle n'a pas de GIR 0 : le brief mentionnait « 0 à 6 »,
     * la valeur 0 n'est donc pas proposée.
     * Source : https://www.service-public.gouv.fr/particuliers/vosdroits/F1229
     */
    public const GIR = [
        '1' => 'GIR 1 : confiné au lit ou au fauteuil, fonctions mentales gravement altérées',
        '2' => 'GIR 2 : confiné au lit ou au fauteuil (fonctions mentales partiellement altérées) '
            . 'ou fonctions mentales altérées mais capable de se déplacer',
        '3' => 'GIR 3 : autonomie mentale conservée, aide corporelle plusieurs fois par jour',
        '4' => 'GIR 4 : transferts non assurés seul ou aide pour les activités corporelles et les repas',
        '5' => 'GIR 5 : aide ponctuelle (toilette, repas, ménage)',
        '6' => 'GIR 6 : autonome pour les actes essentiels de la vie courante',
    ];

    /**
     * Profils INTERMACS 1 à 7 (insuffisance cardiaque avancée).
     * Source : Stevenson LW et al. INTERMACS profiles of advanced heart failure: the current
     * picture. J Heart Lung Transplant. 2009;28(6):535-41. https://pubmed.ncbi.nlm.nih.gov/19481012/
     * Tableau des profils consulté : https://cdn.clinicaltrials.gov/large-docs/55/NCT02224755/Prot_001.pdf
     */
    public const INTERMACS = [
        '1' => 'Profil 1 : choc cardiogénique critique',
        '2' => 'Profil 2 : dégradation progressive sous inotropes',
        '3' => 'Profil 3 : stable mais dépendant des inotropes',
        '4' => 'Profil 4 : symptômes au repos',
        '5' => 'Profil 5 : intolérance à l\'effort',
        '6' => 'Profil 6 : effort limité',
        '7' => 'Profil 7 : NYHA III avancé',
    ];

    /**
     * Score de Glasgow (Glasgow Coma Scale) : somme des trois composantes, de 3 (coma profond) à 15
     * (conscience normale). Bornes reprises par le CHECK chk_shockroom_glasgow.
     * Source : Teasdale G, Jennett B. Assessment of coma and impaired consciousness. A practical scale.
     * Lancet. 1974;2(7872):81-4. https://pubmed.ncbi.nlm.nih.gov/4136544/
     */
    public const GLASGOW = ['min' => 3, 'max' => 15];

    /** Composantes du score de Glasgow (même source) : yeux (E), réponse verbale (V), réponse motrice (M). */
    public const GLASGOW_COMPONENTS = [
        'E' => [
            '4' => 'E4 : ouverture spontanée',
            '3' => 'E3 : ouverture à la demande',
            '2' => 'E2 : ouverture à la douleur',
            '1' => 'E1 : aucune ouverture',
        ],
        'V' => [
            '5' => 'V5 : orientée',
            '4' => 'V4 : confuse',
            '3' => 'V3 : mots inappropriés',
            '2' => 'V2 : sons incompréhensibles',
            '1' => 'V1 : aucune réponse verbale',
        ],
        'M' => [
            '6' => 'M6 : obéit aux ordres',
            '5' => 'M5 : localise la douleur',
            '4' => 'M4 : évitement (retrait)',
            '3' => 'M3 : flexion anormale (décortication)',
            '2' => 'M2 : extension (décérébration)',
            '1' => 'M1 : aucune réponse motrice',
        ],
    ];

    /**
     * IGS II (indice de gravité simplifié, SAPS II) : de 0 à 163 points. Bornes reprises par le CHECK
     * chk_shockroom_igs2 (borne basse ; la borne haute est contrôlée par l'application).
     * Source : Le Gall JR, Lemeshow S, Saulnier F. A new Simplified Acute Physiology Score (SAPS II) based on a
     * European/North American multicenter study. JAMA. 1993;270(24):2957-63. https://pubmed.ncbi.nlm.nih.gov/8254858/
     */
    public const IGS2 = ['min' => 0, 'max' => 163];

    /**
     * ISS (Injury Severity Score) : somme des carrés des trois AIS les plus élevés, de 0 à 75. Bornes reprises par
     * le CHECK chk_shockroom_iss.
     * Source : Baker SP, O'Neill B, Haddon W Jr, Long WB. The injury severity score: a method for describing
     * patients with multiple injuries and evaluating emergency care. J Trauma. 1974;14(3):187-96.
     * https://pubmed.ncbi.nlm.nih.gov/4814394/
     */
    public const ISS = ['min' => 0, 'max' => 75];

    /**
     * Grade de gravité du traumatisé à l'arrivée au déchocage (« Grade à l'arrivée », figure 3.a) :
     * grades A, B, C du réseau de traumatologie TRENAU. Libellés abrégés : en cas de doute, la source fait foi.
     * Source : Bouzat P, Ageron FX, Brun J, et al. A regional trauma system to optimize the pre-hospital triage
     * of trauma patients. Crit Care. 2015;19:111. https://pubmed.ncbi.nlm.nih.gov/25887150/
     */
    public const ARRIVAL_GRADES = [
        'A' => 'Grade A : détresse vitale non stabilisée',
        'B' => 'Grade B : détresse stabilisée ou lésion anatomique grave',
        'C' => 'Grade C : cinétique violente, sans détresse ni lésion grave',
    ];

    /**
     * Mode ventilatoire de l'évaluation respiratoire (« VS/VC », figure 2.b et écran du service) : ventilation
     * spontanée ou contrôlée. Stocké dans ClinicalImpression.details['ventilationMode'].
     */
    public const VENTILATION_MODES = [
        'VS' => 'VS : ventilation spontanée',
        'VC' => 'VC : ventilation contrôlée',
    ];

    /**
     * Listes normalisées des fiches de suivi (« Évaluation … (LISTE) », figure 2.b), par système : clé de
     * ClinicalObservation::CATEGORIES => [valeur stockée dans ClinicalObservation.valueCodeableConcept => libellé].
     * Les volets complémentaires du modèle v2 (cutané, ostéo-articulaire, métabolique, infectieux, dépistage BMR,
     * biologie anormale) n'ont pas de liste : note et interprétation libres seulement.
     *
     * Échelles sourcées :
     * - Sédation : RASS (Richmond Agitation-Sedation Scale). Sessler CN, Gosnell MS, Grap MJ, et al. Am J Respir
     *   Crit Care Med. 2002;166(10):1338-44. https://pubmed.ncbi.nlm.nih.gov/12421743/
     * - Neurologique : AVPU. McNarry AF, Goldhill DR. Simple bedside assessment of level of consciousness.
     *   Anaesthesia. 2004;59(1):34-7. https://pubmed.ncbi.nlm.nih.gov/14687096/
     * - Hémodynamique : les quatre mécanismes de choc. Vincent JL, De Backer D. Circulatory shock. N Engl J Med.
     *   2013;369(18):1726-34. https://pubmed.ncbi.nlm.nih.gov/24171518/
     * - Uro-rénal : stades de l'insuffisance rénale aiguë KDIGO. KDIGO Clinical Practice Guideline for Acute
     *   Kidney Injury. Kidney Int Suppl. 2012;2(1):1-138. https://kdigo.org/guidelines/acute-kidney-injury/
     *
     * Sédation : « Non » (patient non sédaté) précède les niveaux RASS, comme le « + Sédation : Non » de
     * l'écran du service (mail du 24/11/2026).
     *
     * Listes de travail sans échelle de référence (cardiovasculaire : rythme ECG ; pulmonaire : examen
     * clinique ; abdominal : examen clinique) : termes cliniques usuels, à faire valider par le service avant tout
     * usage réel, comme les données de démonstration. Le mode ventilatoire « VS/VC » est un champ à part
     * (VENTILATION_MODES), plus une valeur de la liste pulmonaire.
     */
    public const OBSERVATION_LISTS = [
        ClinicalObservation::CATEGORY_SEDATION => [
            'NONE' => 'Non : pas de sédation',
            '+4' => 'RASS +4 : combatif',
            '+3' => 'RASS +3 : très agité',
            '+2' => 'RASS +2 : agité',
            '+1' => 'RASS +1 : ne tient pas en place',
            '0' => 'RASS 0 : éveillé et calme',
            '-1' => 'RASS -1 : somnolent',
            '-2' => 'RASS -2 : sédation légère',
            '-3' => 'RASS -3 : sédation modérée',
            '-4' => 'RASS -4 : sédation profonde',
            '-5' => 'RASS -5 : non réveillable',
        ],
        ClinicalObservation::CATEGORY_NEUROLOGICAL => [
            'A' => 'A : éveillé (alert)',
            'V' => 'V : répond à la voix',
            'P' => 'P : répond à la douleur',
            'U' => 'U : aucune réponse',
        ],
        ClinicalObservation::CATEGORY_CARDIOVASCULAR => [
            'SINUS' => 'Rythme sinusal régulier',
            'AF' => 'Fibrillation atriale',
            'FLUTTER' => 'Flutter atrial',
            'AV_BLOCK' => 'Bloc auriculo-ventriculaire',
            'PACED' => 'Rythme électro-entraîné',
            'OTHER' => 'Autre trouble du rythme',
        ],
        ClinicalObservation::CATEGORY_HEMODYNAMIC => [
            'STABLE' => 'Hémodynamique stable',
            'HYPOVOLEMIC' => 'Choc hypovolémique',
            'CARDIOGENIC' => 'Choc cardiogénique',
            'OBSTRUCTIVE' => 'Choc obstructif',
            'DISTRIBUTIVE' => 'Choc distributif (septique, anaphylactique, vasoplégique)',
        ],
        ClinicalObservation::CATEGORY_RESPIRATORY => [
            'NORMAL' => 'Auscultation normale',
            'CONGESTION' => 'Encombrement bronchique',
            'CRACKLES' => 'Crépitants',
            'WHEEZING' => 'Sibilants',
            'CONDENSATION' => 'Syndrome de condensation',
            'PLEURAL_EFFUSION' => 'Syndrome d\'épanchement pleural',
            'PNEUMOTHORAX' => 'Pneumothorax',
        ],
        ClinicalObservation::CATEGORY_ABDOMINAL => [
            'NORMAL' => 'Abdomen souple et indolore',
            'TENDER' => 'Abdomen sensible',
            'GUARDING' => 'Défense',
            'RIGIDITY' => 'Contracture',
            'DISTENDED' => 'Distension, météorisme',
        ],
        ClinicalObservation::CATEGORY_URORENAL => [
            '0' => 'Pas d\'insuffisance rénale aiguë',
            '1' => 'IRA KDIGO stade 1',
            '2' => 'IRA KDIGO stade 2',
            '3' => 'IRA KDIGO stade 3',
        ],
    ];
}
