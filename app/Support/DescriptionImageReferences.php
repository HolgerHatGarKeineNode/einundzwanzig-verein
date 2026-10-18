<?php

namespace App\Support;

/**
 * Finds the uploaded description images a piece of Markdown refers to.
 *
 * A reference is the path `description-images/<uuid>` anywhere in the text,
 * regardless of host: an `APP_URL` change or a URL copied from staging still
 * points at the same media row, and the prune command must not delete an
 * image that is still shown somewhere. Images on other hosts are not ours
 * and are never matched.
 *
 * UUIDs are returned lowercased. The route accepts both cases and a pgsql
 * uuid column compares case-insensitively, so `ABCD…` in a description is a
 * reference to the row stored as `abcd…`.
 */
class DescriptionImageReferences
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * The lowercased uuids of all description images the Markdown refers to.
     *
     * @return list<string>
     */
    public static function extract(?string $markdown): array
    {
        if ($markdown === null || $markdown === '') {
            return [];
        }

        preg_match_all('~/description-images/('.self::UUID.')(?![0-9a-zA-Z_-])~', $markdown, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Removes every occurrence of the exact form the uploader inserts,
     * `![Bild](<url>/description-images/<uuid>)`, and nothing else.
     *
     * The uploader writes the image as its own paragraph followed by a blank
     * line (`markdown-field.blade.php`); where an occurrence starts a line,
     * up to two line breaks after it go with it, so the surrounding
     * paragraphs close up the way they were before the upload. Any other
     * form — a different alt text, a title, a reference-style link — stays
     * and has to be edited by hand.
     */
    public static function removeUploaderForm(string $markdown, string $uuid): string
    {
        $token = '!\[Bild\]\([^\s()]*/description-images/(?i:'.preg_quote($uuid, '~').')\)';

        $markdown = preg_replace('~(?:^|(?<=\n))'.$token.'[ \t]*(?:\r?\n){0,2}~', '', $markdown);

        return preg_replace('~'.$token.'~', '', $markdown);
    }
}
