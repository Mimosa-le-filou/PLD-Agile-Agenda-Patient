// Formulaire « Nouvelle évaluation médicale » (UC1, src/View/pages/evaluation/form.php) :
// - le bloc d'authentification FACTICE du médecin ([data-evaluation-auth]) est placé dans une fenêtre modale
//   <dialog>, restée DANS le formulaire : ses champs (idPractitioner, password) partent avec la saisie ;
// - « Valider l'évaluation » vérifie d'abord les contrôles natifs de la saisie (date obligatoire, bornes), puis
//   ouvre la fenêtre ; « Confirmer et enregistrer » envoie le formulaire, que form.js poste en AJAX ;
// - après un refus (événement aura:form-errors de form.js) : la fenêtre reste ouverte si l'erreur la concerne
//   (médecin, mot de passe), sinon elle se ferme pour montrer les champs en erreur, saisie conservée ;
// - les liens « Accès aux sections » ouvrent la section repliée visée avant d'y défiler ;
// - « DER : dépense énergétique au repos » est recalculée à chaque saisie du niveau d'agression : DER de base
//   (Harris et Benedict, calculée par le serveur, data-der-basal) × facteur. Aperçu seulement : le serveur refait
//   le calcul à l'enregistrement.
// Sans JavaScript (ou sans <dialog>), le bloc reste visible en bas du formulaire : POST classique.
// Le serveur reste la garantie : ce script ne valide rien lui-même. Aucun appel réseau (form.js s'en charge).

(function () {
    const form = document.querySelector('form[data-evaluation-form]');
    if (!form) {
        return;
    }

    // Accès aux sections : ouvre le <details> visé.
    document.querySelectorAll('a[data-open-section]').forEach(link => {
        link.addEventListener('click', () => {
            const target = document.getElementById(link.hash.slice(1));
            if (target && target.tagName === 'DETAILS') {
                target.open = true;
            }
        });
    });

    // Aperçu de la DER : DER de base × niveau d'agression, arrondie au kcal/j.
    const factorInput = form.querySelector('[data-der-factor]');
    const derOutput = form.querySelector('[data-der-output]');
    if (factorInput && derOutput) {
        const basal = Number.parseFloat(derOutput.dataset.derBasal);
        const showDer = () => {
            const factor = Number.parseFloat(factorInput.value.trim().replace(',', '.'));
            if (Number.isNaN(basal)) {
                derOutput.textContent = 'Non calculable';
            } else if (factorInput.value.trim() === '' || Number.isNaN(factor) || !factorInput.checkValidity()) {
                derOutput.textContent = `Renseignez le niveau d'agression (DER de base : ${Math.round(basal)} kcal/j)`;
            } else {
                derOutput.textContent = `${Math.round(basal * factor)} kcal/j`;
            }
        };
        factorInput.addEventListener('input', showDer);
        showDer();
    }

    const auth = form.querySelector('[data-evaluation-auth]');
    const validate = form.querySelector('[data-evaluation-validate]');
    if (!auth || !validate || typeof HTMLDialogElement !== 'function') {
        return;
    }

    const dialog = document.createElement('dialog');
    dialog.className = 'auth-dialog evaluation-auth-dialog';
    dialog.setAttribute('aria-labelledby', 'evaluation-auth-title');
    auth.parentNode.insertBefore(dialog, auth);
    dialog.appendChild(auth);
    auth.classList.add('auth-dialog__form');
    const actions = auth.querySelector('[data-evaluation-auth-actions]');
    if (actions) {
        actions.hidden = false;
    }
    // Le bouton de la page ouvre la fenêtre au lieu d'envoyer.
    validate.type = 'button';

    const dateInput = form.querySelector('[name="effectiveDateTime"]');
    const dateLabel = auth.querySelector('[data-evaluation-date]');
    const practitioner = auth.querySelector('[name="idPractitioner"]');

    // « 2026-10-14T14:00 » (champ datetime-local) → « 14/10/2026 à 14:00 » ; valeur inattendue : telle quelle.
    function frenchDateTime(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2})/.exec(value);
        return match ? `${match[3]}/${match[2]}/${match[1]} à ${match[4]}` : value;
    }

    // Contrôles natifs de la saisie, hors fenêtre (les champs de la fenêtre sont vérifiés à la confirmation).
    function firstInvalidOutsideDialog() {
        return Array.from(form.elements).find(control =>
            !dialog.contains(control) && typeof control.checkValidity === 'function' && !control.checkValidity()
        );
    }

    validate.addEventListener('click', () => {
        const invalid = firstInvalidOutsideDialog();
        if (invalid) {
            // form-sections.js ouvre la section fautive sur l'événement « invalid ».
            invalid.reportValidity();
            return;
        }
        if (dateInput && dateLabel) {
            dateLabel.textContent = frenchDateTime(dateInput.value);
        }
        dialog.showModal();
        (practitioner || auth.querySelector('input, select')).focus();
    });

    auth.querySelector('[data-auth-cancel]')?.addEventListener('click', () => dialog.close());

    form.addEventListener('aura:form-errors', () => {
        if (!dialog.open) {
            return;
        }
        if (dialog.querySelector('.has-error')) {
            dialog.querySelector('.has-error input, .has-error select')?.focus();
            return;
        }
        // Erreur de la saisie ou hors champ : on revient au formulaire, le message est affiché près du bouton.
        dialog.close();
        const message = form.querySelector('.evaluation-form__actions .form-error');
        if (message) {
            message.scrollIntoView({ block: 'center' });
        }
    });
})();
