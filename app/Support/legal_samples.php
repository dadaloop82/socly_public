<?php

declare(strict_types=1);

/** @return non-empty-string */
function privacy_sample_draft(): string
{
    $text = trim((string) __('setup.privacy_sample_draft'));

    return $text !== '' ? $text : '—';
}
