<?php

namespace App\Services\Branding;

/**
 * The settings keys that make up the platform's identity. They live in one
 * place so the settings form, the seeder, the cache invalidation listener and
 * the tests can never drift apart.
 */
enum BrandingKey: string
{
    case Name = 'company_name';
    case ShortName = 'company_short_name';
    case Tagline = 'company_tagline';
    case Email = 'company_email';
    case Phone = 'company_phone';
    case Whatsapp = 'company_whatsapp';
    case Address = 'company_address';
    case RcNumber = 'company_rc_number';
    case Logo = 'company_logo';
    case LogoDark = 'company_logo_dark';
    case Favicon = 'company_favicon';
    case SocialLinks = 'company_social_links';

    /**
     * Who signs a certificate, and in what capacity.
     *
     * Identity, not academy configuration: the same block would sign a letter
     * or a receipt, and it has to change with the company name rather than
     * being typed again somewhere else.
     */
    case SignatoryName = 'company_signatory_name';
    case SignatoryTitle = 'company_signatory_title';

    public const GROUP = 'branding';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function type(): string
    {
        return match ($this) {
            self::SocialLinks => 'json',
            default => 'string',
        };
    }
}
