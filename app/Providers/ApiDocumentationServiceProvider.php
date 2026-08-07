<?php

namespace App\Providers;

use App\Support\OpenApi\MembershipApiDocumentTransformer;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * The OpenAPI document for /api/v1 and the reference that renders it.
 *
 * Its own provider rather than three more statements in AppServiceProvider,
 * for the same reason NostrAuthServiceProvider and GroupPackageServiceProvider
 * are their own: this is one subsystem with one seam to the framework, and the
 * seam is worth being able to read in one file.
 */
class ApiDocumentationServiceProvider extends ServiceProvider
{
    /**
     * The path of the human-readable reference.
     */
    public const UI_PATH = 'docs/v1/api';

    /**
     * The path of the machine-readable document behind it.
     */
    public const DOCUMENT_PATH = 'docs/v1/api.json';

    public function boot(): void
    {
        /*
         * The package registers a `default` API on `docs/api` and
         * `docs/api.json` covering everything under `api`, which is exactly
         * the surface this repo does NOT want published: it would carry the
         * two unversioned legacy endpoints.
         *
         * Withdrawn with expose(false) rather than with
         * Scramble::ignoreDefaultRoutes(): that flag is read inside the
         * package's own bootingPackage(), which has already run by the time an
         * application provider boots, so setting it here would be a statement
         * nobody reads. expose(false) clears the routes themselves, and the
         * package registers routes from a booted() callback — after this.
         */
        Scramble::configure()->expose(false);

        /*
         * `registerApi` merges the given array over config('scramble'), so
         * everything declarative already lives in the config file. What is
         * left here is behaviour: where the two routes go, and what has to be
         * written into the document that cannot be read off the code.
         *
         * BOTH ROUTES ARE PUBLIC — no authentication, no local-only gate. The
         * reasoning is in config/scramble.php under `middleware`; in short,
         * the readers are third-party client developers outside this
         * deployment and the document holds no secret.
         */
        Scramble::registerApi('v1')
            ->expose(
                ui: fn (Router $router, $action) => $router
                    ->get(self::UI_PATH, $action)
                    ->name('docs.v1.ui'),
                document: fn (Router $router, $action) => $router
                    ->get(self::DOCUMENT_PATH, $action)
                    ->name('docs.v1.document'),
            )
            ->withDocumentTransformers(MembershipApiDocumentTransformer::class);
    }
}
