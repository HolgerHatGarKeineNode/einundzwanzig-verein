<?php

namespace App\Http\Controllers;

use App\Models\EinundzwanzigPleb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves stored media files. The signed routes (`dl`, `media.signed`) carry
 * their authorisation in the URL signature, checked by the `signed`
 * middleware; description images are public by design.
 */
class MediaController extends Controller
{
    /**
     * A signed download with the original file name.
     */
    public function download(Media $media): StreamedResponse
    {
        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name
        );
    }

    /**
     * A signed inline response, optionally of a generated conversion; an
     * unknown or not yet generated conversion falls back to the original.
     */
    public function show(Media $media, Request $request): StreamedResponse
    {
        $conversion = $request->query('conversion');

        if ($conversion && $media->hasGeneratedConversion($conversion)) {
            $path = $media->getPathRelativeToRoot($conversion);
        } else {
            $path = $media->getPathRelativeToRoot();
        }

        return Storage::disk($media->disk)->response(
            $path,
            $media->file_name,
            [
                'Content-Type' => $media->mime_type,
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }

    /**
     * Images embedded in project descriptions. Public and without a signature,
     * because the URL is written into the Markdown and has to outlive any
     * expiry. The collection only ever holds WebP files re-encoded on upload.
     */
    public function descriptionImage(string $uuid): StreamedResponse
    {
        $media = Media::query()
            ->where('uuid', $uuid)
            ->where('collection_name', EinundzwanzigPleb::DESCRIPTION_IMAGES)
            ->where('mime_type', 'image/webp')
            ->firstOrFail();

        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            null,
            [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
