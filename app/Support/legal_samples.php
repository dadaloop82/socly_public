<?php

declare(strict_types=1);

/** @return non-empty-string */
function privacy_sample_draft(): string
{
    $text = trim((string) __('setup.privacy_sample_draft'));
    if ($text === '') {
        $text = '—';
    }

    $name = '';
    $legal = '';
    $address = '';
    $email = '';
    $pec = '';
    try {
        /** @var \Socly\Services\SettingsService $settings */
        $settings = app(\Socly\Services\SettingsService::class);
        $name = trim(localized((string) $settings->get('association.name', '')));
        $legal = trim(localized((string) $settings->get('association.legal_name', '')));
        $city = trim((string) $settings->get('association.city', ''));
        $street = trim((string) $settings->get('association.address', ''));
        $house = trim((string) $settings->get('association.house_number', ''));
        $cap = trim((string) $settings->get('association.postal_code', ''));
        $province = trim((string) $settings->get('association.province', ''));
        $parts = array_filter([$street . ($house !== '' ? ' ' . $house : ''), trim($cap . ' ' . $city), $province]);
        $address = trim(implode(', ', $parts));
        $email = trim((string) $settings->get('association.email', ''));
        $pec = trim((string) $settings->get('association.pec', ''));
    } catch (Throwable) {
        // Keep placeholders if settings are unavailable during early boot.
    }

    $title = $name !== '' ? $name : ($legal !== '' ? $legal : '');
    $contact = $pec !== '' ? $pec : $email;

    $replacements = [
        "[Titolo / Denominazione dell'associazione]" => $title !== '' ? $title : "[Titolo / Denominazione dell'associazione]",
        '[Association name]' => $title !== '' ? $title : '[Association name]',
        '[Vereinsname]' => $title !== '' ? $title : '[Vereinsname]',
        '[indirizzo]' => $address !== '' ? $address : '[indirizzo]',
        '[address]' => $address !== '' ? $address : '[address]',
        '[Adresse]' => $address !== '' ? $address : '[Adresse]',
        'registered office [address]' => $address !== '' ? ('registered office ' . $address) : 'registered office [address]',
        'Sitz [Adresse]' => $address !== '' ? ('Sitz ' . $address) : 'Sitz [Adresse]',
        '[indirizzo email / PEC]' => $contact !== '' ? $contact : '[indirizzo email / PEC]',
        '[email / certified email]' => $contact !== '' ? $contact : '[email / certified email]',
        '[E-Mail / PEC]' => $contact !== '' ? $contact : '[E-Mail / PEC]',
        '[email / PEC del titolare]' => $contact !== '' ? $contact : '[email / PEC del titolare]',
        '[controller email]' => $contact !== '' ? $contact : '[controller email]',
        '[E-Mail des Verantwortlichen]' => $contact !== '' ? $contact : '[E-Mail des Verantwortlichen]',
    ];

    foreach ($replacements as $from => $to) {
        if ($from !== '' && $to !== '') {
            $text = str_replace($from, $to, $text);
        }
    }

    return $text !== '' ? $text : '—';
}
