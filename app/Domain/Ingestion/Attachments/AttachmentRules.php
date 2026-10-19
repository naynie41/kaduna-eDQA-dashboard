<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Attachments;

use ErrorException;
use finfo;

/**
 * What an ODK attachment must be before it is stored or served (SECURITY.md §7).
 *
 * - Its name is ODK's own and is kept exactly: a name that could leave its directory, hide, or
 *   carry control characters is not renamed but refused (the route looks names up exactly).
 * - It lives in odk/{instance}/, the instance ID with anything but letters, digits, dot, dash
 *   and underscore turned into '_' (ODK's "uuid:" colon is not allowed on every filesystem).
 * - Its type is sniffed from its bytes, never taken from ODK or the extension, and must be on
 *   the allow-list. Text types cannot be told apart by bytes alone, so for them the extension
 *   picks among the sniffed possibilities: GeoJSON must also parse as a GeoJSON object.
 */
final class AttachmentRules
{
    private const SAFE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._ ()-]{0,199}$/';

    /**
     * ODK's name made storable as text, nothing more: Postgres cuts a string at a NUL byte, which
     * would turn "x\0.png" into a safe-looking "x". NUL and invalid UTF-8 become U+FFFD, which
     * isSafeName() then refuses.
     */
    public static function storable(string $name): string
    {
        return str_replace("\0", "\u{FFFD}", mb_scrub($name, 'UTF-8'));
    }

    public static function isSafeName(string $name): bool
    {
        return preg_match(self::SAFE_NAME, $name) === 1 && ! str_contains($name, '..') && basename($name) === $name;
    }

    /** The directory on the odk disk for one submission's files. */
    public static function directory(string $instanceId): string
    {
        $dir = preg_replace('/[^A-Za-z0-9._-]/', '_', $instanceId) ?? '';

        return trim($dir, '.') === '' ? '_' : $dir;
    }

    /**
     * The allowed MIME type of a downloaded file, or null when it is not one we accept.
     *
     * @param  list<string>  $allowed
     */
    public static function sniff(string $file, string $name, array $allowed): ?string
    {
        // From the bytes in memory (at most max_bytes): finfo::file() errors on some content. A file
        // that cannot be read (an antivirus may have taken it) is not one we accept.
        try {
            $bytes = file_get_contents($file);
        } catch (ErrorException) {
            return null;
        }
        $sniffed = $bytes === false ? false : (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($sniffed)) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $text = in_array($sniffed, ['text/plain', 'application/json', 'text/csv', 'application/csv', 'text/xml', 'application/xml'], true);

        $type = match (true) {
            in_array($sniffed, ['image/jpeg', 'image/png'], true) => $sniffed,
            $text && in_array($extension, ['geojson', 'json'], true) && self::isGeoJson($file) => 'application/geo+json',
            $text && $extension === 'csv' && in_array($sniffed, ['text/plain', 'text/csv', 'application/csv'], true) => 'text/csv',
            $extension === 'xml' && in_array($sniffed, ['text/xml', 'application/xml'], true) => 'application/xml',
            default => null,
        };

        return $type !== null && in_array($type, $allowed, true) ? $type : null;
    }

    private static function isGeoJson(string $file): bool
    {
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && in_array($data['type'] ?? null, ['FeatureCollection', 'Feature', 'Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon', 'GeometryCollection'], true);
    }
}
