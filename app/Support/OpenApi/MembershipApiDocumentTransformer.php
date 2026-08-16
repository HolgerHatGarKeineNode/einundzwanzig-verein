<?php

namespace App\Support\OpenApi;

use App\Http\Middleware\VerifyNip98;
use App\Http\Requests\Api\V1\StoreAppApplicationRequest;
use App\Http\Requests\Api\V1\StoreAppInvoiceRequest;
use App\Http\Requests\Api\V1\StoreApplicationRequest;
use App\Http\Requests\Api\V1\StoreInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Http\Resources\Api\V1\MembershipConfigResource;
use App\Http\Resources\Api\V1\MembershipExportResource;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Http\Resources\Api\V1\PaymentEventResource;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\Exceptions\OpenApiReferenceTargetNotFoundException;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Routing\Router;
use RuntimeException;

/**
 * Everything the OpenAPI document needs that cannot be read off the code.
 *
 * Three kinds of work happen here, and each is here for its own reason.
 *
 * THE PROSE — the API description and both security schemes. A NIP-98
 * credential is assembled by the client, not by this application, so no amount
 * of static analysis can produce the recipe for building one; it has to be
 * written out, and it is written out from `App\Support\Nip98` rather than from
 * memory.
 *
 * THE SECURITY PER OPERATION — the main surface needs the client key AND the
 * end-user signature, while `GET /membership/config` and the whole app branch
 * get by on the key alone. That distinction lives in the route file as a
 * middleware list, and Scramble's middleware-based strategy only recognises
 * Laravel's own `auth` guards — so it is read back off the routes here
 * (`requiresSignature()`) rather than restated as a list.
 *
 * THE CORRECTIONS — Scramble infers response shapes from the code, and where
 * a JsonResource is built from an array rather than from a model it infers
 * `string` for everything the array holds. Left alone, the document would tell
 * a third party that `paid` is a string and that `POST /applications` answers
 * with a bare string. Those are not shapes this API has; correcting them is
 * the difference between documenting the endpoint and misdescribing it.
 *
 * FAIL LOUD, NOT QUIET. Every field named below must exist in the generated
 * document, or the export throws. A description that survives the removal of
 * the field it describes is worse than no description, and this is the only
 * moment at which the two can still be compared.
 */
class MembershipApiDocumentTransformer implements DocumentTransformer
{
    /**
     * The `X-Api-Key` scheme: which APPLICATION is calling.
     */
    public const CLIENT_KEY_SCHEME = 'clientKey';

    /**
     * The NIP-98 scheme: WHICH END USER the call is for.
     */
    public const NIP98_SCHEME = 'nip98';

    /**
     * The operations that carry a NIP-98 signature, resolved once per document
     * from the routes themselves.
     *
     * NOT a hardcoded list, and that is the whole point. It used to be one —
     * `PUBLIC_CONFIG_OPERATION = 'get api/v1/membership/config'`, everything
     * else signed — and the app branch (P8) walked straight through it: three
     * endpoints that have no NIP-98 middleware at all were published as
     * requiring a signature, error responses included. A list is a second copy
     * of a decision that lives in `routes/api.php`, and copies drift silently.
     * Read from `VerifyNip98` being on the route or not, the document cannot
     * disagree with what the server actually verifies.
     *
     * @var array<string, bool>|null
     */
    private ?array $signedOperations = null;

    /**
     * Where a third party asks the association for the two things it cannot
     * give itself: a client key, and an entry on the return-address allowlist.
     *
     * Both are server-side decisions — `VerifyApiClient` checks the key
     * against the association's own list, and `InvoiceReturnUrl::isAllowed()`
     * checks `return_url` against `einundzwanzig.config.invoice_return_urls`.
     * A client developer therefore cannot get either by reading harder or by
     * trying again; without the address in this document, the only signal is a
     * 401 or a 422 that looks like a bug in their own code. Named next to both
     * of the fields it unblocks, because that is where the question comes up.
     */
    private const CONTACT_URL = 'https://group.einundzwanzig.space/rooms/42466283723001275';

    /**
     * The warning a consumer has to read, in the one wording that is repeated
     * everywhere it applies.
     *
     * Duplicated on purpose across the API description and every field that
     * carries the trap: a reader who lands on `association_status` in a schema
     * browser never sees the introduction.
     */
    private const STATUS_WARNING = 'DO NOT EVALUATE `association_status` ON ITS OWN. It is the category the '
        .'board assigned and it does not lapse: under Art. 4.1 of the statutes a membership ends after a year '
        .'without payment, but the association deliberately applies no hard cut, so a record can read `ACTIVE` '
        .'while the current fee year is unpaid. Such a person is not a member. `membership_status` is derived '
        .'from both the category and the payment and is the only correct answer to "is this person a member '
        .'right now".';

    /**
     * Whether the route behind an operation verifies a NIP-98 signature.
     *
     * TWO THINGS ARE RESOLVED HERE RATHER THAN READ LITERALLY, and each covers
     * a way of reaching the middleware that a naive check would miss.
     *
     * The GROUP. The signed endpoints of the main surface reach `VerifyNip98`
     * through the group name `api.v1` (bootstrap/app.php); a raw
     * `gatherMiddleware()` would see only that string and report every one of
     * them as unsigned. The routes that list their middleware individually —
     * both `/config` endpoints — pass through the same call unchanged, so one
     * path covers both spellings.
     *
     * The PARAMETER. Middleware may be written `Class::class.':argument'`, as
     * `ThrottleApiV1` is on these very routes. `VerifyNip98` takes none today,
     * but a strict equality check would answer "unsigned" the day it does —
     * publishing a documented-as-open endpoint that in fact demands a
     * signature. Matched on the class prefix instead.
     */
    private function requiresSignature(Operation $operation): bool
    {
        if ($this->signedOperations === null) {
            $router = app(Router::class);
            $this->signedOperations = [];

            foreach ($router->getRoutes() as $route) {
                $resolved = $router->resolveMiddleware(
                    $route->gatherMiddleware(),
                    $route->excludedMiddleware(),
                );

                $verifiesSignature = array_any(
                    $resolved,
                    fn (mixed $middleware): bool => is_string($middleware)
                        && ($middleware === VerifyNip98::class || str_starts_with($middleware, VerifyNip98::class.':')),
                );

                foreach ($route->methods() as $method) {
                    $this->signedOperations[strtolower($method).' '.$route->uri()] = $verifiesSignature;
                }
            }
        }

        $key = $operation->method.' '.$operation->path;

        return $this->signedOperations[$key] ?? throw new RuntimeException(
            "Operation [{$key}] matches no registered route, so whether it needs a NIP-98 signature cannot be established."
        );
    }

    /**
     * Fill `{{CONTACT_URL}}` into a block of documentation prose.
     *
     * The prose lives in nowdoc blocks — single-quoted heredocs, which
     * interpolate nothing — and that is deliberate: they hold `$` signs,
     * braces and backticks of example code that a parsing heredoc would try to
     * read as PHP. A placeholder plus one substitution keeps both properties,
     * the literal blocks and a contact address that exists exactly once.
     */
    private function withContactUrl(string $markdown): string
    {
        return str_replace('{{CONTACT_URL}}', self::CONTACT_URL, $markdown);
    }

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->info->setDescription($this->withContactUrl($this->apiDescription()));

