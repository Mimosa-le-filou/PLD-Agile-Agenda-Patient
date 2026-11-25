<?php

declare(strict_types=1);

namespace Aura\Service;

use InvalidArgumentException;

/**
 * Évaluation médicale refusée (UC1) : rien n'a été écrit. Porte les messages affichables indexés par champ du
 * formulaire (clé EvaluationService::FORM_ERROR pour une erreur hors champ) et le code HTTP à renvoyer :
 * 404 séjour inexistant ou clos (E1), 403 praticien non habilité (E2), 422 saisie invalide (E3).
 */
final class EvaluationRejected extends InvalidArgumentException
{
    public const NOT_FOUND = 404;
    public const FORBIDDEN = 403;
    public const INVALID = 422;

    /** @param array<string, string> $errors messages affichables indexés par champ */
    public function __construct(public readonly array $errors, public readonly int $status = self::INVALID)
    {
        parent::__construct(implode(' ', $errors));
    }
}
