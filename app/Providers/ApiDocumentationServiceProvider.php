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
     *
     * `docs/api`, deliberately the same address the sister deployment publishes
     * its reference under (`portal.einundzwanzig.space/docs/api`): a developer
     * writing a client against both surfaces should not have to learn two
     * conventions for the same thing. The version is not in the URL because it
     * is in the paths — every operation of this document reads
     * `/api/v1/...` (config/scramble.php explains why the prefix stays there),
     * so a second API surface later gets its own path here and this one keeps
     * documenting v1 without moving again.
     */
    public const UI_PATH = 'docs/api';

    /**
     * The path of the machine-readable document behind it.
     */
    public const DOCUMENT_PATH = 'docs/api.json';

    public function boot(): void
    {
        /*
         * The package registers a `default` API on `docs/api` and
         * `docs/api.json`, and since the `v1` API now publishes under those
         * very paths, the two would otherwise collide.
         *
         * Withdrawn with expose(false) rather than with
         * Scramble::ignoreDefaultRoutes(): that flag is read inside the
         * package's own bootingPackage(), which has already run by the time an
         * application provider boots, so setting it here would be a statement
         * nobody reads. expose(false) clears the routes themselves, and the
         * package registers routes from a booted() callback — after this.
         *
         * WHAT A COLLISION WOULD COST, measured rather than assumed, because
         * the assumption was wrong twice in a row. It is NOT the two
         * unversioned legacy endpoints: `bootingPackage()` hands the default
         * API our own `config('scramble')`, `api_path` included, so its
         * document covers the same `api/v1/*` surface ours does — generated
         * to ten paths at runtime, neither legacy endpoint among them. What
         * the default API does NOT carry is the document transformer, which is
         * registered on `v1` alone. A package win therefore publishes an
         * UNTRANSFORMED v1 document: no `security` on any operation, no tags,
         * none of the prose a third party is meant to implement against.
         *
         * A collision on one URI is not an error in Laravel either.
         * `RouteCollection::addToCollections()` keys by method plus URI and
         * simply OVERWRITES: the LAST registration answers, while the earlier
         * one lives on as an unreachable entry in the name list, so neither a
         * route count nor a name lookup can see the difference. Which of the
         * two wins is not ours to decide — with this line removed, both APIs
         * register and OURS are the routes that answer (`route:list` shows
         * `docs.v1.ui` on `docs/api`). So today this is hygiene, removing a
         * dead duplicate, and not a wall; it is kept because the order belongs
         * to the package and a release that moves that `booted()` call turns
         * the harmless duplicate into a silently gutted document.
         *
         * `OpenApiDocumentationTest` therefore asserts on something only the
         * transformer produces. Which route object exists proves nothing here,
         * and neither does the mere set of paths — both documents carry the
         * same ones.
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
