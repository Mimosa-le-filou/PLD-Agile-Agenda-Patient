// Messages des contrôles natifs du navigateur en français, sur toutes les pages (chargé par layouts/main.php) :
// le navigateur affiche sinon les siens dans sa propre langue (« Please fill out this field », « Please enter a
// number »…), quelle que soit la langue de la page. Couvre champ obligatoire, nombre ou date mal saisis, bornes
// min / max, pas (step), longueur, format (pattern : message du champ dans data-pattern-message s'il existe),
// e-mail et adresse. Le serveur reste la garantie : ce script ne change pas ce qui est accepté, seulement le texte.
// Écoute l'événement « invalid » (en capture : il ne remonte pas) ; aucun appel réseau.

(function () {
    // Nombre à la française pour les messages (« 1,5 »).
    const french = value => String(value).replace('.', ',');

    function message(field) {
        const v = field.validity;
        if (v.valueMissing) {
            if (field.tagName === 'SELECT') {
                return 'Choisissez une valeur dans la liste.';
            }
            if (field.type === 'checkbox' || field.type === 'radio') {
                return 'Cochez une option pour continuer.';
            }
            return 'Ce champ est obligatoire.';
        }
        if (v.badInput) {
            if (field.type === 'number') {
                return 'Saisissez un nombre.';
            }
            if (field.type === 'date' || field.type === 'datetime-local' || field.type === 'time') {
                return 'Saisissez une date et une heure complètes et valides.';
            }
            return 'Valeur invalide.';
        }
        if (v.typeMismatch) {
            if (field.type === 'email') {
                return 'Saisissez une adresse e-mail valide (exemple : nom@domaine.fr).';
            }
            if (field.type === 'url') {
                return 'Saisissez une adresse web valide.';
            }
            return 'Valeur invalide.';
        }
        if (v.patternMismatch) {
            return field.dataset.patternMessage || 'Le format de la saisie n\'est pas valide.';
        }
        if (v.rangeUnderflow) {
            return `La valeur doit être supérieure ou égale à ${french(field.min)}.`;
        }
        if (v.rangeOverflow) {
            return `La valeur doit être inférieure ou égale à ${french(field.max)}.`;
        }
        if (v.stepMismatch) {
            return `La valeur doit être un multiple de ${french(field.step)}.`;
        }
        if (v.tooLong) {
            return `${field.maxLength} caractères au plus (actuellement ${field.value.length}).`;
        }
        if (v.tooShort) {
            return `${field.minLength} caractères au moins (actuellement ${field.value.length}).`;
        }
        return '';
    }

    document.addEventListener('invalid', event => {
        const field = event.target;
        if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement
            || field instanceof HTMLTextAreaElement)) {
            return;
        }
        // On repart du contrôle natif seul, puis on remplace son texte par le message français.
        field.setCustomValidity('');
        if (!field.validity.valid) {
            field.setCustomValidity(message(field));
        }
    }, true);

    // Dès que la saisie change, le message personnalisé est retiré : sans cela, le champ resterait invalide.
    const reset = event => {
        if (typeof event.target.setCustomValidity === 'function') {
            event.target.setCustomValidity('');
        }
    };
    document.addEventListener('input', reset, true);
    document.addEventListener('change', reset, true);
})();
