<?php

declare(strict_types=1);

namespace Socly\Setup;

/**
 * Declarative setup steps. Add a row here → new wizard step after update if empty.
 *
 * Order: language → identity → website/brand → registry & seat → people →
 * legal → membership → mail/enrollment → admin → platform.
 *
 * @phpstan-type SetupField array{
 *   key: string,
 *   env_key?: string,
 *   settings_key?: string,
 *   storage: 'env_user'|'settings'|'both'|'people',
 *   type: string,
 *   required: bool,
 *   title_key: string,
 *   description_key: string,
 *   role?: string,
 *   min?: int,
 *   options?: list<array{value:string,label_key:string}>,
 *   fields?: list<array<string,mixed>>
 * }
 */
final class SetupCatalogue
{
    /** @return list<SetupField> */
    public static function all(): array
    {
        return [
            // —— Experience ——
            [
                'key' => 'app.locale',
                'env_key' => 'APP_LOCALE',
                'settings_key' => 'app.locale',
                'storage' => 'both',
                'type' => 'select',
                'required' => true,
                'title_key' => 'setup.step_locale_title',
                'description_key' => 'setup.step_locale_desc',
                'options' => [
                    ['value' => 'it', 'label_key' => 'setup.locale_it'],
                    ['value' => 'de', 'label_key' => 'setup.locale_de'],
                    ['value' => 'en', 'label_key' => 'setup.locale_en'],
                ],
            ],

            // —— Association identity ——
            [
                'key' => 'association.name',
                'storage' => 'both',
                'type' => 'name_pair',
                'required' => true,
                'title_key' => 'setup.step_assoc_name_title',
                'description_key' => 'setup.step_assoc_name_desc',
                'fields' => [
                    [
                        'key' => 'runts',
                        'env_key' => 'ASSOCIATION_RUNTS',
                        'settings_key' => 'association.runts',
                        'type' => 'text',
                        'required' => false,
                        'label_key' => 'setup.field_runts',
                    ],
                    [
                        'key' => 'name',
                        'env_key' => 'ASSOCIATION_NAME',
                        'settings_key' => 'association.name',
                        'type' => 'text',
                        'required' => true,
                        'label_key' => 'setup.field_assoc_name',
                    ],
                    [
                        'key' => 'legal_name',
                        'env_key' => 'ASSOCIATION_LEGAL_NAME',
                        'settings_key' => 'association.legal_name',
                        'type' => 'select',
                        'required' => true,
                        'label_key' => 'setup.field_assoc_legal_name',
                        'options' => AssociationLegalForms::options(),
                    ],
                    [
                        'key' => 'currency',
                        'settings_key' => 'app.currency',
                        'type' => 'select',
                        'required' => true,
                        'label_key' => 'setup.field_currency',
                        'options' => [
                            ['value' => 'EUR', 'label_key' => 'currency.eur'],
                            ['value' => 'CHF', 'label_key' => 'currency.chf'],
                            ['value' => 'USD', 'label_key' => 'currency.usd'],
                            ['value' => 'GBP', 'label_key' => 'currency.gbp'],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'association.website',
                'env_key' => 'ASSOCIATION_WEBSITE',
                'settings_key' => 'association.website',
                'storage' => 'both',
                'type' => 'website',
                'required' => false,
                'title_key' => 'setup.step_website_title',
                'description_key' => 'setup.step_website_desc',
            ],

            // —— Branding (after website so scrape can prefill) ——
            [
                'key' => 'branding.logo',
                'settings_key' => 'branding.logo_configured',
                'storage' => 'settings',
                'type' => 'logo',
                'required' => false,
                'title_key' => 'setup.step_logo_title',
                'description_key' => 'setup.step_logo_desc',
            ],
            [
                'key' => 'branding.colors',
                'storage' => 'both',
                'type' => 'colors',
                'required' => true,
                'title_key' => 'setup.step_colors_title',
                'description_key' => 'setup.step_colors_desc',
                'fields' => [
                    [
                        'key' => 'primary',
                        'env_key' => 'BRANDING_PRIMARY',
                        'settings_key' => 'branding.primary',
                        'type' => 'color',
                        'label_key' => 'setup.field_primary',
                    ],
                    [
                        'key' => 'accent',
                        'env_key' => 'BRANDING_ACCENT',
                        'settings_key' => 'branding.accent',
                        'type' => 'color',
                        'label_key' => 'setup.field_accent',
                    ],
                ],
            ],

            // —— Registry & contacts ——
            [
                'key' => 'association.tax_ids',
                'storage' => 'both',
                'type' => 'field_group',
                'required' => true,
                'title_key' => 'setup.step_tax_ids_title',
                'description_key' => 'setup.step_tax_ids_desc',
                'fields' => [
                    [
                        'key' => 'fiscal_code',
                        'env_key' => 'ASSOCIATION_FISCAL_CODE',
                        'settings_key' => 'association.fiscal_code',
                        'type' => 'text',
                        'required' => true,
                        'label_key' => 'setup.field_fiscal_code',
                        'autocomplete' => 'off',
                    ],
                    [
                        'key' => 'vat_number',
                        'env_key' => 'ASSOCIATION_VAT',
                        'settings_key' => 'association.vat_number',
                        'type' => 'text',
                        'required' => false,
                        'label_key' => 'setup.field_vat_number',
                        'autocomplete' => 'off',
                    ],
                ],
            ],
            [
                'key' => 'association.seat',
                'storage' => 'both',
                'type' => 'address_block',
                'required' => true,
                'title_key' => 'setup.step_seat_title',
                'description_key' => 'setup.step_seat_desc',
                'fields' => [
                    [
                        'key' => 'city',
                        'env_key' => 'ASSOCIATION_CITY',
                        'settings_key' => 'association.city',
                        'type' => 'text',
                        'required' => true,
                        'label_key' => 'setup.field_city',
                    ],
                    [
                        'key' => 'postal_code',
                        'env_key' => 'ASSOCIATION_POSTAL_CODE',
                        'settings_key' => 'association.postal_code',
                        'type' => 'text',
                        'required' => true,
                        'label_key' => 'setup.field_postal_code',
                    ],
                    [
                        'key' => 'province',
                        'env_key' => 'ASSOCIATION_PROVINCE',
                        'settings_key' => 'association.province',
                        'type' => 'text',
                        'required' => false,
                        'label_key' => 'setup.field_province',
                    ],
                    [
                        'key' => 'address',
                        'env_key' => 'ASSOCIATION_ADDRESS',
                        'settings_key' => 'association.address',
                        'type' => 'text',
                        'required' => true,
                        'label_key' => 'setup.field_street',
                    ],
                    [
                        'key' => 'house_number',
                        'env_key' => 'ASSOCIATION_HOUSE_NUMBER',
                        'settings_key' => 'association.house_number',
                        'type' => 'text',
                        'required' => true,
                        'label_key' => 'setup.field_house_number',
                    ],
                ],
            ],
            [
                'key' => 'association.contacts',
                'storage' => 'both',
                'type' => 'field_group',
                'required' => true,
                'title_key' => 'setup.step_contacts_title',
                'description_key' => 'setup.step_contacts_desc',
                'fields' => [
                    [
                        'key' => 'pec',
                        'env_key' => 'ASSOCIATION_PEC',
                        'settings_key' => 'association.pec',
                        'type' => 'email',
                        'required' => true,
                        'label_key' => 'setup.field_pec',
                        'autocomplete' => 'email',
                    ],
                    [
                        'key' => 'email',
                        'env_key' => 'ASSOCIATION_EMAIL',
                        'settings_key' => 'association.email',
                        'type' => 'email',
                        'required' => true,
                        'label_key' => 'setup.field_email',
                        'autocomplete' => 'email',
                    ],
                    [
                        'key' => 'phone',
                        'env_key' => 'ASSOCIATION_PHONE',
                        'settings_key' => 'association.phone',
                        'type' => 'tel',
                        'required' => false,
                        'label_key' => 'setup.field_phone',
                        'autocomplete' => 'tel',
                    ],
                ],
            ],

            // —— Governance ——
            [
                'key' => 'association.president',
                'storage' => 'people',
                'type' => 'president',
                'role' => 'president',
                'required' => true,
                'title_key' => 'setup.step_president_title',
                'description_key' => 'setup.step_president_desc',
            ],
            [
                'key' => 'association.board',
                'storage' => 'people',
                'type' => 'people_list',
                'role' => 'board',
                'min' => 1,
                'required' => true,
                'title_key' => 'setup.step_board_title',
                'description_key' => 'setup.step_board_desc',
                'settings_key' => 'association.board_configured',
            ],
            [
                'key' => 'association.auditors',
                'storage' => 'people',
                'type' => 'people_list',
                'role' => 'auditor',
                'min' => 0,
                'required' => false,
                'title_key' => 'setup.step_auditors_title',
                'description_key' => 'setup.step_auditors_desc',
                'settings_key' => 'association.auditors_configured',
            ],

            // —— Legal documents ——
            [
                'key' => 'legal.statute',
                'settings_key' => 'legal.statute',
                'storage' => 'settings',
                'type' => 'textarea',
                'required' => true,
                'title_key' => 'setup.step_statute_title',
                'description_key' => 'setup.step_statute_desc',
            ],
            [
                'key' => 'gdpr.enabled',
                'env_key' => 'GDPR_ENABLED',
                'settings_key' => 'gdpr.enabled',
                'storage' => 'both',
                'type' => 'checkbox',
                'required' => true,
                'title_key' => 'setup.step_gdpr_title',
                'description_key' => 'setup.step_gdpr_desc',
            ],
            [
                'key' => 'legal.privacy',
                'settings_key' => 'legal.privacy',
                'storage' => 'settings',
                'type' => 'textarea',
                'required' => true,
                'depends_on' => 'gdpr.enabled',
                'title_key' => 'setup.step_privacy_title',
                'description_key' => 'setup.step_privacy_desc_gdpr',
            ],

            // —— Membership ——
            [
                'key' => 'membership.types',
                'settings_key' => 'membership.types_configured',
                'storage' => 'settings',
                'type' => 'member_types',
                'required' => true,
                'title_key' => 'setup.step_types_title',
                'description_key' => 'setup.step_types_desc',
            ],
            [
                'key' => 'membership.periods',
                'settings_key' => 'membership.periods_configured',
                'storage' => 'settings',
                'type' => 'membership_periods',
                'required' => true,
                'title_key' => 'setup.step_periods_title',
                'description_key' => 'setup.step_periods_desc',
            ],
            [
                'key' => 'membership.fields',
                'settings_key' => 'membership.fields_configured',
                'storage' => 'settings',
                'type' => 'member_fields',
                'required' => true,
                'title_key' => 'setup.step_fields_title',
                'description_key' => 'setup.step_fields_desc',
            ],
            [
                'key' => 'mail.smtp',
                'settings_key' => 'mail.configured',
                'storage' => 'settings',
                'type' => 'smtp_config',
                'required' => true,
                'title_key' => 'setup.step_mail_title',
                'description_key' => 'setup.step_mail_desc',
            ],
            [
                'key' => 'membership.enrollment_validation',
                'settings_key' => 'membership.enrollment_validation',
                'storage' => 'settings',
                'type' => 'select',
                'required' => true,
                'title_key' => 'setup.step_enrollment_title',
                'description_key' => 'setup.step_enrollment_desc',
                'options' => [
                    ['value' => 'none', 'label_key' => 'setup.enrollment_none'],
                    ['value' => 'print_scan', 'label_key' => 'setup.enrollment_print_scan'],
                    ['value' => 'tablet_sign', 'label_key' => 'setup.enrollment_tablet_sign'],
                    ['value' => 'otp_email', 'label_key' => 'setup.enrollment_otp_email'],
                ],
            ],

            // —— Base components ——
            [
                'key' => 'components.select',
                'settings_key' => 'components.configured',
                'storage' => 'settings',
                'type' => 'component_select',
                'required' => true,
                'title_key' => 'setup.step_components_title',
                'description_key' => 'setup.step_components_desc',
            ],

            // —— Access & platform ——
            [
                'key' => 'app.admin',
                'settings_key' => 'app.admin_user_id',
                'storage' => 'settings',
                'type' => 'admin_account',
                'required' => true,
                'title_key' => 'setup.step_admin_title',
                'description_key' => 'setup.step_admin_desc',
            ],
            [
                'key' => 'platform.consents',
                'settings_key' => 'platform.consents_configured',
                'storage' => 'settings',
                'type' => 'platform_consents',
                'required' => true,
                'title_key' => 'setup.step_platform_title',
                'description_key' => 'setup.step_platform_desc',
            ],
        ];
    }

    /** @return SetupField|null */
    public static function findByKey(string $key): ?array
    {
        foreach (self::all() as $step) {
            if (($step['key'] ?? '') === $key) {
                return $step;
            }
        }
        return null;
    }
}
