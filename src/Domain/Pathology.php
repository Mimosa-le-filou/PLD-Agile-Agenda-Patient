<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Entrée du référentiel des pathologies (table Pathology) : ce que retient un diagnostic de séjour
 * (EpisodeDiagnosis) et ce à quoi s'applique un protocole (PlanDefinition.idPathology). Pas de nom HL7 :
 * en FHIR, Condition est le diagnostic d'UN patient, pas un référentiel de maladies.
 *
 * Accesseurs get*() : un par colonne, sans logique ni validation.
 */
final class Pathology
{
    public function __construct(
        private string $idPathology,
        private string $label
    ) {
    }

    public function getIdPathology(): string
    {
        return $this->idPathology;
    }

    /** Libellé unique dans le référentiel (« Sepsis et choc septique »). */
    public function getLabel(): string
    {
        return $this->label;
    }
}
