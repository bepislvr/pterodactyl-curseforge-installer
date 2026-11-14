<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

/**
 * The project's author has disabled distribution through third-party services. Nothing is downloaded; the user
 * is pointed to the project's CurseForge page instead.
 */
class DistributionDisabledException extends CurseForgeException
{
    public function __construct(string $message, public readonly ?string $projectUrl = null)
    {
        parent::__construct($message);
    }
}
