<?php

declare(strict_types=1);

namespace CoaVault\Support;

/**
 * Collapses the legacy image-vs-file-vs-url mess into one model:
 * {file_id, url, kind}. Tuned for LOCAL uploads (the locked decision) — a bare
 * URL is reverse-resolved to an attachment when it lives in this site's media
 * library, otherwise kept as an external link.
 */
final class Report
{
    public const KIND_IMAGE = 'image';
    public const KIND_FILE  = 'file';
    public const KIND_LINK  = 'link';
    public const KIND_NONE  = 'none';

    /**
     * @param int|null $attachmentId Attachment ID from an ACF image/file leaf (already the bare ID).
     * @param string   $url          A report_link / report_url / native 'coa' URL.
     * @param string   $preferKind   'image'|'file' hint from the source field type.
     *
     * @return array{file_id:?int,url:string,kind:string,dead:bool}
     */
    public static function resolve(?int $attachmentId, string $url = '', string $preferKind = self::KIND_IMAGE): array
    {
        $url = trim($url);

        // 1) Explicit attachment ID (the common ACF image/file case).
        if ($attachmentId) {
            if (get_post($attachmentId) instanceof \WP_Post) {
                $resolved = wp_get_attachment_url($attachmentId);
                // Image-vs-file is decided by the attachment's REAL mime type (a COA can
                // be a JPG/PNG or a PDF), not the source field's hint — legacy ACF
                // "image" and "file" fields are used interchangeably across sites.
                $isImage = str_starts_with((string) get_post_mime_type($attachmentId), 'image/');
                return [
                    'file_id' => $attachmentId,
                    'url'     => is_string($resolved) ? $resolved : $url,
                    'kind'    => ($preferKind === self::KIND_FILE || !$isImage) ? self::KIND_FILE : self::KIND_IMAGE,
                    'dead'    => false,
                ];
            }
            // Dead ID. If we also have a usable URL fall back to it; else flag.
            if ($url !== '') {
                return ['file_id' => $attachmentId, 'url' => $url, 'kind' => self::KIND_LINK, 'dead' => true];
            }
            return ['file_id' => $attachmentId, 'url' => '', 'kind' => self::KIND_NONE, 'dead' => true];
        }

        // 2) URL only — try to reverse-resolve against the local media library.
        if ($url !== '') {
            $id = function_exists('attachment_url_to_postid') ? attachment_url_to_postid($url) : 0;
            if ($id > 0) {
                return [
                    'file_id' => $id,
                    'url'     => $url,
                    'kind'    => self::looks_like_pdf($url) ? self::KIND_FILE : self::KIND_IMAGE,
                    'dead'    => false,
                ];
            }
            return ['file_id' => null, 'url' => $url, 'kind' => self::KIND_LINK, 'dead' => false];
        }

        // 3) Nothing at all.
        return ['file_id' => null, 'url' => '', 'kind' => self::KIND_NONE, 'dead' => false];
    }

    private static function looks_like_pdf(string $url): bool
    {
        return (bool) preg_match('/\.pdf(\?|#|$)/i', $url);
    }
}
