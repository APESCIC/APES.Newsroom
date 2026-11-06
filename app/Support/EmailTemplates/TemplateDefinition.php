<?php

namespace App\Support\EmailTemplates;

/**
 * Immutable description of one system email template key.
 *
 * For security emails that use SecurityNotification, defaultBody holds only
 * the editable summary lines. The locked “Was this you?” block and CTA are
 * applied at send time (see lockedNotice).
 */
readonly class TemplateDefinition
{
    /**
     * @param  list<string>  $allowedPlaceholders
     * @param  list<string>  $requiredPlaceholders
     * @param  array<string, string>  $samplePlaceholders
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $group,
        public array $allowedPlaceholders,
        public array $requiredPlaceholders,
        public string $defaultSubject,
        public string $defaultBody,
        public bool $isSecurity = false,
        public string $lockedNotice = '',
        public ?string $actionLabel = null,
        public ?string $actionUrlPlaceholder = null,
        public array $samplePlaceholders = [],
        public ?string $defaultBodyViaReset = null,
    ) {}

    public function defaultBodyForContext(bool $viaReset = false): string
    {
        if ($viaReset && $this->defaultBodyViaReset !== null) {
            return $this->defaultBodyViaReset;
        }

        return $this->defaultBody;
    }
}
