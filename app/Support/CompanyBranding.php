<?php

namespace App\Support;

/**
 * The company details every system email is dressed in.
 *
 * One place reads config/company.php, so the header, the footer and any
 * future template cannot disagree about the name, the logo or the contact
 * details - and a deployment changes all of them by editing `.env` alone.
 */
class CompanyBranding
{
    /**
     * The letterhead every exported PDF is printed on.
     *
     * Held here rather than in a controller because more than one page
     * exports one now - the system reports and the audit trail - and a second
     * copy of the company's own name is exactly the sort of thing that drifts.
     *
     * Deliberately not read from config/company.php: those values are the
     * email footer's and a deployment may leave them blank, which is fine in
     * an inbox and not fine on a document. Move both to a settings table if
     * they ever become editable in-app.
     *
     * @return array<string, string>
     */
    public static function letterhead(): array
    {
        return [
            'name' => 'Coliconstruct',
            'address' => 'Carmona, Cavite, Philippines',
            'system' => 'Coliconstruct Project Management System',
        ];
    }

    /**
     * The logo as bytes, because dompdf cannot fetch one over HTTP.
     */
    public static function logoDataUri(): ?string
    {
        $path = public_path('img/coliconstructlogor.png');

        if (! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * @return array{
     *     name: string,
     *     tagline: string,
     *     logo: ?string,
     *     address: string,
     *     phone: string,
     *     email: string,
     *     website: string,
     *     colors: array{primary: string, header: string}
     * }
     */
    public static function toArray(): array
    {
        return [
            'name' => (string) config('company.name'),
            'tagline' => (string) config('company.tagline'),
            'logo' => self::logoUrl(),
            'address' => (string) config('company.address'),
            'phone' => (string) config('company.phone'),
            'email' => (string) config('company.email'),
            'website' => (string) config('company.website'),
            'colors' => [
                'primary' => (string) config('company.colors.primary'),
                'header' => (string) config('company.colors.header'),
            ],
        ];
    }

    /**
     * The logo as something an inbox can actually fetch.
     *
     * A mail client has no page to resolve a relative path against, so a
     * configured path is made absolute against APP_URL. An already-absolute
     * URL is left alone, which is what a deployment serving assets from a CDN
     * would configure.
     */
    public static function logoUrl(): ?string
    {
        $logo = trim((string) config('company.logo'));

        if ($logo === '') {
            return null;
        }

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($logo, '/');
    }
}
