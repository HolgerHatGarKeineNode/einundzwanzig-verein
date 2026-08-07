<?php

namespace App\Support\OpenApi;

use App\Http\Requests\Api\V1\StoreApplicationRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Http\Resources\Api\V1\MembershipConfigResource;
use App\Http\Resources\Api\V1\MembershipExportResource;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Http\Resources\Api\V1\PaymentEventResource;
use Dedoc\Scramble\Contracts\DocumentTransformer;
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
 * THE SECURITY PER OPERATION — seven endpoints need the client key AND the
 * end-user signature, `GET /membership/config` needs only the first. That
 * distinction lives in the route file as a middleware list, and Scramble's
 * middleware-based strategy only recognises Laravel's own `auth` guards.
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
     * The one operation that gets by on the client key alone.
     */
    private const PUBLIC_CONFIG_OPERATION = 'get api/v1/membership/config';

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

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->info->setDescription($this->apiDescription());

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
     * The four groups the reference lists the endpoints under.
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
            'Configuration' => 'What joining costs and what an application has to carry. The only part of this API that needs no end-user signature.',
            'Membership' => 'Applying, and reading your own membership.',
            'Payments' => 'The annual fee: the checkout for it, and the record of the ones already paid.',
            'Personal data' => 'The two rights a data subject exercises directly — access and erasure.',
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

        Every request carries the **client key** of the calling application in `X-Api-Key`. Every request
        except `GET /api/v1/membership/config` additionally carries a **NIP-98 signature** identifying the
        end user. The two answer different questions — which application is calling, and who it is calling
        for — and neither substitutes for the other. See the security schemes for how to build the
        signature.

        The subject of a request is always the pubkey that signed it. There is no parameter for it and a
        pubkey sent in the path, the query or the body is refused. No endpoint on this API returns data
        about another member.

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

        ## Joining, end to end

        1. `GET /api/v1/membership/config` — the fee, the currency and the current fee year.
        2. `POST /api/v1/membership/applications` — the application and the consent to the statutes. This
           grants nothing on its own: the statutes tie the membership to the payment (Art. 4).
        3. `POST /api/v1/membership/payments/{year}/invoice` — a BTCPay checkout for that fee year. Send
           the payer to `checkout_url`.
        4. The settled payment grants the membership. It normally arrives as a BTCPay webhook; if that
           delivery is lost, `POST /api/v1/membership/payments/{year}/refresh` pulls the same result.

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
                ->setDescription($this->clientKeyDescription()),
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
        `GET /api/v1/membership/config` included.

        Send the key the association issued to you in the `X-Api-Key` header:

        ```http
        X-Api-Key: <your client key>
        ```

        A missing or unknown key is answered with `401` before any data operation runs and before the
        signature is verified. The key is never echoed back by any response and never appears in a log.

        It is not a user credential and unlocks no member data on its own: every endpoint but the
        configuration one additionally requires a NIP-98 signature from the end user, and the association
        never sees that user's private key.

        Requests are counted per client key and per end-user pubkey. Exceeding either quota answers `429`
        with a `Retry-After` header.
        MARKDOWN;
    }

    private function nip98Description(): string
    {
        return <<<'MARKDOWN'
        NIP-98 HTTP Auth — identifies the END USER a request is made for. Required on every endpoint except
        `GET /api/v1/membership/config`, **in addition to** the client key.

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
        $isPublicConfig = $key === self::PUBLIC_CONFIG_OPERATION;

        $tag = $this->operationTags()[$key] ?? throw new RuntimeException(
            "Operation [{$key}] has no documentation group. A new /api/v1 endpoint needs one in MembershipApiDocumentTransformer."
        );

        $operation->setTags([$tag]);

        /*
         * Stated on every operation rather than once at the top of the
         * document. A global default would be read by the renderer but not by
         * a person scrolling to a single endpoint, and this API has exactly
         * one exception to the rule — which is precisely the case a global
         * default hides.
         */
        $operation->security = [
            new SecurityRequirement($isPublicConfig
                ? [self::CLIENT_KEY_SCHEME => []]
                : [self::CLIENT_KEY_SCHEME => [], self::NIP98_SCHEME => []]),
        ];

        $this->addErrorResponse($operation, 401, $isPublicConfig
            ? 'The client key is missing or unknown.'
            : 'The client key is missing or unknown, or the NIP-98 credential failed verification. Which of '
                .'the two, and which condition of the credential, is deliberately not disclosed.');

        if (! $isPublicConfig) {
            $this->addErrorResponse($operation, 503,
                'The replay lock behind the NIP-98 verification is unreachable. The request was refused '
                .'rather than waved through; nothing was written. Retry.');
        }

        $this->addErrorResponse($operation, 429,
            'A quota was exceeded — per client key, per end-user pubkey, or, for invoice creation, the '
            .'daily invoice quota. `Retry-After` says how long to wait.');

        if ($operation->path === 'api/v1/membership/applications') {
            $this->addErrorResponse($operation, 415,
                'The request carried a body with a `Content-Type` other than `application/json`. The '
                .'NIP-98 `payload` tag can only be checked against a body PHP has not already consumed.');

            $this->describeApplicationResponses($document, $operation);
        }

        $this->describeYearParameter($operation);
        $this->describeDeleteResponse($operation);
    }

    /**
     * Add a `message`-only error response unless the generator produced one
     * for that status already.
     */
    private function addErrorResponse(Operation $operation, int $code, string $description): void
    {
        foreach ($operation->responses ?? [] as $existing) {
            if ($existing instanceof Response && (int) $existing->code === $code) {
                return;
            }
        }

        $body = (new ObjectType)
            ->addProperty('message', (new StringType)->setDescription('A human-readable summary. It carries no detail a caller could use to distinguish one cause from another.'))
            ->setRequired(['message']);

        $operation->addResponse(
            Response::make($code)
                ->setDescription($description)
                ->setContent('application/json', Schema::fromType($body))
        );
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
         * The request body is the one schema whose generated description comes
         * from a class docblock rather than from a field comment, and that
         * docblock argues with a plan step and an audit item. A third party
         * needs the rule, not the argument for it.
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
                    'description' => 'The body fields `POST /api/v1/membership/applications` requires on a first application. The consent is the only one: the statutes demand no legal name, no address and no date of birth.',
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

            StoreApplicationRequest::class => [
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