        $this->addSecuritySchemes($document);
        $this->describeComponentSchemas($document);
        $this->addTags($document);

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $this->describeOperation($document, $operation);
            }
        }
    }

    /**
     * The five groups the reference lists the endpoints under.
     *
     * Without them Scramble tags each operation with its controller's class
     * name, and the sidebar reads `StoreInvoice`, `ShowConfig`,
     * `DeleteMembership` — the internal names of this application, in no
     * order a reader could act on. Grouped by what a client is trying to do
     * instead, and ordered along the way in.
     *
     * @return array<string, string>
     */
    private function tagDescriptions(): array
    {
        return [
            'Configuration' => 'What joining costs and what an application has to carry. The one endpoint of the main surface that needs no end-user signature — send the client key alone.',
            'Membership' => 'Applying, and reading your own membership.',
            'Payments' => 'The annual fee: the checkout for it, and the record of the ones already paid.',
            'Personal data' => 'The two rights a data subject exercises directly — access and erasure.',
            'Native app' => 'The branch for the TWENTY ONE Companion app: same membership, same payment, but the subject is a pubkey in the body instead of a signature — the app knows its signer, and the payment itself is the proof. Three endpoints, deliberately no read surface.',
        ];
    }

    /**
     * Which group each operation belongs to.
     *
     * @return array<string, string>
     */
    private function operationTags(): array
    {
        return [
            'get api/v1/membership/config' => 'Configuration',
            'get api/v1/membership/me' => 'Membership',
            'post api/v1/membership/applications' => 'Membership',
            'get api/v1/membership/payments' => 'Payments',
            'post api/v1/membership/payments/{year}/invoice' => 'Payments',
            'post api/v1/membership/payments/{year}/refresh' => 'Payments',
            'get api/v1/membership/export' => 'Personal data',
            'delete api/v1/membership/me' => 'Personal data',
            'get api/v1/app/membership/config' => 'Native app',
            'post api/v1/app/membership/applications' => 'Native app',
            'post api/v1/app/membership/payments/{year}/invoice' => 'Native app',
        ];
    }

    private function addTags(OpenApi $document): void
    {
        $document->tags = array_map(
            fn (string $name, string $description): Tag => new Tag($name, $description),
            array_keys($this->tagDescriptions()),
            array_values($this->tagDescriptions()),
        );
    }

    /**
     * The home page of the reference.
     */
    private function apiDescription(): string
    {
        return <<<'MARKDOWN'
        The membership API of the EINUNDZWANZIG association: apply for membership, pay the annual fee,
        read your own record and have it erased. It is meant for third-party clients acting on behalf of
        a member — a Nostr client, a wallet, a community app.

        ## Two identities, two credentials

        Every request carries the **client key** of the calling application in `X-Api-Key`. On the main
        surface — everything under `/api/v1/membership` except `GET /api/v1/membership/config` — each
        request additionally carries a **NIP-98 signature** identifying the end user. The two answer
        different questions — which application is calling, and who it is calling for — and neither
        substitutes for the other. See the security schemes for how to build the signature.

        On that surface the subject of a request is always the pubkey that signed it. There is no parameter
        for it and a pubkey sent in the path, the query or the body is refused. No endpoint returns data
        about another member.

        ## The app branch

        `/api/v1/app/membership` is a second, smaller surface for a **native app that cannot produce a
        NIP-98 signature**. It carries the client key alone, and the subject is a `pubkey` field in the
        request body instead. Every endpoint on it is tagged **Native app**.

        The trade is deliberate and it is worth reading before choosing this branch:

        - It has **three** endpoints — the configuration, the application and the invoice — and it will not
          grow a fourth. There is **no `/me`, no `/payments` and no `/export`** here, because without a
          signature those would answer questions about pubkeys the caller does not control.
        - Naming a pubkey is not proving it. A client key holder can file an application for any pubkey and
          order a checkout for it; what that buys is the right to pay somebody else's fee, which is a gift
          rather than an attack. **No membership is granted by either call** — the settled payment grants it,
          exactly as on the main surface.
        - There is no `refresh` either. A settled payment reaches the association through the BTCPay webhook
          and a scheduled reconciliation; an app client sees the result in the association's published member
          list and has nothing to pull.

        **If your client can sign, use the main surface.** It is the one that can read a membership back.

        ## Reading the membership status

        DO NOT EVALUATE `association_status` ON ITS OWN. It is the category the board assigned and it does
        not lapse: under Art. 4.1 of the statutes a membership ends after a year without payment, but the
        association deliberately applies no hard cut, so a record can read `ACTIVE` while the current fee
        year is unpaid. Such a person is not a member.

        `membership_status` is derived from the category **and** the payment and is the only correct answer
        to "is this person a member right now":

        | Value | Meaning |
        | --- | --- |
        | `none` | No application on record and no membership category. |
        | `awaiting_payment` | Applied for, current fee year unpaid, not a member yet. |
        | `member` | A membership category and the current fee year paid. |
        | `lapsed` | A membership category, but the current fee year is unpaid. |

        A client that renders `association_status` alone will call a lapsed member active, and two clients
        doing it differently will publish two contradicting answers about the same person.

        ## Before you write any code

        Two things are issued by the association and cannot be obtained from this API:

        1. **A client key** for `X-Api-Key`. Every endpoint needs one.
        2. **An allowlisted return address**, if you want the payer to land back in your own flow after the
           checkout. `return_url` is checked against a list kept on the server, and an address that is not
           on it is refused with `422` rather than silently replaced.

        Ask for both in one message: [einundzwanzig.group]({{CONTACT_URL}}). Without the
        second, the checkout still works — the payer simply returns to the association's own page.

        ## Joining, end to end

        1. `GET /api/v1/membership/config` — the fee, the currency and the current fee year.
        2. `POST /api/v1/membership/applications` — the application and the consent to the statutes. This
           grants nothing on its own: the statutes tie the membership to the payment (Art. 4).
        3. `POST /api/v1/membership/payments/{year}/invoice` — a BTCPay checkout for that fee year. Send
           the payer to `checkout_url`.
        4. The settled payment grants the membership. It normally arrives as a BTCPay webhook; if that
           delivery is lost, `POST /api/v1/membership/payments/{year}/refresh` pulls the same result.

        The app branch walks steps 1 to 3 under `/api/v1/app/membership` with a `pubkey` in the body instead
        of a signature. Step 4 is the same event on the server and needs nothing from the client.

        Paid fees are not refundable (Art. 4.2), and erasure under
        `DELETE /api/v1/membership/me` therefore anonymises the bookkeeping entries rather than deleting
        them.

        ## Errors and quotas

        Errors are JSON objects with a single `message` field. Refusals are deliberately indistinguishable:
        "no record for this pubkey", "not a member" and "no invoice for that year" all answer `404` with
        the same body, so the API cannot be used to look up who belongs to the association.

        Requests are counted per client key and per end-user pubkey, and invoice creation carries its own
        much tighter daily quota. Exceeding a quota answers `429` with `Retry-After`.
        MARKDOWN;
    }

    /**
     * Both authentication schemes, written from `App\Support\Nip98` and
     * `App\Http\Middleware\VerifyApiClient`.
     */
    private function addSecuritySchemes(OpenApi $document): void
    {
        $document->components->addSecurityScheme(
            self::CLIENT_KEY_SCHEME,
            SecurityScheme::apiKey('header', 'X-Api-Key')
                ->as(self::CLIENT_KEY_SCHEME)
                ->setDescription($this->withContactUrl($this->clientKeyDescription())),
        );

        $document->components->addSecurityScheme(
            self::NIP98_SCHEME,
            SecurityScheme::http('nostr')
                ->as(self::NIP98_SCHEME)
                ->setDescription($this->nip98Description()),
        );
    }

    private function clientKeyDescription(): string
    {
        return <<<'MARKDOWN'
        Identifies the CALLING APPLICATION — not the end user. Required on **every** endpoint of this API,
        on both surfaces, `GET /api/v1/membership/config` included. On the app branch it is the ONLY
        credential.

        Send the key the association issued to you in the `X-Api-Key` header:

        ```http
        X-Api-Key: <your client key>
        ```

        **You have to ask for one.** Keys are issued by the association and there is no self-service
        endpoint — request yours here: [einundzwanzig.group]({{CONTACT_URL}}). Ask for a
        return address to be allowlisted in the same message if your onboarding flow needs one; see
        `return_url` on the invoice endpoint for what that is.

        A missing or unknown key is answered with `401` before any data operation runs and before the
        signature is verified. The key is never echoed back by any response and never appears in a log.

        It is not a user credential and unlocks no member data on its own. On the main surface every
        endpoint but the configuration one additionally requires a NIP-98 signature from the end user, and
        the association never sees that user's private key. On the app branch, where there is no signature,
        the point holds by a different construction: that surface has no read endpoint at all, so a client
        key alone can file an application and order a checkout — and can read nothing back.

        Requests are counted per client key and per end-user pubkey. Exceeding either quota answers `429`
        with a `Retry-After` header.
        MARKDOWN;
    }

    private function nip98Description(): string
    {
        return <<<'MARKDOWN'
        NIP-98 HTTP Auth — identifies the END USER a request is made for. Required on every endpoint of the
        main surface except `GET /api/v1/membership/config`, **in addition to** the client key. The app
        branch under `/api/v1/app/membership` uses none of this: it takes a `pubkey` in the body instead,
        and pays for that with having no read endpoint.

        Send a signed Nostr event of kind `27235`, JSON-encoded and then base64-encoded:

        ```http
        Authorization: Nostr <base64 of the event JSON>
        ```

        The private key stays with the user's signer. Neither the association nor the client operator ever
        holds it, which is what makes "the subject is whoever signed" a guarantee rather than a promise.

        ## What the server checks

        In this order, and each condition on its own:

        1. `kind` is `27235`.
        2. Tag `method` equals the HTTP method of the request, compared case-insensitively.
        3. Tag `u` equals the absolute request URL, compared byte for byte. Scheme, host and port come from
           the server's own configuration — use the server URL listed above — while path and query come
           from the request. A trailing slash or a different query string is a different URL.
        4. `created_at` is within **60 seconds** of server time, in either direction.
        5. If the request carries a body, its `Content-Type` must be exactly `application/json`. Anything
           else, `multipart/form-data` above all, is refused with `415` — a body PHP has already consumed
           cannot be checked against a signature.
        6. If the request carries a body, tag `payload` is the SHA-256 of the **raw body bytes**, lowercase
           hex. It is hashed over the bytes actually sent, not over a re-encoding of them: sign and send
           the same string.
        7. `id` is the SHA-256 of the NIP-01 serialisation `[0, pubkey, created_at, kind, tags, content]`,
           JSON-encoded with slashes and unicode unescaped.
        8. `sig` is a valid BIP-340 Schnorr signature over `id`.
        9. The `id` has not been presented before. **Every event is accepted exactly once** and is
           remembered for 150 seconds; a retried request needs a NEW event.

        `id` and `pubkey` must be 64 lowercase hex characters and `sig` 128. Uppercase hex is rejected even
        though it verifies cryptographically — one private key would otherwise yield arbitrarily many
        identities, each with its own quota.

        Any failed check answers `401` with the same body. Which one failed is deliberately not disclosed.

        ## Building the credential

        With a NIP-07 browser signer, which fills in `pubkey`, `id` and `sig`:

        ```js
        const url    = 'https://verein.einundzwanzig.space/api/v1/membership/applications'
        const method = 'POST'
        const body   = JSON.stringify({ statutes_accepted: true, email: 'satoshi@example.org' })

        const sha256Hex = async (input) => {
          const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input))
          return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('')
        }

        const tags = [
          ['u', url],
          ['method', method],
          // Not required by the server, but two events built in the same second for the same URL and
          // method would otherwise share an id — and the second one would be refused as a replay.
          ['nonce', crypto.randomUUID()],
        ]

        if (body) {
          tags.push(['payload', await sha256Hex(body)])
        }

        const event = await window.nostr.signEvent({
          kind: 27235,
          created_at: Math.floor(Date.now() / 1000),
          tags,
          content: '',
        })

        const response = await fetch(url, {
          method,
          headers: {
            'Content-Type': 'application/json',
            'X-Api-Key': '<your client key>',
            Authorization: 'Nostr ' + btoa(JSON.stringify(event)),
          },
          body,
        })
        ```

        For a GET there is no body: leave out the `payload` tag, the `Content-Type` header and the `body`
        option. Sending an empty JSON body such as `[]` is NOT the same thing — it counts as a body and
        then demands a matching `payload` tag.

        ## A decoded credential

        This is what the base64 in the header decodes to:

        ```json
        {
          "id": "5cf1b4f0e4b90a4a02f0f0a0b1c2d3e4f5061728394a5b6c7d8e9f0a1b2c3d4e",
          "pubkey": "6e468422dfb74a5738702a8823b9b28168abab8655faacb6853cd0ee15deee93",
          "created_at": 1785062400,
          "kind": 27235,
          "tags": [
            ["u", "https://verein.einundzwanzig.space/api/v1/membership/me"],
            ["method", "GET"],
            ["nonce", "0f2b7a1c9d3e4f50"]
          ],
          "content": "",
          "sig": "908f5d1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6"
        }
        ```

        `content` is unused by this API and may stay empty. Tags other than `u`, `method` and `payload` are
        ignored, so a `nonce` costs nothing.
        MARKDOWN;
    }

    /**
     * Security, the shared error responses, and the one operation whose
     * response Scramble could not infer at all.
     */
    private function describeOperation(OpenApi $document, Operation $operation): void
    {
        $key = $operation->method.' '.$operation->path;
        $isSigned = $this->requiresSignature($operation);

        $tag = $this->operationTags()[$key] ?? throw new RuntimeException(
            "Operation [{$key}] has no documentation group. A new /api/v1 endpoint needs one in MembershipApiDocumentTransformer."
        );

        $operation->setTags([$tag]);

        /*
         * Stated on every operation rather than once at the top of the
         * document. A global default would be read by the renderer but not by
         * a person scrolling to a single endpoint, and this API has TWO groups
         * of exceptions to the rule — the configuration endpoint and the whole
         * app branch — which is precisely what a global default hides.
         */
        $operation->security = [
            new SecurityRequirement($isSigned
                ? [self::CLIENT_KEY_SCHEME => [], self::NIP98_SCHEME => []]
                : [self::CLIENT_KEY_SCHEME => []]),
        ];

        $this->addErrorResponse($operation, 401, $isSigned
            ? 'The client key is missing or unknown, or the NIP-98 credential failed verification. Which of '
                .'the two, and which condition of the credential, is deliberately not disclosed.'
            : 'The client key is missing or unknown.');

        if ($isSigned) {
            $this->addErrorResponse($operation, 503,
                'The replay lock behind the NIP-98 verification is unreachable. The request was refused '
                .'rather than waved through; nothing was written. Retry.');
        }

        $this->addErrorResponse($operation, 429,
            'A quota was exceeded — per client key, per end-user pubkey, or, for invoice creation, the '
            .'daily invoice quota. `Retry-After` says how long to wait.');

        if (str_ends_with($operation->path, 'membership/applications')) {
            /*
             * 415 only where a signature is verified. The status comes out of
             * the NIP-98 check, which refuses a body PHP has already consumed
             * because the `payload` tag could no longer be compared against
             * it; on the app branch there is no such tag and no such refusal,
             * and documenting one would describe a wall that is not there.
             */
            if ($isSigned) {
                $this->addErrorResponse($operation, 415,
                    'The request carried a body with a `Content-Type` other than `application/json`. The '
                    .'NIP-98 `payload` tag can only be checked against a body PHP has not already consumed.');
            }

            $this->describeApplicationResponses($document, $operation);
        }

        /*
         * THE DELIBERATELY AMBIGUOUS 404, and the one thing about this API a
         * client developer cannot work out from a failing request. Scramble
         * publishes the framework's shared "Not found" for it, which reads
         * like an ordinary missing resource and sends the reader looking for
         * the one cause that produced it. There are two, they are indist-
         * inguishable on purpose, and only one of them is worth acting on.
         */
        if (str_contains($operation->path, 'payments/{year}')) {
            $this->addErrorResponse($operation, 404, str_contains($operation->path, 'invoice')
                ? 'Either the pubkey has no membership record, or `year` is not the fee year the association is '
                    .'currently collecting. WHICH OF THE TWO IS NOT DISCLOSED — telling them apart would answer '
                    .'"is this pubkey known to the association" to anyone who asked. Read the current year from the '
                    .'configuration endpoint, and file the application before asking for a checkout.'
                : 'Either the pubkey has no membership record, or no invoice was ever created for that fee year. '
                    .'Which of the two is not disclosed, for the same reason it is not disclosed anywhere else on '
                    .'this API.',
                replace: true);
        }

        $this->describeYearParameter($operation);
        $this->describeDeleteResponse($operation);
    }

    /**
     * The status code a generated response answers under, whether it is a
     * response or a reference to a shared one.
     *
     * The reference arm is not decoration. Scramble emits the framework's own
     * 404 and 422 as `$ref`s into `components/responses`, and the code lives
     * on the TARGET — `Operation::toArray()` resolves the reference for
     * exactly this reason. A check that only understood `Response` would
     * report "no 404 here" for an operation that plainly documents one, and
     * `addErrorResponse()` would then append a second entry that silently
     * overwrites the first in the emitted map.
     */
    private function responseCode(mixed $response): ?int
    {
        if ($response instanceof Reference) {
            try {
                $response = $response->resolve();
            } catch (OpenApiReferenceTargetNotFoundException) {
                return null;
            }
        }

        return $response instanceof Response && $response->code !== null
            ? (int) $response->code
            : null;
    }

    /**
     * Add a `message`-only error response for a status the generator has not
     * produced — or, with `$replace`, restate one it produced generically.
     */
    private function addErrorResponse(Operation $operation, int $code, string $description, bool $replace = false): void
    {
        $existingIndex = null;

        foreach ($operation->responses ?? [] as $index => $existing) {
            if ($this->responseCode($existing) === $code) {
                $existingIndex = $index;

                break;
            }
        }

        if ($existingIndex !== null && ! $replace) {
            return;
        }

        $body = (new ObjectType)
            ->addProperty('message', (new StringType)->setDescription('A human-readable summary. It carries no detail a caller could use to distinguish one cause from another.'))
            ->setRequired(['message']);

        $response = Response::make($code)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($body));

        if ($existingIndex !== null) {
            $operation->responses[$existingIndex] = $response;

            return;
        }

        $operation->addResponse($response);
    }

    /**
     * `POST /applications` answers with a membership, and with 201 the first
     * time.
     *
     * Scramble sees `(new MembershipResource(...))->response()->setStatusCode(...)`
     * and gives up, documenting a bare string with a single 200. Both the
     * shape and the second status code are restated here.
     */
    private function describeApplicationResponses(OpenApi $document, Operation $operation): void
    {
        $envelope = fn (): Schema => Schema::fromType(
            (new ObjectType)
                ->addProperty('data', new Reference(
                    'schemas',
                    $this->schemaName($document, MembershipResource::class),
                    $document->components,
                ))
                ->setRequired(['data'])
        );

        $responses = [];

        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (int) $response->code === 200) {
                $responses[] = Response::make(201)
                    ->setDescription('The consent to the statutes was recorded for the first time. The membership itself still waits for the annual fee.')
                    ->setContent('application/json', $envelope());

                $responses[] = Response::make(200)
                    ->setDescription('A repeat application. Contact data was updated where sent; `statutes_accepted_at` keeps the value it already had.')
                    ->setContent('application/json', $envelope());

                continue;
            }

            $responses[] = $response;
        }

        $operation->responses = $responses;
    }

    /**
     * The `{year}` path parameter of both payment endpoints.
     */
    private function describeYearParameter(Operation $operation): void
    {
        foreach ($operation->parameters as $parameter) {
            if (! property_exists($parameter, 'name') || $parameter->name !== 'year') {
                continue;
            }

            $parameter->description = str_contains($operation->path, 'invoice')
                ? 'The fee year to be paid. Only the CURRENT fee year is accepted — the one `GET /api/v1/membership/config` reports. Any other year answers 404.'
                : 'The fee year to re-read from BTCPay. Any year is accepted; a refresh creates nothing.';

            $parameter->example = '2026';

            $schema = $parameter->schema instanceof Schema ? $parameter->schema->type : $parameter->schema;

            if ($schema instanceof Type) {
                $schema->pattern = '^[0-9]{4}$';
            }
        }
    }

    /**
     * The two fields of the erasure response.
     *
     * Their descriptions in the document are generated from the reasoning
     * comments in the controller, which run to paragraphs about tombstones and
     * recomputable links. That reasoning belongs in the code; a consumer needs
     * two sentences.
     */
    private function describeDeleteResponse(Operation $operation): void
    {
        if ($operation->method !== 'delete' || $operation->path !== 'api/v1/membership/me') {
            return;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || (int) $response->code !== 200) {
                continue;
            }

            $response->setDescription('The erasure is complete. The same answer is given on every repeat call.');

            $schema = $response->content['application/json'] ?? null;

            if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
                throw new RuntimeException('The erasure response is no longer a JSON object; MembershipApiDocumentTransformer needs updating.');
            }

            $this->patchProperties($schema->type, [
                'data.erased' => [
                    'description' => 'Always true: no personal data of this pubkey is stored. A statement about the state after the call, not about how much work it took.',
                ],
                'data.retained_payments' => [
                    'description' => 'How many annual fees remain as anonymised bookkeeping entries. Null on every call after the first — the link needed to count them is exactly what the erasure destroyed.',
                    'examples' => [2],
                ],
            ]);
        }
    }

    /**
     * Types, descriptions and examples for every field of every response
     * component.
     */
    private function describeComponentSchemas(OpenApi $document): void
    {
        foreach ($this->componentFields() as $class => $fields) {
            $schema = $document->components->schemas[$this->schemaName($document, $class)] ?? null;

            if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
                throw new RuntimeException("Component schema for [{$class}] is missing or is not an object.");
            }

            $this->patchProperties($schema->type, $fields);
        }

        /*
         * Request bodies are the schemas whose generated description comes
         * from a class docblock rather than from a field comment, and those
         * docblocks argue with plan steps, audit items and measurements. A
         * third party needs the rule, not the argument for it.
         */
        $requestSchema = $document->components->schemas[$this->schemaName($document, StoreApplicationRequest::class)] ?? null;

        if (! $requestSchema instanceof Schema || ! $requestSchema->type instanceof ObjectType) {
            throw new RuntimeException('The application request body is missing from the generated document.');
        }

        $requestSchema->type->setDescription(
            'The body of an application. There is no `pubkey` field: the subject is the pubkey that signed the '
            .'request, and a body naming one is refused. There is no `association_status` field either — the '
            .'membership category is raised by a settled fee and by nothing else.'
            ."\n\n"
            .'An omitted field is left untouched; an explicit `null` clears the stored value. The two are not the '
            .'same instruction, because the body is signed as a whole: were omission treated as "clear it", '
            .'dropping a field from the payload would erase data the user never touched.'
        );

        $invoiceSchema = $document->components->schemas[$this->schemaName($document, StoreInvoiceRequest::class)] ?? null;

        if (! $invoiceSchema instanceof Schema || ! $invoiceSchema->type instanceof ObjectType) {
            throw new RuntimeException('The invoice request body is missing from the generated document.');
        }

        /*
         * The two app-branch bodies. Their class docblocks are written for
         * whoever maintains this application — they argue about trust
         * boundaries and name the sibling class the rule was copied from — and
         * Scramble would publish that argument verbatim. Restated here for the
         * reader who has to fill the body in.
         */
        $appApplicationSchema = $document->components->schemas[$this->schemaName($document, StoreAppApplicationRequest::class)] ?? null;

        if (! $appApplicationSchema instanceof Schema || ! $appApplicationSchema->type instanceof ObjectType) {
            throw new RuntimeException('The app-branch application request body is missing from the generated document.');
        }

        $appApplicationSchema->type->setDescription(
            'The body of an application on the APP BRANCH. It is the body of `POST /api/v1/membership/applications` '
            .'plus the one field that surface refuses: `pubkey`, which here names the subject because no '
            .'signature does.'
            ."\n\n"
            .'Everything else behaves identically — the consent is recorded once and never refreshed, an omitted '
            .'field is left untouched, an explicit `null` clears the stored value. As on the main surface this '
            .'call grants no membership: the settled annual fee does that and nothing else.'
        );

        $appInvoiceSchema = $document->components->schemas[$this->schemaName($document, StoreAppInvoiceRequest::class)] ?? null;

        if (! $appInvoiceSchema instanceof Schema || ! $appInvoiceSchema->type instanceof ObjectType) {
            throw new RuntimeException('The app-branch invoice request body is missing from the generated document.');
        }

        $appInvoiceSchema->type->setDescription(
            'The body of an invoice request on the APP BRANCH. Unlike its counterpart on the main surface this '
            .'body is REQUIRED, because it carries `pubkey` — the subject of the checkout, which no signature '
            .'supplies here.'
            ."\n\n"
            .'`return_url` is the only other field that is read, and it is optional. Amount, currency and fee '
            .'year are not request values: the first two come from the association\'s configuration and the year '
            .'from the path.'
        );

        $invoiceSchema->type->setDescription(
            'The body of an invoice request, and `return_url` is the only field of it that is read. Amount, '
            .'currency and fee year are not request values: the first two come from the association\'s '
            .'configuration and the year from the path. A body naming them is ignored rather than rejected — a '
            .'client asking for a different amount is not to be helped with an error message but to be charged '
            .'the correct fee.'
            ."\n\n"
            .'The whole body may also be omitted.'
        );
    }

    /**
     * The component schema key Scramble stored a resource under.
     *
     * Looked up rather than assumed. Scramble keys a component by its short
     * class name while a name collision is not in sight and falls back to the
     * fully qualified one when it is, so the key that is correct today is not
     * a key worth hardcoding — a `$ref` built on the wrong guess points at
     * nothing.
     *
     * @param  class-string  $class
     */
    private function schemaName(OpenApi $document, string $class): string
    {
        $candidates = [trim($class, '\\'), class_basename($class)];

        foreach (array_keys($document->components->schemas) as $name) {
            if (in_array(trim($name, '\\'), $candidates, true)) {
                return $name;
            }
        }

        throw new RuntimeException("No OpenAPI component schema was generated for [{$class}].");
    }

    /**
     * Apply a field map to an object schema, addressing nested fields with
     * dots.
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function patchProperties(ObjectType $root, array $fields): void
    {
        foreach ($fields as $path => $spec) {
            $property = $this->property($root, $path);

            if (isset($spec['type'])) {
                $replacement = $this->makeType($spec['type']);
                $replacement->nullable = $spec['nullable'] ?? $property->nullable;

                $this->replaceProperty($root, $path, $replacement);
                $property = $replacement;
            } elseif (array_key_exists('nullable', $spec)) {
                $property->nullable = $spec['nullable'];
            }

            if (isset($spec['description'])) {
                $property->setDescription($spec['description']);
            }

            if (isset($spec['format'])) {
                $property->format($spec['format']);
            }

            if (isset($spec['enum'])) {
                $property->enum($spec['enum']);
            }

            if (isset($spec['pattern'])) {
                $property->pattern = $spec['pattern'];
            }

            if (isset($spec['examples'])) {
                $property->examples($spec['examples']);
            }

            if (isset($spec['items'])) {
                if (! $property instanceof ArrayType) {
                    throw new RuntimeException("Field [{$path}] was expected to be an array.");
                }

                $property->setItems($spec['items']());
            }
        }
    }

    private function makeType(string $type): Type
    {
        return match ($type) {
            'string' => new StringType,
            'integer' => new IntegerType,
            'boolean' => new BooleanType,
            'array' => new ArrayType,
            'object' => new ObjectType,
            default => throw new RuntimeException("Unsupported schema type [{$type}]."),
        };
    }

    /**
     * Resolve a dotted field path, refusing to describe what is not there.
     */
    private function property(ObjectType $root, string $path): Type
    {
        $current = $root;

        foreach (explode('.', $path) as $index => $segment) {
            if (! $current instanceof ObjectType || ! $current->hasProperty($segment)) {
                throw new RuntimeException("Field [{$path}] no longer exists in the generated document.");
            }

            $current = $current->getProperty($segment);
        }

        if (! $current instanceof Type) {
            throw new RuntimeException("Field [{$path}] has no type in the generated document.");
        }

        return $current;
    }

    private function replaceProperty(ObjectType $root, string $path, Type $type): void
    {
        $segments = explode('.', $path);
        $leaf = array_pop($segments);
        $current = $root;

        foreach ($segments as $segment) {
            $current = $current->getProperty($segment);
        }

        $current->addProperty($leaf, $type);
    }

    /**
     * The fee fields of one annual fee, shared by the payments list and by the
     * invoice response, which inlines the very same shape.
     *
     * @return array<string, array<string, mixed>>
     */
    private function paymentFields(string $prefix = ''): array
    {
        return [
            $prefix.'year' => [
                'description' => 'The fee year this entry is for.',
                'examples' => [2026],
            ],
            $prefix.'amount' => [
                'description' => 'The annual fee that was billed for that year, in the smallest unit of `currency`. The general assembly fixes it per year, so an older entry may name a different amount than the current one.',
                'examples' => [21],
            ],
            $prefix.'currency' => [
                'description' => 'The currency the fee is billed in, as an ISO 4217 code.',
                'examples' => ['CHF'],
            ],
            $prefix.'paid' => [
                'description' => 'Whether this fee has been settled. A settled fee is what constitutes the membership for that year.',
            ],
            $prefix.'receipt_url' => [
                'description' => 'The BTCPay receipt, and null until the fee is settled. An unsettled invoice has no receipt — to pay an open year, call `POST /api/v1/membership/payments/{year}/invoice`.',
                'examples' => ['https://pay.einundzwanzig.space/i/3T8xkPZbvhMzTMbY5rYWbA/receipt'],
            ],
        ];
    }

    /**
     * @return array<class-string, array<string, array<string, mixed>>>
     */
    private function componentFields(): array
    {
        return [
            MembershipConfigResource::class => [
                'fee' => [
                    'description' => 'The annual fee for `year`, in the smallest unit of `currency`.',
                    'examples' => [21],
                ],
                'currency' => [
                    'description' => 'The currency the fee is billed in, as an ISO 4217 code.',
                    'examples' => ['CHF'],
                ],
                'year' => [
                    'description' => 'The fee year the association is currently collecting. It is the only year `POST /api/v1/membership/payments/{year}/invoice` accepts.',
                    'examples' => [2026],
                ],
                'statutes.url' => [
                    'description' => 'The public statutes a member consents to when applying. A client must show them before asking for that consent.',
                    'examples' => ['https://einundzwanzig.space/files/Statuten_v1.3.pdf'],
                ],
                'statutes.version' => [
                    'description' => 'The version of the statutes currently in force.',
                    'examples' => ['1.3'],
                ],
                'statutes.adopted_at' => [
                    'description' => 'The date the general assembly adopted that version.',
                    'examples' => ['2024-04-20'],
                ],
                'application.required_fields' => [
                    'description' => 'The body fields an application requires on a first application. The consent is the only one: '
                        .'the statutes demand no legal name, no address and no date of birth. '
                        .'ON THE APP BRANCH ADD `pubkey`, which this list does not name — the response is association-wide '
                        .'and identical on both branches, and `pubkey` is a property of the branch rather than of the '
                        .'association. `POST /api/v1/app/membership/applications` documents it as required.',
                ],
                'application.optional_fields' => [
                    'description' => 'The body fields that application may additionally carry. An absent field is left alone, an explicit null clears the stored value.',
                ],
                'application.application_text_max_length' => [
                    'description' => 'The maximum length of `application_text`, in characters. A longer text is refused with 422.',
                ],
            ],

            MembershipResource::class => [
                'pubkey' => [
                    'description' => 'The Nostr public key of the end user this record belongs to — the key that signed the request. 64 lowercase hex characters (NIP-01).',
                    'pattern' => '^[0-9a-f]{64}$',
                    'examples' => ['6e468422dfb74a5738702a8823b9b28168abab8655faacb6853cd0ee15deee93'],
                ],
                'association_status' => [
                    'description' => 'The membership category on record. '.self::STATUS_WARNING,
                    'enum' => ['DEFAULT', 'PASSIVE', 'ACTIVE', 'HONORARY'],
                    'examples' => ['PASSIVE'],
                ],
                'association_status_value' => [
                    'type' => 'integer',
                    'description' => 'The numeric form of `association_status` as stored: DEFAULT 1, PASSIVE 2, ACTIVE 3, HONORARY 4. Present for clients that persist the value; prefer the name.',
                    'enum' => [1, 2, 3, 4],
                    'examples' => [2],
                ],
                'membership_status' => [
                    'description' => 'Whether this person is a member RIGHT NOW, derived from the category and the current fee year together. This is the field to render. `none` — no application and no category. `awaiting_payment` — applied for, current fee year unpaid. `member` — a category and the current fee year paid. `lapsed` — a category, but the current fee year unpaid.',
                    'enum' => ['none', 'awaiting_payment', 'member', 'lapsed'],
                    'examples' => ['member'],
                ],
                'statutes_accepted_at' => [
                    'description' => 'When this person consented to the statutes, ISO 8601. Null means the membership predates the field — NOT that consent was refused. It is recorded once and is never backdated or refreshed by a later application.',
                    'format' => 'date-time',
                    'examples' => ['2026-02-14T09:31:07+00:00'],
                ],
                'applied_at' => [
                    'description' => 'When the most recent application was recorded, ISO 8601. Null if there has never been one.',
                    'format' => 'date-time',
                    'examples' => ['2026-02-14T09:31:07+00:00'],
                ],
                'current_year.year' => [
                    'type' => 'integer',
                    'description' => 'The fee year the association is currently collecting.',
                    'examples' => [2026],
                ],
                'current_year.fee' => [
                    'type' => 'integer',
                    'description' => 'The annual fee for that year, in the smallest unit of `currency`.',
                    'examples' => [21],
                ],
                'current_year.currency' => [
                    'type' => 'string',
                    'description' => 'The currency the fee is billed in, as an ISO 4217 code.',
                    'examples' => ['CHF'],
                ],
                'current_year.paid' => [
                    'type' => 'boolean',
                    'description' => 'Whether the current fee year has been settled. This is the payment half of `membership_status`.',
                ],
                'current_year.receipt_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'The BTCPay receipt for the current year, and null until it is settled.',
                    'examples' => ['https://pay.einundzwanzig.space/i/3T8xkPZbvhMzTMbY5rYWbA/receipt'],
                ],
            ],

            PaymentEventResource::class => $this->paymentFields(),

            InvoiceResource::class => array_merge([
                'checkout_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'The BTCPay checkout page to send the payer to. Null only on a refresh whose invoice BTCPay reported as expired or invalid — the fee year is then free again and a new invoice can be created.',
                    'examples' => ['https://pay.einundzwanzig.space/i/3T8xkPZbvhMzTMbY5rYWbA'],
                ],
                'bolt11' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'The Lightning payment request (BOLT11) of the same invoice, so that a client with a wallet can pay it without opening the checkout page. It is additive: null means "use `checkout_url`", never "something went wrong". Null when the invoice carries no Lightning payment method — which happens on real invoices of this store — and null again when BTCPay could not be asked in time. NULL IS NOT AN EXPIRY SIGNAL: BTCPay hands out the payment request of a long-dead invoice unchanged, so read the deadline from the invoice itself. It is the same 1440 minutes the checkout has, so there is no second deadline to track.',
                    'examples' => ['lnbc210u1p48naztpp5tl2nrhjn8skyy3jv0mlhfaugppmu6dv'],
                ],
                'created' => [
                    'type' => 'boolean',
                    'description' => 'Whether THIS call created a BTCPay invoice. False means an invoice for that year already existed and you are being handed that one — the normal idempotent answer, not an error. A refresh never creates anything and always reports false.',
                ],
                'payment' => [
                    'description' => 'The annual fee this checkout belongs to, in the same shape an entry of `GET /api/v1/membership/payments` has.',
                ],
            ], $this->paymentFields('payment.')),

            MembershipExportResource::class => [
                'subject.pubkey' => [
                    'description' => 'The Nostr public key the request was signed with, 64 lowercase hex characters.',
                    'pattern' => '^[0-9a-f]{64}$',
                    'examples' => ['6e468422dfb74a5738702a8823b9b28168abab8655faacb6853cd0ee15deee93'],
                ],
                'subject.npub' => [
                    'description' => 'The same key in the bech32 form of NIP-19, and null if no membership record exists.',
                    /*
                     * The bech32 encoding of the pubkey example above, and it
                     * has to stay that way: `OpenApiDocumentationTest` computes
                     * one from the other with `Key::convertPublicKeyToBech32()`
                     * and fails if they drift. The first value here did not
                     * survive that check — it was outside the bech32 alphabet
                     * and threw `Invalid bech32 checksum`, i.e. the document
                     * published an npub no client could decode.
                     */
                    'examples' => ['npub1dergggklka99wwrs92yz8wdjs952h2ux2ha2ed598ngwu9w7a6fsh9xzpc'],
                ],
                'membership_status' => [
                    'description' => 'Whether this person is a member right now. Same values and same meaning as in `GET /api/v1/membership/me`.',
                    'enum' => ['none', 'awaiting_payment', 'member', 'lapsed'],
                    'examples' => ['member'],
                ],
                'member' => [
                    'description' => 'The membership record, or null if this pubkey has none. "Nothing is stored about you" is a complete answer to an access request.',
                ],
                'member.association_status' => [
                    'description' => 'The membership category on record. '.self::STATUS_WARNING,
                    'enum' => ['DEFAULT', 'PASSIVE', 'ACTIVE', 'HONORARY'],
                    'examples' => ['PASSIVE'],
                ],
                'member.association_status_value' => [
                    'description' => 'The numeric form of `association_status`: DEFAULT 1, PASSIVE 2, ACTIVE 3, HONORARY 4.',
                    'enum' => [1, 2, 3, 4],
                    'examples' => [2],
                ],
                'member.applied_at' => [
                    'description' => 'When the most recent application was recorded, ISO 8601.',
                    'format' => 'date-time',
                ],
                'member.statutes_accepted_at' => [
                    'description' => 'When the statutes were consented to, ISO 8601. Null means the membership predates the field.',
                    'format' => 'date-time',
                ],
                'member.email' => [
                    'description' => 'The e-mail address on file. This endpoint is the only one that discloses it.',
                    'examples' => ['satoshi@example.org'],
                ],
                'member.no_email' => [
                    'description' => 'Whether this person asked not to be contacted by e-mail.',
                ],
                'member.nip05_handle' => [
                    'description' => 'The local part of the NIP-05 identifier the association serves for this member, if one was claimed.',
                    'examples' => ['satoshi'],
                ],
                'member.application_text' => [
                    'description' => 'The free-form text written on the most recent application. Disclosed here and nowhere else.',
                ],
                'member.archived_application_text' => [
                    'description' => 'Application text kept from an earlier application. Disclosed here and nowhere else.',
                ],
                'member.application_for' => [
                    'description' => 'An internal reference on the application record. Reported for completeness of the access request.',
                ],
                'member.created_at' => [
                    'description' => 'When the membership record came into existence, ISO 8601.',
                    'format' => 'date-time',
                ],
                'member.updated_at' => [
                    'description' => 'When the membership record was last written, ISO 8601.',
                    'format' => 'date-time',
                ],
                'payments' => [
                    'type' => 'array',
                    'description' => 'Every annual fee on record, newest year first. Fuller than `GET /api/v1/membership/payments`: the BTCPay invoice id and the Nostr event id are withheld from ordinary responses because no client needs them, but to their own subject they are part of the record.',
                    'items' => fn (): ObjectType => $this->exportPaymentItem(),
                ],
                'membership_grants' => [
                    'type' => 'array',
                    'description' => 'Every status change a settled fee caused, newest year first. This is the audit trail behind `association_status`.',
                    'items' => fn (): ObjectType => $this->membershipGrantItem(),
                ],
                'nostr_profile' => [
                    'description' => 'The cached public kind-0 profile of this pubkey, exactly as its owner published it to the Nostr network, or null if none was ever seen. It is public data, held here only as a cache, and the association is not its source.',
                ],
                'nostr_profile.name' => ['description' => 'The `name` field of the kind-0 profile.'],
                'nostr_profile.display_name' => ['description' => 'The `display_name` field of the kind-0 profile.'],
                'nostr_profile.picture' => ['description' => 'The avatar URL from the kind-0 profile.'],
                'nostr_profile.banner' => ['description' => 'The banner image URL from the kind-0 profile.'],
                'nostr_profile.website' => ['description' => 'The website from the kind-0 profile.'],
                'nostr_profile.about' => ['description' => 'The free-form "about" text from the kind-0 profile.'],
                'nostr_profile.nip05' => ['description' => 'The NIP-05 identifier claimed in the kind-0 profile. Unverified, and unrelated to `member.nip05_handle`, which is the handle the association itself serves.'],
                'nostr_profile.lud16' => ['description' => 'The Lightning address from the kind-0 profile.'],
                'nostr_profile.lud06' => ['description' => 'The LNURL-pay entry from the kind-0 profile.'],
                'nostr_profile.created_at' => ['description' => 'When this cache entry was created, ISO 8601. Not the age of the profile itself.', 'format' => 'date-time'],
                'nostr_profile.updated_at' => ['description' => 'When this cache entry was last refreshed, ISO 8601.', 'format' => 'date-time'],
            ],

            StoreApplicationRequest::class => $this->applicationBodyFields(),

            /*
             * The same five fields, plus the subject. Shared rather than
             * copied: the two form requests validate identically apart from
             * `pubkey` (StoreAppApplicationRequest says so in as many words),
             * and two hand-maintained copies of the same prose would drift the
             * first time one of the rules is touched.
             */
            StoreAppApplicationRequest::class => array_merge($this->applicationBodyFields(), [
                'pubkey' => $this->appSubjectField(
                    'The Nostr public key the application is for. REQUIRED HERE and refused on the main surface, '
                    .'where the signature names the subject instead.'
                ),
            ]),

            StoreAppInvoiceRequest::class => [
                'pubkey' => $this->appSubjectField(
                    'The Nostr public key the checkout is for. REQUIRED HERE and refused on the main surface, '
                    .'where the signature names the subject instead. A pubkey with no membership record answers '
                    .'404 — file the application first.'
                ),
                'return_url' => [
                    'description' => 'Where the payer is sent after the checkout, and optional. A value must be one of '
                        .'the addresses configured on the server — for this branch that is the local address the '
                        .'native app serves — and anything else is refused with 422 rather than silently replaced. '
                        .'ASK FOR YOUR ADDRESS TO BE ALLOWLISTED BEFORE YOU SEND ONE; it is not on the list until the '
                        .'association puts it there: '.self::CONTACT_URL.'. '
                        .'It only takes effect on the call that actually creates the invoice; on an idempotent '
                        .'repeat the redirect belongs to the invoice that already exists.',
                ],
            ],

            StoreInvoiceRequest::class => [
                'return_url' => [
                    'description' => 'Where the payer is sent after the checkout. Optional: omit it (or send null) and the payer returns to the association\'s own profile page, which is what happened before this field existed. '
                        .'ASK FOR YOUR ADDRESS TO BE ALLOWLISTED BEFORE YOU SEND ONE — the value is checked against a list kept on the server, and your own URL is not on it until the association puts it there: '.self::CONTACT_URL.'. '
                        .'This is not bureaucracy but the reason the field is safe to have: the value ends up in BTCPay\'s `redirectURL`, so an unchecked one would let any key holder bounce a payer off the association\'s domain to anywhere. An address that is not on the list is refused with 422 rather than silently replaced, so that a rejected address is never mistaken for an accepted one. '
                        .'It only takes effect on the call that actually creates the invoice — on an idempotent repeat the redirect belongs to the invoice that already exists.',
                    'examples' => ['https://einundzwanzig.group/verein/beitritt'],
                ],
            ],
        ];
    }

    /**
     * The `pubkey` body field of both app-branch endpoints.
     *
     * @return array<string, mixed>
     */
    private function appSubjectField(string $lead): array
    {
        return [
            'description' => $lead
                .' 64 lowercase hex characters (NIP-01); any other spelling is refused with 422, uppercase hex'
                .' included — one private key would otherwise yield arbitrarily many identities, each with its'
                .' own quota.'
                ."\n\n"
                .'NAMING A KEY IS NOT PROVING IT. On this branch the calling application vouches for the value and'
                .' the association cannot check it, which is why the branch has no endpoint that reads anything'
                .' back. Nothing is granted by naming a pubkey — the settled annual fee grants the membership.',
            'pattern' => '^[0-9a-f]{64}$',
            'examples' => ['6e468422dfb74a5738702a8823b9b28168abab8655faacb6853cd0ee15deee93'],
        ];
    }

    /**
     * The five body fields an application carries on either surface.
     *
     * @return array<string, array<string, mixed>>
     */
    private function applicationBodyFields(): array
    {
        return [
            'statutes_accepted' => [
                'description' => 'Consent to the statutes. Send `true`. Required on a first application and optional afterwards — the consent is given once, and a repeat application neither needs it again nor may overwrite the recorded timestamp. An explicit `false` is a refusal and is rejected with 422 rather than silently recorded as a non-consent. The other accepted spellings are Laravel\'s `accepted` rule and are listed for completeness.',
            ],
            'application_text' => [
                'description' => 'Free-form text the applicant wants on record, up to 2000 characters. Disclosed only back to its own author, through `GET /api/v1/membership/export`.',
                'examples' => ['I have been running the local meetup since 2023 and would like to join.'],
            ],
            'email' => [
                'description' => 'Contact e-mail address. Optional — the statutes let invitations travel by online publication, so a membership does not depend on it.',
                'examples' => ['satoshi@example.org'],
            ],
            'no_email' => [
                'description' => 'Set to true to state that this person does not want to be contacted by e-mail.',
            ],
            'nip05_handle' => [
                'description' => 'The local part of a NIP-05 identifier the association serves as `<handle>@einundzwanzig.space`. Lowercase letters, digits, hyphen and underscore only, because it becomes part of a public .well-known/nostr.json. Handles are unique across members; a taken one is refused with 422. It is a public identifier by design, so refusing it discloses nothing.',
                'examples' => ['satoshi'],
            ],
        ];
    }

    /**
     * One annual fee as the access request reports it.
     */
    private function exportPaymentItem(): ObjectType
    {
        $item = (new ObjectType)
            ->addProperty('year', (new IntegerType)->setDescription('The fee year.')->examples([2026]))
            ->addProperty('amount', (new IntegerType)->setDescription('The amount billed for that year, in the smallest unit of `currency`.')->examples([21]))
            ->addProperty('currency', (new StringType)->setDescription('The currency the fee is billed in, as an ISO 4217 code.')->examples(['CHF']))
            ->addProperty('paid', (new BooleanType)->setDescription('Whether the fee has been settled.'))
            ->addProperty('btc_pay_invoice', (new StringType)->nullable(true)->setDescription('The BTCPay invoice id, if one was ever created for this year.')->examples(['3T8xkPZbvhMzTMbY5rYWbA']))
            ->addProperty('nostr_event_id', (new StringType)->nullable(true)->setDescription('The id of the Nostr event this payment was recorded from, if it came in that way.'))
            ->addProperty('created_at', (new StringType)->nullable(true)->format('date-time')->setDescription('When the entry was created, ISO 8601.'))
            ->addProperty('updated_at', (new StringType)->nullable(true)->format('date-time')->setDescription('When the entry was last written, ISO 8601.'));

        return $item->setRequired([
            'year', 'amount', 'currency', 'paid', 'btc_pay_invoice', 'nostr_event_id', 'created_at', 'updated_at',
        ]);
    }

    /**
     * One recorded status change.
     */
    private function membershipGrantItem(): ObjectType
    {
        $status = fn (string $description): StringType => (new StringType)
            ->setDescription($description)
            ->enum(['DEFAULT', 'PASSIVE', 'ACTIVE', 'HONORARY']);

        $item = (new ObjectType)
            ->addProperty('year', (new IntegerType)->setDescription('The fee year whose settled payment caused the change.')->examples([2026]))
            ->addProperty('from_status', $status('The membership category before the change.'))
            ->addProperty('to_status', $status('The membership category after it.'))
            ->addProperty('granted_at', (new StringType)->nullable(true)->format('date-time')->setDescription('When the change was recorded, ISO 8601.'));

        return $item->setRequired(['year', 'from_status', 'to_status', 'granted_at']);
    }
}
