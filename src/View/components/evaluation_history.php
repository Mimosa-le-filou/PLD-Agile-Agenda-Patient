<?php

use Aura\Core\View;

/**
 * Section « Évaluations et suivi » du dossier du séjour (UC3, lecture seule) : bouton « Nouvelle évaluation »
 * (UC1, séjour en cours seulement), message après un enregistrement, puis les évaluations actives du séjour, de la
 * plus récente à la plus ancienne. Chaque évaluation se déplie (<details>) sur ses sections : informations
 * générales, un bloc par système renseigné, synthèse et orientation. Les évaluations retirées (removedAt) ne
 * sont jamais affichées. Inclus par pages/episode/record.php.
 *
 * @var list<array<string, mixed>> $evaluations vue-modèle de EvaluationService::present()
 * @var ?array<string, mixed> $savedEvaluation évaluation qui vient d'être enregistrée, null sinon
 * @var int $idEpisodeOfCare
 * @var bool $ongoing séjour en cours : création possible
 */
$count = count($evaluations);
?>
<section class="record-evaluations" aria-labelledby="titre-evaluations">
    <div class="record-evaluations__header">
        <div>
            <h2 class="record-subtitle" id="titre-evaluations">Évaluations et suivi</h2>
            <p class="record-evaluations__count">
                Historique du séjour · <?= View::e($count) ?>
                évaluation<?= $count > 1 ? 's' : '' ?> enregistrée<?= $count > 1 ? 's' : '' ?>
            </p>
        </div>
        <?php if ($ongoing) : ?>
            <p class="page-actions">
                <a class="button" href="/evaluations/new?idEpisodeOfCare=<?= View::e($idEpisodeOfCare) ?>">
                    <?= View::render('components/icon', ['name' => 'plus']) ?>
                    Nouvelle évaluation
                </a>
            </p>
        <?php else : ?>
            <p class="record-evaluations__closed">Séjour en cours requis pour créer une évaluation.</p>
        <?php endif; ?>
    </div>

    <?php if ($savedEvaluation !== null) : ?>
        <p class="b3-success record-evaluations__saved" role="status">
            <?= View::render('components/icon', ['name' => 'circle-check']) ?>
            Évaluation <?= View::e(mb_strtolower($savedEvaluation['kindLabel'])) ?> enregistrée le
            <?= View::e($savedEvaluation['recordedAt'] ?? '') ?> par <?= View::e($savedEvaluation['practitioner']) ?>.
        </p>
    <?php endif; ?>

    <?php if ($evaluations === []) : ?>
        <?= View::render('components/empty_state', [
            'title' => 'Aucune évaluation enregistrée pour ce séjour.',
            'text' => $ongoing
                ? 'Le bouton « Nouvelle évaluation » ouvre l\'évaluation médicale classique par systèmes.'
                : null,
            'icon' => 'clipboard-list',
        ]) ?>
    <?php else : ?>
        <div class="record-evaluations__list-header">
            <h3>Toutes les évaluations du séjour</h3>
            <span>Du plus récent au plus ancien</span>
        </div>
        <ul class="evaluation-cards">
            <?php foreach ($evaluations as $evaluation) : ?>
                <?php $isSaved = $savedEvaluation !== null && $savedEvaluation['id'] === $evaluation['id']; ?>
                <li>
                    <details class="evaluation-card" id="evaluation-<?= View::e($evaluation['id']) ?>"
                        <?= $isSaved ? ' open' : '' ?>>
                        <summary>
                            <span class="evaluation-card__meta">
                                <span class="label evaluation-card__kind evaluation-card__kind--<?=
                                    View::e(strtolower($evaluation['kind'])) ?>"><?=
                                    View::e($evaluation['kindLabel']) ?></span>
                                <strong class="evaluation-card__date"><?=
                                    View::e($evaluation['effectiveDateTime']) ?></strong>
                                <span class="evaluation-card__author"><?= View::e($evaluation['practitioner']) ?></span>
                                <?php if ($evaluation['igs2'] !== null) : ?>
                                    <span class="evaluation-card__score">IGS II <?=
                                        View::e($evaluation['igs2']) ?></span>
                                <?php elseif ($evaluation['der'] !== null) : ?>
                                    <span class="evaluation-card__score">DER : <?= View::e($evaluation['der']) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="evaluation-card__title"><?= View::e($evaluation['title']) ?></span>
                            <?php if ($evaluation['headline'] !== null) : ?>
                                <span class="evaluation-card__headline"><?= View::e($evaluation['headline']) ?></span>
                            <?php endif; ?>
                        </summary>
                        <div class="evaluation-card__body">
                            <p class="evaluation-card__readonly">
                                Lecture seule · données enregistrées et horodatées
                            </p>
                            <?php foreach ($evaluation['sections'] as $section) : ?>
                                <?php if ($section['lines'] === []) {
                                    continue;
                                } ?>
                                <section class="evaluation-card__section">
                                    <h4><?= View::e($section['title']) ?></h4>
                                    <dl>
                                        <?php foreach ($section['lines'] as $line) : ?>
                                            <dt><?= View::e($line['label']) ?></dt>
                                            <dd><?= View::e($line['value']) ?></dd>
                                        <?php endforeach; ?>
                                    </dl>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p class="record-evaluations__note">Seules les évaluations non supprimées sont affichées. Les actions médicales
        sont tracées au nom du praticien.</p>
</section>
